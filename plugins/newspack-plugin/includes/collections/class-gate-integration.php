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
	public static function init(): void {
		add_filter( 'newspack_content_gate_supported_archive_post_types', [ __CLASS__, 'add_archive_support' ] );
		add_filter( 'newspack_collections_ctas', [ __CLASS__, 'filter_ctas' ], 10, 2 );
		add_filter( 'rest_prepare_' . Post_Type::get_post_type(), [ __CLASS__, 'filter_rest_response' ], 10, 2 );
		add_filter( 'infinite_scroll_archive_supported', [ __CLASS__, 'disable_infinite_scroll_on_gated_archive' ] );
	}

	/**
	 * Keep Jetpack's Infinite Scroll off a gated archive, so it can't append more
	 * Collections below the gate.
	 *
	 * @param mixed $supported Whether Infinite Scroll runs on this archive.
	 *
	 * @return mixed False on a gated archive, otherwise the value given.
	 */
	public static function disable_infinite_scroll_on_gated_archive( $supported ) {
		if ( Content_Restriction_Control::get_archive_gate() ) {
			return false;
		}
		return $supported;
	}

	/**
	 * Whether a gate keeps the current reader from a Collection.
	 *
	 * On the Collection's own page, once the loop has decided whether to gate
	 * it, this is that decision, which accounts for metering. Before then
	 * (a block or widget in the header, say), and anywhere else, such as a
	 * list of Collections or REST, it is whether a gate restricts the
	 * Collection. So an answer asked for too early hides the buttons rather
	 * than showing them.
	 *
	 * @param int $collection_id Collection post ID.
	 *
	 * @return bool
	 */
	public static function is_collection_gated( int $collection_id ): bool {
		if ( is_singular( Post_Type::get_post_type() ) && get_queried_object_id() === $collection_id ) {
			$decision = Content_Gate::get_article_restriction( $collection_id );
			if ( null !== $decision ) {
				return $decision;
			}
		}
		return (bool) Content_Gate::is_post_restricted( $collection_id );
	}

	/**
	 * Hide a gated Collection's own buttons (files and custom links).
	 *
	 * Anything other than a non-empty list comes back unchanged.
	 *
	 * @param mixed $ctas    The Collection's buttons.
	 * @param int   $post_id Collection post ID.
	 *
	 * @return mixed
	 */
	public static function filter_ctas( $ctas, $post_id ) {
		if ( ! is_array( $ctas ) || empty( $ctas ) || ! self::is_collection_gated( (int) $post_id ) ) {
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
	 * covered by {@see self::filter_ctas()}. Anything other than a response for a
	 * post comes back unchanged.
	 *
	 * @param \WP_REST_Response|mixed $response The response.
	 * @param \WP_Post|mixed          $post     The Collection.
	 *
	 * @return \WP_REST_Response|mixed
	 */
	public static function filter_rest_response( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}
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
	 * Print the gate on a gated single Collection page.
	 *
	 * Called by the single Collection template inside its loop, which skips
	 * the section lists when this returns true. No teaser: the intro above
	 * already shows the excerpt, and a Collection's body is never shown to
	 * a reader with access either.
	 *
	 * restrict_post() staged the layout with the fade, so this renders it again without it.
	 *
	 * @param int $collection_id Collection post ID.
	 *
	 * @return bool Whether the gate was printed.
	 */
	public static function render_single_gate( int $collection_id ): bool {
		// Not is_collection_gated(), which fails closed before the loop has
		// decided: print the gate only once the loop restricted the Collection.
		if ( ! Content_Gate::did_restrict_post( $collection_id ) ) {
			return false;
		}
		// The inline gate, without the fade: there's no teaser above it to fade
		// out, so the fade would cover the intro instead. Empty for an overlay layout.
		echo '<div class="entry-content">';
		echo Content_Gate::get_inline_gate_html( $collection_id, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered gate layout, as for posts.
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
	 * @param mixed $post_types Post types whose archive a gate can restrict. A non-array passes through.
	 *
	 * @return mixed The post types, or whatever a filter handed in that wasn't a list.
	 */
	public static function add_archive_support( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}
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
		// The lock is claimed for parity with the other gate render paths. Nothing
		// on an archive reads it today.
		Content_Gate::mark_gate_as_rendered();
		echo Content_Gate::get_archive_gate_html( $archive_gate['gate_layout_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered gate layout, as for posts.
		return true;
	}
}
