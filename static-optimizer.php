<?php
/*
Plugin Name: StaticOptimizer
Plugin URI: https://statopt.com
Description: Makes your your images, js, css load faster by optimizing them and loading them from StaticOptimizer Optimization servers
Version: 1.0.0
Author: Svetoslav Marinov (Slavi)
Author URI: https://statopt.com
*/

/*  Copyright 2012-3000 Svetoslav Marinov (Slavi) <slavi@statopt.com>

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
	define( 'STATIC_OPTIMIZER_CONF_FILE', WP_CONTENT_DIR . '/.ht-static-optimizer/config.json' );
}

$mu_plugins_dir = '';

if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$mu_plugins_dir = WPMU_PLUGIN_DIR;
} elseif ( defined( 'WP_PLUGIN_DIR' ) ) {
	$mu_plugins_dir = WP_PLUGIN_DIR . '/mu-plugins';
}

// Adds or removes the loader depending on the status
if ( ! empty( $mu_plugins_dir ) ) {
	$system_worker_loader_file = $mu_plugins_dir . '/000-static-optimizer-system-loader.php';
	define( 'STATIC_OPTIMIZER_SYSTEM_WORKER_LOADER_FILE', $system_worker_loader_file );
}

require_once __DIR__ . '/lib/request.php';

// Set up plugin
add_action( 'init', 'static_optimizer_init' );
add_action( 'admin_menu', 'static_optimizer_setup_admin' );
add_action( 'update_option_static_optimizer_settings', 'static_optimizer_after_option_update', 20, 3 ); // be the last in the footer
add_action( 'static_optimizer_action_after_settings_form', 'static_optimizer_maybe_render_get_key_form' ); // be the last in the footer
add_action( 'static_optimizer_action_after_settings_form', 'static_optimizer_maybe_render_manage_key_form' ); // be the last in the footer

// multisite
add_action( 'network_admin_menu', 'static_optimizer_setup_admin' ); // manage_network_themes

register_uninstall_hook( __FILE__, 'static_optimizer_process_uninstall' );

function static_optimizer_process_uninstall() {
	// delete cfg files and dir
	if ( defined( 'STATIC_OPTIMIZER_CONF_FILE' ) ) {
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
	}

	if ( defined( 'STATIC_OPTIMIZER_SYSTEM_WORKER_LOADER_FILE' ) && file_exists( STATIC_OPTIMIZER_SYSTEM_WORKER_LOADER_FILE ) ) {
		unlink( STATIC_OPTIMIZER_SYSTEM_WORKER_LOADER_FILE );
	}

	delete_option( 'static_optimizer_settings' );

	// @todo clean htaccess if it was modified by us. backup first of course
}

/**
 * We'll sync the conf file on option save.
 *
 * @param array $old_value
 * @param array $value
 * @param string $option
 */
