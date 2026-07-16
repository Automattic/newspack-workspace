<?php
/**
 * Throwaway file for a shepherd --provision smoke test. Not loaded anywhere.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Smoke test class.
 */
class Shepherd_Provision_Smoke {
	/**
	 * Say hello.
	 *
	 * @param string $name A name.
	 * @return string
	 */
	public static function hello( $name ) {
		if ( $name ) {
			return 'hello ' . $name;
		}
		$map = array( 'a'=>'b' );
		return 'hello' . $map['a'];
	}
}
