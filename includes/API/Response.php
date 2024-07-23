<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace WooCommerce\Facebook\API;

use WooCommerce\Facebook\Framework\Api\JSONResponse;

defined( 'ABSPATH' ) || exit;

/**
 * Base API response object
 *
 * @since 2.0.0
 */
class Response extends JSONResponse {

	use Traits\Rate_Limited_Response;

	/**
	 * Gets the response ID.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}
}