function static_optimizer_after_option_update( $old_value, $value, $option ) {
	$dir_perm = 0755; // I really want 0700 but probably most servers will show permission errors.

	if ( defined( 'STATIC_OPTIMIZER_CONF_FILE' ) ) {
		$dir = dirname( STATIC_OPTIMIZER_CONF_FILE );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, $dir_perm, true );
		}

		$data                       = $value;
		$data['is_multisite']       = function_exists( 'is_multisite' ) && is_multisite() ? true : false;
		$data['site_url']           = $data['is_multisite'] ? network_site_url() : site_url();
		$data['host']               = parse_url( $data['site_url'], PHP_URL_HOST );
		$data['host']               = strtolower( $data['host'] );
		$data['host']               = preg_replace( '#^www\.#si', '', $data['host'] );
		$data['updated_on']         = date( 'r' );
		$data['updated_by_user_id'] = get_current_user_id();
		$data_str                   = json_encode( $data, JSON_PRETTY_PRINT );
		$save_stat                  = file_put_contents( STATIC_OPTIMIZER_CONF_FILE, $data_str, LOCK_EX );

		// let's add an empty file
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', "StaticOptimizer", LOCK_EX );
		}

		// let's add some more protection
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "deny from all", LOCK_EX );
		}

		$mu_plugins_dir = '';

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$mu_plugins_dir = WPMU_PLUGIN_DIR;
		} elseif ( defined( 'WP_PLUGIN_DIR' ) ) {
			$mu_plugins_dir = WP_PLUGIN_DIR . '/mu-plugins';
		}

		// Adds or removes the loader depending on the status
		if ( ! empty( $mu_plugins_dir ) ) {
			$system_worker_loader_file     = STATIC_OPTIMIZER_SYSTEM_WORKER_LOADER_FILE;
			$src_system_worker_loader_file = __DIR__ . '/' . basename( $system_worker_loader_file );

			if ( ! is_dir( $mu_plugins_dir ) ) {
				mkdir( $mu_plugins_dir, $dir_perm, true );
			}

			if ( ! empty( $data['status'] ) ) {
				if ( ! file_exists( $system_worker_loader_file ) ) {
					// the plugin may be installed in a different dir so we need to check if that's the case.
					// if it is we need to update the system plugin that loads the worker so it looks for it in the proper folder.
					// If the worker file can't be found the plugin won't optimize anything.
					$expected_plugin_install_dir = 'static-optimizer';
					$plugin_directory            = plugin_dir_path( __FILE__ );
					$actual_plugin_install_dir   = basename( $plugin_directory );

					$copy_res = copy( $src_system_worker_loader_file, $system_worker_loader_file );

					if ( $copy_res && $actual_plugin_install_dir != $expected_plugin_install_dir ) { // dev or another install dir.
						$system_worker_loader_file_buff = file_get_contents( $system_worker_loader_file );
						$system_worker_loader_file_buff = str_replace(
							"/$expected_plugin_install_dir/",
							"/$actual_plugin_install_dir/",
							$system_worker_loader_file_buff
						);
						file_put_contents( $system_worker_loader_file, $system_worker_loader_file_buff, LOCK_EX );
					}
				}
			} elseif ( file_exists( $system_worker_loader_file ) ) { // on deactivate delete the worker loader
				$del_res = unlink( $system_worker_loader_file );
			}
		}
	}
}

/**
 * Adds the action link to settings. That's from Plugins. It is a nice thing.
 *
 * @param array $links
 * @param string $file
 *
 * @return array
 */
function static_optimizer_add_quick_settings_link( $links, $file ) {
	if ( $file == plugin_basename( __FILE__ ) ) {
		$link          = static_optimizer_get_settings_link();
		$settings_link = "<a href=\"{$link}\">Settings</a>";
		array_unshift( $links, $settings_link );
	}

	return $links;
}

/**
 * Setups loading of assets (css, js).
 * @return void
 */
function static_optimizer_init() {
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return;
	}
}

/**
 * Set up administration
 *
 * @package StaticOptimizer
 * @since 0.1
 */
function static_optimizer_setup_admin() {
	$hook = add_options_page(
		__( 'StaticOptimizer', 'static_optimizer' ),
		__( 'StaticOptimizer', 'static_optimizer' ),
		'manage_options', __FILE__,
		'static_optimizer_options_page'
	);

	add_filter( 'plugin_action_links', 'static_optimizer_add_quick_settings_link', 10, 2 );
}

add_action( 'static_optimizer_action_before_render_settings_form', 'static_optimizer_redirect_to_gen_api_key' );

