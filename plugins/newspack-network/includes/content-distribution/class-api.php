<?php
/**
 * Newspack Network Content Distribution API.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack\Data_Events;
use InvalidArgumentException;
use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Utils;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * API Class.
 */
class API {
	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the REST API routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/distribute/(?P<post_id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'distribute' ],
				'args'                => [
					'urls'              => [
						'type'     => 'array',
						'required' => true,
						'items'    => [
							'type' => 'string',
						],
					],
					'status_on_publish' => [
						'type'    => 'string',
						'enum'    => [ 'draft', 'pending', 'publish' ],
						'default' => 'draft',
					],
				],
				'permission_callback' => function ( $request ) {
					return current_user_can( Admin::CAPABILITY ) && current_user_can( 'edit_post', $request['post_id'] );
				},
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/unlink/(?P<post_id>\d+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'toggle_unlink' ],
				'args'                => [
					'unlinked' => [
						'required' => true,
						'type'     => 'boolean',
					],
				],
				'permission_callback' => function () {
					return current_user_can( Admin::CAPABILITY );
				},
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/pull/(?P<post_id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'pull_post' ],
				'args'                => [
					'url'               => [
						'type'     => 'string',
						'required' => false, // If not provided, it'll look for the X-Network-Site-URL header.
					],
					'status_on_publish' => [
						'type'    => 'string',
						'enum'    => [ 'draft', 'pending', 'publish' ],
						'default' => 'draft',
					],
				],
				'permission_callback' => function () {
					return current_user_can( Admin::CAPABILITY );
				},
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/insert',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'insert_post' ],
				'args'                => [
					'payload' => [
						'type'     => 'object',
						'required' => true,
					],
				],
				'permission_callback' => function () {
					return current_user_can( Admin::CAPABILITY );
				},
			]
		);
	}

	/**
	 * Toggle the unlinked status of an incoming post.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function toggle_unlink( $request ): WP_REST_Response|WP_Error {
		$post_id  = $request->get_param( 'post_id' );
		$unlinked = $request->get_param( 'unlinked' );

		try {
			$incoming_post = new Incoming_Post( $post_id );
			$incoming_post->set_unlinked( $unlinked );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'newspack_network_content_distribution_error', $e->getMessage(), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'post_id'  => $post_id,
				'unlinked' => ! $incoming_post->is_linked(),
				'status'   => 'success',
			]
		);
	}

	/**
	 * Distribute a post to the network.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function distribute( $request ) {
		// Re-distributing a syndicated copy would give it a second lineage.
		if ( Content_Distribution_Class::is_post_incoming( $request->get_param( 'post_id' ) ) ) {
			return new WP_Error( 'newspack_network_content_distribution_error', __( 'A post received from the network cannot be distributed.', 'newspack-network' ), [ 'status' => 400 ] );
		}

		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return new WP_Error( 'newspack_network_content_distribution_error', __( 'Data Events class not found.', 'newspack-network' ), [ 'status' => 400 ] );
		}

		$post_id           = $request->get_param( 'post_id' );
		$urls              = $request->get_param( 'urls' );
		$status_on_publish = $request->get_param( 'status_on_publish' );

		// Prevent missing posts and auto-drafts from being distributed.
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'newspack_network_content_distribution_error', __( 'Post not found.', 'newspack-network' ), [ 'status' => 404 ] );
		}
		if ( 'auto-draft' === $post->post_status ) {
			return new WP_Error( 'newspack_network_content_distribution_error', __( 'Post is currently an auto-draft. Save before distributing it.', 'newspack-network' ), [ 'status' => 400 ] );
		}

		try {
			$outgoing_post = new Outgoing_Post( $post_id );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'newspack_network_content_distribution_error', $e->getMessage(), [ 'status' => 400 ] );
		}

		$distribution = $outgoing_post->set_distribution( $urls );

		if ( is_wp_error( $distribution ) ) {
			return new WP_Error( 'newspack_network_content_distribution_error', $distribution->get_error_message(), [ 'status' => 400 ] );
		}

		$payload    = $outgoing_post->get_payload( $status_on_publish );
		$dispatched = Data_Events::dispatch( 'network_post_updated', $payload );

		// Bail before storing the payload hash if the dispatch failed, so the next
		// post update retries the distribution instead of being suppressed by the hash.
		if ( is_wp_error( $dispatched ) ) {
			return new WP_Error( 'newspack_network_content_distribution_error', $dispatched->get_error_message(), [ 'status' => 500 ] );
		}

		// Store payload hash to prevent unnecessary updates.
		update_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, $outgoing_post->get_payload_hash( $payload ) );

		return rest_ensure_response( $distribution );
	}

	/**
	 * Pull a post and set up distribution to the requester.
	 *
	 * This request will not dispatch a post update. It's up to the requester
	 * to create the post on their site with the provided payload.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function pull_post( $request ): WP_REST_Response|WP_Error {
		$post_id  = $request->get_param( 'post_id' );
		$url      = $request->get_param( 'url' );
		$status_on_publish = $request->get_param( 'status_on_publish' );

		if ( ! $url ) {
			$url = filter_input( INPUT_SERVER, 'HTTP_X_NETWORK_SITE_URL', FILTER_VALIDATE_URL );
			if ( ! $url ) {
				return new WP_Error( 'missing_url', 'The URL is required.', [ 'status' => 400 ] );
			}
		}

		if ( ! Utils\Network::is_networked_url( $url ) ) {
			return new WP_Error( 'site_not_networked', 'The destination site is not part of the network.', [ 'status' => 400 ] );
		}

		try {
			$outgoing_post = new Outgoing_Post( $post_id );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_post_id', $e->getMessage(), [ 'status' => 400 ] );
		}

		$distribution = $outgoing_post->set_distribution( [ $url ] );
		if ( is_wp_error( $distribution ) ) {
			return $distribution;
		}

		return rest_ensure_response(
			$outgoing_post->get_payload( $status_on_publish )
		);
	}

	/**
	 * Remove block custom CSS from post content.
	 *
	 * Core has `wp_strip_custom_css_from_blocks()`, but only since WordPress 7.0,
	 * and guarding on its existence would gate on feature availability rather than
	 * on safety: content stored on an older site keeps the attribute, and it starts
	 * rendering the day that site upgrades. Stripping it ourselves closes that
	 * window and keeps one code path under test. Matches core's output where no
	 * block carries custom CSS, including the untouched-bytes case; once something
	 * is stripped this re-serializes the document while core splices only the
	 * attribute it changed, so siblings written in non-canonical form normalize.
	 *
	 * @param string $content Post content.
	 *
	 * @return string The content with any block custom CSS removed.
	 */
	private static function strip_block_custom_css( string $content ): string {
		// Deliberately not gated on has_blocks(). That tests for the exact literal
		// '<!-- wp:', while WP_Block_Parser accepts extra whitespace or a newline
		// after the opener, so a guard here skips content the parser still reads as
		// a block — and the kses pass that follows then normalizes the delimiter
		// back to canonical form with the attribute intact. Core carries the same
		// guard; this is the one place we deliberately differ from it. The $removed
		// check below already returns the original bytes when nothing was stripped,
		// so the guard bought nothing.
		$removed = false;
		$blocks  = self::remove_custom_css_attribute( parse_blocks( $content ), $removed );

		// Return the original bytes when there was nothing to strip, so this does not
		// needlessly re-serialize markup it had no reason to touch. Note the stored
		// payload is the FILTERED copy, not the sender's original — see the note on
		// filter_incoming_content(), which that safety depends on.
		if ( ! $removed ) {
			return $content;
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively drop the `style.css` attribute from parsed blocks.
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param bool  $removed Set to true when any custom CSS was found and removed.
	 *
	 * @return array The blocks, without custom CSS.
	 */
	private static function remove_custom_css_attribute( array $blocks, bool &$removed ): array {
		foreach ( $blocks as $index => $block ) {
			if ( isset( $block['attrs']['style']['css'] ) ) {
				unset( $blocks[ $index ]['attrs']['style']['css'] );
				$removed = true;

				// Match core: an emptied style attribute is removed, not left behind.
				if ( empty( $blocks[ $index ]['attrs']['style'] ) ) {
					unset( $blocks[ $index ]['attrs']['style'] );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::remove_custom_css_attribute( $block['innerBlocks'], $removed );
			}
		}

		return $blocks;
	}

	/**
	 * Hold caller-supplied content to the caller's own capabilities.
	 *
	 * This route's payload is composed in the browser rather than by a peer site,
	 * and Incoming_Post::insert() stores content with kses disabled, which is only
	 * defensible for trusted-node content. So content is filtered here, against the
	 * requesting user's own capabilities.
	 *
	 * Scoped deliberately to this route. Distribution between sites arrives as a
	 * Data Event carrying network credentials and never passes through here, so
	 * it keeps storing content verbatim — see test_event_path_content_is_not_filtered.
	 *
	 * Applies what core would clean for such a caller, directly rather than by
	 * running the filter chain, so this does not depend on global filter
	 * registration: block custom CSS and kses over `content`/`raw_content`, and
	 * kses over `title` and `excerpt` in core's own two contexts. (Core also puts
	 * wp_filter_global_styles_post on `content_save_pre`; it acts only on
	 * global-styles JSON, which a distributed post never carries.)
	 *
	 * Title and excerpt are filtered twice — here, and again by core's
	 * `title_save_pre`/`excerpt_save_pre`, which this route never removes. Both
	 * passes are idempotent, so the stored value is the same either way. The
	 * reason to filter them here at all is the persisted payload, below.
	 *
	 * Load-bearing: this mutates the payload BEFORE Incoming_Post is constructed,
	 * so the copy persisted to PAYLOAD_META is the filtered one. Re-linking an
	 * unlinked post replays that stored payload through insert(), with
	 * `content_save_pre` still removed and no route filtering — and a caller
	 * holding unfiltered_html at that moment has no title or excerpt filters of
	 * their own either. Persisting a pristine payload would reopen the hole
	 * through that path, for every field.
	 *
	 * The cost of that choice, on legitimate content: the filtered copy is also the
	 * merge base `get_payload_from_partial()` merges later updates over, so where a
	 * low-privilege caller degrades content, subsequent partial updates re-apply the
	 * degraded copy. Only a full re-distribution from the origin heals it.
	 *
	 * @param mixed $payload The incoming payload.
	 *
	 * @return mixed The payload, with content filtered where the caller requires it.
	 */
	private static function filter_incoming_content( mixed $payload ): mixed {
		if ( ! is_array( $payload ) || empty( $payload['post_data'] ) || ! is_array( $payload['post_data'] ) ) {
			return $payload;
		}

		// Checked separately because core gates them separately: it registers the
		// custom-CSS strip for callers without edit_css and kses for callers without
		// unfiltered_html. Both meta caps resolve to the same primitive by default,
		// but map_meta_cap is a filter and a plugin can diverge them, so mirroring
		// core's two gates keeps this correct either way.
		$strip_css   = ! current_user_can( 'edit_css' );
		$filter_html = ! current_user_can( 'unfiltered_html' );

		if ( ! $strip_css && ! $filter_html ) {
			return $payload;
		}

		// Both fields can reach post_content: get_post_content() returns 'content'
		// unless 'raw_content' carries blocks, in which case that is what is stored.
		foreach ( [ 'content', 'raw_content' ] as $field ) {
			if ( ! isset( $payload['post_data'][ $field ] ) || ! is_string( $payload['post_data'][ $field ] ) ) {
				continue;
			}

			$value = $payload['post_data'][ $field ];

			// Core's order: the custom-CSS strip runs at priority 8, kses at 10.
			if ( $strip_css ) {
				$value = self::strip_block_custom_css( $value );
			}

			if ( $filter_html ) {
				$value = wp_kses_post( $value );
			}

			$payload['post_data'][ $field ] = $value;
		}

		// Mirrors core's other two kses registrations for this caller:
		// title_save_pre => wp_filter_kses, which scopes the tag set by context,
		// and excerpt_save_pre => wp_filter_post_kses. Custom CSS is deliberately
		// not stripped here — neither field is block content, and core does not
		// strip it there either.
		if ( $filter_html ) {
			foreach ( [
				'title'   => 'title_save_pre',
				'excerpt' => 'post',
			] as $field => $context ) {
				if ( ! isset( $payload['post_data'][ $field ] ) || ! is_string( $payload['post_data'][ $field ] ) ) {
					continue;
				}

				$payload['post_data'][ $field ] = wp_kses( $payload['post_data'][ $field ], $context );
			}
		}

		return $payload;
	}

	/**
	 * Insert a post given an Outgoing_Post payload.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function insert_post( $request ): WP_REST_Response|WP_Error {
		$payload = self::filter_incoming_content( $request->get_param( 'payload' ) );

		try {
			$incoming_post = new Incoming_Post( $payload );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_payload', $e->getMessage(), [ 'status' => 400 ] );
		}

		$post_id = $incoming_post->insert();
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'insert_failed', $post_id->get_error_message(), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'post_id' => $post_id,
			]
		);
	}
}
