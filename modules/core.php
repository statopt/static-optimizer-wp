<?php

$obj = StaticOptimizerCore::getInstance();
add_action( 'init', [ $obj, 'init' ] );

register_activation_hook(STATIC_OPTIMIZER_BASE_PLUGIN, [ $obj, 'onActivate' ] );
register_deactivation_hook(STATIC_OPTIMIZER_BASE_PLUGIN, [ $obj, 'onDeactivate' ] );
register_uninstall_hook( STATIC_OPTIMIZER_BASE_PLUGIN, [ $obj, 'onUninstall' ] );

class StaticOptimizerCore extends StaticOptimizerBase {
	/**
	 * The plugin was activated. If conf file exists and has api_key we can set it to status=1 now
	 */
	public function onActivate() {
		if ( ! file_exists( STATIC_OPTIMIZER_CONF_FILE ) ) {
			return;
		}

		$buff = file_get_contents(STATIC_OPTIMIZER_CONF_FILE);;

		if (empty($buff)) {
			return;
		}

		$json = json_decode($buff,true);

		// We'll reactivate if only it was activated before that and has an API key
		if (!empty($json) && empty($json['status']) && !empty($json['api_key']) && !empty($json['was_active_before_plugin_deactivation'])) {
			$json['status'] = true;
			$buff = json_encode($json, JSON_PRETTY_PRINT);

			if (!empty($buff)) {
				file_put_contents(STATIC_OPTIMIZER_CONF_FILE, $buff, LOCK_EX);
			}
		}
	}

	/**
	 * The plugin was deactivated so we need to set status to 0
	 */
	public function onDeactivate() {
		if ( ! file_exists( STATIC_OPTIMIZER_CONF_FILE ) ) {
			return;
		}

		$buff = file_get_contents(STATIC_OPTIMIZER_CONF_FILE);

		if (empty($buff)) {
			return;
		}

		$json = json_decode($buff,true);

		if (!empty($json) && !empty($json['status'])) { // update only if it was active before.
			$json['status'] = false;
			$json['was_active_before_plugin_deactivation'] = true;
			$buff = json_encode($json, JSON_PRETTY_PRINT);

			if (!empty($buff)) {
				file_put_contents(STATIC_OPTIMIZER_CONF_FILE, $buff, LOCK_EX);
			}
		}
	}

	/**
	 * The plugin is about to be uninstalled.
	 */
	public function onUninstall() {
		// delete cfg files and data dir
		if ( file_exists( STATIC_OPTIMIZER_CONF_FILE ) ) {
			unlink( STATIC_OPTIMIZER_CONF_FILE );
		}

		$opt_dir = dirname( STATIC_OPTIMIZER_CONF_FILE );

		if ( file_exists( $opt_dir . '/.htaccess' ) ) {
			unlink( $opt_dir . '/.htaccess' );
		}

		if ( file_exists( $opt_dir . '/index.html' ) ) {
			unlink( $opt_dir . '/index.html' );
		}

		if ( is_dir( $opt_dir ) ) {
			rmdir( $opt_dir );
		}

		delete_option( 'static_optimizer_settings' );

		// @todo clean htaccess if it was modified by us. backup first of course
	}
}
