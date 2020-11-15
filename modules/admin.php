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
}
