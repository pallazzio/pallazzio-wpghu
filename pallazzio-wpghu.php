<?php
/**
 * Allows WordPress plugins and themes hosted on GitHub to be updated automatically.
 *
 * @link https://github.com/pallazzio/pallazzio-wpghu/
 */

// if this file is called directly, abort
if ( ! defined( 'WPINC' ) ) die();

if ( ! class_exists( 'Pallazzio_WPGHU' ) ) :

class Pallazzio_WPGHU {
	private $is_theme        = false;   // bool
	private $github_user     = '';      // str e.g. 'pallazzio'
	private $github_repo     = '';      // str e.g. 'item-dir'
	private $github_response = array(); // array Info about new version from GitHub.
	private $access_token    = '';      // str Optional. For private GitHub repo.
	private $item            = '';      // str e.g. 'item-dir/item-file.php'
	private $item_path       = '';      // str e.g. '/home/user/public_html/wp-content/[plugins],[themes]/item-dir'
	private $item_file       = '';      // str e.g. '/home/user/public_html/wp-content/[plugins],[themes]/item-dir/item-file.php'
	private $item_data       = array(); // array Info about currently installed version.
	private $plugin_active   = false;   // bool

	/**
	 * Class constructor.
	 *
	 * @param  str $item_file
	 * @param  str $github_user
	 * @param  str $access_token Optional.
	 */
	function __construct( $item_file, $github_user, $access_token = '' ) {
		$item_r             = explode( '/', $item_file );
		$item_r_count       = count( $item_r );
		$this->is_theme     = $item_r[ $item_r_count - 3 ] === 'themes' ? true : false;
		$this->github_user  = $github_user;
		$this->github_repo  = $item_r[ $item_r_count - 2 ];
		$this->access_token = $access_token;
		$this->item         = $this->github_repo . '/' . $item_r[ $item_r_count - 1 ];
		$this->item_file    = $item_file;

		if ( $this->is_theme ) {
			add_filter( 'pre_set_site_transient_update_themes',  array( $this, 'modify_transient' ), 10, 1 );
			add_filter( 'themes_api',                            array( $this, 'item_info' ),        10, 3 );
		} else {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
			add_filter( 'plugins_api',                           array( $this, 'item_info' ),        10, 3 );
			add_filter( 'upgrader_pre_install',                  array( $this, 'pre_install'  ),     10, 2 );
		}

		add_filter( 'upgrader_package_options', array( $this, 'modify_package' ), 10, 1 );
		add_filter( 'upgrader_post_install',    array( $this, 'post_install' ),   10, 3 );
	}

	/**
	 * Adds info to the item update transient.
	 *
	 * @param object $transient
	 *
	 * @return object $transient
	 */
	public function modify_transient( $transient ) {
		// if it was already set, don't do it again ( because this function can be called multiple times during a sigle page load )
		if ( isset( $transient->response[ $this->item ] ) || isset( $transient->response[ $this->github_repo ] ) ) return $transient;

		$last_github_call_time = get_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU_Time' );
		if ( $last_github_call_time && time() - $last_github_call_time < 60 * 60 * 6 ) { // don't query github more than once every six hours

			if ( $stored = get_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU' ) && ! empty( $stored ) ) {

				if ( $this->is_theme ) {
					$transient->response[ $this->github_repo ] = json_decode( $stored, true );
				} else {
					$transient->response[ $this->item ] = json_decode( $stored );
				}

			} else {
				
				unset( $transient->response[ $this->item ] );
				unset( $transient->response[ $this->github_repo ] );

			}

			return $transient;
		}

		$this->item_data       = $this->is_theme ? wp_get_theme( $this->github_repo ) : get_plugin_data( $this->item_file );
		$this->github_response = empty( $this->github_response ) ? $this->github_api_fetch( $this->github_user, $this->github_repo, $this->access_token ) : '';

		update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU_Time', time() );

		$version = $this->is_theme ? $this->item_data->get( 'Version' ) : $this->item_data[ 'Version' ];
		if ( 1 !== version_compare( $this->github_response->tag_name, $version ) ) {

			// clear stored info because it may still contain the old version
			update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU', '' );

			return $transient;
		}

		$r = array();
		$r[ 'new_version' ] = $this->github_response->tag_name;
		$r[ 'package' ]     = $this->github_response->zipball_url;
		$r[ 'url' ]         = $this->is_theme ? $this->item_data->get( 'ThemeURI' ) : $this->item_data[ 'PluginURI' ];
		if ( $this->is_theme ) {
			$r[ 'theme' ]  = $this->github_repo;

			// add this theme to the site transient
			$transient->response[ $this->github_repo ] = $r;
		} else {
			$r[ 'slug' ]   = $this->github_repo;
			$r[ 'plugin' ] = $this->item;
			$r[ 'tested' ] = isset( $this->github_response->tested ) ? $this->github_response->tested : '';

			// add this plugin to the site transient
			$transient->response[ $this->item ] = (object) $r;
		}

		// store this transient object locally so it can be used again without querying GitHub
		update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU', wp_json_encode( $r ) );

		return $transient;
	}

