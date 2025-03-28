<?php
// phpcs:ignoreFile
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

require_once __DIR__ . '/includes/fbutils.php';

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use WooCommerce\Facebook\Admin\Tasks\Setup;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\ProductSync\ProductValidator as ProductSyncValidator;
use WooCommerce\Facebook\Utilities\Background_Handle_Virtual_Products_Variations;
use WooCommerce\Facebook\Utilities\Background_Remove_Duplicate_Visibility_Meta;
use WooCommerce\Facebook\Utilities\DebugTools;
use WooCommerce\Facebook\Utilities\Heartbeat;

class WC_Facebookcommerce extends WooCommerce\Facebook\Framework\Plugin {
	/** @var string the plugin version */
	const VERSION = WC_Facebook_Loader::PLUGIN_VERSION;

	/** @var string for backwards compatibility TODO: remove this in v2.0.0 {CW 2020-02-06} */
	const PLUGIN_VERSION = self::VERSION;

	/** @var string the plugin ID */
	const PLUGIN_ID = 'facebook_for_woocommerce';

	/** @var string the integration ID */
	const INTEGRATION_ID = 'facebookcommerce';

	/** @var string the product set categories meta name */
	const PRODUCT_SET_META = '_wc_facebook_product_cats';

	/** @var string the plugin user agent name to use for HTTP calls within User-Agent header */
	const PLUGIN_USER_AGENT_NAME = 'Facebook-for-WooCommerce';

	/** @var WC_Facebookcommerce singleton instance */
	protected static $instance;

	/** @var WooCommerce\Facebook\API instance */
	private $api;

	/** @var \WC_Facebookcommerce_Integration instance */
	private $integration;

	/** @var WooCommerce\Facebook\Admin admin handler instance */
	private $admin;

	/** @var WooCommerce\Facebook\Admin\Settings */
	private $admin_settings;

	/** @var WooCommerce\Facebook\AJAX Ajax handler instance */
	private $ajax;

	/** @var WooCommerce\Facebook\Products\Feed product feed handler */
	private $product_feed;

	/** @var Background_Handle_Virtual_Products_Variations instance */
	protected $background_handle_virtual_products_variations;

	/** @var Background_Remove_Duplicate_Visibility_Meta job handler instance */
	protected $background_remove_duplicate_visibility_meta;

	/** @var WooCommerce\Facebook\Products\Stock products stock handler */
	private $products_stock_handler;

	/** @var WooCommerce\Facebook\Products\Sync products sync handler */
	private $products_sync_handler;

	/** @var WooCommerce\Facebook\Products\Sync\Background background sync handler */
	private $sync_background_handler;

	/** @var WooCommerce\Facebook\ProductSets\Sync product sets sync handler */
	private $product_sets_sync_handler;

	/** @var WooCommerce\Facebook\Handlers\Connection connection handler */
	private $connection_handler;

	/** @var WooCommerce\Facebook\Handlers\WebHook webhook handler */
	private $webhook_handler;

	/** @var WooCommerce\Facebook\Commerce commerce handler */
	private $commerce_handler;

	/** @var WooCommerce\Facebook\Jobs\JobManager */
	public $job_manager;

	/** @var WooCommerce\Facebook\Utilities\Heartbeat */
	public $heartbeat;

	/** @var WooCommerce\Facebook\Products\FBCategories instance. */
	private $fb_categories;

	/**
	 * The Debug tools instance.
	 *
	 * @var WooCommerce\Facebook\Utilities\DebugTools
	 */
	private $debug_tools;

