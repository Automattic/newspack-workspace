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

	/**
	 * The script is revalidated every time, so turning the PWA plugin back on is not masked by an edge or browser copy.
	 */
	public function test_response_is_never_served_from_a_cache_without_revalidation() {
		$response = PWA::get_orphaned_worker_response( '/wp.serviceworker', false );
		$this->assertSame( 'no-cache, max-age=0', $response['headers']['Cache-Control'] );
	}

	/**
	 * The script never navigates or reloads a client, which would loop when the page registers the worker again.
	 */
	public function test_script_does_not_navigate_or_reload_clients() {
		$body = PWA::get_orphaned_worker_response( '/wp.serviceworker', false )['body'];
		foreach ( [ 'navigate', 'reload', 'location', 'postMessage' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $body );
		}
	}

	/**
	 * WordPress in /wordpress with the home at / serves the admin worker under the site path and the front worker under the home path.
	 */
	public function test_admin_worker_is_matched_under_a_site_path_that_differs_from_the_home_path() {
		$admin_worker = '/wordpress/wp-admin/admin-ajax.php?action=wp_service_worker';
		$this->assertNotNull( PWA::get_orphaned_worker_response( $admin_worker, false, '', '/wordpress' ) );
		$this->assertNotNull( PWA::get_orphaned_worker_response( '/wp.serviceworker', false, '', '/wordpress' ) );
		$this->assertNull( PWA::get_orphaned_worker_response( '/wordpress/wp.serviceworker', false, '', '/wordpress' ), 'The front worker is under the home path, not the site path.' );
		$this->assertNull( PWA::get_orphaned_worker_response( '/wp-admin/admin-ajax.php?action=wp_service_worker', false, '', '/wordpress' ), 'The admin worker is under the site path.' );
	}

	/**
	 * Only the exact worker URL shapes are answered: content that merely mentions the name, other plugins' workers and other actions are not.
	 */
	public function test_lookalike_urls_are_left_alone() {
		$lookalikes = [
			'/wp.serviceworker/',
			'/blog/wp.serviceworker',
			'/wp.serviceworker-news/',
			'/2026/wp-service-worker/',
			'/my-post/?wp_service_worker=1',
			'/?s=wp.serviceworker',
			'/?page_id=5&ref=wp_service_worker',
			'/?wp_service_worker=0',
			'/sw.js',
			'/newspack-sw.js',
			'/OneSignalSDKWorker.js',
			'/wp-admin/admin-ajax.php?action=wp_service_worker_other',
			'/wp-admin/admin-ajax.php?ref=wp_service_worker',
			'/wp-admin/admin-post.php?action=wp_service_worker',
			'/wp-admin/post.php?action=wp_service_worker',
			'/wp-json/wp.serviceworker',
		];
		foreach ( $lookalikes as $lookalike ) {
			$this->assertNull( PWA::get_orphaned_worker_response( $lookalike, false ), $lookalike );
		}
	}

	/**
	 * Logged-in users and wp-admin requests run the hook too; it answers only the exact worker URLs.
	 */
	public function test_hook_does_nothing_for_admin_requests_other_than_the_worker() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=heartbeat';
		PWA::maybe_serve_orphaned_worker_cleanup();
		$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php';
		PWA::maybe_serve_orphaned_worker_cleanup();
		$this->assertTrue( true, 'Reaching this line means the hook did not exit the request.' );
	}
}
