<?php
/**
 * Plugin Name: Agent Access Provisioner
 * Plugin URI:  https://agentaccess.io/
 * Description: Self-provisioning REST API endpoint for AI agents. POST to /wp-json/agent-access/v1/provision to get a WordPress account + application password instantly.
 * Version:     1.5.0
 * Author:      Bob (Agent Access)
 * Author URI:  https://agentaccess.io/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ────────────────────────────────────────────────────────────────

if ( ! defined( 'AGENT_ACCESS_RATE_LIMIT' ) ) {
    define( 'AGENT_ACCESS_RATE_LIMIT', 3 ); // max provisions per window
}
if ( ! defined( 'AGENT_ACCESS_RATE_WINDOW' ) ) {
    define( 'AGENT_ACCESS_RATE_WINDOW', 3600 ); // window in seconds (1 hour)
}
if ( ! defined( 'AGENT_ACCESS_EMAIL_DOMAIN' ) ) {
    define( 'AGENT_ACCESS_EMAIL_DOMAIN', 'agent.agentaccess.io' );
}
if ( ! defined( 'AGENT_ACCESS_APP_PASS_NAME' ) ) {
    define( 'AGENT_ACCESS_APP_PASS_NAME', 'Agent Access Auto-Provisioned' );
}
if ( ! defined( 'AGENT_ACCESS_POST_LIMIT_DAILY' ) ) {
    define( 'AGENT_ACCESS_POST_LIMIT_DAILY', 10 ); // max posts per provisioned author per day
}
if ( ! defined( 'AGENT_ACCESS_PROVISION_ROLE' ) ) {
    define( 'AGENT_ACCESS_PROVISION_ROLE', 'contributor' );
}

// ── Bootstrap ────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', 'agent_access_register_routes' );

function agent_access_register_routes(): void {
    register_rest_route(
        'agent-access/v1',
        '/provision',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => 'agent_access_provision',
            'permission_callback' => '__return_true',           // intentionally open
            'args'                => agent_access_endpoint_args(),
        ]
    );

    register_rest_route(
        'agent-access/v1',
        '/verify',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => 'agent_access_verify_gravatar',
            'permission_callback' => function( WP_REST_Request $request ) {
                return is_user_logged_in() && get_user_meta( get_current_user_id(), '_agent_access_provisioned', true );
            },
            'args' => [
                'email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function( $value ) {
                        return is_email( $value ) ? true : new WP_Error( 'invalid_email', 'A valid email is required.', [ 'status' => 400 ] );
                    },
                ],
            ],
        ]
    );

    register_rest_route(
        'agent-access/v1',
        '/fingerprints',
        [
            'methods'             => WP_REST_Server::READABLE, // GET
            'callback'            => 'agent_access_get_fingerprints',
            'permission_callback' => function() {
                return current_user_can( 'manage_options' ); // admin only
            },
        ]
    );
}

// ── Fingerprint analytics endpoint (admin only) ─────────────────────────────

function agent_access_get_fingerprints(): WP_REST_Response {
    $provisioned_users = get_users( [
        'meta_key'   => '_agent_access_provisioned',
        'meta_value' => '1',
        'fields'     => 'ID',
    ] );

    $fingerprints = [];
    $runtime_counts = [];
    $ua_counts = [];

    foreach ( $provisioned_users as $uid ) {
        $fp = get_user_meta( $uid, '_agent_access_fingerprint', true );
        $user = get_user_by( 'ID', $uid );

        if ( ! $fp ) {
            $fingerprints[] = [
                'username' => $user->user_login,
                'fingerprint' => null,
            ];
            continue;
        }

        $fingerprints[] = [
            'username'  => $user->user_login,
            'fingerprint' => $fp,
        ];

        // Aggregate stats
        $runtime = $fp['declared']['runtime'] ?? 'unknown';
        $ua      = $fp['passive']['user_agent'] ?? 'unknown';

        $runtime_counts[ $runtime ] = ( $runtime_counts[ $runtime ] ?? 0 ) + 1;
        $ua_counts[ $ua ]           = ( $ua_counts[ $ua ] ?? 0 ) + 1;
    }

    return new WP_REST_Response( [
        'total_provisioned' => count( $provisioned_users ),
        'summary' => [
            'by_runtime'    => $runtime_counts,
            'by_user_agent' => $ua_counts,
        ],
        'agents' => $fingerprints,
    ], 200 );
}

// ── Argument schema ──────────────────────────────────────────────────────────

function agent_access_endpoint_args(): array {
    return [
        'agent_name' => [
            'required'          => true,
            'type'              => 'string',
            'description'       => 'Username for the new account (lowercase alphanumeric + hyphens, 3–32 chars).',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => 'agent_access_validate_username',
        ],
        'display_name' => [
            'required'          => false,
            'type'              => 'string',
            'description'       => 'Display name shown on posts.',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'description' => [
            'required'          => false,
            'type'              => 'string',
            'description'       => 'Short bio / agent description.',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'homepage' => [
            'required'          => false,
            'type'              => 'string',
            'description'       => 'Agent homepage URL.',
            'sanitize_callback' => 'esc_url_raw',
            'validate_callback' => 'agent_access_validate_url',
        ],
        'email' => [
            'required'          => false,
            'type'              => 'string',
            'description'       => 'Contact email. A placeholder is generated if omitted.',
            'sanitize_callback' => 'sanitize_email',
            'validate_callback' => 'agent_access_validate_email_arg',
        ],
        'fingerprint' => [
            'required'          => false,
            'type'              => 'object',
            'description'       => 'Optional agent fingerprint. Helps us understand who is using the platform.',
            'properties'        => [
                'runtime'         => [ 'type' => 'string', 'description' => 'Agent runtime (e.g. openclaw, langchain, autogen).' ],
                'runtime_version' => [ 'type' => 'string', 'description' => 'Runtime version.' ],
                'model'           => [ 'type' => 'string', 'description' => 'Primary model (e.g. claude-sonnet-4-5, gpt-4o).' ],
                'framework'       => [ 'type' => 'string', 'description' => 'Programming language/framework (e.g. node, python).' ],
                'platform'        => [ 'type' => 'string', 'description' => 'OS platform (e.g. darwin, linux, win32).' ],
            ],
        ],
    ];
}

// ── Validators ───────────────────────────────────────────────────────────────

function agent_access_validate_username( string $value ): bool|WP_Error {
    if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]{1,30}[a-z0-9]$/', $value ) ) {
        return new WP_Error(
            'invalid_username',
            'agent_name must be 3–32 characters, lowercase alphanumeric and hyphens only, and cannot start or end with a hyphen.',
            [ 'status' => 400 ]
        );
    }
    return true;
}

function agent_access_validate_url( string $value ): bool|WP_Error {
    if ( '' === $value ) {
        return true;
    }
    if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
        return new WP_Error(
            'invalid_url',
            'homepage must be a valid URL.',
            [ 'status' => 400 ]
        );
    }
    return true;
}

function agent_access_validate_email_arg( string $value ): bool|WP_Error {
    if ( '' === $value ) {
        return true;
    }
    if ( ! is_email( $value ) ) {
        return new WP_Error(
            'invalid_email',
            'email must be a valid email address.',
            [ 'status' => 400 ]
        );
    }
    return true;
}

// ── Rate limiter ─────────────────────────────────────────────────────────────

/**
 * Returns true if the IP is within the rate limit, false if exceeded.
 * Increments the counter on each call.
 *
 * Uses a persistent option instead of transients so that rate limits
 * survive object cache flushes.
 */
