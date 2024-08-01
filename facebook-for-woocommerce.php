<?php
// phpcs:ignoreFile
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * Plugin Name: Facebook for WooCommerce (Performance Optimized)
 * Plugin URI: https://github.com/casperh123/facebook-for-woocommerce-performance-focus
 * Description: Grow your business on Facebook! Use this official plugin to help sell more of your products using Facebook. After completing the setup, you'll be ready to create ads that promote your products and you can also create a shop section on your Page where customers can browse your products on Facebook.
 * Author: Facebook, Casper Holten (Clypper Technology)
 * Author URI: https://www.facebook.com/
 * Version: 3.2.7
 * Requires at least: 5.6
 * Text Domain: facebook-for-woocommerce
 * Requires Plugins: woocommerce
 * Tested up to: 6.6
 * WC requires at least: 6.4
 * WC tested up to: 9.1
 *
 * @package FacebookCommerce
 */

require_once __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Grow\Tools\CompatChecker\v0_0_1\Checker;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

// HPOS compatibility declaration.
add_action(
	'before_woocommerce_init',
	function() {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', plugin_basename( __FILE__ ), true );
		}
	}
);
/**
 * The plugin loader class.
 *
 * @since 1.10.0
 */
class WC_Facebook_Loader {

	/**
	 * @var string the plugin version. This must be in the main plugin file to be automatically bumped by Woorelease.
	 */
	const PLUGIN_VERSION = '3.2.6'; // WRCS: DEFINED_VERSION.

	// Minimum PHP version required by this plugin.
	const MINIMUM_PHP_VERSION = '7.4.0';


	// Minimum WooCommerce version required by this plugin.
	const MINIMUM_WC_VERSION = '5.3';


	// The plugin name, for displaying notices.
	const PLUGIN_NAME = 'Facebook for WooCommerce';


	/**
	 * This class instance.
	 *
	 * @var \WC_Facebook_Loader single instance of this class.
	 */
	private static $instance;

	/**
	 * Admin notices to add.
	 *
	 * @var array Array of admin notices.
	 */
	private $notices = array();

    /**
     * Constructs the class.
     *
     * @since 1.10.0
     */
    protected function __construct() {

        register_activation_hook( __FILE__, array( $this, 'activation_check' ) );

		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
    }


	/**
	 * Cloning instances is forbidden due to singleton pattern.
	 *
	 * @since 1.10.0
	 */
	public function __clone() {

		wc_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot clone instances of %s.', get_class( $this ) ), '1.10.0' );
	}


	/**
	 * Unserializing instances is forbidden due to singleton pattern.
	 *
	 * @since 1.10.0
	 */
	public function __wakeup() {

		wc_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot unserialize instances of %s.', get_class( $this ) ), '1.10.0' );
	}


    /**
     * Initializes the plugin.
     *
     * @since 1.10.0
     */
    public function init_plugin() {
        if ( ! function_exists( 'facebook_for_woocommerce' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-wc-facebookcommerce.php';
        }

        facebook_for_woocommerce();
    }


    /**
     * Checks the server environment and other factors during plugin activation.
     *
     * @internal
     * @since 1.10.0
     */
    public function activation_check() {
        if ( ! $this->is_environment_compatible() ) {
            $this->deactivate_plugin();
            wp_die( esc_html( self::PLUGIN_NAME . ' could not be activated. ' . $this->get_environment_message() ) );
        }
    }


	/**
	 * Deactivates the plugin.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	protected function deactivate_plugin() {

		deactivate_plugins( plugin_basename( __FILE__ ) );

		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}


	/**
	 * Determines if the server environment is compatible with this plugin.
	 *
	 * Override this method to add checks for more than just the PHP version.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	private function is_environment_compatible(): bool
    {
		return version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=' );
	}


	/**
	 * Gets the message for display when the environment is incompatible with this plugin.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_environment_message() {

		return sprintf( 'The minimum PHP version required for this plugin is %1$s. You are running %2$s.', self::MINIMUM_PHP_VERSION, PHP_VERSION );
	}


	/**
	 * Gets the main \WC_Facebook_Loader instance.
	 *
	 * Ensures only one instance can be loaded.
	 *
	 * @since 1.10.0
	 *
	 * @return \WC_Facebook_Loader
	 */
	public static function instance(): WC_Facebook_Loader
    {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

WC_Facebook_Loader::instance();
