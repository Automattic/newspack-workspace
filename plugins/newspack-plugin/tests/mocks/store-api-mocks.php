<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Mocks for the WooCommerce Store API.
 *
 * @package Newspack\Tests
 */

namespace Automattic\WooCommerce\StoreApi\Exceptions;

if ( ! class_exists( __NAMESPACE__ . '\RouteException' ) ) {
	/**
	 * Mirrors the Store API's RouteException: the exception a validation hook throws
	 * to reject a request, carrying the REST error code and HTTP status it maps to.
	 */
	class RouteException extends \Exception {
		/**
		 * REST error code.
		 *
		 * @var string
		 */
		public $error_code;

		/**
		 * HTTP status code.
		 *
		 * @var int
		 */
		public $http_status_code;

		/**
		 * Constructor.
		 *
		 * @param string $error_code       REST error code.
		 * @param string $message          Error message.
		 * @param int    $http_status_code HTTP status code.
		 */
		public function __construct( $error_code, $message, $http_status_code = 400 ) {
			$this->error_code       = $error_code;
			$this->http_status_code = $http_status_code;
			parent::__construct( $message, $http_status_code );
		}

		/**
		 * REST error code.
		 *
		 * @return string
		 */
		public function getErrorCode() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Store API's method name.
			return $this->error_code;
		}
	}
}