	/**
	 * Constructs the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION,
			[ 'text_domain' => 'facebook-for-woocommerce' ]
		);
		$this->init();
		$this->init_admin();
	}

	/**
	 * __get method for backward compatibility.
	 *
	 * @param string $key property name
	 * @return mixed
	 * @since 3.0.32
	 */
	public function __get( $key ) {
		// Add warning for private properties.
		if ( in_array( $key, array( 'configuration_detection', 'fb_categories' ), true ) ) {
			/* translators: %s property name. */
			_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'The %s property is private and should not be accessed outside its class.', 'facebook-for-woocommerce' ), esc_html( $key ) ), '3.0.32' );
			return $this->$key;
		}

		return null;
	}

	/**
	 * Initializes the plugin.
	 *
	 * @internal
	 */
	public function init() {

		if ( ! WC_Facebookcommerce_Utils::isWoocommerceIntegration() ) {
			return;
		}

		add_action( 'init', array( $this, 'get_integration' ) );
		add_action( 'init', array( $this, 'register_custom_taxonomy' ) );

		if ( is_admin() && ! wp_doing_ajax()) {
			add_filter( 'fb_product_set_row_actions', array( $this, 'product_set_links' ) );
			add_filter( 'manage_edit-fb_product_set_columns', array( $this, 'manage_fb_product_set_columns' ) );

			add_action( 'add_meta_boxes_product', array( $this, 'remove_product_fb_product_set_metabox' ), 50 );

			// Product Set breadcrumb filters
			add_filter( 'woocommerce_navigation_is_connected_page', array( $this, 'is_current_page_conected_filter' ), 99, 2 );
			add_filter( 'woocommerce_navigation_get_breadcrumbs', array( $this, 'wc_page_breadcrumbs_filter' ), 99 );
		}

		// Hook the setup task. The hook admin_init is not triggered when the WC fetches the tasks using the endpoint: wp-json/wc-admin/onboarding/tasks and hence hooking into init.
		add_action( 'init', array( $this, 'add_setup_task' ), 20 );

		add_filter(
			'wc_' . WC_Facebookcommerce::PLUGIN_ID . '_http_request_args',
			array( $this, 'force_user_agent_in_latin' )
		);

		include_once 'facebook-commerce.php';

		require_once __DIR__ . '/includes/fbproductfeed.php';

		$this->heartbeat = new Heartbeat( WC()->queue() );
		$this->heartbeat->init();

		$this->product_feed              = new WooCommerce\Facebook\Products\Feed();
		$this->products_stock_handler    = new WooCommerce\Facebook\Products\Stock();
		$this->products_sync_handler     = new WooCommerce\Facebook\Products\Sync();
		$this->sync_background_handler   = new WooCommerce\Facebook\Products\Sync\Background();
		$this->product_sets_sync_handler = new WooCommerce\Facebook\ProductSets\Sync();
		$this->commerce_handler          = new WooCommerce\Facebook\Commerce();
		$this->fb_categories             = new WooCommerce\Facebook\Products\FBCategories();

		// Initialize AJAX handling for specific AJAX actions.
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && $this->is_facebook_ajax_action( $_REQUEST['action'] ) ) {
			$this->ajax = new WooCommerce\Facebook\AJAX();
		}


		if ( 'yes' !== get_option( 'wc_facebook_background_handle_virtual_products_variations_complete', 'no' ) ) {
			$this->background_handle_virtual_products_variations = new Background_Handle_Virtual_Products_Variations();
		}

		if ( 'yes' !== get_option( 'wc_facebook_background_remove_duplicate_visibility_meta_complete', 'no' ) ) {
			$this->background_remove_duplicate_visibility_meta = new Background_Remove_Duplicate_Visibility_Meta();
		}

		$this->connection_handler = new WooCommerce\Facebook\Handlers\Connection( $this );
		$this->webhook_handler    = new WooCommerce\Facebook\Handlers\WebHook( $this );

		// Init jobs
		$this->job_manager = new WooCommerce\Facebook\Jobs\JobManager();
		add_action( 'init', [ $this->job_manager, 'init' ] );

		// Instantiate the debug tools.
		$this->debug_tools = new DebugTools();

		if ( is_admin() ) {
			$this->admin_settings = new WooCommerce\Facebook\Admin\Settings( $this->connection_handler->is_connected() );
		}
	}


	/**
	 * Check if the action is a Facebook AJAX action.
	 *
	 * @param string $action The AJAX action.
	 *
	 * @return bool True if it's a Facebook AJAX action, false otherwise.
	 */
	private function is_facebook_ajax_action( string $action ): bool {
		$facebook_ajax_actions = array(
			'facebook_for_woocommerce_set_product_sync_bulk_action_prompt',
			'facebook_for_woocommerce_set_excluded_terms_prompt',
			'wc_facebook_sync_products',
			'wc_facebook_get_sync_status',
			WooCommerce\Facebook\AJAX::ACTION_SEARCH_PRODUCT_ATTRIBUTES,
		);

		return in_array( $action, $facebook_ajax_actions, true );
	}


	/**
	 * Initializes the admin handling.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function init_admin() {
		add_action(
			'admin_init',
			function () {
				$this->admin = new WooCommerce\Facebook\Admin();
			},
			0
		);
	}


	/**
	 * Adds the setup task to the Tasklists.
	 *
	 * @since 2.6.29
	 */
	public function add_setup_task() {
		TaskLists::add_task(
			'extended',
			new Setup(
				TaskLists::get_list( 'extended' )
			)
		);
	}


	/**
	 * Saves errors or messages to WooCommerce Log (woocommerce/logs/plugin-id-xxx.txt)
	 *
	 * @since 2.3.3
	 * @param string $message error or message to save to log
	 * @param string $log_id optional log id to segment the files by, defaults to plugin id
	 */
	public function log( $message, $log_id = null ) {
		// Bail if site is connected and user has disabled logging.
		// If site is disconnected, force-enable logging so merchant can diagnose connection issues.
		if ( ( ! $this->get_integration() || ! $this->get_integration()->is_debug_mode_enabled() ) && $this->get_connection_handler()->is_connected() ) {
			return;
		}

		parent::log( $message, $log_id );
	}

	/**
	 * Logs an API request.
	 *
	 * @since 2.0.0
	 *
	 * @param array $request request data
	 * @param array $response response data
	 * @param null  $log_id log ID
	 */
	public function log_api_request( $request, $response, $log_id = null ) {
		// bail if logging isn't enabled
		if ( ! $this->get_integration() || ! $this->get_integration()->is_debug_mode_enabled() ) {
			return;
		}

		// Maybe remove headers from the debug log.
		if( ! $this->get_integration()->are_headers_requested_for_debug() ) {
			unset( $request['headers'] );
			unset( $response['headers'] );
		}

		$this->log( $this->get_api_log_message( $request ), $log_id );

		if ( ! empty( $response ) ) {
			$this->log( $this->get_api_log_message( $response ), $log_id );
		}
	}

	/**
	 * Remove Product Set metabox from Product edit page
	 *
	 * @since 2.3.0
	 */
	public function remove_product_fb_product_set_metabox() {
		remove_meta_box( 'fb_product_setdiv', 'product', 'side' );
	}

	/**
	 * Register Facebook Product Set Taxonomy
	 *
	 * @since 2.3.0
	 */
	public function register_custom_taxonomy() {
		$plural   = esc_html__( 'Facebook Product Sets', 'facebook-for-woocommerce' );
		$singular = esc_html__( 'Facebook Product Set', 'facebook-for-woocommerce' );

		$args = array(
			'labels'            => array(
				'name'                       => $plural,
				'singular_name'              => $singular,
				'menu_name'                  => $plural,
				// translators: Edit item label
				'edit_item'                  => sprintf( esc_html__( 'Edit %s', 'facebook-for-woocommerce' ), $singular ),
				// translators: Add new label
				'add_new_item'               => sprintf( esc_html__( 'Add new %s', 'facebook-for-woocommerce' ), $singular ),
				// translators: No items found text
				'not_found'                  => sprintf( esc_html__( 'No %s found.', 'facebook-for-woocommerce' ), $plural ),
				// translators: Search label
				'search_items'               => sprintf( esc_html__( 'Search %s.', 'facebook-for-woocommerce' ), $plural ),
				// translators: Text label
				'separate_items_with_commas' => sprintf( esc_html__( 'Separate %s with commas', 'facebook-for-woocommerce' ), $plural ),
				// translators: Text label
				'choose_from_most_used'      => sprintf( esc_html__( 'Choose from the most used %s', 'facebook-for-woocommerce' ), $plural ),
				// translators: Backlink item label
				'back_to_items'              => sprintf( esc_html__( '&larr; Go to %s', 'facebook-for-woocommerce' ), $plural ),
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
			'show_in_menu'      => false,
		);

		register_taxonomy( 'fb_product_set', array( 'product' ), $args );
	}


	/**
	 * Filter Facebook Product Set Taxonomy table links
	 *
	 * @since 2.3.0
	 *
	 * @param array $actions Item Actions.
	 *
	 * @return array
	 */
	public function product_set_links( $actions ) {
		unset( $actions['inline hide-if-no-js'] );
		unset( $actions['view'] );
		return $actions;
	}


	/**
	 * Remove posts count column from Facebook Product Set custom taxonomy
	 *
	 * @since 2.3.0
	 *
	 * @param array $columns Taxonomy columns.
	 *
	 * @return array
	 */
	public function manage_fb_product_set_columns( $columns ) {
		unset( $columns['posts'] );
		return $columns;
	}


	/**
	 * Filter WC Breadcrumbs when the page is Facebook Product Sets
	 *
	 * @since 2.3.0
	 *
	 * @param array $breadcrumbs Page breadcrumbs.
	 *
	 * @return array
	 */
	public function wc_page_breadcrumbs_filter( $breadcrumbs ) {

		if ( 'edit-fb_product_set' !== $this->get_current_page_id() ) {
			return $breadcrumbs;
		}

		$breadcrumbs = array(
			array( 'admin.php?page=wc-admin', 'WooCommerce' ),
			array( 'edit.php?post_type=product', 'Products' ),
		);

		$term_id = empty( $_GET['tag_ID'] ) ? '' : wc_clean( wp_unslash( $_GET['tag_ID'] ) ); //phpcs:ignore WordPress.Security
		if ( ! empty( $term_id ) ) {
			$breadcrumbs[] = array( 'edit-tags.php?taxonomy=fb_product_set&post_type=product', 'Products Sets' );
		}

		$breadcrumbs[] = ( empty( $term_id ) ? 'Product Sets' : 'Edit Product Set' );
		return $breadcrumbs;
	}


	/**
	 * Return that Facebook Product Set page is a WC Conected Page
	 *
	 * @since 2.3.0
	 *
	 * @param boolean $is_conected If it's connected or not.
	 *
	 * @return boolean
	 */
	public function is_current_page_conected_filter( $is_conected ) {
		if ( 'edit-fb_product_set' === $this->get_current_page_id() ) {
			return true;
		}

		return $is_conected;
	}

	/**
	 * Filter is responsible to always set latin user agent header value, because translated plugin names
	 * may contain characters which Facebook does not accept and return 400 response for requests with such
	 * header values.
	 * Applying either sanitize_title() nor remove_accents() on header value will not work for all the languages
	 * we support translations to e.g. Hebrew is going to convert into something %d7%90%d7%a8%d7%99%d7%92 which is
	 * not acceptable neither.
	 *
	 * @param array $http_request_headers - http request headers
	 * @return array
	 */
	public function force_user_agent_in_latin( array $http_request_headers ) {
		if ( isset( $http_request_headers['user-agent'] ) ) {
			$http_request_headers['user-agent'] = sprintf(
				'%s/%s (WooCommerce/%s; WordPress/%s)',
				WC_Facebookcommerce::PLUGIN_USER_AGENT_NAME,
				WC_Facebookcommerce::PLUGIN_VERSION,
				defined( 'WC_VERSION' ) ? WC_VERSION : WC_Facebook_Loader::MINIMUM_WC_VERSION,
				$GLOBALS['wp_version']
			);
		}
		return $http_request_headers;
	}


	/** Getter methods ********************************************************************************************/


	/**
	 * Gets the API instance.
	 *
	 * @since 2.0.0
	 *
	 * @param string $access_token access token to use for this API request
	 * @return WooCommerce\Facebook\API
	 * @throws ApiException
	 */
	public function get_api( string $access_token = '' ): WooCommerce\Facebook\API {
		// if none provided, use the general access token
		if ( ! $access_token ) {
			$access_token = $this->get_connection_handler()->get_access_token();
		}
		if ( ! is_object( $this->api ) ) {
			if ( ! $access_token ) {
				throw new ApiException( __( 'Cannot create the API instance because the access token is missing.', 'facebook-for-woocommerce' ) );
			}
			$this->api = new WooCommerce\Facebook\API( $access_token );
		} else {
			$this->api->set_access_token( $access_token );
		}
		return $this->api;
	}

	/**
	 * Gets the category handler.
	 *
	 * @since 1.11.0
	 *
	 * @return WooCommerce\Facebook\Products\FBCategories
	 */
	public function get_facebook_category_handler() {
		return $this->fb_categories;
	}

	/**
	 * Gets the background handle virtual products and variations handler instance.
	 *
	 * @since 2.0.0
	 *
	 * @return Background_Handle_Virtual_Products_Variations
	 */
	public function get_background_handle_virtual_products_variations_instance() {
		return $this->background_handle_virtual_products_variations;
	}


	/**
	 * Gets the products sync handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Products\Sync
	 */
	public function get_products_sync_handler() {
		return $this->products_sync_handler;
	}


	/**
	 * Gets the products sync background handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Products\Sync\Background
	 */
	public function get_products_sync_background_handler() {
		return $this->sync_background_handler;
	}


	/**
	 * Gets the connection handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Handlers\Connection
	 */
	public function get_connection_handler() {
		return $this->connection_handler;
	}


	/**
	 * Gets the integration instance.
	 *
	 * @since 1.10.0
	 *
	 * @return WC_Facebookcommerce_Integration instance
	 */
	public function get_integration() {
		if ( null === $this->integration ) {
			$this->integration = new WC_Facebookcommerce_Integration( $this );
		}

		return $this->integration;
	}


	/**
	 * Gets the commerce handler instance.
	 *
	 * @since 2.1.0
	 *
	 * @return WooCommerce\Facebook\Commerce commerce handler instance
	 */
	public function get_commerce_handler() {
		return $this->commerce_handler;
	}


	/**
	 * Gets the debug profiling logger instance.
	 *
	 * @return WooCommerce\Facebook\Debug\ProfilingLogger
	 */
	public function get_profiling_logger() {
		static $instance = null;
		if ( null === $instance ) {
			$is_enabled = defined( 'FACEBOOK_FOR_WOOCOMMERCE_PROFILING_LOG_ENABLED' ) && FACEBOOK_FOR_WOOCOMMERCE_PROFILING_LOG_ENABLED;
			$instance   = new WooCommerce\Facebook\Debug\ProfilingLogger( $is_enabled );
		}

		return $instance;
	}

	/**
	 * Get the product sync validator class.
	 *
	 * @param WC_Product $product A product object to be validated.
	 *
	 * @return ProductSyncValidator
	 */
	public function get_product_sync_validator( WC_Product $product ) {
		return new ProductSyncValidator( $this->get_integration(), $product );
	}

	/**
	 * Gets the advertise tab page URL.
	 *
	 * @since 2.6.29
	 *
	 * @return string
	 */
	public function get_advertise_tab_url() {
		return admin_url( 'admin.php?page=wc-facebook&tab=advertise' );
	}

	/**
	 * Gets the settings page URL.
	 *
	 * @since 1.10.0
	 *
	 * @param null $plugin_id unused
	 * @return string
	 */
	public function get_settings_url( $plugin_id = null ) {
		return admin_url( 'admin.php?page=wc-facebook' );
	}


	/**
	 * Gets the plugin name.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return __( 'Facebook for WooCommerce', 'facebook-for-woocommerce' );
	}

	/**
	 * Gets the url for the assets build directory.
	 *
	 * @since 2.3.4
	 *
	 * @return string
	 */
	public function get_asset_build_dir_url() {
		return $this->get_plugin_url() . '/assets/build';
	}


	/** Conditional methods ***************************************************************************************/


	/**
	 * Determines if viewing the plugin settings in the admin.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	public function is_plugin_settings() {
		return is_admin() && WooCommerce\Facebook\Admin\Settings::PAGE_ID === Helper::get_requested_value( 'page' );
	}


	/** Utility methods *******************************************************************************************/


	/**
	 * Gets the plugin singleton instance.
	 *
	 * @see \facebook_for_woocommerce()
	 *
	 * @since 1.10.0
	 *
	 * @return \WC_Facebookcommerce the plugin singleton instance
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Gets the plugin file.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	protected function get_file() {
		return __FILE__;
	}


	/**
	 * Return current page ID
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected function get_current_page_id(): string {
		$current_screen_id = '';
		$current_screen    = get_current_screen();
		if ( ! empty( $current_screen ) ) {
			$current_screen_id = $current_screen->id;
		}
		return $current_screen_id;
	}
}


/**
 * Gets the Facebook for WooCommerce plugin instance.
 *
 * @since 1.10.0
 *
 * @return \WC_Facebookcommerce instance of the plugin
 */
function facebook_for_woocommerce() {
	return \WC_Facebookcommerce::instance();
}
