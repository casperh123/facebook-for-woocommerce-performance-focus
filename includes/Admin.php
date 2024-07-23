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

defined('ABSPATH') or exit;

/**
 * Admin handler.
 *
 * @since 1.10.0
 */
class Admin
{

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
	public function __construct()
	{

		$order_screen_id = class_exists(OrderUtil::class) ? OrderUtil::get_order_admin_screen() : 'shop_order';

		$this->screen_ids = [
			'product',
			'edit-product',
			'woocommerce_page_wc-facebook',
			'marketing_page_wc-facebook',
			'edit-product_cat',
			$order_screen_id,
		];

		// enqueue admin scripts
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

		$plugin = facebook_for_woocommerce();
		// only alter the admin UI if the plugin is connected to Facebook and ready to sync products
		if (!$plugin->get_connection_handler()->is_connected() || !$plugin->get_integration()->get_product_catalog_id()) {
			return;
		}

		$this->product_categories = new Admin\Product_Categories();
		$this->product_sets = new Admin\Product_Sets();

		// add custom taxonomy for Product Sets
		add_filter('gettext', array($this, 'change_custom_taxonomy_tip'), 20, 2);
	}

	/**
	 * __get method for backward compatibility.
	 *
	 * @param string $key property name
	 * @return mixed
	 * @since 3.0.32
	 */
	public function __get($key)
	{
		// Add warning for private properties.
		if ('product_sets' === $key) {
			/* translators: %s property name. */
			_doing_it_wrong(__FUNCTION__, sprintf(esc_html__('The %s property is protected and should not be accessed outside its class.', 'facebook-for-woocommerce'), esc_html($key)), '3.0.32');
			return $this->$key;
		}

		return null;
	}

	/**
	 * Change custom taxonomy tip text
	 *
	 * @param string $translation Text translation.
	 * @param string $text Original text.
	 *
	 * @return string
	 * @since 2.3.0
	 *
	 */
	public function change_custom_taxonomy_tip($translation, $text)
	{
		global $current_screen;
		if (isset($current_screen->id) && 'edit-fb_product_set' === $current_screen->id && 'The name is how it appears on your site.' === $text) {
			$translation = esc_html__('The name is how it appears on Facebook Catalog.', 'facebook-for-woocommerce');
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
	public function enqueue_scripts()
	{
		global $current_screen;

		if (isset($current_screen->id)) {

			if (in_array($current_screen->id, $this->screen_ids, true) || facebook_for_woocommerce()->is_plugin_settings()) {

				// enqueue modal functions
				wp_enqueue_script(
					'facebook-for-woocommerce-modal',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/modal.js',
					array('jquery', 'wc-backbone-modal', 'jquery-blockui'),
					\WC_Facebookcommerce::PLUGIN_VERSION
				);

				// enqueue google product category select
				wp_enqueue_script(
					'wc-facebook-google-product-category-fields',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/google-product-category-fields.js',
					array('jquery'),
					\WC_Facebookcommerce::PLUGIN_VERSION
				);

				wp_localize_script(
					'wc-facebook-google-product-category-fields',
					'facebook_for_woocommerce_google_product_category',
					array(
						'i18n' => array(
							'top_level_dropdown_placeholder' => __('Search main categories...', 'facebook-for-woocommerce'),
							'second_level_empty_dropdown_placeholder' => __('Choose a main category', 'facebook-for-woocommerce'),
							'general_dropdown_placeholder' => __('Choose a category', 'facebook-for-woocommerce'),
						),
					)
				);
			}

			if ('edit-fb_product_set' === $current_screen->id) {
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
					['jquery', 'select2'],
					\WC_Facebookcommerce::PLUGIN_VERSION,
					true
				);

				wp_localize_script(
					'facebook-for-woocommerce-product-sets',
					'facebook_for_woocommerce_product_sets',
					array(

						'excluded_category_ids' => facebook_for_woocommerce()->get_integration()->get_excluded_product_category_ids(),
						'excluded_category_warning_message' => __('You have selected one or more categories currently excluded from the Facebook sync. Products belonging to the excluded categories will not be added to your Facebook Product Set.', 'facebook-for-woocommerce'),
					)
				);
			}

			if (facebook_for_woocommerce()->is_plugin_settings()) {
				wp_enqueue_style('woocommerce_admin_styles');
				wp_enqueue_script('wc-enhanced-select');
			}
		}//end if
	}


	/**
	 * Adds a transient so an informational notice is displayed on the next page load.
	 *
	 * @param int $count number of products
	 * @since 2.0.0
	 *
	 */
	public static function add_product_disabled_sync_notice($count = 1)
	{

		if (!facebook_for_woocommerce()->get_admin_notice_handler()->is_notice_dismissed('wc-' . facebook_for_woocommerce()->get_id_dasherized() . '-product-disabled-sync')) {
			set_transient('wc_' . facebook_for_woocommerce()->get_id() . '_show_product_disabled_sync_notice_' . get_current_user_id(), $count, MINUTE_IN_SECONDS);
		}
	}

}
