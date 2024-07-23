<?php
/**
 * Facebook for WooCommerce.
 */

namespace WooCommerce\Facebook\Framework\Api;

defined('ABSPATH') || exit;

/**
 * API Request
 */
interface Request
{
	/**
	 * Returns the method for this request: one of HEAD, GET, PUT, PATCH, POST, DELETE
	 *
	 * @return string the request method, or null to use the API default
	 * @since 4.0.0
	 */
	public function get_method();


	/**
	 * Returns the request path
	 *
	 * @return string the request path, or '' if none
	 * @since 4.0.0
	 */
	public function get_path();


	/**
	 * Gets the request query params.
	 *
	 * @return array
	 * @since 5.0.0
	 *
	 */
	public function get_params();


	/**
	 * Gets the request data.
	 *
	 * @return array
	 * @since 5.0.0
	 *
	 */
	public function get_data();


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
