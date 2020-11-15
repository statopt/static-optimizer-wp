<?php

class StaticOptimizerBase {
	/**
	 * Singleton pattern i.e. we have only one instance of this obj
	 * @staticvar static $instance
	 * @return orbisius_prop_ed_file
	 */
	public static function getInstance() {
		static $instance = null;

		// This will make the calling class to be instantiated.
		// no need each sub class to define this method.
		if ( is_null( $instance ) ) {
			$instance = new static();
		}

		return $instance;
	}
}