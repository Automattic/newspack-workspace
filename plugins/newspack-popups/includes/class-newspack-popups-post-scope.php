<?php
/**
 * Newspack Popups Post Scope
 *
 * Supports prompts that are scoped to a single post (e.g. Contextual Prompts):
 * they display only on their parent post and are kept out of the general
 * eligible-prompts query so sites with many scoped prompts don't pay a query
 * cost on every page view.
 *
 * The scope link is the prompt's `post_parent` (set to the article ID). This is
 * deliberate: excluding scoped prompts from the eligible query is then a cheap
 * `post_parent = 0` filter on the posts table, rather than a meta anti-join that
 * gets more expensive as scoped prompts accumulate.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Popups Post Scope Class.
 */
final class Newspack_Popups_Post_Scope {
	/**
	 * Campaign group all Contextual Prompts are collected under.
	 */
	const CAMPAIGN_GROUP_NAME = 'Contextual Prompts';

	/**
	 * Prompt meta: whether the copy was AI-generated.
	 */
	const META_AI_GENERATED = 'newspack_ai_generated';

	/**
	 * Prompt meta: the prompt-template version that produced the copy.
	 */
	const META_AI_TEMPLATE_VERSION = 'newspack_ai_template_version';

	/**
	 * Prompt meta: the generation request ID, for tracing back to the backend.
	 */
	const META_AI_REQUEST_ID = 'newspack_ai_request_id';