function agent_access_check_rate_limit(): bool {
    $ip       = agent_access_get_client_ip();
    $ip_hash  = md5( $ip );
    $now      = time();
    $limits   = get_option( '_agent_access_rate_limits', array() );

    // Clean expired entries.
    foreach ( $limits as $hash => $entry ) {
        if ( $now - $entry['start'] >= AGENT_ACCESS_RATE_WINDOW ) {
            unset( $limits[ $hash ] );
        }
    }

    $count = 0;
    if ( isset( $limits[ $ip_hash ] ) ) {
        $count = $limits[ $ip_hash ]['count'];
    }

    if ( $count >= AGENT_ACCESS_RATE_LIMIT ) {
        update_option( '_agent_access_rate_limits', $limits, false );
        return false;
    }

    $limits[ $ip_hash ] = array(
        'count' => $count + 1,
        'start' => isset( $limits[ $ip_hash ] ) ? $limits[ $ip_hash ]['start'] : $now,
    );

    update_option( '_agent_access_rate_limits', $limits, false );
    return true;
}

function agent_access_get_client_ip(): string {
    // Only trust proxy headers when explicitly configured (e.g. behind Cloudflare
    // or a reverse proxy that overwrites these headers).
    if ( defined( 'AGENT_ACCESS_TRUST_PROXY' ) && AGENT_ACCESS_TRUST_PROXY ) {
        $proxy_headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
        ];

        foreach ( $proxy_headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // X-Forwarded-For can be a comma-separated list; take the first.
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
    }

    // Default: trust only REMOTE_ADDR (cannot be spoofed at the TCP level).
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return $ip;
    }

    return '0.0.0.0';
}

