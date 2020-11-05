<?php

/**
 * This script is part of StaticOptimizer plugin so it can intercept content as early as possible.
 * The script won't do any processing in the following cases.
 */
if (    !defined('ABSPATH')
     || defined('WP_UNINSTALL_PLUGIN')
     || defined('DOING_CRON')
     || defined('DOING_AJAX')
     || !defined('WP_CONTENT_DIR')
     || php_sapi_name() == 'cli'
     || (defined('WP_CLI') && WP_CLI)
//     || !empty($_POST)
     || (!empty($_SERVER['REQUEST_URI']) &&
         (     (strpos($_SERVER['REQUEST_URI'], 'wp-cron') !== false) // no cron requests
            || (strpos($_SERVER['REQUEST_URI'], '/wp-admin/') !== false) // no running in admin area
         )
     )
	 || empty( $_SERVER['HTTP_HOST'] )
	 || empty( $_SERVER['SERVER_NAME'] )
     || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'xmlhttprequest') == 0)
//	|| ! empty( $_SERVER['QS_SYSTEM_CORE_CORRECT_ASSETS_VER'] ) // do not run on Orbisius servers as this is built-in.
//	|| ! empty( $_SERVER['QS_APP_SYSTEM_OPTIMIZER_URL'] )
) {
    return;
}

// We have the .ht prefix because this is normally blocked by apache .ht access rules
if (defined( 'STATIC_OPTIMIZER_CONF_FILE')) {
	$cfg_file = STATIC_OPTIMIZER_CONF_FILE;
} elseif (defined('WP_CONTENT_DIR')) {
	$cfg_file = WP_CONTENT_DIR . '/.ht-static-optimizer/config.json';
} else {
	return;
}

if (!file_exists($cfg_file)) {
	return;
}

$fp = fopen($cfg_file, 'rb');

if (empty($fp)) {
	return;
}

@flock($fp, LOCK_SH);
$cfg_buff = file_get_contents($cfg_file);
@flock($fp, LOCK_UN);
fclose($fp);

if (empty($cfg_buff)) {
	return;
}

$cfg = [];

if (substr($cfg_buff, 0, 1 ) == '{') { // is this a JSON file?
	$cfg = json_decode($cfg_buff, true);
} else { // it must be php serialized file then.
	$php_ser = base64_decode($cfg_buff);
	$cfg = unserialize($php_ser);
}

if (empty($cfg['status']) || empty($cfg['file_types'])) { // deactivated or nothing selected?
	return;
}

require_once __DIR__ . '/lib/opt.php';

// We've got everything we need so let's go and optimize.
$static_opt_obj = new Static_Optimizer_Asset_Optimizer($cfg);
ob_start( [ $static_opt_obj, 'run' ] );