	/**
	 * Prompt meta: whether a human edited the AI copy before approving.
	 */
	const META_AI_EDITED = 'newspack_ai_edited';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'newspack_popups_should_display_prompt', [ __CLASS__, 'filter_should_display' ], 10, 2 );
	}

	/**
	 * Create a prompt scoped to a single post from an approved candidate.
	 *
	 * The created prompt is an ordinary inline Campaigns prompt whose only
	 * special property is its post_parent (the article) — so it inherits
	 * frequency, segmentation, analytics, and A/B for free.
	 *
	 * @param array $args {
	 *     Creation arguments.
	 *
	 *     @type int    $post_id          Required. The article to scope the prompt to.
	 *     @type string $body             Required. The approved appeal copy.
	 *     @type string $button_label     Button label. Default 'Donate'.
	 *     @type string $button_url       Button URL. Omit to render copy only.
	 *     @type int    $position         Blocks-count insertion position. Default 3.
	 *     @type string $status           Post status. Default 'publish'.
	 *     @type bool   $ai_generated     Whether the copy was AI-generated. Default false.
	 *     @type string $template_version Prompt-template version. Default ''.
	 *     @type string $request_id       Generation request ID. Default ''.
	 *     @type bool   $ai_edited        Whether a human edited the AI copy. Default false.
	 * }
	 * @return int|\WP_Error The new prompt ID, or an error.
	 */
	public static function create_scoped_prompt( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$body    = trim( (string) ( $args['body'] ?? '' ) );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'newspack_popups_invalid_post', __( 'A valid post to scope the prompt to is required.', 'newspack-popups' ), [ 'status' => 400 ] );
		}
		if ( '' === $body ) {
			return new \WP_Error( 'newspack_popups_empty_body', __( 'Prompt copy cannot be empty.', 'newspack-popups' ), [ 'status' => 400 ] );
		}

		$button_label = (string) ( $args['button_label'] ?? __( 'Donate', 'newspack-popups' ) );
		$button_url   = (string) ( $args['button_url'] ?? '' );
		$position     = max( 0, (int) ( $args['position'] ?? 3 ) );

		$prompt_id = wp_insert_post(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'  => (string) ( $args['status'] ?? 'publish' ),
				'post_parent'  => $post_id,
				/* translators: %s: parent post title. */
				'post_title'   => sprintf( __( 'Contextual prompt: %s', 'newspack-popups' ), get_the_title( $post_id ) ),
				'post_content' => self::build_prompt_content( $body, $button_label, $button_url ),
			],
			true
		);

		if ( is_wp_error( $prompt_id ) ) {
			return $prompt_id;
		}

		$options_result = Newspack_Popups_Model::set_popup_options(
			$prompt_id,
			[
				'placement'            => 'inline',
				'trigger_type'         => 'blocks_count',
				'trigger_blocks_count' => (string) $position,
				'frequency'            => 'once',
			]
		);
		if ( is_wp_error( $options_result ) ) {
			return $options_result;
		}

		self::assign_campaign_group( $prompt_id );

		update_post_meta( $prompt_id, self::META_AI_GENERATED, ! empty( $args['ai_generated'] ) );
		update_post_meta( $prompt_id, self::META_AI_TEMPLATE_VERSION, sanitize_text_field( (string) ( $args['template_version'] ?? '' ) ) );
		update_post_meta( $prompt_id, self::META_AI_REQUEST_ID, sanitize_text_field( (string) ( $args['request_id'] ?? '' ) ) );
		update_post_meta( $prompt_id, self::META_AI_EDITED, ! empty( $args['ai_edited'] ) );

		return $prompt_id;
	}

	/**
	 * Build the block markup for a scoped prompt: the appeal copy and, when a
	 * URL is given, a button.
	 *
	 * @param string $body         Appeal copy.
	 * @param string $button_label Button label.
	 * @param string $button_url   Button URL, or '' to render copy only.
	 * @return string Serialized block markup.
	 */
	private static function build_prompt_content( $body, $button_label, $button_url ) {
		$content = "<!-- wp:paragraph -->\n<p>" . esc_html( $body ) . "</p>\n<!-- /wp:paragraph -->";

		if ( '' !== trim( $button_url ) ) {
			$content .= "\n<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->"
				. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $button_url ) . '">'
				. esc_html( $button_label )
				. "</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
		}

		return $content;
	}

	/**
	 * Assign a prompt to the shared "Contextual Prompts" campaign group, creating
	 * the term if needed.
	 *
	 * @param int $prompt_id Prompt ID.
	 * @return void
	 */
	private static function assign_campaign_group( $prompt_id ) {
		$taxonomy = Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY;
		$term     = get_term_by( 'name', self::CAMPAIGN_GROUP_NAME, $taxonomy );

		if ( ! $term ) {
			$created = wp_insert_term( self::CAMPAIGN_GROUP_NAME, $taxonomy );
			if ( is_wp_error( $created ) ) {
				return;
			}
			$term_id = $created['term_id'];
		} else {
			$term_id = $term->term_id;
		}

		wp_set_post_terms( $prompt_id, [ (int) $term_id ], $taxonomy );
	}

	/**
	 * The post ID a prompt is scoped to, or 0 if it is site-wide.
	 *
	 * @param array $popup A popup object (must contain an 'id' key).
	 * @return int Scoped post ID, or 0.
	 */
	public static function get_scoped_post_id( $popup ) {
		if ( empty( $popup['id'] ) ) {
			return 0;
		}
		return (int) get_post_field( 'post_parent', $popup['id'] );
	}

	/**
	 * Gate a scoped prompt to its parent post.
	 *
	 * A scoped prompt overrides the usual taxonomy/post-type context match: it
	 * shows on its parent post regardless of those, and nowhere else. Non-scoped
	 * prompts are returned unchanged.
	 *
	 * @param bool  $should_display Result of the prior should_display checks.
	 * @param array $popup          The popup being assessed.
	 * @return bool
	 */
	public static function filter_should_display( $should_display, $popup ) {
		$scoped_post_id = self::get_scoped_post_id( $popup );
		if ( ! $scoped_post_id ) {
			return $should_display;
		}
		return is_singular() && (int) get_the_ID() === $scoped_post_id;
	}

	/**
	 * Exclude scoped prompts from a WP_Query args array.
	 *
	 * Restricts the query to top-level prompts (post_parent = 0), so post-scoped
	 * prompts never load through the general eligible-prompts query. They are
	 * injected explicitly for the current post via get_scoped_popups_for_current_post().
	 *
	 * @param array $args WP_Query args.
	 * @return array Modified args.
	 */
	public static function exclude_scoped_from_args( $args ) {
		$args['post_parent'] = 0;
		return $args;
	}

	/**
	 * Prompts scoped to the current singular post, ready to merge into the
	 * display candidates. Empty on non-singular views.
	 *
	 * @param bool $include_unpublished Whether to include unpublished prompts.
	 * @return array Popup objects.
	 */
	public static function get_scoped_popups_for_current_post( $include_unpublished = false ) {
		if ( ! is_singular() ) {
			return [];
		}
		return Newspack_Popups_Model::retrieve_scoped_popups( get_the_ID(), $include_unpublished );
	}
}
