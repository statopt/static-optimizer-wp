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


		// this filter runs most often than action 'update_option_static_optimizer_settings'
		add_filter( 'pre_update_option_static_optimizer_settings', [ $this, 'processBeforeDbSaveSettings' ], 20, 3 );

		add_action( 'admin_init', [ $this, 'registerSettings' ] );

		add_action( 'static_optimizer_action_before_settings_form', [ $this, 'maybeRenderNotice' ] );
		add_action( 'static_optimizer_action_before_render_settings_form', [ $this, 'redirectToGenerateApiKeyPage' ] );
		add_action( 'static_optimizer_action_before_settings_form', [ $this, 'maybeRenderNoticePluginNotActive' ] );
		add_action( 'static_optimizer_action_after_settings_form', [ $this, 'maybeRenderGetKeyForm' ] );
		add_action( 'static_optimizer_action_after_settings_form', [ $this, 'maybeRenderManageKeyForm' ] );
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
			STATIC_OPTIMIZER_BASE_PLUGIN,
			[ $this, 'static_optimizer_options_page' ]
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
		if ( $file == plugin_basename( STATIC_OPTIMIZER_BASE_PLUGIN ) ) {
			$link          = $this->static_optimizer_get_settings_link();
			$settings_link = "<a href=\"{$link}\">Settings</a>";
			array_unshift( $links, $settings_link );
		}

		return $links;
	}

	/**
	 * @param $value
	 * @param $option
	 * @param $old_value
	 *
	 * @return mixed
	 */
	function processBeforeDbSaveSettings($value, $old_value, $option) {
		$this->syncStaticFileSettings($old_value, $value, $option);
		return $value;
	}

	/**
	 * We'll sync the conf file on option save.
	 *
	 * @param array $old_value
	 * @param array $value
	 * @param string $option
	 */
	function syncStaticFileSettings( $old_value, $value, $option = null) {
		$dir = dirname( STATIC_OPTIMIZER_CONF_FILE );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$data = $value;

		$data['is_multisite']       = function_exists( 'is_multisite' ) && is_multisite() ? true : false;
		$data['site_url']           = $data['is_multisite'] ? network_site_url() : site_url();
		$data['host']               = parse_url( $data['site_url'], PHP_URL_HOST );
		$data['host']               = strtolower( $data['host'] );
		$data['host']               = preg_replace( '#^www\.#si', '', $data['host'] );
		$data['updated_on']         = date( 'r' );
		$data['updated_by_user_id'] = get_current_user_id();

		$data_str                   = @json_encode( $data, JSON_PRETTY_PRINT );

		if (empty($data_str)) { // JSON serialization failed possibly due to UTF-8 formatting
			// let's try php serialization
			$data_str = serialize( $data );

			// Well, we've tried ...
			if ( empty( $data_str ) ) {
				return;
			}

			// Let's encode the data so it works in case it's transferred to another host and another php version.
			$data_str = base64_encode($data_str);
		}

		// Save data
		$save_stat = file_put_contents( STATIC_OPTIMIZER_CONF_FILE, $data_str, LOCK_EX );

		// let's add an empty file
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', "StaticOptimizer", LOCK_EX );
		}

		// let's add some more protection
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "deny from all", LOCK_EX );
		}

		return $save_stat;
	}

	/**
	 * Retrieves the plugin options. It inserts some defaults.
	 * The saving is handled by the settings page. Basically, we submit to WP and it takes
	 * care of the saving.
	 *
	 * @return array
	 */
	function getOptions( $load_defaults = false ) {
		$defaults = array(
			'status'     => false,
			'api_key'    => '',
			'file_types' => [
				'images' => 1,
				'js' => 0,
				'css' => 0,
				'fonts' => 0,
			],
		);

		if ( $load_defaults ) {
			return $defaults;
		}

		$opts = get_option( 'static_optimizer_settings' );

		if ( ! empty( $opts ) ) {
			$opts = (array) $opts;
			$opts = array_replace_recursive( $defaults, $opts );
		} else {
			$opts = $defaults;
		}

		return $opts;
	}

	function registerSettings() {
		register_setting( 'static_optimizer_settings', 'static_optimizer_settings', [ $this, 'validateSettings' ] );
		add_settings_section( 'plugin_settings', 'Settings', [ $this, 'static_optimizer_settings_text' ], 'static_optimizer_settings' );
		add_settings_field( 'static_optimizer_setting_status', 'Status',  [ $this, 'renderSettingStatus' ], 'static_optimizer_settings', 'plugin_settings' );
		add_settings_field( 'static_optimizer_setting_api_key', 'API Key',  [ $this, 'renderSettingApiKey' ], 'static_optimizer_settings', 'plugin_settings' );
		add_settings_field( 'static_optimizer_setting_file_types', 'File Types',  [ $this, 'static_optimizer_setting_file_types' ], 'static_optimizer_settings', 'plugin_settings' );
	}

	function validateSettings( $input ) {
		$opts = $this->getOptions();
		$req_obj = StaticOptimizerRequest::getInstance();
		$input                   = $req_obj->sanitizeData($input);
		$new_input['api_key']    = trim( $input['api_key'] );
		$new_input['status']     = isset( $input['status'] ) ? ! empty( $input['status'] ) : true;
		$new_input['file_types'] = empty( $input['file_types'] ) ? [] : $input['file_types'];

		if ( ! preg_match( '/^[\w]{5,60}$/si', $new_input['api_key'] ) ) {
			$new_input['api_key'] = '';
		}

		// Here we go through the known keys and check if the user has selected a type.
		// We need to have a value because the defaults would take precedence.
		// if there's no value this means that the user has unchecked that value.
		// The bug is present when the default value is 1 (images) and the user tried to uncheck it.
		// it doesn't get unchecked without the code below
		$file_types = empty( $opts['file_types'] ) ? [] : $opts['file_types'];

		foreach ($file_types as $file_type => $default_val) {
			$new_input['file_types'][$file_type] = empty($new_input['file_types'][$file_type]) ? 0 : 1;
		}

		// let extensions do their thing
		$filtered_new_input = apply_filters( 'static_optimizer_ext_filter_settings', $new_input, $input );
		$new_input          = ! empty( $filtered_new_input ) && is_array( $filtered_new_input ) ? $filtered_new_input : $new_input; // did the extension break stuff?

		return $new_input;
	}

	/**
	 * We prefill the get api key form so when the user requests that the receiving page will have
	 * some data prefilled in so we'll save 20 seconds for the user.
	 *
	 * @param array $ctx
	 */
	public function maybeRenderManageKeyForm( $ctx = [] ) {
		$options = $this->getOptions();

		if ( empty( $options['api_key'] ) ) {
			return;
		}

		$app_site_url      = STATIC_OPTIMIZER_APP_SITE_URL . '/login';
		$app_site_url_href = STATIC_OPTIMIZER_APP_SITE_URL . '/login';
		?>
		<br/>
		<hr/>
		<div id="static_optimizer_manage_api_key_form_wrapper" class="static_optimizer_get_api_key_form_wrapper">
			<h3>Manage API Key</h3>
			<p>
				To manage your StaticOptimizer API key to go <a href="<?php echo esc_url( $app_site_url_href ); ?>"
				                                                target="_blank"
				                                                class="button"><?php echo esc_url( $app_site_url ); ?></a>
			</p>
		</div> <!-- /static_optimizer_manage_api_key_form_wrapper -->
		<?php
	}

	/**
	 * @param array $ctx
	 */
	public function maybeRenderNotice( $ctx = []) {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return;
		}

		$ip = empty( $_SERVER['REMOTE_ADDR'] ) ? '' : $_SERVER['REMOTE_ADDR'];
		$local_ips = [ '::1', '127.0.0.1', ];
		$server_name = empty( $_SERVER['SERVER_NAME'] ) ? '' : $_SERVER['SERVER_NAME'];
		$show_localhost_notice = false;

		// Let's check LAN IPs
		if ( in_array($ip, $local_ips)
		     || preg_match( '#^(::1|127\.0\.|10\.0\.[0-2]|192\.168.[0-2]\.|172\.[1-3]\d*\.0)#si', $_SERVER['REMOTE_ADDR'] )
		) {
			$show_localhost_notice = true;
		} elseif ( $server_name == 'localhost'
		           ||  preg_match( '#^(localhost|\.local)#si', $server_name ) ) { // internal req or dev machine
			$show_localhost_notice = true;
		}

		if (!$show_localhost_notice) {
			return;
		}

		?>
		<div class="alert" style="background:red;color: #fff;padding: 3px;">
			<p>Warning: This plugin doesn't work on localhost because our servers need to be able to access your site.</p>
		</div>
		<?php
	}

	/**
	 * Remind the user if something is missing in the configuration.
	 *
	 * @param $ctx
	 */
	function maybeRenderNoticePluginNotActive( $ctx = []) {
		$options = $this->getOptions();

		$cls = '';

		if ( empty( $options['api_key'] ) ) {
			$cls = 'notice-warning';
			$msg = __( "Warning: You need to request a free API key. "
			           . "<br/>Generating an API key will authorize the current site."
			           . " If the site is not authorized our servers will not deliver your files (403 error).", 'statopt' );
		} elseif ( empty( $options['status'] ) ) {
			$cls = 'notice-warning';
			$msg = __( "Warning: Plugin is Inactive. You need set plugin's status to active in order for it to work.", 'statopt' );
		} else {
			return;
		}

		?>
		<div class="notice <?php echo $cls;?>" style00="background:red;color: #fff;padding: 3px;">
			<p><?php echo $msg; ?></p>
		</div>
		<?php
	}

	/**
	 * We prefill the get api key form so when the user requests that the receiving page will have
	 * some data prefilled in so we'll save 20 seconds for the user.
	 *
	 * @param array $ctx
	 */
	public function maybeRenderGetKeyForm( $ctx = [] ) {
		$options = $this->getOptions();

		if ( ! empty( $options['api_key'] ) ) {
			return;
		}

		$site_url    = site_url();
		$admin_email = get_option( 'admin_email' );
		?>
		<br/>
		<hr/>
		<div id="static_optimizer_get_api_key_form_wrapper" class="static_optimizer_get_api_key_form_wrapper">
			<h3>API Key</h3>
			<p>Get your StaticOptimizer API key using this form.</p>

			<form id="static_optimizer_get_api_key_form" name="static_optimizer_get_api_key_form"
			      class="static_optimizer_get_api_key_form"
			      target="_blank"
			      method="post">
				<input type="hidden" id="static_optimizer_cmd" name="static_optimizer_cmd" value="api_key.generate"/>

				Email: <input type="email" id="static_optimizer_email" name="email"
				              value="<?php esc_attr_e( $admin_email ); ?>"/>

				Site: <input type="url" id="static_optimizer_site_url" name="site_url"
				             style="width: 35%"
				             value="<?php esc_attr_e( $site_url ); ?>"/>

				<input name='submit' class='button' type='submit' value='Get API Key'/>
			</form>
		</div> <!-- /static_optimizer_get_api_key_form_wrapper -->
		<?php
	}

	/**
	 * Returns the link to the Theme Editor e.g. when a theme_1 or theme_2 is supplied.
	 *
	 * @param array $params
	 *
	 * @return string
	 */
	function static_optimizer_get_settings_link( $params = array() ) {
		$rel_path = 'options-general.php?page=' . plugin_basename( STATIC_OPTIMIZER_BASE_PLUGIN );

		if ( ! empty( $params ) ) {
			$rel_path = add_query_arg( $params, $rel_path );
		}

		$link = is_multisite()
			? network_admin_url( $rel_path )
			: admin_url( $rel_path );

		return $link;
	}

	function static_optimizer_setting_file_types() {
		$options         = $this->getOptions();
		$file_types      = empty( $options['file_types'] ) ? [] : $options['file_types'];

		echo "<div>Which file types would like to be optimized?</div>";

		foreach ( $file_types as $file_type => $checked_value) {
			$checked = $checked_value === 1 || $checked_value === '1' || $checked_value === true ? checked( 1, 1, false ) : '';
			echo "<label for='static_optimizer_setting_file_types_{$file_type}'>
            <input type='checkbox' id='static_optimizer_setting_file_types_{$file_type}' name='static_optimizer_settings[file_types][$file_type]' 
             value='1' $checked /> $file_type </label><br/>";
		}

		$note_on_fonts =<<<NOTE_EOF
        <br/>
	        <div>
                Note: If you have fonts that are loaded/referenced in CSS via relative paths they may not properly load from our
                servers.
            </div>
NOTE_EOF;

		if (isset($file_types['fonts'])) {
			echo $note_on_fonts;
		}
	}

	function static_optimizer_settings_text() {
		//echo '<p>Here you can set all the options for using the API</p>';
	}

	/**
	 * Generates the api_key box
	 */
	function renderSettingApiKey() {
		$options = $this->getOptions();
		$val     = $options['api_key'];
		$val_esc = esc_attr( $val );
		echo "<input id='static_optimizer_setting_api_key' name='static_optimizer_settings[api_key]' class='widefat' type='text' value='$val_esc' />";
	}

	/**
	 * Renders the radio buttons for the plugin status
	 */
	function renderSettingStatus() {
		$options          = $this->getOptions();
		$val              = $options['status'];
		$active_checked   = ! empty( $val ) ? checked( 1, 1, false ) : '';
		$inactive_checked = empty( $val ) ? checked( 1, 1, false ) : '';
		echo "<label for='static_optimizer_setting_status_active'><input id='static_optimizer_setting_status_active' 
name='static_optimizer_settings[status]' type='radio' value='1' $active_checked /> Active</label>";
		echo "&nbsp;&nbsp;&nbsp;";
		echo "<label for='static_optimizer_setting_status_inactive'><input id='static_optimizer_setting_status_inactive' 
name='static_optimizer_settings[status]' type='radio' value='0' $inactive_checked /> Inactive</label>";
	}

	/**
	 * Options page and this is shown under Products.
	 * For some reason the saved message doesn't show up on Products page
	 * that's why I had to display the message for edit.php page specifically.
	 *
	 * @package StaticOptimizer
	 * @since 1.0
	 */
	function static_optimizer_options_page() {
		$opts = $this->getOptions();
		$plugin_ctx = [
			'opts' => $opts,
		];

		$show_settings_form = !empty($opts['api_key']);

		do_action( 'static_optimizer_action_before_render_settings_form', $plugin_ctx );

		?>
		<div id="static_optimizer_wrapper" class="wrap static_optimizer_wrapper">
			<h2>StaticOptimizer</h2>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<!-- main content -->
					<div id="post-body-content">
						<div class="meta-box-sortables ui-sortable">
							<div class="postbox">
								<!--                                <h3><span>Settings</span></h3>-->
								<div class="inside">
									<?php do_action( 'static_optimizer_action_before_settings_form', $plugin_ctx ); ?>
									<style>
                                        .static_optimizer_admin_options_form h2 {
                                            display: none;
                                        }

                                        .static_optimizer_hide {
                                            display: none;
                                        }

                                        .static_optimizer_notice {
                                            color:yellow;
                                        }
									</style>
									<script>
                                        (function($) {
                                            $(function() {
                                                jQuery(".static_optimizer_admin_options_fields_reveal_btn").on('click', function () {
                                                    jQuery(".static_optimizer_admin_options_fields_reveal_btn_wrapper").hide();
                                                    jQuery(".static_optimizer_admin_options_fields").show();
                                                    jQuery("#static_optimizer_setting_api_key").focus();
                                                    return false;
                                                });

                                                // When the user submits the form to sign up for the API key, it will wait for him/her expanded.
                                                jQuery('.static_optimizer_get_api_key_form').on('submit', function (e) {
                                                    setTimeout(function () {
                                                        jQuery('.static_optimizer_admin_options_fields_reveal_btn').trigger('click');
                                                    }, 100);

                                                    return true;
                                                });
                                            });
                                        })(jQuery);
									</script>
									<form id="static_optimizer_admin_options_form"
									      class="static_optimizer_admin_options_form"
									      action="options.php" method="post">
										<?php
										if ( is_multisite() && ! is_main_site() ) {
											$next_url = $this->static_optimizer_get_settings_link();
											$msg      = "You can configure the settings globally in WordPress multisite network admin area "
											            . "<br/><a href='$next_url' class='button button-primary'>Continue</a>";
											echo $msg;
										} else {
											if (!$show_settings_form) {
												echo "<div class='static_optimizer_admin_options_fields static_optimizer_hide'>";
											}
											settings_fields( 'static_optimizer_settings' );
											do_settings_sections( 'static_optimizer_settings' );
											$btn_label = esc_attr( 'Save Changes' );
											echo "<input name='submit' class='button button-primary' type='submit' value='$btn_label' />";

											if (!$show_settings_form) {
												echo "</div>";
											}

											if (!$show_settings_form) {
												echo "<br/>";
												echo "<div class='static_optimizer_admin_options_fields_reveal_btn_wrapper'>";
												echo __( "Use the form below to get your API key "
												         . " | <a href='javascript:void(0);' class='static_optimizer_admin_options_fields_reveal_btn'>I already have an API key</a>", 'statopt' );
												echo "</div>";
											}
										}
										?>
									</form>
									<?php do_action( 'static_optimizer_action_after_settings_form', $plugin_ctx ); ?>
								</div> <!-- .inside -->
							</div> <!-- .postbox -->

							<div class="postbox">
								<!--                            <h3><span>Usage</span></h3>-->
								<div class="inside">
									<div class="">
										<p><a href="https://statopt.com" target="_blank">StaticOptimizer</a> makes your site
											load faster by loading your files from StaticOptimizer Optimization servers.</p>
										<p>We'll take care of optimizing the images & minimizing the javascript and css
											files.</p>
										<p>If our servers are down for some reason the original images will be loaded from
											your server.</p>
										<p>We've tried to make this plugin and our servers as efficient as possible,
											however,
											if you have a suggestion please file a ticket at
											<a href="https://github.com/statopt/static-optimizer-wp/issues" target="_blank">https://github.com/statopt/static-optimizer-wp/issues</a>
										</p>
									</div>
								</div> <!-- .inside -->
							</div> <!-- .postbox -->

							<div class="postbox">
								<h3><span>Stats</span></h3>
								<div class="inside">
									<div class="">
										We'll show some traffic info in the future regarding your usage.
									</div>
								</div> <!-- .inside -->
							</div> <!-- .postbox -->

							<?php if ( 1 ) : // turn off this for now. ?>
								<div class="postbox">
									<h3><span>Demo (1min 47s)</span></h3>
									<div class="inside">
										<div class="">
											<iframe width="560" height="315"
											        src="https://www.youtube-nocookie.com/embed/1KC_JJOcu1s?rel=0"
											        frameborder="0"
											        allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
											        allowfullscreen></iframe>
										</div>
									</div> <!-- .inside -->
								</div> <!-- .postbox -->
							<?php endif; ?>

						</div> <!-- .meta-box-sortables .ui-sortable -->
					</div> <!-- #postbox-container-1 .postbox-container -->

					<!-- sidebar -->
					<div id="postbox-container-1" class="postbox-container">
						<div class="meta-box-sortables">
							<div class="postbox"> <!-- quick-contact -->
								<?php
								$current_user      = wp_get_current_user();
								$email             = empty( $current_user->user_email ) ? '' : $current_user->user_email;
								$quick_form_action = 'https://apps.orbisius.com/quick-contact/';

								if ( ! empty( $_SERVER['DEV_ENV'] ) ) {
									$quick_form_action = '//localhost/projects/quick-contact/';
								}
								?>
								<h3><span>Quick Question or Suggestion</span></h3>
								<div class="inside">
									<div>
										<form method="post" action="<?php echo $quick_form_action; ?>" target="_blank"
										      enctype="multipart/form-data">
											<?php
											global $wp_version;
											$plugin_data = get_plugin_data( STATIC_OPTIMIZER_BASE_PLUGIN );

											$hidden_data = array(
												'site_url'        => site_url(),
												'wp_ver'          => $wp_version,
												'first_name'      => $current_user->first_name,
												'last_name'       => $current_user->last_name,
												'product_name'    => $plugin_data['Name'],
												'product_ver'     => $plugin_data['Version'],
												'woocommerce_ver' => defined( 'WOOCOMMERCE_VERSION' ) ? WOOCOMMERCE_VERSION : 'n/a',
											);
											$hid_data    = http_build_query( $hidden_data );
											echo "<input type='hidden' name='data[sys_info]' value='$hid_data' />\n";
											?>
											<textarea class="widefat" id='static_optimizer_msg' name='data[msg]'
											          required="required"></textarea>
											<br/>Your Email: <input type="text" class=""
											                        name='data[sender_email]' placeholder="Email"
											                        required="required"
											                        value="<?php echo esc_attr( $email ); ?>"
											/>
											<br/><input type="submit" class="button-primary" value="<?php _e( 'Send' ) ?>"
											            onclick="try { if (jQuery('#static_optimizer_msg').val().trim() == '') { alert('Enter your message.'); jQuery('#static_optimizer_msg').focus(); return false; } } catch(e) {};"/>
											<br/>
											What data will be sent
											<a href='javascript:void(0);'
											   onclick='jQuery(".static-price-changer-woocommerce-quick-contact-data-to-be-sent").toggle();'>(show/hide)</a>
											<div class="hide hide-if-js static-price-changer-woocommerce-quick-contact-data-to-be-sent">
                                            <textarea class="widefat" rows="4" readonly="readonly" disabled="disabled"><?php
	                                            foreach ( $hidden_data as $key => $val ) {
		                                            if ( is_array( $val ) ) {
			                                            $val = var_export( $val, 1 );
		                                            }

		                                            echo "$key: $val\n";
	                                            }
	                                            ?></textarea>
											</div>
										</form>
									</div>
								</div> <!-- .inside -->
							</div> <!-- .postbox --> <!-- /quick-contact -->

                            <!-- Support options -->
                            <div class="postbox">
                                <h3><span>Support & Feature Requests</span></h3>
                                <h3>
									<span>
                                    <a href="https://statopt.com/?utm_source=plugin" target="_blank" title="[new window]">Product Page</a>
                                    |
                                    <a href="https://github.com/statopt/static-optimizer-wp/issues"
                                       target="_blank" title="[new window]">Bugs / Features</a>
                                </span>
                                </h3>
                            </div> <!-- .postbox -->
                            <!-- /Support options -->

							<!-- Hire Us -->
							<div class="postbox">
								<h3><span>Hire Us (StaticOptimizer & Orbisius)</span></h3>
								<div class="inside">
                                    Hire us to create a plugin/<span title="Software As a Service">SaaS [?]</span> app
									<br/><a href="https://orbisius.com/page/free-quote/?utm_source=<?php echo str_replace( '.php', '', basename( STATIC_OPTIMIZER_BASE_PLUGIN ) ); ?>&utm_medium=plugin-settings&utm_campaign=product"
									        title="If you want a custom web/mobile app/plugin developed contact us. This opens in a new window/tab"
									        class="button-primary" target="_blank">Get a Free Quote</a>
								</div> <!-- .inside -->
							</div> <!-- .postbox -->
							<!-- /Hire Us -->

							<!-- Newsletter-->
							<div class="postbox">
								<h3><span>Newsletter</span></h3>
								<div class="inside">
									<!-- Begin MailChimp Signup Form -->
									<div id="mc_embed_signup">
										<?php
										$current_user = wp_get_current_user();
										$email        = empty( $current_user->user_email ) ? '' : $current_user->user_email;
										?>

										<form action="//WebWeb.us2.list-manage.com/subscribe/post?u=005070a78d0e52a7b567e96df&amp;id=1b83cd2093"
										      method="post"
										      id="mc-embedded-subscribe-form" name="mc-embedded-subscribe-form"
										      class="validate" target="_blank">
											<input type="hidden" value="settings" name="SRC2"/>
											<input type="hidden"
											       value="<?php echo str_replace( '.php', '', basename( STATIC_OPTIMIZER_BASE_PLUGIN ) ); ?>"
											       name="SRC"/>

											<span>Get notified about cool plugins we release</span>
											<!--<div class="indicates-required"><span class="app_asterisk">*</span> indicates required
											</div>-->
											<div class="mc-field-group">
												<label for="mce-EMAIL">Email</label>
												<input type="email" value="<?php echo esc_attr( $email ); ?>" name="EMAIL"
												       class="required email" id="mce-EMAIL">
											</div>
											<div id="mce-responses" class="clear">
												<div class="response" id="mce-error-response" style="display:none"></div>
												<div class="response" id="mce-success-response" style="display:none"></div>
											</div>
											<div class="clear"><input type="submit" value="Subscribe" name="subscribe"
											                          id="mc-embedded-subscribe" class="button-primary">
											</div>
										</form>
									</div>
									<!--End mc_embed_signup-->
								</div> <!-- .inside -->
							</div> <!-- .postbox -->
							<!-- /Newsletter-->

						</div> <!-- .meta-box-sortables -->
					</div> <!-- #postbox-container-1 .postbox-container -->

				</div> <!-- #post-body .metabox-holder .columns-2 -->

				<br class="clear"/>
			</div> <!-- /poststuff -->
		</div> <!-- /static_optimizer_wrapper -->

		<?php
	}

	/**
     * The get key is first submitted to this plugin so we can prefill the fields and then redirect
	 * @param $ctx
	 */
	function redirectToGenerateApiKeyPage( $ctx = [] ) {
		try {
			$req_obj = StaticOptimizerRequest::getInstance();
			$cmd     = $req_obj->get( 'static_optimizer_cmd' );

			if ( empty( $cmd ) || ! current_user_can('manage_options') ) {
				return;
			}

			$base_url        = STATIC_OPTIMIZER_APP_SITE_URL;
			$app_login_url   = $base_url . '/login';
			$api_key_gen_url = $base_url . '/api-key/create';

			if ( $cmd == 'api_key.generate' ) {
				$key_gen_page_params = [];

				if ( $req_obj->get( 'email' ) ) {
					$email         = $req_obj->get( 'email' );
					$app_login_url = add_query_arg( 'email', rawurlencode($email), $app_login_url );
				}

				if ( $req_obj->get( 'site_url' ) ) {
					$key_gen_page_params['url']              = $req_obj->get( 'site_url' );
				}

				$key_gen_page_params['current_page_url'] = $req_obj->getRequestUrl();

				// https://wpvip.com/documentation/encode-values-passed-to-add_query_arg/
				if ( ! empty( $key_gen_page_params ) ) {
					$post_login_redirect_url = $api_key_gen_url  . '?' .http_build_query( $key_gen_page_params);
					$app_login_url = add_query_arg( 'redirect_to', rawurlencode($post_login_redirect_url), $app_login_url );
				}

				$req_obj->redirect( $app_login_url, StaticOptimizerRequest::REDIRECT_EXTERNAL_SITE );
			}
		} catch ( Exception $e ) {
			wp_die("Error: " . $e->getMessage());
		}
	}
}

