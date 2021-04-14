<?php
/**
 * Skeleton Core Application class
 *
 * @author Christophe Gosiau <christophe@tigron.be>
 * @author Gerry Demaret <gerry@tigron.be>
 */

namespace Skeleton\Core\Application;

use \Skeleton\Core\Web\Session;
use \Skeleton\Core\Web\Media;
use \Skeleton\Core\Web\Module;

class Web extends \Skeleton\Core\Application {

	/**
	 * Media Path
	 *
	 * @var string $media_path
	 * @access public
	 */
	public $media_path = null;

	/**
	 * Module Path
	 *
	 * @var string $module_path
	 * @access public
	 */
	public $module_path = null;

	/**
	 * Template path
	 *
	 * @var string $template_path
	 * @ccess public
	 */
	public $template_path = null;

	/**
	 * Get details
	 *
	 * @access protected
	 */
	protected function get_details() {
		parent::get_details();
		
		$this->media_path = $this->path . '/media/';
		$this->module_path = $this->path . '/module/';
		$this->template_path = $this->path . '/template/';
	}


	/**
	 * Load the config
	 *
	 * @access private
	 */
	protected function load_config() {
		/**
		 * Set some defaults
		 */
		$this->config->session_name = 'APP';
		$this->config->sticky_session_name = 'sys_sticky_session';
		$this->config->csrf_enabled = false;
		$this->config->replay_enabled = false;
		$this->config->hostnames = [];
		$this->config->routes = [];
		$this->config->default_language = 'en';
		$this->config->module_default = 'index';
		$this->config->module_404 = '404';
		$this->config->sticky_pager = false;

		parent::load_config();
	}

	/**
	 * Run the application
	 *
	 * @access public
	 */
	public function run() {
		/**
		 * Handle the media
		 */
		if (isset($this->config->detect_media) AND $this->config->detect_media === true OR !isset($this->config->detect_media)) {
			try {
				Media::detect($this->request_relative_uri);
			} catch (\Skeleton\Core\Exception\Media\Not\Found $e) {
				HTTP\Status::code_404('media');
			}
		}

		/**
		 * Start the session
		 */
		$session_properties = [];
		Session::start($session_properties);

		/**
		 * Find the module to load
		 *
		 * FIXME: this nested try/catch is not the prettiest of things
		 */
		$module = null;
		try {
			// Attempt to find the module by matching defined routes
			$module = $this->route($request_uri);
		} catch (\Exception $e) {
			try {
				// Attempt to find a module by matching paths
				$module = Module::get($this->request_relative_uri);
			} catch (\Exception $e) {
				if ($this->event_exists('module', 'not_found')) {
					$this->call_event_if_exists('module', 'not_found');
				} else {
					HTTP\Status::code_404('module');
				}
			}
		}

		/**
		 * Set language
		 */
		// Set the language to something sensible if it isn't set yet
		if (class_exists('\Skeleton\I18n\Config') AND isset(\Skeleton\I18n\Config::$language_interface)) {
			$language_interface = \Skeleton\I18n\Config::$language_interface;
			if (!class_exists($language_interface)) {
				throw new \Exception('The language interface does not exists: ' . $language_interface);
			}

			if (!isset($_SESSION['language'])) {
				try {
					$language = $language_interface::detect();
					$_SESSION['language'] = $language;
				} catch (\Exception $e) {
					$language = $language_interface::get_by_name_short($this->config->default_language);
					$_SESSION['language'] = $language;
				}
			}

			if (isset($_GET['language'])) {
				try {
					$language = $language_interface::get_by_name_short($_GET['language']);
					$_SESSION['language'] = $language;
				} catch (\Exception $e) {
					$_SESSION['language'] = $language_interface::get_by_name_short($this->config->default_language);
				}
			}
			$this->language = $_SESSION['language'];
		}

		/**
		 * Validate CSRF
		 */
		$csrf = \Skeleton\Core\Web\Security\Csrf::get();

		if ($session_properties['resumed'] === true && !$csrf->validate()) {
			if ($this->event_exists('security', 'csrf_validation_failed')) {
				$this->call_event_if_exists('security', 'csrf_validation_failed');
			} else {
				HTTP\Status::code_403('CSRF validation failed');
			}
		}

		/**
		 * Check for replays
		 */
		$replay = \Skeleton\Core\Web\Security\Replay::get();
		if ($replay->check() == false) {
			$this->call_event('security', 'replay_detected');
		}

		if ($module !== null) {
			$module->accept_request();
		}
	}

	/**
	 * Search module
	 *
	 * @access public
	 * @param string $request_uri
	 */
	public function route($request_uri) {
		/**
		 * Remove leading slash
		 */
		if ($request_uri[0] == '/') {
			$request_uri = substr($request_uri, 1);
		}

		if (substr($request_uri, -1) == '/') {
			$request_uri = substr($request_uri, 0, strlen($request_uri)-1);
		}

		if (!isset($this->config->base_uri)) {
			$this->config->base_uri = '/';
		}

		if (strpos( '/' . $request_uri, $this->config->base_uri) === 0) {
			$request_uri = substr($request_uri, strlen($this->config->base_uri)-1);
		}
		$request_parts = explode('/', $request_uri);

		$routes = $this->config->routes;

		/**
		 * We need to find the route that matches the most the fixed parts
		 */
		$matched_module = null;
		$best_matches_fixed_parts = 0;
		$route = '';

		foreach ($routes as $module => $uris) {
			foreach ($uris as $uri) {
				if (isset($uri[0]) AND $uri[0] == '/') {
					$uri = substr($uri, 1);
				}
				$parts = explode('/', $uri);
				$matches_fixed_parts = 0;
				$match = true;

				foreach ($parts as $key => $value) {
					if (!isset($request_parts[$key])) {
						$match = false;
						continue;
					}

					if ($value == $request_parts[$key]) {
						$matches_fixed_parts++;
						continue;
					}

					if (isset($value[0]) AND $value[0] == '$') {
						preg_match_all('/(\[(.*?)\])/', $value, $matches);
						if (!isset($matches[2][0])) {
							/**
							 *  There are no possible values for the variable
							 *  The match is valid
							 */
							 continue;
						}

						$possible_values = explode(',', $matches[2][0]);

						$variable_matches = false;
						foreach ($possible_values as $possible_value) {
							if ($request_parts[$key] == $possible_value) {
								$variable_matches = true;
							}
						}

						if (!$variable_matches) {
							$match = false;
						}

						// This is a variable, we do not increase the fixed parts
						continue;
					}
					$match = false;
				}


				if ($match and count($parts) == count($request_parts)) {
					if ($matches_fixed_parts >= $best_matches_fixed_parts) {
						$best_matches_fixed_parts = $matches_fixed_parts;
						$route = $uri;
						$matched_module = $module;
					}
				}
			}
		}

		if ($matched_module === null) {
			throw new \Exception('No matching route found');
		}

		/**
		 * We now have the correct route
		 * Now fill in the GET-parameters
		 */
		$parts = explode('/', $route);

		foreach ($parts as $key => $value) {
			if (isset($value[0]) and $value[0] == '$') {
				$value = substr($value, 1);
				if (strpos($value, '[') !== false) {
					$value = substr($value, 0, strpos($value, '['));
				}
				$_GET[$value] = $request_parts[$key];
				$_REQUEST[$value] = $request_parts[$key];
			}
		}

		$request_relative_uri = str_replace('web_module_', '', $matched_module);
		$request_relative_uri = str_replace('_', '/', $request_relative_uri);
		return \Skeleton\Core\Web\Module::get($request_relative_uri);
	}
}
