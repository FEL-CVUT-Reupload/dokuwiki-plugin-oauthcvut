<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class helper_plugin_oauthcvut extends DokuWiki_Plugin
{
	public $plugin_name = 'oauthcvut';

	public function get_var($name)
	{
		return $_SESSION[DOKU_COOKIE][$this->plugin_name][$name];
	}

	public function set_var($name, $value)
	{
		return $_SESSION[DOKU_COOKIE][$this->plugin_name][$name] = $value;
	}

	public function unset_var($name)
	{
		unset($_SESSION[DOKU_COOKIE][$this->plugin_name][$name]);
	}

	public function get_refresh_token()
	{
		return $_COOKIE[DOKU_COOKIE . $this->plugin_name];
	}

	public function set_refresh_token($token)
	{
		if ($token)
			setcookie(DOKU_COOKIE . $this->plugin_name, $token, time() + 60 * 60 * 24 * 365, DOKU_REL, '', is_ssl(), true);
		else
			setcookie(DOKU_COOKIE . $this->plugin_name, '', time() - 60, DOKU_REL, '', is_ssl(), true);
	}

	public function clear_data()
	{
		if (isset($_SESSION[DOKU_COOKIE][$this->plugin_name]))
			unset($_SESSION[DOKU_COOKIE][$this->plugin_name]);
		$this->set_refresh_token(null);
	}

	public function refresh_token()
	{
		$refresh_token = $this->get_refresh_token();
		if (!$refresh_token) {
			msg("No oauth2 refresh token found!", -1);
			$this->clear_data();
			return;
		}

		$data = $this->http_post($this->getConf('endpoint-token'), "grant_type=refresh_token&refresh_token=" . $refresh_token);
		if ($data == null) {
			msg("Invalid oauth2 refresh!", -1);
			$this->clear_data();
			return;
		}

		$this->set_var('expires', time() + $data['expires_in']);
		$this->set_var('access_token', $data['access_token']);
	}

	public function http_post($url, $post_fields)
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

	public function http_api_get($url, $access_token, $mime_type)
	{
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				"Authorization: Bearer " . $access_token,
				"Accept: " . $mime_type
			]
		]);

		$data_str = curl_exec($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		/*if ($http_code != 200) //TODO: Handle error
			return null;*/
		return $data_str;
	}

	public function http_api_get_json($url, $access_token)
	{
		$data_str = $this->http_api_get($url, $access_token, "application/json");
		if (!$data_str)
			return null;
		return json_decode($data_str, true);
	}

	public function http_api_get_xml($url, $access_token)
	{
		$data_str = $this->http_api_get($url, $access_token, "application/xml");
		if (!$data_str)
			return null;
		return simplexml_load_string($data_str);
	}
}