	/**
	 * Stores the package locally and renames the dir inside the zipball: FROM "the GitHub release identifier" TO "the plugin folder name".
	 *
	 * @param array $options
	 *
	 * @return array $options
	 */
	public function modify_package( $options ) {
		global $wp_filesystem;

		$this->item_path = substr( $this->item_file, 0, strrpos( $this->item_file, '/' ) ); // no trailing slash
		$destination     = $this->item_path . '/package-temp-' . time();
		$temp_filename   = $destination . '.zip';

		wp_remote_get( $options[ 'package' ], array(
			'stream'   => true,
			'filename' => $temp_filename,
		) );

		$wp_filesystem->mkdir( $destination );
		unzip_file( $temp_filename, $destination );
		$dirs = glob( $destination . '/*', GLOB_ONLYDIR );
		$temp_dirname = '';
		foreach ( $dirs as $dir ) {
			if ( false !== strpos( $dir, $github_user . '-' . $github_repo ) ) {
				$temp_dirname = $dir;
				break;
			}
		}

		$zip = new ZipArchive();
		$zip->open( $this->item_path . '/' . $this->github_repo . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $temp_dirname ), RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $file ) {
			if ( ! $file->isDir() ) {
				$filePath     = $file->getRealPath();
				$relativePath = $this->github_repo . '/' . substr( $filePath, strlen( $temp_dirname ) + 1 );

				$zip->addFile( $filePath, $relativePath );
			}
		}

		$zip->close();

		$options[ 'package' ] = get_template_directory_uri() . '/' . $this->github_repo . '.zip';

		return $options;
	}

	/**
	 * Displays item info in the 'View Details' popup.
	 *
	 * @param object $result
	 *
	 * @return object $result
	 */
	public function item_info( $result, $action, $args ) {
		// TODO: add item info for 'View Details' popup
		return $result;
	}

	/**
	 * Registers the state of the item before updating so that it can be set to the same state afterwards.
	 *
	 * @return void
	 */
	public function pre_install( $true, $args ) {
		$this->plugin_active = is_plugin_active( $this->item );
	}

	/**
	 * Modifies the internal location pointer and moves the files from the GitHub dir name to the WordPress dir name.
	 *
	 * @param array $result
	 *
	 * @return array $result
	 */
	public function post_install( $response, $hook_extra, $result ) {
		// get any submodules that may be part of the item
		$gitmodules_file = $this->item_path . '/.gitmodules';
		if ( file_exists( $gitmodules_file ) && $modules = parse_ini_file( $gitmodules_file, true ) ) {
			$this->get_modules( $modules );
		}

		// clear stored info so it won't still contain the old version
		update_option( $this->github_user . '_' . $this->github_repo . '_Pallazzio_WPGHU', '' );

		if ( $this->plugin_active ) activate_plugin( $this->item_file );

		return $result;
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
			$module_r    = explode( '/', $module[ 'url' ] );
			$github_repo = array_pop( $module_r );
			$github_user = array_pop( $module_r );

			$github_response = $this->github_api_fetch( $github_user, $github_repo );

			$temp_filename = $this->item_path . '/' . $github_repo . '.zip';

			wp_remote_get( $github_response->zipball_url, array(
				'stream'   => true,
				'filename' => $temp_filename,
			) );

			// prepend path if submodule nesting level is deeper than 1
			$module[ 'path' ] = ! empty ( $module_path ) ? $module_path . '/' . $module[ 'path' ] : $module[ 'path' ];

			// unzip and rename dir
			$destination = $this->item_path . '/' . substr( $module[ 'path' ], 0, strrpos( $module[ 'path' ], '/' ) ); // no trailing slash
			unzip_file( $temp_filename, $destination );
			$wp_filesystem->delete( $temp_filename );
			$dirs = glob( $destination . '/*', GLOB_ONLYDIR );
			foreach ( $dirs as $dir ) {
				if ( false !== strpos( $dir, $github_user . '-' . $github_repo ) ) {
					$wp_filesystem->move( $dir, $destination . '/' . $github_repo, true );
				}
			}

			// yo dawg, I heard you like submodules, so I submoduled some submodules into your submodule so you can submodule while you submodule
			// recurse if the submodule has submodules of its own
			$gitmodules_file = $this->item_path . '/' . $module[ 'path' ] . '/.gitmodules';
			if ( file_exists( $gitmodules_file ) && $modules = parse_ini_file( $gitmodules_file, true ) ) {
				$this->get_modules( $modules, $module[ 'path' ] );
			}
		}
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

		$matches = null;
		preg_match( '/tested:\s([\d\.]+)/i', $github_response->body, $matches );
		if ( is_array( $matches ) && count( $matches ) > 1 ) {
			$github_response->tested = $matches[ 1 ];
		}

		return $github_response;
	}

}

endif;

?>
