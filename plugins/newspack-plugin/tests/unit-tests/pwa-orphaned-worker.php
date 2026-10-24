<?php
/**
 * Tests for cleaning up an orphaned PWA service worker.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use Newspack\PWA;

/**
 * A browser that registered the PWA plugin's worker keeps re-fetching its URL after the plugin is turned off.
 *
 * @group pwa
 */
class Test_PWA_Orphaned_Worker extends \WP_UnitTestCase {

	/**
	 * With the PWA plugin off, every URL it served the worker from gets a 200 script that unregisters the worker.
	 */
	public function test_inactive_plugin_serves_unregistering_worker_on_every_worker_url() {
		$worker_urls = [
			'/wp.serviceworker',
			'/wp.serviceworker?ver=1',
			'/index.php/wp.serviceworker',
			'/?wp_service_worker=1',
			'/wp-admin/admin-ajax.php?action=wp_service_worker',
		];
		foreach ( $worker_urls as $worker_url ) {
			$response = PWA::get_orphaned_worker_response( $worker_url, false );
			$this->assertNotNull( $response, $worker_url );
			$this->assertStringStartsWith( 'application/javascript', $response['headers']['Content-Type'], $worker_url );
			$this->assertStringContainsString( 'registration.unregister()', $response['body'], $worker_url );
			$this->assertStringNotContainsString( 'clients.claim', $response['body'], $worker_url );
		}
	}

	/**
	 * Pages and other admin-ajax actions are not answered, and a site in a subdirectory is matched under its path.
	 */
	public function test_other_requests_are_left_alone() {
		foreach ( [ '/', '/sample-page/', '/wp.serviceworker.js', '/wp-admin/admin-ajax.php?action=heartbeat', '/news/wp.serviceworker' ] as $other_url ) {
			$this->assertNull( PWA::get_orphaned_worker_response( $other_url, false ), $other_url );
		}
		$this->assertNotNull( PWA::get_orphaned_worker_response( '/news/wp.serviceworker', false, '/news' ) );
	}

	/**
	 * While the PWA plugin is on, its own worker is served, so nothing is answered here.
	 */
	public function test_active_plugin_is_left_alone() {
		$this->assertNull( PWA::get_orphaned_worker_response( '/wp.serviceworker', true ) );
	}
}
