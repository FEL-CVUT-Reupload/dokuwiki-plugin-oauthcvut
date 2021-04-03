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
	}

	// TODO: Merge getters/setters with auth.php
	private function get_var($name)
	{
		return $_SESSION[DOKU_COOKIE][$this->plugin_name][$name];
	}

	private function set_var($name, $value)
	{
		return $_SESSION[DOKU_COOKIE][$this->plugin_name][$name] = $value;
	}

	private function unset_var($name)
	{
		unset($_SESSION[DOKU_COOKIE][$this->plugin_name][$name]);
	}

	private function get_refresh_token()
	{
		return $_COOKIE[DOKU_COOKIE . $this->plugin_name];
	}

	private function set_refresh_token($token)
	{
		if ($token)
			setcookie(DOKU_COOKIE . $this->plugin_name, $token, time() + 60 * 60 * 24 * 365, DOKU_REL, '', is_ssl(), true);
		else
			setcookie(DOKU_COOKIE . $this->plugin_name, '', time() - 60, DOKU_REL, '', is_ssl(), true);
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
		global $ID, $INPUT;
		if ($INPUT->bool($this->plugin_name . '_login')) { //redirect to oauth login
			$state = bin2hex(random_bytes(12));
			$this->set_var('state', $state);
			$this->set_var('finish_id', $ID);

			$url = sprintf("%s?response_type=code&client_id=%s&state=%s&redirect_uri=%s", $this->getConf('endpoint-auth'), $this->getConf('client-id'), $state, wl('', '', true));
			send_redirect($url);
		} else if ($INPUT->bool($this->plugin_name . '_renew')) {
			// Renew access token
			$refresh_token = $this->get_refresh_token();
			$data = $this->http_post($this->getConf('endpoint-token'), "grant_type=refresh_token&refresh_token=" . $refresh_token);
			if ($data == null) {
				msg("Invalid oauth2 renew!", -1);
				$this->clearData();
				return;
			}

			$this->login($data['access_token']);
		} else if ($INPUT->has('state') && $INPUT->has('code')) {
			if ($this->get_var('state') != $INPUT->str('state')) {
				msg("Invalid oauth2 state!", -1);
				$this->clearData();
				return;
			}

			$this->unset_var('state');

			// Get access token
			$data = $this->http_post($this->getConf('endpoint-token'), sprintf("grant_type=authorization_code&code=%s&redirect_uri=%s", $INPUT->str('code'), wl('', '', true)));
			if ($data == null) {
				msg("Invalid oauth2 get token!", -1);
				$this->clearData();
				return;
			}

			$this->set_refresh_token($data['refresh_token']);

			$this->login($data['access_token']);
		} else if ($INPUT->has('error')) {
			msg($INPUT->str('error'), -1);
			$this->clearData();
		}
	}

	private function clearData()
	{
		if (isset($_SESSION[DOKU_COOKIE][$this->plugin_name]))
			unset($_SESSION[DOKU_COOKIE][$this->plugin_name]);
		$this->set_refresh_token(null);
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
		global $conf;

		// Get user info
		$data = $this->http_post($this->getConf('endpoint-check-token'), "token=" . $access_token);
		if ($data == null) {
			msg("Invalid oauth2 get info!", -1);
			$this->clearData();
			return;
		}

		$usermap_url = sprintf("%s/people/%s", $this->getConf('endpoint-usermap'), $data['user_name']);
		$usermap_data = $this->http_api_get($usermap_url, $access_token);

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

		$this->set_var('logined', true);
		$this->set_var('access_token', $access_token);
		$this->set_var('info', array(
			'user' => $data['user_name'],
			'name' => $usermap_data['fullName'],
			'mail' => $usermap_data['preferredEmail'],
			'grps' => $groups
		));

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
			$this->clearData();
			session_write_close();
		}
	}

	private function http_post($url, $post_fields)
	{
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERPWD => $this->getConf('client-id') . ":" . $this->getConf('client-secret'),
			CURLOPT_POSTFIELDS => $post_fields
		]);

		$data_str = curl_exec($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if ($http_code != 200) //TODO: Handle error
			return null;
		return json_decode($data_str, true);
	}

	private function http_api_get($url, $access_token)
	{
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				"Authorization: Bearer " . $access_token
			]
		]);

		$data_str = curl_exec($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if ($http_code != 200) //TODO: Handle error
			return null;
		return json_decode($data_str, true);
	}
}
