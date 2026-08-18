<?php
/**
 * Trait for resetting Content_Restriction_Control's request-scoped caches.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate\Traits;

use Newspack\Content_Restriction_Control;

/**
 * Trait providing a reset for Content_Restriction_Control's request-scoped caches.
 *
 * The caches are keyed by post ID and assume the gate configuration is stable for
 * the lifetime of the request — true in production, false across test cases, which
 * roll back and reuse post IDs. A gate lookup cached by an earlier case would
 * otherwise be served to the next one, reporting "no gates" for a post that has one.
 * The caches are private, deliberately: nothing in production needs to discard them,
 * so the reset stays in the test layer rather than widening the class's API.
 */
trait Trait_Restriction_Cache_Test {

	/**
	 * Discard every request-scoped cache on Content_Restriction_Control.
	 */
	protected function reset_restriction_cache() {
		foreach ( [ 'post_gate_id_map', 'post_gate_layout_id_map', 'post_gates_map', 'term_descendants_map' ] as $cache_property ) {
			$cache_property_reflection = new \ReflectionProperty( Content_Restriction_Control::class, $cache_property );
			$cache_property_reflection->setAccessible( true );
			$cache_property_reflection->setValue( null, [] );
		}
	}
}