function static_optimizer_redirect_to_gen_api_key( $ctx ) {
	try {
		$req_obj = StaticOptimizerRequest::getInstance();
		$cmd     = $req_obj->get( 'static_optimizer_cmd' );

		if ( empty( $cmd ) ) {
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

			if ( ! empty( $key_gen_page_params ) ) {
				$post_login_redirect_url = $api_key_gen_url  . '?' .http_build_query( $key_gen_page_params);
				// https://wpvip.com/documentation/encode-values-passed-to-add_query_arg/
//				$key_gen_page_params = array_map( 'rawurlencode', $key_gen_page_params );
//				$post_login_redirect_url = add_query_arg( $key_gen_page_params, $api_key_gen_url );

				$app_login_url           = add_query_arg( 'redirect_to', rawurlencode($post_login_redirect_url), $app_login_url );
			}

			$req_obj->redirect( $app_login_url, StaticOptimizerRequest::REDIRECT_EXTERNAL_SITE );
		}
	} catch ( Exception $e ) {
        wp_die("Error: " . $e->getMessage());
	}
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
	$plugin_ctx = [];
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
                                </style>
                                <form id="static_optimizer_admin_options_form"
                                      class="static_optimizer_admin_options_form"
                                      action="options.php" method="post">
									<?php
									if ( is_multisite() && ! is_main_site() ) {
										$next_url = static_optimizer_get_settings_link();
										$msg      = "You can configure the settings globally in WordPress multisite network admin area "
										            . "<br/><a href='$next_url' class='button button-primary'>Continue</a>";
										echo $msg;
									} else {
										settings_fields( 'static_optimizer_settings' );
										do_settings_sections( 'static_optimizer_settings' );
										$btn_label = esc_attr( 'Save Changes' );
										echo "<input name='submit' class='button button-primary' type='submit' value='$btn_label' />";
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

						<?php if ( 0 ) : // turn off this for now. ?>
                            <div class="postbox">
                                <h3><span>Demo (1min 22s)</span></h3>
                                <div class="inside">
                                    <div class="">
                                        <iframe width="560" height="315"
                                                src="https://www.youtube-nocookie.com/embed/a7f9vYVlmxg?rel=0"
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
							$quick_form_action = is_ssl()
								? 'https://ssl.statopt.com/apps/quick-contact/'
								: '//apps.statopt.com/quick-contact/';

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
										$plugin_data = get_plugin_data( __FILE__ );

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

                        <!-- Hire Us -->
                        <div class="postbox">
                            <h3><span>Hire Us</span></h3>
                            <div class="inside">
                                Hire us to create a plugin/SaaS app
                                <br/><a href="https://statopt.com/page/free-quote/?utm_source=<?php echo str_replace( '.php', '', basename( __FILE__ ) ); ?>&utm_medium=plugin-settings&utm_campaign=product"
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
                                               value="<?php echo str_replace( '.php', '', basename( __FILE__ ) ); ?>"
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

                        <!-- Support options -->
                        <div class="postbox">
                            <h3><span>Support & Feature Requests</span></h3>
                            <h3>
								<?php
								$plugin_data         = get_plugin_data( __FILE__ );
								$product_name        = trim( $plugin_data['Name'] );
								$product_page        = trim( $plugin_data['PluginURI'] );
								$product_descr       = trim( $plugin_data['Description'] );
								$product_descr_short = substr( $product_descr, 0, 50 ) . '...';
								$product_descr_short .= ' #WordPress #plugin';

								$base_name_slug = basename( __FILE__ );
								$base_name_slug = str_replace( '.php', '', $base_name_slug );
								$product_page   .= ( strpos( $product_page, '?' ) === false ) ? '?' : '&';
								$product_page   .= "utm_source=$base_name_slug&utm_medium=plugin-settings&utm_campaign=product";

								$product_page_tweet_link = $product_page;
								$product_page_tweet_link = str_replace( 'plugin-settings', 'tweet', $product_page_tweet_link );
								?>
                                <!-- Twitter: code -->
                                <script>!function (d, s, id) {
                                        var js, fjs = d.getElementsByTagName(s)[0];
                                        if (!d.getElementById(id)) {
                                            js = d.createElement(s);
                                            js.id = id;
                                            js.src = "//platform.twitter.com/widgets.js";
                                            fjs.parentNode.insertBefore(js, fjs);
                                        }
                                    }(document, "script", "twitter-wjs");</script>
                                <!-- /Twitter: code -->

                                <!-- Twitter: Orbisius_Follow:js -->
                                <a href="https://twitter.com/static" class="twitter-follow-button"
                                   data-align="right" data-show-count="false">Follow @statopt</a>
                                <!-- /Twitter: Orbisius_Follow:js -->

                                <!-- Twitter: Tweet:js -->
                                <a href="https://twitter.com/share" class="twitter-share-button"
                                   data-lang="en"
                                   data-text="Checkout <?php echo $product_name; ?> #WordPress #plugin <?php echo $product_descr_short; ?>"
                                   data-count="none" data-via="statopt" data-related="orbisius"
                                   data-url="<?php echo $product_page_tweet_link; ?>">Tweet</a>
                                <!-- /Twitter: Tweet:js -->

                                <br/>
                                <span>
                                    <a href="<?php echo $product_page; ?>" target="_blank" title="[new window]">Product Page</a>
                                    |
                                    <a href="https://github.com/statopt/static-optimizer-wp/issues"
                                       target="_blank" title="[new window]">Report Bugs / Features</a>
                                </span>
                            </h3>
                        </div> <!-- .postbox -->
                        <!-- /Support options -->

                        <div class="postbox">
                            <h3><span>Share</span></h3>
                            <div class="inside">
								<?php
								$plugin_data = get_plugin_data( __FILE__ );

								$app_link  = urlencode( $plugin_data['PluginURI'] );
								$app_title = urlencode( $plugin_data['Name'] );
								$app_descr = urlencode( $plugin_data['Description'] );
								?>
                                <p>
                                    <!-- AddThis Button BEGIN -->
                                <div class="addthis_toolbox addthis_default_style addthis_32x32_style">
                                    <a class="addthis_button_facebook" addthis:url="<?php echo $app_link ?>"
                                       addthis:title="<?php echo $app_title ?>"
                                       addthis:description="<?php echo $app_descr ?>"></a>
                                    <a class="addthis_button_twitter" addthis:url="<?php echo $app_link ?>"
                                       addthis:title="<?php echo $app_title ?>"
                                       addthis:description="<?php echo $app_descr ?>"></a>
                                    <a class="addthis_button_linkedin" addthis:url="<?php echo $app_link ?>"
                                       addthis:title="<?php echo $app_title ?>"
                                       addthis:description="<?php echo $app_descr ?>"></a>
                                    <a class="addthis_button_email" addthis:url="<?php echo $app_link ?>"
                                       addthis:title="<?php echo $app_title ?>"
                                       addthis:description="<?php echo $app_descr ?>"></a>
                                    <!--<a class="addthis_button_myspace" addthis:url="<?php echo $app_link ?>" addthis:title="<?php echo $app_title ?>" addthis:description="<?php echo $app_descr ?>"></a>
                                        <a class="addthis_button_google" addthis:url="<?php echo $app_link ?>" addthis:title="<?php echo $app_title ?>" addthis:description="<?php echo $app_descr ?>"></a>
                                        <a class="addthis_button_digg" addthis:url="<?php echo $app_link ?>" addthis:title="<?php echo $app_title ?>" addthis:description="<?php echo $app_descr ?>"></a>
                                        <a class="addthis_button_delicious" addthis:url="<?php echo $app_link ?>" addthis:title="<?php echo $app_title ?>" addthis:description="<?php echo $app_descr ?>"></a>
                                        <a class="addthis_button_stumbleupon" addthis:url="<?php echo $app_link ?>" addthis:title="<?php echo $app_title ?>" addthis:description="<?php echo $app_descr ?>"></a>
                                        <a class="addthis_button_tumblr" addthis:url="<?php echo $app_link ?>" addthis:title="<?php echo $app_title ?>" addthis:description="<?php echo $app_descr ?>"></a>
                                        <a class="addthis_button_favorites" addthis:url="<?php echo $app_link ?>" addthis:title="<?php echo $app_title ?>" addthis:description="<?php echo $app_descr ?>"></a>-->
                                    <a class="addthis_button_compact"></a>
                                </div>
                                <!-- The JS code is in the footer -->

                                <script type="text/javascript">
                                    var addthis_config = {"data_track_clickback": false};
                                    var addthis_share = {
                                        templates: {twitter: 'Check out {{title}} @ {{lurl}} (from @static)'}
                                    }
                                </script>
                                <!-- AddThis Button START part2 -->
                                <script type="text/javascript" src="//s7.addthis.com/js/250/addthis_widget.js"
                                        async></script>
                                <!-- AddThis Button END part2 -->
                                </p>
                            </div> <!-- .inside -->

                        </div> <!-- .postbox -->

                    </div> <!-- .meta-box-sortables -->
                </div> <!-- #postbox-container-1 .postbox-container -->

            </div> <!-- #post-body .metabox-holder .columns-2 -->

            <br class="clear"/>
        </div> <!-- /poststuff -->
    </div> <!-- /static_optimizer_wrapper -->

	<?php
}

/**
 * Retrieves the plugin options. It inserts some defaults.
 * The saving is handled by the settings page. Basically, we submit to WP and it takes
 * care of the saving.
 *
 * @return array
 */
function static_optimizer_get_options( $load_defaults = false ) {
	$defaults = array(
		'status'     => false,
		'api_key'    => '',
		'file_types' => [
			'images',
			'js',
			'css',
			'fonts',
		],
	);

	if ( $load_defaults ) {
		return $defaults;
	}

	$opts = get_option( 'static_optimizer_settings' );

	if ( ! empty( $opts ) ) {
		$opts = (array) $opts;
		$opts = array_merge( $defaults, $opts );
	} else {
		$opts = $defaults;
	}

	return $opts;
}

function static_optimizer_register_settings() {
	register_setting( 'static_optimizer_settings', 'static_optimizer_settings', 'static_optimizer_settings_validate' );
	add_settings_section( 'plugin_settings', 'Settings', 'static_optimizer_settings_text', 'static_optimizer_settings' );
	add_settings_field( 'static_optimizer_setting_status', 'Status', 'static_optimizer_setting_status', 'static_optimizer_settings', 'plugin_settings' );
	add_settings_field( 'static_optimizer_setting_api_key', 'API Key', 'static_optimizer_setting_api_key', 'static_optimizer_settings', 'plugin_settings' );
	add_settings_field( 'static_optimizer_setting_file_types', 'File Types', 'static_optimizer_setting_file_types', 'static_optimizer_settings', 'plugin_settings' );
}

add_action( 'admin_init', 'static_optimizer_register_settings' );

function static_optimizer_settings_trim( $input ) {
	if ( is_scalar( $input ) ) {
		return trim( $input );
	}

	if ( is_array( $input ) ) {
		$input = array_map( 'static_optimizer_settings_trim', $input );
	}

	return $input;
}

function static_optimizer_settings_validate( $input ) {
	$input                   = array_map( 'static_optimizer_settings_trim', $input );
	$new_input['api_key']    = trim( $input['api_key'] );
	$new_input['status']     = isset( $input['status'] ) ? ! empty( $input['status'] ) : 1;
	$new_input['file_types'] = empty( $input['file_types'] ) ? [] : $input['file_types'];

	if ( ! preg_match( '/^[\w]{5,60}$/si', $new_input['api_key'] ) ) {
		$new_input['api_key'] = '';
	}

	// let extensions do their thing
	$filtered_new_input = apply_filters( 'static_optimizer_ext_filter_settings', $new_input, $input );
	$new_input          = ! empty( $filtered_new_input ) && is_array( $filtered_new_input ) ? $filtered_new_input : $new_input; // did the extension break stuff?

	return $new_input;
}

function static_optimizer_settings_text() {
	//echo '<p>Here you can set all the options for using the API</p>';
}

function static_optimizer_setting_api_key() {
	$options = static_optimizer_get_options();
	$val     = $options['api_key'];
	$val_esc = esc_attr( $val );
	echo "<input id='static_optimizer_setting_api_key' name='static_optimizer_settings[api_key]' type='text' value='$val_esc' />";
}

function static_optimizer_setting_status() {
	$options          = static_optimizer_get_options();
	$val              = $options['status'];
	$active_checked   = ! empty( $val ) ? checked( 1, 1, false ) : '';
	$inactive_checked = empty( $val ) ? checked( 1, 1, false ) : '';
	echo "<label for='static_optimizer_setting_status_active'><input id='static_optimizer_setting_status_active' name='static_optimizer_settings[status]' type='radio' value='1' $active_checked /> Active</label>";
	echo "&nbsp;&nbsp;&nbsp;";
	echo "<label for='static_optimizer_setting_status_inactive'><input id='static_optimizer_setting_status_inactive' name='static_optimizer_settings[status]' type='radio' value='0' $inactive_checked /> Inactive</label>";
}

function static_optimizer_setting_file_types() {
	$default_options = static_optimizer_get_options( true );
	$options         = static_optimizer_get_options();
	$file_types      = empty( $options['file_types'] ) ? [] : $options['file_types'];

	echo "<div>Which file types would like to be optimized?</div>";

	foreach ( $default_options['file_types'] as $file_type ) {
		$checked = in_array( $file_type, $file_types ) ? checked( 1, 1, false ) : '';
		echo "<label for='static_optimizer_setting_file_types_{$file_type}'>
            <input id='static_optimizer_setting_file_types_{$file_type}' name='static_optimizer_settings[file_types][]' 
            type='checkbox' value='{$file_type}' $checked /> $file_type </label><br/>";
	}
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

/**
 * We prefill the get api key form so when the user requests that the receiving page will have
 * some data prefilled in so we'll save 20 seconds for the user.
 *
 * @param array $ctx
 */
function static_optimizer_maybe_render_get_key_form( $ctx = [] ) {
	$options = static_optimizer_get_options();

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
              target="_blank"
              method="post">
            <input type="hidden" id="static_optimizer_cmd" name="static_optimizer_cmd" value="api_key.generate"/>

            Site: <input type="url" id="static_optimizer_site_url" name="site_url"
                         value="<?php esc_attr_e( $site_url ); ?>"/>
            Email: <input type="email" id="static_optimizer_email" name="email"
                          value="<?php esc_attr_e( $admin_email ); ?>"/>
            <input name='submit' class='button button-primary' type='submit' value='Get API Key'/>
        </form>
    </div> <!-- /static_optimizer_get_api_key_form_wrapper -->
	<?php
}

/**
 * We prefill the get api key form so when the user requests that the receiving page will have
 * some data prefilled in so we'll save 20 seconds for the user.
 *
 * @param array $ctx
 */
function static_optimizer_maybe_render_manage_key_form( $ctx = [] ) {
	$options = static_optimizer_get_options();

	if ( empty( $options['api_key'] ) ) {
		return;
	}

	$app_site_url      = STATIC_OPTIMIZER_APP_SITE_URL . '/login';
	$app_site_url_href = STATIC_OPTIMIZER_APP_SITE_URL . '/login';
	$admin_email       = get_option( 'admin_email' );
	$app_site_url_href = add_query_arg( 'email', urlencode($admin_email), $app_site_url_href );
	?>
    <br/>
    <hr/>
    <div id="static_optimizer_manage_api_key_form_wrapper" class="static_optimizer_get_api_key_form_wrapper">
        <h3>Manage API Key</h3>
        <p>
            To manage your StaticOptimizer API key to go <a href="<?php echo esc_url( $app_site_url_href ); ?>"
                                                            target="_blank"
                                                            class="button button-primary"><?php echo esc_url( $app_site_url ); ?></a>
        </p>
    </div> <!-- /static_optimizer_manage_api_key_form_wrapper -->
	<?php
}

add_action( 'static_optimizer_action_before_settings_form', 'static_optimizer_maybe_render_localhost_notice' );

function static_optimizer_maybe_render_localhost_notice( $ctx ) {
	$local_ips = [ '::1', '127.0.0.1', ];

	if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return;
	}

	// Let's check LAN IPs
	if ( ! preg_match( '#^(::1|127\.0\.|10\.0\.[0-2]|192\.168.[0-2]\.|172\.[1-3]\d*\.0)#si', $_SERVER['REMOTE_ADDR'] ) ) { // internal req or dev machine
		return;
	}

	$server_name = empty( $_SERVER['SERVER_NAME'] ) ? '' : $_SERVER['SERVER_NAME'];

	if ( ! preg_match( '#^(localhost|\.local)#si', $server_name ) ) { // internal req or dev machine
		return;
	}

	?>
    <div class="alert" style="background:red;color: #fff;padding: 3px;">
        This plugin doesn't work on localhost because our servers need to be able to access your site.
    </div>
	<?php
}

add_action( 'static_optimizer_action_before_settings_form', 'static_optimizer_maybe_render_not_active_plugin' );

/**
 * Remind the user if something is missing in the configuration.
 *
 * @param $ctx
 */
function static_optimizer_maybe_render_not_active_plugin( $ctx ) {
	$options = static_optimizer_get_options();

	if ( empty( $options['api_key'] ) ) {
		$msg = __( "Error: Missing API key. You need to request an API key to use this plugin. "
		           . "<br/>Generating an API key will authorize the current site."
		           . " If the site is not authorized our servers will not deliver your files (403 error).", 'statopt' );
	} elseif ( empty( $options['status'] ) ) {
		$msg = __( "Error: Plugin Inactive. You need set plugin's status to active in order for it to work.", 'statopt' );
	} else {
		return;
	}

	?>
    <div class="alert" style="background:red;color: #fff;padding: 3px;">
		<?php echo $msg; ?>
    </div>
	<?php
}