<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class auth_plugin_oauthcvut extends auth_plugin_authplain
{
	private $plugin_name = 'oauthcvut';

	public function __construct()
	{
		parent::__construct();

		$this->cando['external'] = true;

		/** @var helper_plugin_oauthcvut $helper */
		$helper = plugin_load('helper', $this->plugin_name);
		if ($helper->get_var('logined'))
			$this->cando['modPass'] = false;

		$this->success = true;
	}

	function trustExternal($user, $pass, $sticky = false)
	{
		/** @var helper_plugin_oauthcvut $helper */
		$helper = plugin_load('helper', $this->plugin_name);
		global $ID, $USERINFO, $INPUT;

		if ($helper->get_var('logined')) { //user logined
			if ($helper->get_var('finish_id')) {
				if (!$this->processUser($_SESSION[DOKU_COOKIE][$this->plugin_name]['info'])) { // TODO: Better variable reference
					msg('Finishing login error!', -1);
					return false;
				}

				$url = wl($helper->get_var('finish_id'));
				$helper->unset_var('finish_id');
				send_redirect($url);
			}

			if ($helper->get_var('expires') <= time() && !$INPUT->has($this->plugin_name . '_renew')) // access token expired
				$helper->refresh_token();

			$USERINFO = $helper->get_var('info');
			$_SERVER['REMOTE_USER'] = $USERINFO['user'];
			return true;
		} else if ($helper->get_refresh_token() && !$INPUT->has($this->plugin_name . '_renew')) { //renew token
			$url = wl($ID, array($this->plugin_name . '_renew' => true));
			send_redirect($url);
		}

		return auth_login($user, $pass, $sticky); // normal login
	}

	function processUser(&$uinfo)
	{
		$user = $this->getUserData($uinfo['user']);
		if ($user) {
			$groups = array_unique(array_merge(array_filter($user['grps'], function ($var) {
				return substr($var, 0, 7) !== $this->getConf('group-prefix');
			}), $uinfo['grps']));

			if ($groups != $user['grps'])
				$this->modifyUser($uinfo['user'], array('grps' => $groups));

			$uinfo['name'] = $user['name'];
			$uinfo['mail'] = $user['mail'];
			return true;
		}

		if (!$this->addUser($uinfo)) {
			msg('something went wrong creating your user account. please try again later.', -1);
			return false;
		}

		return true;
	}

	protected function addUser(&$uinfo)
	{
		global $conf;
		$user = $uinfo['user'];
		$ok = $this->triggerUserMod(
			'create',
			array($user, auth_pwgen($user), $uinfo['name'], $uinfo['mail'], $uinfo['grps'],)
		);
		if (!$ok) {
			return false;
		}

		return true;
	}

	public function modifyUser($user, $changes)
	{
		/** @var helper_plugin_oauthcvut $helper */
		$helper = plugin_load('helper', $this->plugin_name);
		global $ID, $USERINFO;

		$own_session = session_status() === PHP_SESSION_NONE;

		if ($own_session)
			session_start();

		$new_info = $helper->get_var('info');

		if (isset($changes['mail']))
			$new_info['mail'] = $changes['mail'];
		if (isset($changes['name']))
			$new_info['name'] = $changes['name'];
		if (isset($changes['grps']))
			$new_info['grps'] = $changes['grps'];

		$helper->set_var('info', $new_info);
		$USERINFO = $new_info;

		$ok = parent::modifyUser($user, $changes);

		if ($own_session) {
			session_write_close();
			send_redirect(wl($ID)); //reload to update username in website header
		}

		return $ok;
	}
}
