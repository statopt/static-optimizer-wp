<?php

$obj = StaticOptimizerMisc::getInstance();
add_action( 'init', [ $obj, 'init' ] );

class StaticOptimizerMisc extends StaticOptimizerBase {
	public function init() {
		parent::init();

		if (defined('STATIC_OPTIMIZER_CFG_DATA')) {
			$cfg_data = STATIC_OPTIMIZER_CFG_DATA;

			if (!empty($cfg_data['provide_credits'])) {
				$hook = 'wp_footer';
				add_action($hook, [ $this, 'renderPluginCredits' ], 50);
			}
		}
	}

	/**
	 * When the user selects that option in the settings we'll add a link to the footer
	 */
	public function renderPluginCredits() {
		?>
		<style>
            .statopt_credits_wrapper {
                text-align: center;
            }
		</style>
		<!-- statopt_credits_wrapper: enabled in plugin settings -->
		<div id="statopt_credits_wrapper" class="statopt_credits_wrapper">
			<div id="statopt_credits_container" class="statopt_credits_container">
				Static file optimization by <a href="https://statopt.com/?utm_source=plugin&utm_medium=credit"
				                               title="Optimizes and serves your assets (images, css, js) for faster site loading">StaticOptimizer</a>
			</div>
		</div> <!-- /statopt_credits_wrapper -->
		<?php
	}
}