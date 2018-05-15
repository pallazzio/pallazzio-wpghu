<?php
/**
 * Allows WordPress plugins hosted on GitHub to be updated automatically.
 *
 * @link https://github.com/pallazzio/pallazzio-wpghu/
 */

// if this file is called directly, abort
if ( ! defined( 'WPINC' ) ) die();

if ( ! class_exists( 'Pallazzio_WPGHU' ) ) :

class Pallazzio_WPGHU {
	private $github_user     = '';      // str    e.g. 'pallazzio'
	private $github_repo     = '';      // str    e.g. 'plugin-dir'
	private $github_response = null;    // object Info about new version from GitHub.
	private $access_token    = '';      // str    Optional. For private GitHub repo.
	private $plugin          = '';      // str    e.g. 'plugin-dir/plugin-file.php'
	private $plugin_path     = '';      // str    e.g. '/home/user/public_html/wp-content/plugins/plugin-dir'
	private $plugin_file     = '';      // str    e.g. '/home/user/public_html/wp-content/plugins/plugin-dir/plugin-file.php'
	private $plugin_data     = array(); // array  Info about currently installed version.

	/**
	 * Class constructor.
	 *
	 * @param str $plugin_file
	 * @param str $github_user
	 * @param str $access_token Optional.
	 */
	function __construct( $plugin_file, $github_user, $access_token = '' ) {
		$plugin_r           = explode( '/', $plugin_file );
		$plugin_r_count     = count( $plugin_r );
		$this->github_user  = $github_user;
		$this->github_repo  = $plugin_r[ $plugin_r_count - 2 ];
		$this->access_token = $access_token;
		$this->plugin       = $this->github_repo . '/' . $plugin_r[ $plugin_r_count - 1 ];
		$this->plugin_file  = $plugin_file;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
		add_filter( 'plugins_api',                           array( $this, 'plugin_info' ),      10, 3 );
		add_filter( 'upgrader_post_install',                 array( $this, 'post_install' ),     10, 3 );
	}

	/**
	 * Adds info to the plugin update transient.
	 *
	 * @param object $transient
	 *
	 * @return object $transient
	 */
	public function modify_transient( $transient ) {
		// if it was already set, don't do it again ( because this function can be called multiple times during a sigle page load )
		if ( isset( $transient->response[ $this->plugin ] ) /*|| isset( $transient->response[ $this->github_repo ] )*/ ) return $transient;

		$last_github_call_time = get_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU_Time' );

		if ( $last_github_call_time && time() - $last_github_call_time < /*60 * 60 * */6 ) { // don't call github more than once every six hours

			$stored = get_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU' );

			if ( ! empty( $stored ) ) {

				$transient->response[ $this->plugin ] = json_decode( $stored );

			} else {
				
				unset( $transient->response[ $this->plugin ] );
				//unset( $transient->response[ $this->github_repo ] );

			}

			return $transient;

		}

		$this->plugin_data     = get_plugin_data( $this->plugin_file );
		$this->github_response = empty( $this->github_response ) ? $this->github_api_fetch( $this->github_user, $this->github_repo, $this->access_token ) : null;

		update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU_Time', time() );

		$version = $this->plugin_data[ 'Version' ];
		if ( 1 !== version_compare( $this->github_response->tag_name, $version ) ) {

			// clear stored info because it may still contain the old version
			update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU', '' );
			return $transient;

		}

		$r = array(
			'new_version' => $this->github_response->tag_name,
			'package'     => $this->github_response->zipball_url,
			'url'         => $this->plugin_data[ 'PluginURI' ],
			'slug'        => $this->github_repo,
			'plugin'      => $this->plugin,
			'tested'      => isset( $this->github_response->tested ) ? $this->github_response->tested : '',
		);

		// add this plugin to the site transient
		$transient->response[ $this->plugin ] = (object) $r;

		// store this transient object locally so it can be used again without calling GitHub
		update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU', wp_json_encode( $r ) );

		return $transient;
	}

	/**
	 * Displays plugin info in the 'View Details' popup.
	 *
	 * @param object $result
	 *
	 * @return object $result
	 */
	public function plugin_info( $result, $action, $args ) {
		// TODO: add plugin info for 'View Details' popup
		return $result;
	}

