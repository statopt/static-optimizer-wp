<?php

$obj = StaticOptimizerAdmin::getInstance();
add_action( 'init', [ $obj, 'init' ] );

class StaticOptimizerAdmin extends StaticOptimizerBase {
	/**
	 *
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'setupAdmin' ] );

		// multisite
		add_action( 'network_admin_menu', [ $this, 'setupAdmin' ] ); // manage_network_themes


		// this filter runs most often than action 'update_option_static_optimizer_settings'
		add_filter( 'pre_update_option_static_optimizer_settings', [ $this, 'static_optimizer_before_option_update' ], 20, 3 );
	}

	/**
	 * Set up administration
	 *
	 * @package StaticOptimizer
	 * @since 0.1
	 */
	public function setupAdmin() {
		$hook = add_options_page(
			__( 'StaticOptimizer', 'static_optimizer' ),
			__( 'StaticOptimizer', 'static_optimizer' ),
			'manage_options',
			__FILE__,
			'static_optimizer_options_page'
		);

		add_filter( 'plugin_action_links', [ $this, 'updatePluginLinksInManagePlugins' ], 10, 2 );
	}

	/**
	 * Adds the action link to settings. That's from Plugins. It is a nice thing.
	 *
	 * @param array $links
	 * @param string $file
	 *
	 * @return array
	 */
	function updatePluginLinksInManagePlugins( $links, $file ) {
		if ( $file == plugin_basename( __FILE__ ) ) {
			$link          = static_optimizer_get_settings_link();
			$settings_link = "<a href=\"{$link}\">Settings</a>";
			array_unshift( $links, $settings_link );
		}

		return $links;
	}

	/**
	 * @param $value
	 * @param $option
	 * @param $old_value
	 *
	 * @return mixed
	 */
	function static_optimizer_before_option_update($value, $old_value, $option) {
		$this->static_optimizer_after_option_update($old_value, $value, $option);
		return $value;
	}

	/**
	 * We'll sync the conf file on option save.
	 *
	 * @param array $old_value
	 * @param array $value
	 * @param string $option
	 */
	function static_optimizer_after_option_update( $old_value, $value, $option = null) {
		$dir = dirname( STATIC_OPTIMIZER_CONF_FILE );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$data = $value;

		$data['is_multisite']       = function_exists( 'is_multisite' ) && is_multisite() ? true : false;
		$data['site_url']           = $data['is_multisite'] ? network_site_url() : site_url();
		$data['host']               = parse_url( $data['site_url'], PHP_URL_HOST );
		$data['host']               = strtolower( $data['host'] );
		$data['host']               = preg_replace( '#^www\.#si', '', $data['host'] );
		$data['updated_on']         = date( 'r' );
		$data['updated_by_user_id'] = get_current_user_id();

		$data_str                   = @json_encode( $data, JSON_PRETTY_PRINT );

		if (empty($data_str)) { // JSON serialization failed possibly due to UTF-8 formatting
			// let's try php serialization
			$data_str = serialize( $data );

			// Well, we've tried ...
			if ( empty( $data_str ) ) {
				return;
			}

			// Let's encode the data so it works in case it's transferred to another host and another php version.
			$data_str = base64_encode($data_str);
		}

		// Save data
		$save_stat = file_put_contents( STATIC_OPTIMIZER_CONF_FILE, $data_str, LOCK_EX );

		// let's add an empty file
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', "StaticOptimizer", LOCK_EX );
		}

		// let's add some more protection
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "deny from all", LOCK_EX );
		}

		return $save_stat;
	}

}