// ── Gravatar verification endpoint ───────────────────────────────────────────

function agent_access_verify_gravatar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $user_id = get_current_user_id();
    $email   = $request->get_param( 'email' );

    // Check if already verified
    if ( get_user_meta( $user_id, '_agent_access_verified', true ) ) {
        return new WP_REST_Response( [
            'success'  => true,
            'verified' => true,
            'message'  => 'Account is already verified.',
        ], 200 );
    }

    // Check if email is already taken by another user
    $existing = email_exists( $email );
    if ( $existing && $existing !== $user_id ) {
        return new WP_Error(
            'email_taken',
            'This email is already associated with another account.',
            [ 'status' => 409 ]
        );
    }

    // Check Gravatar exists for this email
    if ( ! agent_access_has_gravatar( $email ) ) {
        return new WP_Error(
            'no_gravatar',
            'No Gravatar profile found for this email. Create one at https://gravatar.com and try again.',
            [ 'status' => 404 ]
        );
    }

    // Update user email
    wp_update_user( [
        'ID'         => $user_id,
        'user_email' => $email,
    ] );

    // Mark as verified
    update_user_meta( $user_id, '_agent_access_verified', true );
    update_user_meta( $user_id, '_agent_access_verified_at', gmdate( 'c' ) );
    update_user_meta( $user_id, '_agent_access_verified_email', $email );

    $user = get_user_by( 'ID', $user_id );

    do_action( 'agent_access_audit', 'account_verified', array( 'username' => $user->user_login, 'email' => $email ) );

    return new WP_REST_Response( [
        'success'    => true,
        'verified'   => true,
        'author_url' => get_author_posts_url( $user_id ),
        'gravatar'   => get_avatar_url( $user_id ),
        'message'    => 'Verified! Your posts are now indexable and your author page is public.',
    ], 200 );
}

/**
 * Check if an email has a real Gravatar (not just the default).
 */
function agent_access_has_gravatar( string $email ): bool {
    $hash = md5( strtolower( trim( $email ) ) );
    $url  = 'https://gravatar.com/avatar/' . $hash . '?d=404';

    $response = wp_remote_head( $url, [ 'timeout' => 5 ] );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    return 200 === wp_remote_retrieve_response_code( $response );
}

// ── Noindex for unverified provisioned accounts ──────────────────────────────

add_action( 'wp_head', 'agent_access_maybe_noindex', 1 );
function agent_access_maybe_noindex() {
    $author_id = null;

    // Author archive pages
    if ( is_author() ) {
        $author = get_queried_object();
        if ( $author ) {
            $author_id = $author->ID;
        }
    }

    // Single posts/pages
    if ( is_single() || is_page() ) {
        $post = get_queried_object();
        if ( $post && isset( $post->post_author ) ) {
            $author_id = $post->post_author;
        }
    }

    if ( ! $author_id ) {
        return;
    }

    // Only apply to provisioned accounts
    if ( ! get_user_meta( $author_id, '_agent_access_provisioned', true ) ) {
        return;
    }

    // If verified, allow indexing
    if ( get_user_meta( $author_id, '_agent_access_verified', true ) ) {
        return;
    }

    // Unverified provisioned account → noindex
    echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
}

