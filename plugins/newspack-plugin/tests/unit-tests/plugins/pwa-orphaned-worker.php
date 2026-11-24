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
	 * With the PWA plugin off, every URL it served the worker from gets a 200 script that unregisters the worker,
	 * revalidated on every request so that turning the plugin back on is not masked by an edge or browser copy.
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
			$this->assertSame( 'no-cache, max-age=0', $response['headers']['Cache-Control'], $worker_url );
			$this->assertStringContainsString( 'registration.unregister()', $response['body'], $worker_url );
		}
	}

	/**
	 * While the PWA plugin is on, its own worker is served, so nothing is answered here.
	 */
	public function test_active_plugin_is_left_alone() {
		$this->assertNull( PWA::get_orphaned_worker_response( '/wp.serviceworker', true ) );
	}

	/**
	 * The script never claims, navigates or reloads a client, which would loop when the page registers the worker again.
	 */
	public function test_script_does_not_touch_clients() {
		$body = PWA::get_orphaned_worker_response( '/wp.serviceworker', false )['body'];
		foreach ( [ 'clients.claim', 'navigate', 'reload', 'location', 'postMessage' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $body );
		}
	}

	/**
	 * The front worker is matched under the home path and the admin worker under the site path, e.g. a site in /news,
	 * or WordPress in /wordpress with the home at /.
	 */
	public function test_worker_urls_are_matched_under_the_home_and_site_paths() {
		$this->assertNotNull( PWA::get_orphaned_worker_response( '/news/wp.serviceworker', false, '/news' ) );

		$admin_worker = '/wordpress/wp-admin/admin-ajax.php?action=wp_service_worker';
		$this->assertNotNull( PWA::get_orphaned_worker_response( $admin_worker, false, '', '/wordpress' ) );
		$this->assertNotNull( PWA::get_orphaned_worker_response( '/wp.serviceworker', false, '', '/wordpress' ) );
		$this->assertNull( PWA::get_orphaned_worker_response( '/wordpress/wp.serviceworker', false, '', '/wordpress' ), 'The front worker is under the home path, not the site path.' );
		$this->assertNull( PWA::get_orphaned_worker_response( '/wp-admin/admin-ajax.php?action=wp_service_worker', false, '', '/wordpress' ), 'The admin worker is under the site path.' );
	}

	/**
	 * Only the exact worker URL shapes are answered: pages, content that merely mentions the name, other plugins' workers and other actions are not.
	 */
	public function test_other_urls_are_left_alone() {
		$other_urls = [
			'/',
			'/sample-page/',
			'/wp.serviceworker.js',
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
			'/wp-admin/admin-ajax.php?action=heartbeat',
			'/wp-admin/admin-ajax.php?action=wp_service_worker_other',
			'/wp-admin/admin-ajax.php?ref=wp_service_worker',
			'/wp-admin/admin-post.php?action=wp_service_worker',
			'/wp-admin/post.php?action=wp_service_worker',
			'/wp-json/wp.serviceworker',
		];
		foreach ( $other_urls as $other_url ) {
			$this->assertNull( PWA::get_orphaned_worker_response( $other_url, false ), $other_url );
		}
	}

	/**
	 * The hook runs on every request, including wp-admin; it answers only the exact worker URLs.
	 */
	public function test_hook_does_nothing_for_requests_other_than_the_worker() {
		$this->expectNotToPerformAssertions();
		$original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		try {
			foreach ( [ '/wp-admin/admin-ajax.php?action=heartbeat', '/wp-admin/options-general.php' ] as $request_uri ) {
				$_SERVER['REQUEST_URI'] = $request_uri;
				PWA::maybe_serve_orphaned_worker_cleanup();
			}
		} finally {
			if ( null === $original_request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original_request_uri;
			}
		}
	}
}
