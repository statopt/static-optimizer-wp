<?php
/*
Plugin Name: StaticOptimizer
Plugin URI: https://statopt.com
Description: Makes your images, js, css load faster by optimizing them and loading them from StaticOptimizer Optimization servers
Version: 1.0.4
Author: StaticOptimizer & Orbisius
Author URI: https://orbisius.com
*/

/*  Copyright 2012-3000 Svetoslav Marinov (Slavi) <slavi@orbisius.com>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

// define('STATIC_OPTIMIZER_ACTIVE', 0); // to turn off define this in WP config
define( 'STATIC_OPTIMIZER_LIVE_ENV', empty( $_SERVER['DEV_ENV'] ) );
define( 'STATIC_OPTIMIZER_BASE_PLUGIN', __FILE__ );

define( 'STATIC_OPTIMIZER_APP_SITE_URL',
	STATIC_OPTIMIZER_LIVE_ENV
		? 'https://app.statopt.com'
		: 'https://1mapps.qsandbox0.staging.com/statopt/app'
);

if ( defined( 'WP_CONTENT_DIR' ) ) {
	define( 'STATIC_OPTIMIZER_CONF_FILE', WP_CONTENT_DIR . '/.ht-static-optimizer/.ht_config.json' );
} else {
	define( 'STATIC_OPTIMIZER_CONF_FILE', __DIR__ . '/.ht_config.json' );
}

$static_optimizer_worker = __DIR__ . '/000-static-optimizer-system-worker.php';

if (!defined('STATIC_OPTIMIZER_ACTIVE') || STATIC_OPTIMIZER_ACTIVE) {
	include_once $static_optimizer_worker;

	// The worker has all the conditions to run so don't load the plugin's stuff.
	if (defined('STATIC_OPTIMIZER_WORKER_RUNNING') && STATIC_OPTIMIZER_WORKER_RUNNING) {
		return;
	}
}

require_once __DIR__ . '/lib/request.php';
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/modules/core.php';

if (is_admin()) {
	require_once __DIR__ . '/modules/admin.php';
}

register_activation_hook(__FILE__, 'static_optimizer_process_activate');
register_deactivation_hook(__FILE__, 'static_optimizer_process_deactivate');
register_uninstall_hook( __FILE__, 'static_optimizer_process_uninstall' );

/**
 * The plugin was activated. If conf file exists and has api_key we can set it to status=1 now
 */
function static_optimizer_process_activate() {
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
function static_optimizer_process_deactivate() {
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

function static_optimizer_process_uninstall() {
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

