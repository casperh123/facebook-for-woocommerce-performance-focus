<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\API;

use WooCommerce\Facebook\Framework\Api\JSONRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Base API request object.
 *
 * @since 2.0.0
 */
class Request extends JSONRequest {

	use Traits\Rate_Limited_Request;

	/**
	 * API request constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path endpoint route
	 * @param string $method HTTP method
	 */
	public function __construct( $path, $method ) {
		$this->method = $method;
		$this->path   = $path;
	}


	/**
	 * Sets the request parameters.
	 *
	 * @since 2.0.0
	 *
	 * @param array $params request parameters
	 */
	public function set_params( $params ) {
		$this->params = $params;
	}


	/**
	 * Sets the request data.
	 *
	 * @since 2.0.0
	 *
	 * @param array $data request data
	 */
	public function set_data( $data ) {
		$this->data = $data;
	}
}
