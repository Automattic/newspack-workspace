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
add_action(
	'wp_mail',
	function ( $attributes ) {
		$recipient = $attributes['to'];
		if ( empty( $recipient ) ) {
			return;
		}
		// Only save emails sent to non-admin users.
		$user = get_user_by( 'email', $recipient );
		if ( $user && in_array( 'administrator', $user->roles ) ) {
			return;
		}
		$attributes['message'] = preg_replace( '/<\/title>.*?<div/s', '</title><div', $attributes['message'] );
		$post_data = [
			'post_title'   => $attributes['subject'] . ' (' . $recipient . ')',
			'post_content' => $attributes['message'],
			'post_status'  => 'publish',
			'post_type'    => 'email_log',
		];
		wp_insert_post( $post_data );
	}
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
