<?php
/**
 * Connects Collections to content gates.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;

defined( 'ABSPATH' ) || exit;

/**
 * Lets content gates cover the Collections archive page and single Collections.
 */
class Gate_Integration {

	/**
	 * Classes of the buttons a gated Collection keeps: the Subscribe and Order
	 * links, which are how a reader gets access.
	 *
	 * @var string[]
	 */
	const ACCESS_CTA_CLASSES = [ 'cta--subscribe_link', 'cta--order_link' ];

	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_filter( 'newspack_content_gate_supported_archive_post_types', [ __CLASS__, 'add_archive_support' ] );
		add_filter( 'newspack_collections_ctas', [ __CLASS__, 'filter_ctas' ], 10, 2 );
		add_filter( 'rest_prepare_' . Post_Type::get_post_type(), [ __CLASS__, 'filter_rest_response' ], 10, 2 );
	}

	/**
	 * Whether a gate keeps the current reader from a Collection.
	 *
	 * On the Collection's own page this is whether the page was gated, which
	 * already accounts for metering. Anywhere else, such as a list of
	 * Collections or REST, it is whether a gate restricts the Collection.
	 *
	 * @param int $collection_id Collection post ID.
	 *
	 * @return bool
	 */
	public static function is_collection_gated( int $collection_id ): bool {
		if ( is_singular( Post_Type::get_post_type() ) && get_queried_object_id() === $collection_id ) {
			return Content_Gate::did_restrict_post( $collection_id );
		}
		return (bool) Content_Gate::is_post_restricted( $collection_id );
	}

	/**
	 * Hide a gated Collection's own buttons (files and custom links).
	 *
	 * @param array $ctas    The Collection's buttons.
	 * @param int   $post_id Collection post ID.
	 *
	 * @return array
	 */
	public static function filter_ctas( array $ctas, int $post_id ): array {
		if ( empty( $ctas ) || ! self::is_collection_gated( $post_id ) ) {
			return $ctas;
		}
		return array_values(
			array_filter(
				$ctas,
				function ( $cta ) {
					return in_array( $cta['class'] ?? '', self::ACCESS_CTA_CLASSES, true );
				}
			)
		);
	}

	/**
	 * Remove the raw button data from a gated Collection's REST response.
	 *
	 * The raw meta holds attachment IDs and link URLs. The `ctas` field is
	 * covered by {@see self::filter_ctas()}.
	 *
	 * @param \WP_REST_Response $response The response.
	 * @param \WP_Post          $post     The Collection.
	 *
	 * @return \WP_REST_Response
	 */
	public static function filter_rest_response( \WP_REST_Response $response, \WP_Post $post ): \WP_REST_Response {
		$data     = $response->get_data();
		$meta_key = Collection_Meta::$prefix . 'ctas';
		if ( ! is_array( $data['meta'] ?? null ) || ! array_key_exists( $meta_key, $data['meta'] ) ) {
			return $response;
		}
		if ( ! self::is_collection_gated( $post->ID ) ) {
			return $response;
		}
		unset( $data['meta'][ $meta_key ] );
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Print the teaser and the gate on a gated single Collection page.
	 *
	 * Called by the single Collection template inside its loop, which skips
	 * the section lists when this returns true.
	 *
	 * @param int $collection_id Collection post ID.
	 *
	 * @return bool Whether the gate was printed.
	 */
	public static function render_single_gate( int $collection_id ): bool {
		if ( ! self::is_collection_gated( $collection_id ) ) {
			return false;
		}
		// The content filters swap in the teaser and add the inline gate.
		echo '<div class="entry-content">';
		the_content();
		echo '</div>';
		// Classic themes print the overlay gate from get_footer. Block themes
		// print it after a post content block, which this template doesn't have.
		if ( ! has_action( 'get_footer', [ Content_Gate::class, 'render_overlay_gate' ] ) ) {
			Content_Gate::render_overlay_gate();
		}
		return true;
	}

	/**
	 * Offer the Collection post type to content gates as an archive.
	 *
	 * @param string[] $post_types Post types whose archive a gate can restrict.
	 *
	 * @return string[]
	 */
	public static function add_archive_support( $post_types ) {
		$post_types[] = Post_Type::get_post_type();
		return $post_types;
	}

	/**
	 * Print the gate in place of the archive's list, if a gate covers it.
	 *
	 * Called by the archive template, which skips the list when this returns true.
	 *
	 * @return bool Whether the gate was printed.
	 */
	public static function maybe_render_archive_gate(): bool {
		$archive_gate = Content_Restriction_Control::get_archive_gate();
		if ( ! $archive_gate ) {
			return false;
		}
		// Claim the once-per-request render lock before rendering, as every gate
		// render path does, so nothing else renders a second gate on this page.
		Content_Gate::mark_gate_as_rendered();
		echo Content_Gate::get_archive_gate_html( $archive_gate['gate_layout_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered gate layout, as for posts.
		return true;
	}
}
