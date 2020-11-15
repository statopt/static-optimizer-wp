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
define( 'STATIC_OPTIMIZER_BASE_DIR', dirname(__FILE__) );
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
