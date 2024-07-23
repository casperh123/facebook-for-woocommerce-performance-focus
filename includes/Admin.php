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

namespace WooCommerce\Facebook;

use WooCommerce\Facebook\Admin\Enhanced_Catalog_Attribute_Fields;
use WooCommerce\Facebook\Framework\Helper;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) or exit;

/**
 * Admin handler.
 *
 * @since 1.10.0
 */
class Admin {

	/** @var string the "sync and show" sync mode slug */
	const SYNC_MODE_SYNC_AND_SHOW = 'sync_and_show';

	/** @var string the "sync and show" sync mode slug */
	const SYNC_MODE_SYNC_AND_HIDE = 'sync_and_hide';

	/** @var string the "sync disabled" sync mode slug */
	const SYNC_MODE_SYNC_DISABLED = 'sync_disabled';

	/** @var Product_Categories the product category admin handler */
	protected $product_categories;

	/** @var array screens ids where to include scripts */
	protected $screen_ids = [];

	/** @var Product_Sets the product set admin handler. */
	protected $product_sets;

	/**
	 * Admin constructor.
	 *
	 * @since 1.10.0
	 */
	public function __construct() {

		$order_screen_id = class_exists( OrderUtil::class ) ? OrderUtil::get_order_admin_screen() : 'shop_order';

		$this->screen_ids = [
			'product',
			'edit-product',
			'woocommerce_page_wc-facebook',
			'marketing_page_wc-facebook',
			'edit-product_cat',
			$order_screen_id,
		];

		// enqueue admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$plugin = facebook_for_woocommerce();
		// only alter the admin UI if the plugin is connected to Facebook and ready to sync products
		if ( ! $plugin->get_connection_handler()->is_connected() || ! $plugin->get_integration()->get_product_catalog_id() ) {
			return;
		}

		$this->product_categories = new Admin\Product_Categories();
		$this->product_sets       = new Admin\Product_Sets();

		// add custom taxonomy for Product Sets
		add_filter( 'gettext', array( $this, 'change_custom_taxonomy_tip' ), 20, 2 );
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
		if ( 'product_sets' === $key ) {
			/* translators: %s property name. */
			_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'The %s property is protected and should not be accessed outside its class.', 'facebook-for-woocommerce' ), esc_html( $key ) ), '3.0.32' );
			return $this->$key;
		}

		return null;
	}

	/**
	 * Change custom taxonomy tip text
	 *
	 * @since 2.3.0
	 *
	 * @param string $translation Text translation.
	 * @param string $text Original text.
	 *
	 * @return string
	 */
	public function change_custom_taxonomy_tip( $translation, $text ) {
		global $current_screen;
		if ( isset( $current_screen->id ) && 'edit-fb_product_set' === $current_screen->id && 'The name is how it appears on your site.' === $text ) {
			$translation = esc_html__( 'The name is how it appears on Facebook Catalog.', 'facebook-for-woocommerce' );
		}
		return $translation;
	}

	/**
	 * Enqueues admin scripts.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function enqueue_scripts() {
		global $current_screen;

		if ( isset( $current_screen->id ) ) {

			if ( in_array( $current_screen->id, $this->screen_ids, true ) || facebook_for_woocommerce()->is_plugin_settings() ) {

				// enqueue modal functions
				wp_enqueue_script(
					'facebook-for-woocommerce-modal',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/modal.js',
					array( 'jquery', 'wc-backbone-modal', 'jquery-blockui' ),
					\WC_Facebookcommerce::PLUGIN_VERSION
				);

				// enqueue google product category select
				wp_enqueue_script(
					'wc-facebook-google-product-category-fields',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/google-product-category-fields.js',
					array( 'jquery' ),
					\WC_Facebookcommerce::PLUGIN_VERSION
				);

				wp_localize_script(
					'wc-facebook-google-product-category-fields',
					'facebook_for_woocommerce_google_product_category',
					array(
						'i18n' => array(
							'top_level_dropdown_placeholder' => __( 'Search main categories...', 'facebook-for-woocommerce' ),
							'second_level_empty_dropdown_placeholder' => __( 'Choose a main category', 'facebook-for-woocommerce' ),
							'general_dropdown_placeholder'   => __( 'Choose a category', 'facebook-for-woocommerce' ),
						),
					)
				);
			}

			if ( 'edit-fb_product_set' === $current_screen->id ) {
				// enqueue WooCommerce Admin Styles because of Select2
				wp_enqueue_style(
					'woocommerce_admin_styles',
					WC()->plugin_url() . '/assets/css/admin.css',
					[],
					\WC_Facebookcommerce::PLUGIN_VERSION
				);
				wp_enqueue_style(
					'facebook-for-woocommerce-product-sets-admin',
					facebook_for_woocommerce()->get_plugin_url() . '/assets/css/admin/facebook-for-woocommerce-product-sets-admin.css',
					[],
					\WC_Facebookcommerce::PLUGIN_VERSION
				);

				wp_enqueue_script(
					'facebook-for-woocommerce-product-sets',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/product-sets-admin.js',
					[ 'jquery', 'select2' ],
					\WC_Facebookcommerce::PLUGIN_VERSION,
					true
				);

				wp_localize_script(
					'facebook-for-woocommerce-product-sets',
					'facebook_for_woocommerce_product_sets',
					array(

						'excluded_category_ids'             => facebook_for_woocommerce()->get_integration()->get_excluded_product_category_ids(),
						'excluded_category_warning_message' => __( 'You have selected one or more categories currently excluded from the Facebook sync. Products belonging to the excluded categories will not be added to your Facebook Product Set.', 'facebook-for-woocommerce' ),
					)
				);
			}

			add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_settings_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'add_product_settings_tab_content' ) );

			if ( facebook_for_woocommerce()->is_plugin_settings() ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
				wp_enqueue_script( 'wc-enhanced-select' );
			}
		}//end if
	}

	/**
	 * Adds a new tab to the Product edit page.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 *
	 * @param array $tabs product tabs
	 * @return array
	 */
	public function add_product_settings_tab( $tabs ) {

		$tabs['fb_commerce_tab'] = array(
			'label'  => __( 'Facebook', 'facebook-for-woocommerce' ),
			'target' => 'facebook_options',
			'class'  => array( 'show_if_simple', 'show_if_variable', 'show_if_external' ),
		);

		return $tabs;
	}

	/**
	 * Adds content to the new Facebook tab on the Product edit page.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function add_product_settings_tab_content() {
		global $post;


		// all products have sync enabled unless explicitly disabled
		$sync_enabled = 'no' !== get_post_meta( $post->ID, Products::SYNC_ENABLED_META_KEY, true );
		$is_visible   = ( $visibility = get_post_meta( $post->ID, Products::VISIBILITY_META_KEY, true ) ) ? wc_string_to_bool( $visibility ) : true;
		$product 	  = wc_get_product( $post );

		$description  = get_post_meta( $post->ID, \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION, true );
		$price        = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_PRICE, true );
		$image_source = get_post_meta( $post->ID, Products::PRODUCT_IMAGE_SOURCE_META_KEY, true );
		$image        = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_IMAGE, true );

		if ( $sync_enabled ) {
			$sync_mode = $is_visible ? self::SYNC_MODE_SYNC_AND_SHOW : self::SYNC_MODE_SYNC_AND_HIDE;
		} else {
			$sync_mode = self::SYNC_MODE_SYNC_DISABLED;
		}

		// 'id' attribute needs to match the 'target' parameter set above
		?>
		<div id='facebook_options' class='panel woocommerce_options_panel'>
			<div class='options_group hide_if_variable'>
				<?php

				woocommerce_wp_select(
					array(
						'id'      => 'wc_facebook_sync_mode',
						'label'   => __( 'Facebook sync', 'facebook-for-woocommerce' ),
						'options' => array(
							self::SYNC_MODE_SYNC_AND_SHOW => __( 'Sync and show in catalog', 'facebook-for-woocommerce' ),
							self::SYNC_MODE_SYNC_AND_HIDE => __( 'Sync and hide in catalog', 'facebook-for-woocommerce' ),
							self::SYNC_MODE_SYNC_DISABLED => __( 'Do not sync', 'facebook-for-woocommerce' ),
						),
						'value'   => $sync_mode,
					)
				);

				woocommerce_wp_textarea_input(
					array(
						'id'          => \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION,
						'label'       => __( 'Facebook Description', 'facebook-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Custom (plain-text only) description for product on Facebook. If blank, product description will be used. If product description is blank, shortname will be used.', 'facebook-for-woocommerce' ),
						'cols'        => 40,
						'rows'        => 20,
						'value'       => $description,
						'class'       => 'short enable-if-sync-enabled',
					)
				);

				woocommerce_wp_radio(
					array(
						'id'            => 'fb_product_image_source',
						'label'         => __( 'Facebook Product Image', 'facebook-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Choose the product image that should be synced to the Facebook catalog for this product. If using a custom image, please enter an absolute URL (e.g. https://domain.com/image.jpg).', 'facebook-for-woocommerce' ),
						'options'       => array(
							Products::PRODUCT_IMAGE_SOURCE_PRODUCT => __( 'Use WooCommerce image', 'facebook-for-woocommerce' ),
							Products::PRODUCT_IMAGE_SOURCE_CUSTOM  => __( 'Use custom image', 'facebook-for-woocommerce' ),
						),
						'value'         => $image_source ?: Products::PRODUCT_IMAGE_SOURCE_PRODUCT,
						'class'         => 'short enable-if-sync-enabled js-fb-product-image-source',
						'wrapper_class' => 'fb-product-image-source-field',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'    => \WC_Facebook_Product::FB_PRODUCT_IMAGE,
						'label' => __( 'Custom Image URL', 'facebook-for-woocommerce' ),
						'value' => $image,
						'class' => sprintf( 'enable-if-sync-enabled product-image-source-field show-if-product-image-source-%s', Products::PRODUCT_IMAGE_SOURCE_CUSTOM ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_PRODUCT_PRICE,
						'label'       => sprintf(
						/* translators: Placeholders %1$s - WC currency symbol */
							__( 'Facebook Price (%1$s)', 'facebook-for-woocommerce' ),
							get_woocommerce_currency_symbol()
						),
						'desc_tip'    => true,
						'description' => __( 'Custom price for product on Facebook. Please enter in monetary decimal (.) format without thousand separators and currency symbols. If blank, product price will be used.', 'facebook-for-woocommerce' ),
						'cols'        => 40,
						'rows'        => 60,
						'value'       => $price,
						'class'       => 'enable-if-sync-enabled',
					)
				);

				woocommerce_wp_hidden_input(
					array(
						'id'    => \WC_Facebook_Product::FB_REMOVE_FROM_SYNC,
						'value' => '',
					)
				);
				?>
			</div>
			<?php
			$commerce_handler = facebook_for_woocommerce()->get_commerce_handler();
			?>
			<?php if ( $commerce_handler->is_connected() && $commerce_handler->is_available() ) : ?>
				<div class='wc-facebook-commerce-options-group options_group'>
					<?php
					if ( $product instanceof \WC_Product ) {
						\WooCommerce\Facebook\Admin\Products::render_commerce_fields( $product );
					}
					?>
				</div>
			<?php endif; ?>
			<div class='wc-facebook-commerce-options-group options_group'>
				<?php \WooCommerce\Facebook\Admin\Products::render_google_product_category_fields_and_enhanced_attributes( $product ); ?>
			</div>
		</div>
		<?php
	}


	/**
	 * Adds a transient so an informational notice is displayed on the next page load.
	 *
	 * @since 2.0.0
	 *
	 * @param int $count number of products
	 */
	public static function add_product_disabled_sync_notice( $count = 1 ) {

		if ( ! facebook_for_woocommerce()->get_admin_notice_handler()->is_notice_dismissed( 'wc-' . facebook_for_woocommerce()->get_id_dasherized() . '-product-disabled-sync' ) ) {
			set_transient( 'wc_' . facebook_for_woocommerce()->get_id() . '_show_product_disabled_sync_notice_' . get_current_user_id(), $count, MINUTE_IN_SECONDS );
		}
	}

}