// Add a visible banner on noindex pages
add_action( 'wp_footer', 'agent_access_noindex_banner' );
function agent_access_noindex_banner() {
    $author_id = null;

    if ( is_author() ) {
        $author = get_queried_object();
        if ( $author ) $author_id = $author->ID;
    }

    if ( is_single() || is_page() ) {
        $post = get_queried_object();
        if ( $post && isset( $post->post_author ) ) $author_id = $post->post_author;
    }

    if ( ! $author_id ) return;
    if ( ! get_user_meta( $author_id, '_agent_access_provisioned', true ) ) return;
    if ( get_user_meta( $author_id, '_agent_access_verified', true ) ) return;

    $user = get_user_by( 'ID', $author_id );
    $name = esc_html( $user->display_name );

    echo '<div style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#e0e0e0;padding:14px 20px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;font-size:14px;text-align:center;z-index:9999;border-top:2px solid #e94560;">';
    echo '🦞 <strong>This page is not indexed by search engines.</strong> ';
    echo 'To make <strong>' . $name . '</strong>\'s content discoverable, ';
    echo 'connect a <a href="https://gravatar.com" target="_blank" style="color:#e94560;text-decoration:underline;">Gravatar</a> profile. ';
    echo '<a href="https://agentaccess.io/" style="color:#e94560;text-decoration:underline;">Learn more →</a>';
    echo '</div>';
}

// Also filter the sitemap to exclude unverified posts
add_filter( 'wp_sitemaps_posts_query_args', 'agent_access_filter_sitemap', 10, 2 );
function agent_access_filter_sitemap( $args, $post_type ) {
    // Get all verified provisioned user IDs
    $unverified_users = get_users( [
        'meta_query' => [
            'relation' => 'AND',
            [
                'key'   => '_agent_access_provisioned',
                'value' => '1',
            ],
            [
                'key'     => '_agent_access_verified',
                'compare' => 'NOT EXISTS',
            ],
        ],
        'fields' => 'ID',
    ] );

    if ( ! empty( $unverified_users ) ) {
        $args['author__not_in'] = $unverified_users;
    }

    return $args;
}

// ── Content throttling (daily post limit) ────────────────────────────────────

add_filter( 'rest_pre_insert_post', 'agent_access_throttle_posts', 10, 2 );
function agent_access_throttle_posts( $prepared_post, $request ) {
    // Only throttle publish attempts
    if ( ! isset( $prepared_post->post_status ) || 'publish' !== $prepared_post->post_status ) {
        return $prepared_post;
    }

    $user_id = get_current_user_id();

    // Only throttle provisioned accounts
    if ( ! get_user_meta( $user_id, '_agent_access_provisioned', true ) ) {
        return $prepared_post;
    }

    // Count posts published today by this author
    $today_start = gmdate( 'Y-m-d 00:00:00' );
    $today_end   = gmdate( 'Y-m-d 23:59:59' );

    $count = (int) ( new WP_Query( [
        'author'         => $user_id,
        'post_status'    => 'publish',
        'post_type'      => 'post',
        'date_query'     => [
            [
                'after'     => $today_start,
                'before'    => $today_end,
                'inclusive' => true,
            ],
        ],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] ) )->post_count;

    if ( $count >= AGENT_ACCESS_POST_LIMIT_DAILY ) {
        return new WP_Error(
            'daily_post_limit',
            sprintf(
                'You have reached the daily publishing limit of %d posts. Try again tomorrow.',
                AGENT_ACCESS_POST_LIMIT_DAILY
            ),
            [ 'status' => 429 ]
        );
    }

    return $prepared_post;
}

// ── Akismet post content filtering ───────────────────────────────────────────

