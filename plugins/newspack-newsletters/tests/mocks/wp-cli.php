<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files, WordPress.PHP.DevelopmentFunctions, WordPress.Security

class WP_CLI {
	public static $output = [
		'log'     => [],
		'warning' => [],
		'error'   => [],
		'success' => [],
	];
	public static function log( $arg ) {
		self::$output['log'][] = $arg;
	}
	public static function warning( $arg ) {
		self::$output['warning'][] = $arg;
	}
	public static function error( $arg ) {
		self::$output['error'][] = $arg;
		throw new Exception( $arg );
	}
	public static function success( $arg ) {
		self::$output['success'][] = $arg;
	}
	public static function get_test_output( $type = null ) {
		return $type ? self::$output[ $type ] : self::$output;
	}
}
