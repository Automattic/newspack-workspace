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

/*
 * Refuse to do anything unless the site was provisioned as an e2e target.
 *
 * Everything below is unsafe outside a throwaway site: /_email exposes every
 * captured message — password-reset and magic links included — pre_wp_mail
 * swallows all outgoing mail while reporting success, and the logout endpoint
 * answers without a nonce. Two safeguards keep that contained: this constant
 * gate makes a stray copy onto any other site inert, and /_email additionally
 * requires the per-run NEWSPACK_E2E_SENDBOX_SECRET, so an e2e host that is
 * internet-reachable (staging) still won't hand its captured mail to anyone.
 *
 * e2e-setup.sh writes both constants to wp-config.php before it installs and
 * activates this plugin, so a correctly provisioned site always has them.
 */
if ( ! defined( 'NEWSPACK_IS_E2E' ) || ! NEWSPACK_IS_E2E ) {
	return;
}

// Prevent the admin email confirmation screen.
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
			error_log( 'Failed to create the email_log CPT.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Test harness; surfacing setup failure in the container log is the point.
		}
	}
);

// Enable logout without nonce.
add_action(
	'init',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Logging out without a nonce is precisely what this e2e-only endpoint provides.
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'logout_without_nonce' ) {
			wp_logout();
			wp_safe_redirect( home_url() );
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against a literal prefix and never output; a sanitizer would alter the string being matched.
		if ( isset( $_SERVER['REQUEST_URI'] ) && str_starts_with( wp_unslash( $_SERVER['REQUEST_URI'] ), '/_email' ) ) {
			// The sendbox dumps every captured outgoing email — including reader
			// password-reset and account-verification links — so it must never be
			// served to an unauthenticated visitor. Gate it behind a per-run shared
			// secret that provisioning writes to the NEWSPACK_E2E_SENDBOX_SECRET
			// constant, and fail closed (403) — before emitting any email content —
			// whenever that constant is unset/empty or the request does not present
			// a matching `secret` query arg. hash_equals keeps the compare
			// timing-safe.
			$configured_secret = defined( 'NEWSPACK_E2E_SENDBOX_SECRET' ) ? (string) constant( 'NEWSPACK_E2E_SENDBOX_SECRET' ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only, secret-gated endpoint so a nonce doesn't apply; the value is compared via hash_equals against a configured secret and never output, and sanitizing would alter the string being matched.
			$provided_secret = isset( $_GET['secret'] ) ? (string) wp_unslash( $_GET['secret'] ) : '';
			if ( '' === $configured_secret || ! hash_equals( $configured_secret, $provided_secret ) ) {
				status_header( 403 );
				header( 'Content-Type: text/plain' );
				echo 'Forbidden';
				exit;
			}
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DirectQuery: reads every captured email in one pass, whatever its post status. NoCaching: the sendbox must see rows the test wrote moments ago.
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
								<?php echo $email['post_content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured email HTML is rendered verbatim so tests can assert on its markup. ?>
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
