<?php
/**
 * Facebook for WooCommerce.
 */

namespace WooCommerce\Facebook\Framework\Api;

defined('ABSPATH') || exit;

/**
 * API Response
 */
interface Response
{


	/**
	 * Returns the string representation of this request
	 *
	 * @return string the request
	 * @since 2.2.0
	 */
	public function to_string();


	/**
	 * Returns the string representation of this request with any and all
	 * sensitive elements masked or removed
	 *
	 * @return string the request, safe for logging/displaying
	 * @since 2.2.0
	 */
	public function to_string_safe();

}