	/**
	 * Checks for submodules.
	 *
	 * @param array $result
	 *
	 * @return array $result
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		$this->plugin_path = substr( $this->plugin_file, 0, strrpos( $this->plugin_file, '/' ) ); // no trailing slash
		$wp_filesystem->move( $result[ 'destination' ], $this->plugin_path );
		$result[ 'destination' ] = $this->plugin_path;
		activate_plugin( $this->plugin_file );

		$gitmodules_file = $this->plugin_path . '/.gitmodules';
		if ( file_exists( $gitmodules_file ) && $modules = parse_ini_file( $gitmodules_file, true ) ) {
			$this->get_modules( $modules );
		}

		// clear stored info so it won't still contain the old version
		update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU', '' );

		return $result;
	}

	/**
	 * Queries the GitHub API for information about the latest release.
	 *
	 * @param str $github_user
	 * @param str $github_repo
	 * @param str $access_token Optional.
	 *
	 * @return object $github_response
	 */
	private function github_api_fetch( $github_user, $github_repo, $access_token = '' ) {
		$url = 'https://api.github.com/repos/' . $github_user . '/' . $github_repo . '/releases';

		$url = ! empty( $access_token ) ? add_query_arg( array( 'access_token' => $access_token ), $url ) : $url;

		$github_response = json_decode( wp_remote_retrieve_body( wp_remote_get( $url ) ) );

		$github_response = is_array( $github_response ) ? current( $github_response ) : $github_response;

		preg_match( '/tested:\s([\d\.]+)/i', $github_response->body, $matches );
		if ( is_array( $matches ) && count( $matches ) > 1 ) {
			$github_response->tested = $matches[ 1 ];
		}

		return $github_response;
	}

	/**
	 * Downloads, unzips, and moves GitHub submodules to thier proper location.
	 * This only works with public GitHub repos.
	 *
	 * @param array $modules
	 * @param str   $module_path Optional.
	 *
	 * @return void
	 */
	private function get_modules( $modules, $module_path = '' ) {
		global $wp_filesystem;

		foreach ( $modules as $module ) {
			$module_r    = explode( '/', rtrim( $module[ 'url' ], '/' ) );
			$github_repo = array_pop( $module_r );
			$github_user = array_pop( $module_r );

			$github_response = $this->github_api_fetch( $github_user, $github_repo );

			$temp_filename = $this->plugin_path . '/' . $github_repo . '.zip';

			wp_remote_get( $github_response->zipball_url, array(
				'stream'   => true,
				'filename' => $temp_filename,
			) );

			// prepend path if submodule nesting level is deeper than 1
			$module[ 'path' ] = ! empty ( $module_path ) ? $module_path . '/' . $module[ 'path' ] : $module[ 'path' ];

			// unzip and rename dir
			$destination = $this->plugin_path . '/' . substr( $module[ 'path' ], 0, strrpos( $module[ 'path' ], '/' ) ); // no trailing slash
			unzip_file( $temp_filename, $destination );
			$wp_filesystem->delete( $temp_filename );
			$dirs = glob( $destination . '/*', GLOB_ONLYDIR );
			foreach ( $dirs as $dir ) {
				if ( false !== strpos( $dir, $github_user . '-' . $github_repo ) ) {
					$wp_filesystem->move( $dir, $destination . '/' . $github_repo, true );
					$wp_filesystem->delete( $dir, true );
				}
			}

			// Yo Dawg! I heard you like submodules, so I submoduled some submodules into your submodule so you can submodule while you submodule
			// recurse if the submodule has submodules of its own
			$gitmodules_file = $this->plugin_path . '/' . $module[ 'path' ] . '/.gitmodules';
			if ( file_exists( $gitmodules_file ) && $modules = parse_ini_file( $gitmodules_file, true ) ) {
				$this->get_modules( $modules, $module[ 'path' ] );
			}
		}
	}

	/**
	 * Writes to error_log.
	 *
	 * @param mixed  $log
	 * @param string $id Optional.
	 *
	 * @return void
	 */
	private function write_log( $log, $id = '' ) {
		error_log( '************* ' . $id . ' *************' );
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) );
		} else {
			error_log( $log );
		}
	}

}

endif;

?>
