<?php

$static_optimizer_worker = WP_PLUGIN_DIR . '/static-optimizer/000-static-optimizer-system-worker.php';

if ( (!defined('STATIC_OPTIMIZER_ACTIVE') || STATIC_OPTIMIZER_ACTIVE)
     && file_exists( $static_optimizer_worker ) ) {
	include_once $static_optimizer_worker;
}