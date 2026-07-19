<?php
/**
 * Plugin Name: Newspack E2E Plugin
 * Description: Special considerations for E2E testing.
 * Version: 0.0.0
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL2
 * Text Domain: newspack-e2e-plugin
 * Domain Path: /languages/
 *
 * @package Newspack_E2E_Plugin
 */

defined( 'ABSPATH' ) || exit;

// Prevent the admin email confirmation screen
add_filter( 'admin_email_check_interval', '__return_false' );

// Register custom post type for email logs.
add_action(
	'init',
	function () {
		$args = [
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'email_log' ],
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => [ 'title', 'editor', 'author', 'custom-fields' ],
		];
		$result = register_post_type( 'email_log', $args );
		if ( is_wp_error( $result ) ) {
			error_log( 'Failed to create the email_log CPT.' );
		}
	}
);

// Enable logout without nonce.
add_action(
	'init',
	function () {
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'logout_without_nonce' ) {
			wp_logout();
			wp_redirect( home_url() );
			exit;
		}
	}
);

// Save outgoing emails as email_log CPT.
// Capture outgoing mail into the sendbox AND short-circuit the real send.
// Using `pre_wp_mail` (not the `wp_mail` action) lets us return success without
// actually sending, so wp_mail() always reports true. Reader flows that branch
// on wp_mail()'s return value — password reset, email change, account
// verification — then show their success notice regardless of the site's real
// mail deliverability; the captured copy in the sendbox is the suite's source of
// truth. Hooking the action instead left the real (often failing) send in play,
// which surfaced as "the email could not be sent" and broke those flows.
add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $attributes ) {
		$recipient = is_array( $attributes['to'] ?? '' ) ? ( $attributes['to'][0] ?? '' ) : ( $attributes['to'] ?? '' );
		// No recipient: leave the value untouched so core still validates the
		// input and returns false, rather than reporting a bogus success.
		if ( empty( $recipient ) ) {
			return $short_circuit;
		}
		// Only save emails sent to non-admin users.
		$user = get_user_by( 'email', $recipient );
		if ( ! ( $user && in_array( 'administrator', $user->roles, true ) ) ) {
			$message = preg_replace( '/<\/title>.*?<div/s', '</title><div', $attributes['message'] );
			wp_insert_post(
				[
					'post_title'   => $attributes['subject'] . ' (' . $recipient . ')',
					'post_content' => $message,
					'post_status'  => 'publish',
					'post_type'    => 'email_log',
				]
			);
		}
		// Report success without a real send.
		return true;
	},
	10,
	2
);

// Display all sent emails.
add_action(
	'init',
	function () {
		if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/_email' ) === 0 ) {
			header( 'Content-Type: text/html' );
			?>
			<html><head><title>Email Sendbox</title></head><body>
			<h1>Email Sendbox</h1>
			<style>
				.email-content{
					border: 1px solid gray;
					margin: 20px 0;
				}
			</style>
			<?php

			global $wpdb;

			$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}posts WHERE post_type = 'email_log' ORDER BY post_date DESC", ARRAY_A );

			if ( ! empty( $results ) ) {
				foreach ( $results as $email ) {
					?>
					<br>
					<div>
						<details>
							<summary>
								<strong><?php echo esc_html( $email['post_title'] ); ?></strong> - <?php echo esc_html( $email['post_date'] ); ?>
							</summary>
							<div class="email-content">
								<?php echo $email['post_content']; ?>
							</div>
						</details>
					</div>
					<?php
				}
			} else {
				?>
				<p>No emails found.</p>
				<?php
			}
			?>
			</body></html>
			<?php

			exit;
		}
	}
);
