<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class action_plugin_oauthcvut extends DokuWiki_Action_Plugin
{
	private $plugin_name = 'oauthcvut';

	/**
	 * Registers a callback function for a given event
	 *
	 * @param Doku_Event_Handler $controller DokuWiki's event controller object
	 * @return void
	 */
	public function register(Doku_Event_Handler $controller)
	{
		global $conf;
		if ($conf['authtype'] != $this->plugin_name) return;

		$conf['profileconfirm'] = false; // password confirmation doesn't work with oauth only users

		$controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, 'handle_start');
		$controller->register_hook('HTML_LOGINFORM_OUTPUT', 'BEFORE', $this, 'handle_loginform');
		$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_dologin');
		$controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_parser_cache_use');
		$controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handle_indexer_page_add');
		$controller->register_hook('INDEXER_VERSION_GET', 'BEFORE', $this, 'handle_indexer_version_get');
	}

	/**
	 * Start an oAuth login or restore  environment after successful login
	 *
	 * @param Doku_Event $event  event object by reference
	 * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
	 *                           handler was registered]
	 * @return void
	 */
	public function handle_start(Doku_Event &$event, $param)
	{
		/** @var helper_plugin_oauthcvut $helper */
		$helper = plugin_load('helper', $this->plugin_name);
		global $ID, $INPUT;

		if ($INPUT->bool($this->plugin_name . '_login')) { //redirect to oauth login
			$state = bin2hex(random_bytes(12));
			$helper->set_var('state', $state);
			$helper->set_var('finish_id', $ID);

			$url = sprintf("%s?response_type=code&client_id=%s&state=%s&redirect_uri=%s", $this->getConf('endpoint-auth'), $this->getConf('client-id'), $state, wl('', '', true));
			send_redirect($url);
		} else if ($INPUT->bool($this->plugin_name . '_renew')) {
			// Renew access token
			$refresh_token = $helper->get_refresh_token();
			$data = $helper->http_post($this->getConf('endpoint-token'), "grant_type=refresh_token&refresh_token=" . $refresh_token);
			if ($data == null) {
				msg("Invalid oauth2 renew!", -1);
				$helper->clear_data();
				return;
			}

			$helper->set_var('expires', time() + $data['expires_in']);
			$this->login($data['access_token']);
		} else if ($INPUT->has('state') && $INPUT->has('code')) {
			if ($helper->get_var('state') != $INPUT->str('state')) {
				msg("Invalid oauth2 state!", -1);
				$helper->clear_data();
				return;
			}

			$helper->unset_var('state');

			// Get access token
			$data = $helper->http_post($this->getConf('endpoint-token'), sprintf("grant_type=authorization_code&code=%s&redirect_uri=%s", $INPUT->str('code'), wl('', '', true)));
			if ($data == null) {
				msg("Invalid oauth2 get token!", -1);
				$helper->clear_data();
				return;
			}

			$helper->set_var('expires', time() + $data['expires_in']);
			$helper->set_refresh_token($data['refresh_token']);

			$this->login($data['access_token']);
		} else if ($INPUT->has('error')) {
			msg($INPUT->str('error'), -1);
			$helper->clear_data();
		}
	}

	private function in_array_multi($search, $array)
	{
		foreach ($search as $value) {
			if (in_array($value, $array))
				return true;
		}
		return false;
	}

	function login($access_token)
	{
		/** @var helper_plugin_oauthcvut $helper */
		$helper = plugin_load('helper', $this->plugin_name);
		global $conf;

		// Get user info
		$data = $helper->http_post($this->getConf('endpoint-check-token'), "token=" . $access_token);
		if ($data == null) {
			msg("Invalid oauth2 get info!", -1);
			$helper->clear_data();
			return;
		}

		$usermap_url = sprintf("%s/people/%s", $this->getConf('endpoint-usermap'), $data['user_name']);
		$usermap_data = $helper->http_api_get_json($usermap_url, $access_token);

		$groups = array($conf['defaultgroup']);

		$group_prefix = $this->getConf('group-prefix');
		if ($this->in_array_multi($this->getConf('usermap-rw'), $usermap_data['roles']))
			$groups[] = $group_prefix . '-rw';

		if ($this->in_array_multi($this->getConf('usermap-teacher'), $usermap_data['roles']))
			$groups[] = $group_prefix . '-teacher';
		else if ($this->in_array_multi($this->getConf('usermap-student'), $usermap_data['roles']))
			$groups[] = $group_prefix . '-student';
		else
			$groups[] = $group_prefix . '-other';

		// Get courses
		$courses_url = sprintf("%s/students/%s/enrolledCourses?limit=100", $this->getConf('endpoint-kos'), $data['user_name']);
		$courses_data = $helper->http_api_get($courses_url, $access_token, "text/plain");
		$courses_data = explode(',', $courses_data);

		$helper->set_var('logined', true);
		$helper->set_var('access_token', $access_token);
		$helper->set_var('info', array(
			'user' => $data['user_name'],
			'name' => $usermap_data['fullName'],
			'mail' => $usermap_data['preferredEmail'],
			'grps' => $groups
		));
		$helper->set_var('courses', $courses_data);

		send_redirect(wl());
	}

	private function draw_hidden_button($url, $text)
	{
		return '<a style="display: block; position: absolute; bottom: 0.5rem; right: 0.5rem; opacity: 0.3; font-size: 0.75rem" href="' . $url . '">' . $text . '</a></span>';
	}

	public function handle_loginform(Doku_Event &$event, $param)
	{
		global $ID, $INPUT;

		/** @var Doku_Form $form */
		$form = &$event->data;

		if ($INPUT->bool($this->plugin_name . "_basiclogin"))
			$form->_content[] = $this->draw_hidden_button(wl($ID, array('do' => 'login')), 'Normal login');
		else
			$form->_content = array('<button type="button" style="margin: 1rem;" onclick="window.location.href = \'' . wl($ID, array($this->plugin_name . '_login' => true)) . '\'">ČVUT Login</button>', $this->draw_hidden_button(wl($ID, array('do' => 'login', $this->plugin_name . '_basiclogin' => true)), 'Admin login'));
	}

	public function handle_dologin(Doku_Event &$event, $param)
	{
		if ($event->data == 'logout') {
			session_start();
			/** @var helper_plugin_oauthcvut $helper */
			$helper = plugin_load('helper', $this->plugin_name);
			$helper->clear_data();
			session_write_close();
		}
	}

	public function handle_parser_cache_use(Doku_Event $event, $param)
	{
		global $conf;
		/** @var CacheRenderer $cache */
		$cache = $event->data;

		if (!isset($cache->page))
			return;

		if ($cache->mode != 'xhtml') //purge only xhtml cache
			return;

		$no_cache = p_get_metadata($cache->page, $this->plugin_name . '_nocache');
		$error_cache = p_get_metadata($cache->page, $this->plugin_name . '_cache_error');
		if (!$no_cache && !$error_cache) return;

		if ($error_cache)
			touch('conf/local.php');

		$cache->depends['age'] = -1;
	}

	public function handle_indexer_page_add(Doku_Event $event, $param)
	{
		$courses = p_get_metadata($event->data['page'], $this->plugin_name . '_courses');
		$event->data['metadata'][$this->plugin_name . '_courses'] = $courses;
	}

	public function handle_indexer_version_get(Doku_Event $event, $param)
	{
		$event->data['plugin_' . $this->plugin_name] = '1';
	}
}
