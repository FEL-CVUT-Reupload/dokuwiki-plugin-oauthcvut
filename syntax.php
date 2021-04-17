<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_oauthcvut extends DokuWiki_Syntax_Plugin
{
	private $plugin_name = 'oauthcvut';

	private $course_regex = '{{course:[a-zA-Z0-9|]+(?:,sem=[a-zA-Z0-9]+)?(?:,merge)?}}';
	private $student_courses_regex = '{{student_courses}}';

	private $general_regex_match = '/{{([a-z_]+)(?:\:([^}]*))?}}/';
	private $course_regex_match = '/([a-zA-Z0-9|]+)(?:,sem=([a-zA-Z0-9]+))?(?:,(merge))?/';

	private $error_codes = array(
		1 => 'Chybný formát příkazu',
		2 => 'Pro správné zobrazení je potřeba se přihlásit přes ČVUT',
		3 => 'Chyba při parsování dat z KOSapi',
		4 => 'Chyba při získávání dat z KOSapi',
		5 => 'Zadaný předmět neexistuje',
		6 => 'Chyba na straně KOSapi'
	);

	public function getType()
	{
		return 'substition';
	}

	public function getSort()
	{
		return 191;
	}

	public function connectTo($mode)
	{
		$this->Lexer->addSpecialPattern($this->course_regex, $mode, 'plugin_' . $this->plugin_name);
		$this->Lexer->addSpecialPattern($this->student_courses_regex, $mode, 'plugin_' . $this->plugin_name);
	}

	private function parse_course($xml)
	{
		$ns = $xml->getNamespaces(true);

		$course = $xml->children($ns['atom']);
		$content = $course->content->children($ns['']);
		$instance = $content->instance;

		$semester = null;
		$teachers_data = array();
		if (!empty($instance)) {
			$semester = (string)$instance->attributes()->semester;
			$lecturers = $instance->lecturers;

			foreach ($lecturers->teacher as $teacher) {
				$api_url = $teacher->attributes($ns['xlink'])->href;

				$teachers_data[] = array(
					'name' => (string)$teacher,
					'username' => explode('/', $api_url)[1]
				);
			}
		};

		return array(
			'name' => (string)$course->title,
			'code' => (string)$content->code,
			'homepage' => (string)$content->homepage,
			'range' => (string)$content->range,
			'credits' => (string)$content->credits,
			'season' => (string)$content->season,
			'semester' => $semester,
			'teachers' => $teachers_data
		);
	}

	private function get_course_api_url($code, $semester)
	{
		return sprintf("%s/courses/%s?sem=%s", $this->getConf('endpoint-kos'), $code, $semester);
	}

	private function load_course($code, $semester)
	{
		/** @var helper_plugin_oauthcvut $helper */
		$helper = plugin_load('helper', $this->plugin_name);

		$access_token = $helper->get_var('access_token');
		if (!$access_token)
			return array('type' => 'error', 'code' => 2);

		$data = $helper->http_api_get_xml($this->get_course_api_url($code, $semester), $access_token);
		if ($data === false || $data === null)
			return array('type' => 'error', 'code' => 3);

		if ($data->status == '404') {
			$data = $helper->http_api_get_xml($this->get_course_api_url($code, 'none'), $access_token);
			if ($data === false || $data === null)
				return array('type' => 'error', 'code' => 3);
		}

		if ($data->status == '400')
			return array('type' => 'error', 'code' => 4);
		else if ($data->status == '404')
			return array('type' => 'error', 'code' => 5);
		else if (isset($data->status) && intval($data->status) >= 500)
			return array('type' => 'error', 'code' => 6);

		return array('type' => 'course', 'data' => $this->parse_course($data));
	}

	private function merge_courses($courses, $merge)
	{
		$tmp = array(
			'name' => array(),
			'code' => array(),
			'homepage' => array(),
			'range' => array(),
			'credits' => array(),
			'semester' => array(),
			'teachers' => array()
		);

		foreach ($courses['data'] as $course) {
			$tmp['name'][] = $course['name'];
			$tmp['code'][] = $course['code'];
			$tmp['homepage'][] = $course['homepage'];
			$tmp['range'][] = $course['range'];
			$tmp['credits'][] = $course['credits'];
			$tmp['semester'][] = $course['semester'];
			if ($merge)
				$tmp['teachers'] = array_merge($tmp['teachers'], $course['teachers']);
			else
				$tmp['teachers'][] = $course['teachers'];
		}

		$type = 'course_list';
		if ($merge) {
			foreach ($tmp as &$value) {
				$value = array_unique($value);
			}
			$type = 'course_merge';
		}

		return array('type' => $type, 'data' => $tmp);
	}

	private function handle_course($args)
	{
		preg_match($this->course_regex_match, $args, $course_match);

		if (!$course_match[1])
			return array('type' => 'error', 'code' => 1);

		$course_codes = explode('|', strtoupper($course_match[1]));
		$semester = !empty($course_match[2]) ? strtoupper($course_match[2]) : 'current';
		$merge = $course_match[3] == 'merge';

		$courses = array('type' => 'course_list', 'data' => array());
		foreach ($course_codes as $course_code) {
			$course = $this->load_course($course_code, $semester);
			if ($course['type'] == 'error')
				return $course;
			$courses['data'][] = $course['data'];
		}

		return $this->merge_courses($courses, $merge);
	}

	private function handle_student_courses($args)
	{
		return array('type' => 'student_courses');
	}

	public function handle($match, $state, $pos, Doku_Handler $handler)
	{
		if ($state != DOKU_LEXER_SPECIAL)
			return;

		preg_match($this->general_regex_match, $match, $regex_match);
		switch ($regex_match[1]) {
			case 'course':
				return $this->handle_course($regex_match[2]);
			case 'student_courses':
				return $this->handle_student_courses($regex_match[2]);
			default:
				return array('type' => 'error', 'code' => 1);
		}
	}

	private function render_course_table_row(Doku_Renderer_xhtml $renderer, $title, $content, $callback)
	{
		$renderer->doc .= '<tr><th>' . $title . '</th>';
		foreach ($content as $item) {
			$renderer->doc .= '<td>' . $callback($item) . '</td>';
		}
		$renderer->doc .= '</tr>';
	}

	private function render_course_table(Doku_Renderer_xhtml $renderer, $name, $code, $range, $credits, $homepages, $teachers)
	{
		$renderer->doc .= '<table class="' . $this->plugin_name . '_course_table">';
		//$this->render_course_table_row($renderer, 'Předmět', $name, fn ($item) => $item);
		$this->render_course_table_row($renderer, 'Kód', $code, fn ($item) => $item);
		$this->render_course_table_row($renderer, 'Časový rozsah', $range, fn ($item) => $item);
		$this->render_course_table_row($renderer, 'Kredity', $credits, fn ($item) => $item);
		$this->render_course_table_row($renderer, 'Materiály', $homepages, function ($items) {
			if (empty($items))
				return 'Tento předmět nemá webovou stránku!';
			if (!is_array($items))
				$items = array($items);
			$output = '';
			foreach ($items as $index => $homepage) {
				if ($index > 0)
					$output .= '<br>';
				$output .= '<a href="' . $homepage . '">' . htmlentities($homepage) . '</a>';
			}
			return $output;
		});
		$this->render_course_table_row($renderer, 'Přednášející', $teachers, function ($items) {
			if (empty($items))
				return 'Tento předmět se ve vybraném semestru nevyučuje!';
			$output = '';
			foreach ($items as $index => $teacher) {
				if ($index > 0)
					$output .= '<br>';
				$output .= '<a href="https://udb.fel.cvut.cz/udb.phtml?_cmd=show&odn=uid=' . $teacher['username'] . ',ou=People,o=feld.cvut.cz&_type=user">' . $teacher['name'] . '</a>';
			}
			return $output;
		});
		$renderer->doc .= '</table>';
	}

	private function render_course_list(Doku_Renderer_xhtml $renderer, $data)
	{
		$this->render_course_table($renderer, $data['name'], $data['code'], $data['range'], $data['credits'], $data['homepage'], $data['teachers']);
		return true;
	}

	private function render_course_merge(Doku_Renderer_xhtml $renderer, $data)
	{
		$this->render_course_table($renderer, array(implode('<br>', $data['name'])), array(implode('<br>', $data['code'])), array(implode('/', $data['range'])), array(implode('/', $data['credits'])), array($data['homepage']), array($data['teachers']));
		return true;
	}

	private function render_student_courses(Doku_Renderer_xhtml $renderer, $data)
	{
		/** @var helper_plugin_oauthcvut $helper */
		$helper = plugin_load('helper', $this->plugin_name);

		$access_token = $helper->get_var('access_token');
		if (!$access_token)
			return $this->render_error($renderer, 2);

		$username = $helper->get_var('info')['user'];

		$user_courses = $helper->http_api_get(sprintf("%s/students/%s/enrolledCourses?limit=100", $this->getConf('endpoint-kos'), $username), $access_token);
		if (!$user_courses)
			return $this->render_error($renderer, 6);

		$user_courses = explode(',', $user_courses);
		$index_result = idx_get_indexer()->lookupKey($this->plugin_name . '_courses', $user_courses);

		$renderer->doc .= '<ul>';
		foreach ($index_result as $course => $links) {
			if (!empty($links)) {
				$links_distance = array();
				foreach ($links as $course_link) {
					$title = p_get_metadata($course_link, 'title');
					if (!$title)
						$links_distance[$course_link] = 10000000;
					else
						$links_distance[$course_link] = levenshtein($course, $title);
				}

				asort($links_distance);

				$link = array_key_first($links_distance);
				$title = p_get_metadata($link, 'title');
				$renderer->doc .= '<li><a href="' . wl($link) . '">' . ($title ? $title : "Bez názvu") . '</a></li>';
			}
		}
		$renderer->doc .= '</ul>';

		return true;
	}

	private function render_error(Doku_Renderer $renderer, $code)
	{
		$renderer->doc .= "<b>" . $this->error_codes[$code] . "</b>";
		return true;
	}

	public function render($mode, Doku_Renderer $renderer, $data)
	{
		if (!$data)
			return false;

		if ($mode == 'xhtml') {
			/** @var Doku_Renderer_xhtml $renderer */
			switch ($data['type']) {
				case 'error':
					return $this->render_error($renderer, $data['code']);
				case 'course_list':
					return $this->render_course_list($renderer, $data['data']);
				case 'course_merge':
					return $this->render_course_merge($renderer, $data['data']);
				case 'student_courses':
					return $this->render_student_courses($renderer, $data['data']);
				default:
					$renderer->doc .= "<b>Invalid data from handler!</b>";
					return false;
			}
		} else if ($mode == 'metadata') {
			/** @var Doku_Renderer_metadata $renderer */
			switch ($data['type']) {
				case 'course_list':
				case 'course_merge':
					$old_course_metadata = $renderer->meta[$this->plugin_name . '_courses'];
					if (!$old_course_metadata)
						$old_course_metadata = array();
					$renderer->meta[$this->plugin_name . '_courses'] = array_unique(array_merge($old_course_metadata, $data['data']['code']));
					break;
				case 'student_courses':
					$renderer->meta[$this->plugin_name . '_nocache'] = true;
					break;
				case 'error':
					$renderer->meta[$this->plugin_name . '_cache_error'] = true;
					break;
				default:
					return false;
			}

			return true;
		}

		return false;
	}
}
