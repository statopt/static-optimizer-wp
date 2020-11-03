<?php

class StaticOptimizerRequest {
	const REDIRECT_DEFAULT = 0;
	const REDIRECT_FORCE = 1;
	const INT = 2;
	const FLOAT = 4;
	const ESC_ATTR = 8;
	const JS_ESC_ATTR = 16;
	const EMPTY_STR = 32; // when int/float nubmers are 0 make it an empty str
	const STRIP_SOME_TAGS = 64;
	const STRIP_ALL_TAGS = 128;
	const SKIP_STRIP_ALL_TAGS = 256;

	protected $data = null;
	protected $raw_data = [];

	/**
	 * Smart redirect method. Sends header redirect or HTTP meta redirect.
	 * StaticOptimizerRequest::redirect();
	 * @param string $url
	 */
	public function redirect( $url = '', $force = self::REDIRECT_DEFAULT ) {
		if ( defined( 'WP_CLI' ) || empty( $url ) ) {
			// don't do anything if WP-CLI is running.
			return;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) { // wp cron
			return;
		}

		if ( isset( $_REQUEST['wc-api'] ) ) {
			return;
		}

		// Don't do anything for ajax requests
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		// Don't do anything if qs_site_app_cmd is passed (future)
		if ( ! empty( $_REQUEST['qs_site_app_cmd'] ) ) {
			return;
		}

		$local_ips = [ '::1', '127.0.0.1' ];

		if ( $force == self::REDIRECT_DEFAULT
		     && ( ! empty( $_SERVER['REMOTE_ADDR'] )
		          && ! in_array( $_SERVER['REMOTE_ADDR'], $local_ips )
		          && $_SERVER['REMOTE_ADDR'] == $_SERVER['SERVER_ADDR'] )
		) { // internal req or dev machine
			return;
		}

		if ( ! is_numeric( $force ) && ! is_bool( $force ) && is_string( $force ) && $force != $url ) {
			$url = add_query_arg( 'redirect_to', $force, $url );
		}

		if ( headers_sent() ) { // if we encode it twice data won't be transferred.
			$url = wp_sanitize_redirect( $url ); // the wp_safe redir does this
			echo sprintf( '<meta http-equiv="refresh" content="0;URL=\'%s\'" />', $url ); // jic
			echo sprintf( '<script language="javascript">window.parent.location="%s";document.body.innerHTML = "Please wait ...";</script>', $url );
		} else {
			wp_safe_redirect( $url, 302 );
		}

		exit;
	}

	/**
	 * $req_obj = StaticOptimizerRequest::getInstance();
	 * $req_obj->get();
	 * @param string $key
	 * @return mixed
	 */
	public function get( $key = '', $default = '', $force_type = 1 ) {
		if (empty($key)) {
			return $this->data;
		}

		$key = trim( $key );
		$val = !empty($this->data[$key]) ? $this->data[$key] : $default;

		if ( $force_type & self::INT ) {
			$val = intval($val);

			if ( $val == 0 && $force_type & self::EMPTY_STR ) {
				$val = "";
			}
		}

		if ( $force_type & self::FLOAT ) {
			$val = floatval($val);

			if ( $val == 0 && $force_type & self::EMPTY_STR ) {
				$val = "";
			}
		}

		if ( $force_type & self::ESC_ATTR ) {
			$val = esc_attr($val);
		}

		if ( $force_type & self::JS_ESC_ATTR ) {
			$val = esc_js($val);
		}

		if ( $force_type & self::STRIP_SOME_TAGS ) {
			$val = wp_kses($val, $this->allowed_permissive_html_tags);
		}

		// Sanitizing a var
		if ( $force_type & self::STRIP_ALL_TAGS ) {
			$val = wp_kses($val, array());
		}

		$val = is_scalar($val) ? trim($val) : $val;

		return $val;
	}


	public function __construct() {
		$this->init();
	}

	/**
	 * Singleton pattern i.e. we have only one instance of this obj
	 *
	 * @staticvar static $instance
	 * @return static
	 */
	public static function getInstance() {
		static $instance = null;

		// This will make the calling class to be instantiated.
		// no need each sub class to define this method.
		if (is_null($instance)) {
			$instance = new static();
		}

		return $instance;
	}


	/**
	 * WP puts slashes in the values so we need to remove them.
	 * @param array $data
	 */
	public function init( $data = null ) {
		// see https://codex.wordpress.org/Function_Reference/stripslashes_deep
		if ( is_null( $this->data ) ) {
			$data = empty( $data ) ? $_REQUEST : $data;
			$this->raw_data = $data;
			$data = stripslashes_deep( $data );
			$data = $this->sanitize_data( $data );
			$this->data = $data;
		}
	}

	/**
	 *
	 * @param str/array $data
	 * @return str/array
	 * @throws Exception
	 */
	public function sanitize_data( $data = null ) {
		if ( is_scalar( $data ) ) {
			$data = wp_kses_data( $data );
			$data = trim( $data );
		} elseif ( is_array( $data ) ) {
			$data = array_map( array( $this, 'sanitize_data' ), $data );
		} else {
			throw new Exception( "Invalid data type passed for sanitization" );
		}

		return $data;
	}

	/**
	 * @return string
	 */
	public function getRequestUrl() {
		$req_url = empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI'];
		return $req_url;
	}
}
