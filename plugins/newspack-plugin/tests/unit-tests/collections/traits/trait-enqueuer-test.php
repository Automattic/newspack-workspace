<?php
/**
 * Trait with common test helper methods for enqueuer testing.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections\Traits;

use Newspack\Collections\Enqueuer;

/**
 * Trait providing common test helper methods for enqueuer testing.
 *
 * This trait provides reusable methods for cleaning up enqueued scripts and styles
 * in unit tests.
 */
trait Trait_Enqueuer_Test {

	/**
	 * Clean up all enqueued scripts and styles for the Collections Enqueuer.
	 * Deregisters and dequeues both admin and frontend scripts and styles.
	 */
	protected function cleanup_enqueued_assets() {
		foreach ( [ Enqueuer::SCRIPT_NAME_ADMIN, Enqueuer::SCRIPT_NAME_FRONTEND ] as $script_name ) {
			wp_deregister_script( $script_name );
			wp_deregister_style( $script_name );
			wp_dequeue_script( $script_name );
			wp_dequeue_style( $script_name );
		}
	}

	/**
	 * Clean up enqueued assets for a specific script name.
	 *
	 * @param string $script_name The script name to clean up.
	 */
	protected function cleanup_enqueued_assets_for_script( $script_name ) {
		wp_deregister_script( $script_name );
		wp_deregister_style( $script_name );
		wp_dequeue_script( $script_name );
		wp_dequeue_style( $script_name );
	}

	/**
	 * Reset the Enqueuer's internal data state via reflection.
	 */
	protected function reset_enqueuer_data() {
		$reflection = new \ReflectionClass( Enqueuer::class );
		$reflection->setStaticPropertyValue( 'data', [] );
	}

	/**
	 * Clean up enqueued assets and reset enqueuer data. Convenience method.
	 */
	protected function cleanup_enqueuer_state() {
		$this->cleanup_enqueued_assets();
		$this->reset_enqueuer_data();
	}
}