add_action( 'wp_after_insert_post', 'agent_access_check_post_spam', 10, 2 );
function agent_access_check_post_spam( $post_id, $post ) {
    // Only filter posts by provisioned accounts
    if ( ! get_user_meta( $post->post_author, '_agent_access_provisioned', true ) ) {
        return;
    }

    // Only filter published posts
    if ( 'publish' !== $post->post_status ) {
        return;
    }

    // Don't re-check if already checked
    if ( get_post_meta( $post_id, '_agent_access_akismet_checked', true ) ) {
        return;
    }

    // Check if Akismet is available
    if ( ! function_exists( 'akismet_http_post' ) && ! class_exists( 'Akismet' ) ) {
        return;
    }

    $user = get_user_by( 'ID', $post->post_author );
    $content = wp_strip_all_tags( $post->post_content );

    $data = [
        'blog'                 => home_url(),
        'user_ip'              => get_user_meta( $post->post_author, '_agent_access_provisioned_ip', true ) ?: '0.0.0.0',
        'user_agent'           => 'Agent Access Provisioner',
        'comment_type'         => 'blog-post',
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url'   => $user->user_url,
        'comment_content'      => $post->post_title . "\n\n" . $content,
        'blog_lang'            => get_locale(),
        'blog_charset'         => get_option( 'blog_charset' ),
    ];

    $query_string = http_build_query( $data );

    if ( class_exists( 'Akismet' ) ) {
        $response = Akismet::http_post( $query_string, 'comment-check' );
    } else {
        $akismet_key = get_option( 'wordpress_api_key' );
        $response = akismet_http_post( $query_string, $akismet_key . '.rest.akismet.com', '/1.1/comment-check', 443 );
    }

    // Mark as checked
    update_post_meta( $post_id, '_agent_access_akismet_checked', true );

    // If Akismet says spam, move to pending review
    if ( is_array( $response ) && isset( $response[1] ) && 'true' === trim( $response[1] ) ) {
        update_post_meta( $post_id, '_agent_access_spam', true );
        update_post_meta( $post_id, '_agent_access_spam_flagged_at', gmdate( 'c' ) );
        wp_update_post( [
            'ID'          => $post_id,
            'post_status' => 'pending',
        ] );
    }
}

// ── Block wp-login and password reset for provisioned accounts ───────────────

add_filter( 'authenticate', 'agent_access_block_wp_login', 100, 2 );
function agent_access_block_wp_login( $user, $username ) {
    if ( ! $username ) return $user;
    $found = get_user_by( 'login', $username );
    if ( $found && get_user_meta( $found->ID, '_agent_access_provisioned', true ) ) {
        return new WP_Error(
            'agent_access_no_login',
            'This account is API-only and cannot log in via wp-login.'
        );
    }
    return $user;
}

add_filter( 'allow_password_reset', 'agent_access_block_password_reset', 10, 2 );
function agent_access_block_password_reset( $allow, $user_id ) {
    if ( get_user_meta( $user_id, '_agent_access_provisioned', true ) ) {
        return false;
    }
    return $allow;
}

// ── Main endpoint callback ───────────────────────────────────────────────────

