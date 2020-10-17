<?php

/**
 * Removes the settings created by the plugin for single and multi site.
 */
if ( !defined('ABSPATH') || !defined('WP_UNINSTALL_PLUGIN') ) {
    exit();
}

// delete cfg files and dir
if (defined('STATIC_OPTIMIZER_CONF_FILE')) {
	if (file_exists(STATIC_OPTIMIZER_CONF_FILE)) {
		unlink( STATIC_OPTIMIZER_CONF_FILE );
	}

	$opt_dir = dirname(STATIC_OPTIMIZER_CONF_FILE);

	if (file_exists($opt_dir . '/.htaccess')) {
		unlink( $opt_dir . '/.htaccess' );
	}

	if (file_exists($opt_dir . '/index.html')) {
		unlink( $opt_dir . '/index.html' );
	}

	if (is_dir($opt_dir)) {
		rmdir( $opt_dir );
	}
}

delete_option('static_optimizer_settings');

// @todo clean htaccess if it was modified by us. backup first
