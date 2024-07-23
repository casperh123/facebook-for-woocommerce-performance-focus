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

use WooCommerce\Facebook\Events\Event;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Helper;

if ( ! class_exists( 'WC_Facebookcommerce_EventsTracker' ) ) :

	if ( ! class_exists( 'WC_Facebookcommerce_Utils' ) ) {
		include_once 'includes/fbutils.php';
	}

	if ( ! class_exists( 'WC_Facebookcommerce_Pixel' ) ) {
		include_once 'facebook-commerce-pixel-event.php';
	}

	class WC_Facebookcommerce_EventsTracker {

		/** @var \WC_Facebookcommerce_Pixel instance */
		private $pixel;

		/** @var array with events tracked */
		private $tracked_events;

		/** @var array array with epnding events */
		private $pending_events = [];

		/** @var AAMSettings aam settings instance, used to filter advanced matching fields*/
		private $aam_settings;

		/** @var bool whether the pixel should be enabled */
		private $is_pixel_enabled;


		/**
		 * Events tracker constructor.
		 *
		 * @param $user_info
		 * @param $aam_settings
		 */
		public function __construct( $user_info, $aam_settings ) {

			if ( ! $this->is_pixel_enabled() ) {
				return;
			}

			$this->pixel          = new \WC_Facebookcommerce_Pixel( $user_info );
			$this->aam_settings   = $aam_settings;
			$this->tracked_events = array();

			$this->add_hooks();
		}


		/**
		 * Determines whether the Pixel should be enabled.
		 *
		 * @since 2.2.0
		 *
		 * @return bool
		 */
		private function is_pixel_enabled() {

			if ( null === $this->is_pixel_enabled ) {

				/**
				 * Filters whether the Pixel should be enabled.
				 *
				 * @param bool $enabled default true
				 */
				$this->is_pixel_enabled = (bool) apply_filters( 'facebook_for_woocommerce_integration_pixel_enabled', true );
			}

			return $this->is_pixel_enabled;
		}


		/**
		 * Add events tracker hooks.
		 *
		 * @since 2.2.0
		 */
		private function add_hooks() {

			// inject Pixel
			add_action( 'wp_head', array( $this, 'inject_base_pixel' ) );
			add_action( 'wp_footer', array( $this, 'inject_base_pixel_noscript' ) );

			// Purchase and Subscribe events
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'inject_purchase_event' ) );
			add_action( 'woocommerce_thankyou', array( $this, 'inject_purchase_event' ), 40 );

			// Checkout update order meta from the Checkout Block.
			if ( version_compare( \Automattic\WooCommerce\Blocks\Package::get_version(), '7.2.0', '>=' ) ) {
				add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'inject_order_meta_event_for_checkout_block_flow' ), 10, 1 );
			} elseif ( version_compare( \Automattic\WooCommerce\Blocks\Package::get_version(), '6.3.0', '>=' ) ) {
				add_action( 'woocommerce_blocks_checkout_update_order_meta', array( $this, 'inject_order_meta_event_for_checkout_block_flow' ), 10, 1 );
			} else {
				add_action( '__experimental_woocommerce_blocks_checkout_update_order_meta', array( $this, 'inject_order_meta_event_for_checkout_block_flow' ), 10, 1 );
			}

			add_action( 'shutdown', array( $this, 'send_pending_events' ) );
		}


		/**
		 * Prints the base JavaScript pixel code.
		 */
		public function inject_base_pixel() {

			if ( $this->is_pixel_enabled() ) {
				// phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
				echo $this->pixel->pixel_base_code();
			}
		}


		/**
		 * Prints the base <noscript> pixel code.
		 *
		 * This is necessary to avoid W3 validation errors.
		 */
		public function inject_base_pixel_noscript() {

			if ( $this->is_pixel_enabled() ) {
				// phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
				echo $this->pixel->pixel_base_code_noscript();
			}
		}


		/**
		 * Triggers a Purchase event when checkout is completed.
		 *
		 * This may happen either when:
		 * - WooCommerce signals a payment transaction complete (most gateways)
		 * - Customer reaches Thank You page skipping payment (for gateways that do not require payment, e.g. Cheque, BACS, Cash on delivery...)
		 *
		 * The method checks if the event was not triggered already avoiding a duplicate.
		 * Finally, if the order contains subscriptions, it will also track an associated Subscription event.
		 *
		 * @internal
		 *
		 * @param int $order_id order identifier
		 */
		public function inject_purchase_event( $order_id ) {

			$event_name = 'Purchase';

			if ( ! $this->is_pixel_enabled() || $this->pixel->is_last_event( $event_name ) ) {
				return;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return;
			}

			// use a session flag to ensure an order is tracked with any payment method, also when the order is placed through AJAX
			$order_placed_flag = '_wc_' . facebook_for_woocommerce()->get_id() . '_order_placed_' . $order_id;

			// use a session flag to ensure a Purchase event is not tracked multiple times
			$purchase_tracked_flag = '_wc_' . facebook_for_woocommerce()->get_id() . '_purchase_tracked_' . $order_id;

			// when saving the order meta data: add a flag to mark the order tracked
			if ( 'woocommerce_checkout_update_order_meta' === current_action() ) {
				set_transient( $order_placed_flag, 'yes', 15 * MINUTE_IN_SECONDS );
				return;
			}

			// bail if by the time we are on the thank you page the meta has not been set or we already tracked a Purchase event
			if ( 'yes' !== get_transient( $order_placed_flag ) || 'yes' === get_transient( $purchase_tracked_flag ) ) {
				return;
			}

			$content_type  = 'product';
			$contents      = array();
			$product_ids   = array( array() );
			$product_names = array();

			foreach ( $order->get_items() as $item ) {

				$product = $item->get_product();

				if ( $product ) {
					$product_ids[]   = \WC_Facebookcommerce_Utils::get_fb_content_ids( $product );
					$product_names[] = $product->get_name();

					if ( 'product_group' !== $content_type && $product->is_type( 'variable' ) ) {
						$content_type = 'product_group';
					}

					$quantity = $item->get_quantity();
					$content  = new \stdClass();

					$content->id       = \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product );
					$content->quantity = $quantity;

					$contents[] = $content;
				}
			}
			// Advanced matching information is extracted from the order
			$event_data = array(
				'event_name'  => $event_name,
				'custom_data' => array(
					'content_ids'  => wp_json_encode( array_merge( ... $product_ids ) ),
					'content_name' => wp_json_encode( $product_names ),
					'contents'     => wp_json_encode( $contents ),
					'content_type' => $content_type,
					'value'        => $order->get_total(),
					'currency'     => get_woocommerce_currency(),
				),
				'user_data'   => $this->get_user_data_from_billing_address( $order ),
			);

			$event = new Event( $event_data );

			$this->send_api_event( $event );

			$event_data['event_id'] = $event->get_id();

			$this->pixel->inject_event( $event_name, $event_data );

			$this->inject_subscribe_event( $order_id );

			// mark the order as tracked
			set_transient( $purchase_tracked_flag, 'yes', 15 * MINUTE_IN_SECONDS );

		}

		/**
		 * Inject order meta gor WooCommerce Checkout Blocks flow.
		 * The blocks flow does not trigger the woocommerce_checkout_update_order_meta so we can't rely on it.
		 * The Checkout Block has its own hook that allows us to inject the meta at
		 * the appropriate moment: woocommerce_store_api_checkout_update_order_meta.
		 *
		 * Note: __experimental_woocommerce_blocks_checkout_update_order_meta has been deprecated
		 * as of WooCommerce Blocks 6.3.0
		 *
		 *  @since 2.6.6
		 *
		 *  @param WC_Order|int $the_order Order object or id.
		 */
		public function inject_order_meta_event_for_checkout_block_flow( $the_order ) {

			$event_name = 'Purchase';

			if ( ! $this->is_pixel_enabled() || $this->pixel->is_last_event( $event_name ) ) {
				return;
			}

			$order = wc_get_order($the_order);

			if ( ! $order ) {
				return;
			}

			$order_placed_flag = '_wc_' . facebook_for_woocommerce()->get_id() . '_order_placed_' . $order->get_id();
			set_transient( $order_placed_flag, 'yes', 15 * MINUTE_IN_SECONDS );

		}


		/**
		 * Triggers a Subscribe event when a given order contains subscription products.
		 *
		 * @see \WC_Facebookcommerce_EventsTracker::inject_purchase_event()
		 *
		 * @internal
		 *
		 * @param int $order_id order identifier
		 */
		public function inject_subscribe_event( $order_id ) {

			if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) || ! $this->is_pixel_enabled() || $this->pixel->is_last_event( 'Subscribe' ) ) {
				return;
			}

			foreach ( wcs_get_subscriptions_for_order( $order_id ) as $subscription ) {

				// TODO consider 'StartTrial' event for free trial Subscriptions, which is the same as here (minus sign_up_fee) and tracks "when a person starts a free trial of a product or service" {FN 2020-03-20}
				$event_name = 'Subscribe';

				// TODO consider including (int|float) 'predicted_ltv': "Predicted lifetime value of a subscriber as defined by the advertiser and expressed as an exact value." {FN 2020-03-20}
				$event_data = array(
					'event_name'  => $event_name,
					'custom_data' => array(
						'sign_up_fee' => $subscription->get_sign_up_fee(),
						'value'       => $subscription->get_total(),
						'currency'    => get_woocommerce_currency(),
					),
					'user_data'   => $this->pixel->get_user_info(),
				);

				$event = new Event( $event_data );

				$this->send_api_event( $event );

				$event_data['event_id'] = $event->get_id();

				$this->pixel->inject_event( $event_name, $event_data );
			}
		}


		/**
		 * Sends an API event.
		 *
		 * @since 2.0.0
		 *
		 * @param Event $event event object
		 * @param bool $send_now optional, defaults to true
		 */
		protected function send_api_event( Event $event, bool $send_now = true ) {
			$this->tracked_events[] = $event;

			if ( $send_now ) {
				try {
					facebook_for_woocommerce()->get_api()->send_pixel_events( facebook_for_woocommerce()->get_integration()->get_facebook_pixel_id(), array( $event ) );
				} catch ( ApiException $exception ) {
					facebook_for_woocommerce()->log( 'Could not send Pixel event: ' . $exception->getMessage() );
				}
			} else {
				$this->pending_events[] = $event;
			}
		}


		/**
		 * Gets advanced matching information from a given order
		 *
		 * @since 2.0.3
		 *
		 * @return array
		 */
		private function get_user_data_from_billing_address( $order ) {
			if ( $this->aam_settings == null || ! $this->aam_settings->get_enable_automatic_matching() ) {
				return array();
			}
			$user_data       = array();
			$user_data['fn'] = $order->get_billing_first_name();
			$user_data['ln'] = $order->get_billing_last_name();
			$user_data['em'] = $order->get_billing_email();
			// get_user_id() returns 0 if the current user is a guest
			$user_data['external_id'] = $order->get_user_id() === 0 ? null : strval( $order->get_user_id() );
			$user_data['zp']          = $order->get_billing_postcode();
			$user_data['st']          = $order->get_billing_state();
			// We can use country as key because this information is for CAPI events only
			$user_data['country'] = $order->get_billing_country();
			$user_data['ct']      = $order->get_billing_city();
			$user_data['ph']      = $order->get_billing_phone();
			// The fields contain country, so we do not need to add a condition
			foreach ( $user_data as $field => $value ) {
				if ( $value === null || $value === '' ||
					! in_array( $field, $this->aam_settings->get_enabled_automatic_matching_fields() )
				) {
					unset( $user_data[ $field ] );
				}
			}
			return $user_data;
		}

		/**
		 * Gets the pending events awaiting to be sent
		 *
		 * @return array
		 */
		public function get_pending_events() {
			return $this->pending_events;
		}

		/**
		 * Send pending events.
		 */
		public function send_pending_events() {

			$pending_events = $this->get_pending_events();

			if ( empty( $pending_events ) ) {
				return;
			}

			foreach ( $pending_events as $event ) {

				$this->send_api_event( $event );
			}
		}

	}

endif;