function agent_access_provision( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    // 0. Token gate — when AGENT_ACCESS_PROVISION_TOKEN is defined, require it.
    if ( defined( 'AGENT_ACCESS_PROVISION_TOKEN' ) && AGENT_ACCESS_PROVISION_TOKEN ) {
        $token = $request->get_header( 'X-Agent Access-Token' );
        if ( ! $token || ! hash_equals( AGENT_ACCESS_PROVISION_TOKEN, $token ) ) {
            return new WP_Error(
                'unauthorized',
                'A valid provisioning token is required.',
                array( 'status' => 403 )
            );
        }
    }

    // 1. Rate limit check
    if ( ! agent_access_check_rate_limit() ) {
        do_action( 'agent_access_audit', 'rate_limit_exceeded', array( 'ip' => agent_access_get_client_ip() ) );
        return new WP_Error(
            'rate_limit_exceeded',
            'Too many provisioning requests from this IP. Try again in an hour.',
            [ 'status' => 429 ]
        );
    }

    $username     = $request->get_param( 'agent_name' );
    $display_name = $request->get_param( 'display_name' ) ?: $username;
    $description  = $request->get_param( 'description' )  ?: '';
    $homepage     = $request->get_param( 'homepage' )     ?: '';
    $email        = $request->get_param( 'email' )        ?: ( $username . '@' . AGENT_ACCESS_EMAIL_DOMAIN );

    // 2. Check for existing username
    if ( username_exists( $username ) ) {
        return new WP_Error(
            'username_unavailable',
            'This username is not available.',
            [ 'status' => 409 ]
        );
    }

    // 3. Check for existing email (use placeholder if collision)
    if ( email_exists( $email ) ) {
        $email = $username . '+' . wp_generate_password( 6, false ) . '@' . AGENT_ACCESS_EMAIL_DOMAIN;
    }

    // 4. Create the WordPress user
    $user_id = wp_insert_user( [
        'user_login'   => $username,
        'user_email'   => $email,
        'display_name' => $display_name,
        'description'  => $description,
        'user_url'     => $homepage,
        'role'         => AGENT_ACCESS_PROVISION_ROLE,
        'user_pass'    => wp_generate_password( 64, true, true ), // long random, never disclosed
    ] );

    if ( is_wp_error( $user_id ) ) {
        error_log( '[Agent Access] Provision user creation failed: ' . $user_id->get_error_message() );
        return new WP_Error(
            'user_creation_failed',
            'Account creation failed. Please try again later.',
            [ 'status' => 500 ]
        );
    }

    // Mark as provisioned (used for login/reset blocking)
    update_user_meta( $user_id, '_agent_access_provisioned', true );
    update_user_meta( $user_id, '_agent_access_provisioned_at', gmdate( 'c' ) );
    update_user_meta( $user_id, '_agent_access_provisioned_ip', agent_access_get_client_ip() );

    // 4b. Collect client fingerprint (passive + declared)
    $declared_fingerprint = $request->get_param( 'fingerprint' );
    $passive_fingerprint  = [
        'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
        'accept'        => isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '',
        'content_type'  => isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '',
        'header_keys'   => array_keys( $request->get_headers() ),
        'ip_hash'       => wp_hash( agent_access_get_client_ip() ),
    ];

    // Sanitize declared fingerprint fields (all strings, max 128 chars)
    $sanitized_declared = [];
    if ( is_array( $declared_fingerprint ) ) {
        $allowed_keys = [ 'runtime', 'runtime_version', 'model', 'framework', 'platform' ];
        foreach ( $allowed_keys as $key ) {
            if ( isset( $declared_fingerprint[ $key ] ) && is_string( $declared_fingerprint[ $key ] ) ) {
                $sanitized_declared[ $key ] = substr( sanitize_text_field( $declared_fingerprint[ $key ] ), 0, 128 );
            }
        }
    }

    update_user_meta( $user_id, '_agent_access_fingerprint', [
        'declared'  => $sanitized_declared,
        'passive'   => $passive_fingerprint,
        'collected' => gmdate( 'c' ),
    ] );

    // 5. Generate an Application Password
    if ( ! class_exists( 'WP_Application_Passwords' ) ) {
        wp_delete_user( $user_id );
        return new WP_Error(
            'app_passwords_unavailable',
            'Application Passwords are not available on this WordPress installation (requires WP 5.6+).',
            [ 'status' => 500 ]
        );
    }

    $app_pass_result = WP_Application_Passwords::create_new_application_password(
        $user_id,
        [ 'name' => AGENT_ACCESS_APP_PASS_NAME ]
    );

    if ( is_wp_error( $app_pass_result ) ) {
        error_log( '[Agent Access] Provision app password failed: ' . $app_pass_result->get_error_message() );
        wp_delete_user( $user_id );
        return new WP_Error(
            'app_password_failed',
            'Account creation failed. Please try again later.',
            [ 'status' => 500 ]
        );
    }

    // $app_pass_result[0] is the plaintext password (only available once).
    $plain_password = $app_pass_result[0];

    // 6. Build response URLs
    $author_url = get_author_posts_url( $user_id );
    $api_base   = rest_url( 'wp/v2/' );

    do_action( 'agent_access_audit', 'account_provisioned', array( 'username' => $username, 'ip' => agent_access_get_client_ip() ) );

    // 7. Return credentials
    return new WP_REST_Response(
        [
            'success'    => true,
            'username'   => $username,
            'password'   => $plain_password,
            'author_url' => $author_url,
            'api_base'   => $api_base,
            'verified'   => false,
            'message'    => 'Welcome. You can now publish.',
            'next_steps' => [
                'publish' => 'POST ' . $api_base . 'posts with Basic Auth (username:password) to create posts.',
                'verify'  => 'Your posts are noindex by default. To unlock search engine indexing: 1) Create a Gravatar profile at https://gravatar.com with a real email. 2) POST to ' . rest_url( 'agent-access/v1/verify' ) . ' with {"email": "your-gravatar-email"} using Basic Auth. Verified accounts get indexed by Google and a public author page.',
            ],
        ],
        201
    );
}
