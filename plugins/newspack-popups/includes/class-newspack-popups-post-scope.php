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
	 * Stable class hooks on the generated blocks. Once a prompt has been
	 * customized in the block editor its content is no longer regenerated, so
	 * these are how the panel finds the copy and CTA to update in place.
	 */
	const COPY_CLASS = 'newspack-contextual-prompt__copy';
	const CTA_CLASS  = 'newspack-contextual-prompt__cta';

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
	 * Prompt meta: the raw approved copy, button label, and button URL. Stored
	 * alongside the rendered block content so the editor panel can round-trip
	 * (load and edit) the prompt without parsing block markup.
	 */
	const META_BODY         = 'newspack_cp_body';
	const META_BUTTON_LABEL = 'newspack_cp_button_label';
	const META_BUTTON_URL   = 'newspack_cp_button_url';

	/**
	 * The CTA mode a prompt's content was generated with, so the pristine baseline
	 * survives a change of donation platform.
	 */
	const META_CTA_MODE = 'newspack_cp_cta_mode';

	const CTA_MODE_DONATE_BLOCK = 'donate_block';
	const CTA_MODE_BUTTON       = 'button';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'newspack_popups_should_display_prompt', [ __CLASS__, 'filter_should_display' ], 10, 2 );

		// A scoped prompt only means anything alongside its article. Core cascades
		// nothing here — wp_delete_post() only re-parents children of the same post
		// type — so without these the prompt outlives the article forever: invisible
		// to readers but still counted in the Prompts list, exports and reporting.
		add_action( 'before_delete_post', [ __CLASS__, 'delete_scoped_prompts_for_post' ] );
		add_action( 'trashed_post', [ __CLASS__, 'trash_scoped_prompts_for_post' ] );
		add_action( 'untrashed_post', [ __CLASS__, 'untrash_scoped_prompts_for_post' ] );
	}

	/**
	 * Delete the prompts scoped to a post when that post is permanently deleted.
	 *
	 * @param int $post_id The post being deleted.
	 * @return void
	 */
	public static function delete_scoped_prompts_for_post( $post_id ) {
		// Every registered status, so nothing is left behind: the ordinary route to
		// permanent deletion is trash, then Empty Trash (or wp_scheduled_delete), by
		// which point trashed_post has already moved the prompt to trash. Note 'any'
		// would not do — it omits statuses registered exclude_from_search, i.e.
		// exactly trash and auto-draft. Enumerating instead of hard-coding also keeps
		// custom statuses (editorial-workflow plugins) from escaping the cascade.
		foreach ( self::get_scoped_prompt_ids( $post_id, array_keys( get_post_stati() ) ) as $prompt_id ) {
			wp_delete_post( $prompt_id, true );
		}
	}

	/**
	 * Trash the prompts scoped to a post when that post is trashed, so a trashed
	 * article stops serving its prompt.
	 *
	 * @param int $post_id The post being trashed.
	 * @return void
	 */
	public static function trash_scoped_prompts_for_post( $post_id ) {
		foreach ( self::get_scoped_prompt_ids( $post_id ) as $prompt_id ) {
			wp_trash_post( $prompt_id );
		}
	}

	/**
	 * Restore the prompts scoped to a post when that post is restored, so an
	 * untrashed article comes back with a working prompt.
	 *
	 * @param int $post_id The post being restored.
	 * @return void
	 */
	public static function untrash_scoped_prompts_for_post( $post_id ) {
		foreach ( self::get_scoped_prompt_ids( $post_id, [ 'trash' ] ) as $prompt_id ) {
			wp_untrash_post( $prompt_id );
		}
	}

	/**
	 * Prompt IDs scoped to a post, in any status.
	 *
	 * @param int   $post_id  The article.
	 * @param array $statuses Post statuses to match.
	 * @return int[]
	 */
	private static function get_scoped_prompt_ids( $post_id, $statuses = [ 'publish', 'draft', 'pending', 'future', 'private' ] ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return [];
		}

		return get_posts(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => $statuses,
				'post_parent'    => $post_id,
				// Unbounded on purpose: this backs the delete cascade, where a silent
				// truncation would leave exactly the orphans it exists to prevent. The
				// result set is one prompt per article in practice.
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);
	}

	/**
	 * The single Contextual Prompt scoped to a post, as editable fields, or null.
	 *
	 * @param int $post_id The article.
	 * @return array|null { id, body, button_label, button_url, position, edit_link } or null.
	 */
	public static function get_scoped_prompt_for_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return null;
		}

		$ids = get_posts(
			[
				'post_type'        => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_parent'      => $post_id,
				'post_status'      => [ 'publish', 'draft', 'pending', 'future' ],
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			]
		);
		if ( empty( $ids ) ) {
			return null;
		}

		$prompt_id = (int) $ids[0];
		return [
			'id'           => $prompt_id,
			'body'         => (string) get_post_meta( $prompt_id, self::META_BODY, true ),
			'button_label' => (string) get_post_meta( $prompt_id, self::META_BUTTON_LABEL, true ),
			'button_url'   => (string) get_post_meta( $prompt_id, self::META_BUTTON_URL, true ),
			'position'     => (int) get_post_meta( $prompt_id, 'trigger_blocks_count', true ),
			'edit_link'    => get_edit_post_link( $prompt_id, 'rest' ),
			'enabled'      => 'publish' === get_post_status( $prompt_id ),
			'customized'   => self::is_customized( $prompt_id ),
		];
	}

	/**
	 * Show or hide a scoped prompt on its story without deleting it.
	 *
	 * Hidden = draft: drafts are already excluded from retrieve_scoped_popups(),
	 * so no display-side machinery is needed. Mirrors the wizard's own
	 * activate/deactivate behavior for prompts.
	 *
	 * @param int  $prompt_id The scoped prompt.
	 * @param bool $enabled   Whether the prompt should show on its story.
	 * @return int|\WP_Error The prompt ID, or an error.
	 */
	public static function set_scoped_prompt_enabled( $prompt_id, $enabled ) {
		$prompt_id = (int) $prompt_id;
		$prompt    = $prompt_id ? get_post( $prompt_id ) : null;

		if ( ! $prompt || Newspack_Popups::NEWSPACK_POPUPS_CPT !== $prompt->post_type || ! $prompt->post_parent ) {
			return new \WP_Error( 'newspack_popups_invalid_prompt', __( 'Not a Contextual Prompt.', 'newspack-popups' ), [ 'status' => 400 ] );
		}

		if ( $enabled ) {
			wp_publish_post( $prompt_id );
		} else {
			wp_update_post(
				[
					'ID'          => $prompt_id,
					'post_status' => 'draft',
				]
			);
		}

		return $prompt_id;
	}

	/**
	 * Update an existing scoped prompt's copy, button, and position.
	 *
	 * @param int   $prompt_id The scoped prompt.
	 * @param array $args      { body, button_label, button_url, position, ai_edited }.
	 * @return int|\WP_Error The prompt ID, or an error.
	 */
	public static function update_scoped_prompt( $prompt_id, array $args ) {
		$prompt_id = (int) $prompt_id;
		$prompt    = $prompt_id ? get_post( $prompt_id ) : null;

		if ( ! $prompt || Newspack_Popups::NEWSPACK_POPUPS_CPT !== $prompt->post_type || ! $prompt->post_parent ) {
			return new \WP_Error( 'newspack_popups_invalid_prompt', __( 'Not a Contextual Prompt.', 'newspack-popups' ), [ 'status' => 400 ] );
		}
		$body = trim( (string) ( $args['body'] ?? '' ) );
		if ( '' === $body ) {
			return new \WP_Error( 'newspack_popups_empty_body', __( 'Prompt copy cannot be empty.', 'newspack-popups' ), [ 'status' => 400 ] );
		}

		$button_label = (string) ( $args['button_label'] ?? __( 'Donate', 'newspack-popups' ) );
		$button_url   = (string) ( $args['button_url'] ?? '' );
		$position     = max( 0, (int) ( $args['position'] ?? 3 ) );

		// A prompt the publisher has restyled in the block editor is no longer ours
		// to regenerate: rewrite only the copy and CTA in place, so custom design
		// (and any blocks they added) survives an edit made from the panel.
		if ( self::is_customized( $prompt_id ) ) {
			$result = self::update_copy_in_place( $prompt->post_content, $body, $button_label, $button_url );

			// If the copy block was removed while customizing, there is nowhere to put
			// the new wording. Failing loudly beats a silent no-op: the panel would
			// otherwise reload from meta showing the edit while the story kept serving
			// the old copy, with nothing to reveal the divergence.
			if ( ! $result['copy_found'] ) {
				return new \WP_Error(
					'newspack_popups_copy_block_missing',
					__( 'This prompt was customized in Advanced settings and its copy block was removed, so the copy can no longer be edited here. Edit it in Advanced settings, or reset it to the default design.', 'newspack-popups' ),
					[ 'status' => 409 ]
				);
			}

			$content = $result['content'];
		} else {
			// Keep the mode this prompt was built with, so regenerating never silently
			// swaps its CTA out from under the publisher.
			$content = self::build_prompt_content( $body, $button_label, $button_url, self::get_cta_mode( $prompt_id ) );
		}

		$updated = wp_update_post(
			[
				'ID'           => $prompt_id,
				'post_content' => $content,
			],
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// Re-assert the scoped-prompt contract. A Contextual Prompt is always an
		// in-article ask, but placement/trigger are editable in the prompt CPT editor
		// (reachable from the panel's "Advanced settings" link) — without this, a
		// prompt flipped to an overlay placement would stay that way and render as a
		// full-screen takeover on the story.
		//
		// Frequency is deliberately NOT re-asserted: 'always' is only a creation
		// default, and capping frequency per-prompt is a supported publisher choice.
		$options_result = Newspack_Popups_Model::set_popup_options(
			$prompt_id,
			[
				'placement'            => 'inline',
				'trigger_type'         => 'blocks_count',
				'trigger_blocks_count' => (string) $position,
			]
		);
		if ( is_wp_error( $options_result ) ) {
			return $options_result;
		}

		// Keep it filed under the Contextual Prompts group so it stays identifiable
		// in the wizard and in Insights, even if it was un-filed after creation.
		self::assign_campaign_group( $prompt_id );

		self::store_prompt_fields( $prompt_id, $body, $button_label, $button_url );
		if ( isset( $args['ai_edited'] ) ) {
			update_post_meta( $prompt_id, self::META_AI_EDITED, ! empty( $args['ai_edited'] ) );
		}

		return $prompt_id;
	}

	/**
	 * Store the raw copy/button meta for round-tripping.
	 *
	 * @param int    $prompt_id    Prompt ID.
	 * @param string $body         Copy.
	 * @param string $button_label Button label.
	 * @param string $button_url   Button URL.
	 * @return void
	 */
	private static function store_prompt_fields( $prompt_id, $body, $button_label, $button_url ) {
		update_post_meta( $prompt_id, self::META_BODY, sanitize_textarea_field( $body ) );
		update_post_meta( $prompt_id, self::META_BUTTON_LABEL, sanitize_text_field( $button_label ) );
		update_post_meta( $prompt_id, self::META_BUTTON_URL, esc_url_raw( $button_url ) );
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

		// Upsert: a story has at most one Contextual Prompt. Creating blindly makes
		// this endpoint non-idempotent, and a duplicate would be unreachable — the
		// panel only ever reads the first prompt, while the display query returns
		// them all, so both would render with only one editable.
		$existing = self::get_scoped_prompt_ids( $post_id, [ 'publish', 'draft', 'pending', 'future', 'private' ] );
		if ( ! empty( $existing ) ) {
			return self::update_scoped_prompt(
				$existing[0],
				[
					'body'         => $body,
					'button_label' => $button_label,
					'button_url'   => $button_url,
					'position'     => $position,
					'ai_edited'    => $args['ai_edited'] ?? false,
				]
			);
		}

		$cta_mode = self::use_donate_block() ? self::CTA_MODE_DONATE_BLOCK : self::CTA_MODE_BUTTON;

		$prompt_id = wp_insert_post(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'  => (string) ( $args['status'] ?? 'publish' ),
				'post_parent'  => $post_id,
				/* translators: %s: parent post title. */
				'post_title'   => sprintf( __( 'Contextual prompt: %s', 'newspack-popups' ), get_the_title( $post_id ) ),
				'post_content' => self::build_prompt_content( $body, $button_label, $button_url, $cta_mode ),
			],
			true
		);

		if ( is_wp_error( $prompt_id ) ) {
			return $prompt_id;
		}

		// Recorded so is_customized() can rebuild the same baseline later even if the
		// site's donation platform changes in the meantime.
		update_post_meta( $prompt_id, self::META_CTA_MODE, $cta_mode );

		$options_result = Newspack_Popups_Model::set_popup_options(
			$prompt_id,
			[
				'placement'            => 'inline',
				'trigger_type'         => 'blocks_count',
				'trigger_blocks_count' => (string) $position,
				// Inline story CTAs show on every visit by default (like EngageLine);
				// publishers can cap frequency per-prompt in Advanced settings.
				'frequency'            => 'always',
			]
		);
		if ( is_wp_error( $options_result ) ) {
			return $options_result;
		}

		self::assign_campaign_group( $prompt_id );
		self::store_prompt_fields( $prompt_id, $body, $button_label, $button_url );

		update_post_meta( $prompt_id, self::META_AI_GENERATED, ! empty( $args['ai_generated'] ) );
		update_post_meta( $prompt_id, self::META_AI_TEMPLATE_VERSION, sanitize_text_field( (string) ( $args['template_version'] ?? '' ) ) );
		update_post_meta( $prompt_id, self::META_AI_REQUEST_ID, sanitize_text_field( (string) ( $args['request_id'] ?? '' ) ) );
		update_post_meta( $prompt_id, self::META_AI_EDITED, ! empty( $args['ai_edited'] ) );

		return $prompt_id;
	}

	/**
	 * Whether to render the native Newspack donate block rather than a plain
	 * button. Defaults to true when the publisher uses Newspack (WooCommerce)
	 * donations — then reader conversions classify as donations in analytics /
	 * Insights. Falls back to a plain button + URL for off-site donation setups.
	 *
	 * @return bool
	 */
	public static function use_donate_block() {
		$default = method_exists( '\Newspack\Donations', 'is_platform_wc' ) && \Newspack\Donations::is_platform_wc();

		/**
		 * Filters whether Contextual Prompts render the native donate block.
		 *
		 * @param bool $use_donate_block Whether to use the donate block.
		 */
		return (bool) apply_filters( 'newspack_contextual_prompts_use_donate_block', $default );
	}

	/**
	 * Build the block markup for a scoped prompt: the appeal copy plus a
	 * donation CTA, wrapped in a default styled call-out so it reads as a
	 * deliberate donation ask rather than inline body text.
	 *
	 * When the publisher uses Newspack donations, the CTA is the native donate
	 * block (so conversions are tracked as donations); otherwise it's a plain
	 * button linking to the provided donate URL. Publishers can restyle any
	 * individual prompt in Advanced settings, and the final visual design will
	 * be refined with design input.
	 *
	 * @param string $body         Appeal copy.
	 * @param string $button_label Button label (plain-button fallback only).
	 * @param string $button_url   Button URL (plain-button fallback only).
	 * @param string $cta_mode     CTA mode to build; defaults to the site's current one.
	 * @return string Serialized block markup.
	 */
	private static function build_prompt_content( $body, $button_label, $button_url, $cta_mode = null ) {
		if ( null === $cta_mode ) {
			$cta_mode = self::use_donate_block() ? self::CTA_MODE_DONATE_BLOCK : self::CTA_MODE_BUTTON;
		}

		// The __copy / __cta classes are stable anchors: once a publisher has
		// customized a prompt in the block editor we no longer regenerate its
		// content, and instead update just these blocks in place (see
		// update_copy_in_place()). They double as precise CSS handles.
		$inner = '<!-- wp:paragraph {"className":"' . self::COPY_CLASS . '","style":{"spacing":{"margin":{"top":"0","bottom":"16px"}}}} -->' . "\n"
			. '<p class="' . self::COPY_CLASS . '" style="margin-top:0;margin-bottom:16px">' . esc_html( $body ) . "</p>\n<!-- /wp:paragraph -->";

		if ( self::CTA_MODE_DONATE_BLOCK === $cta_mode ) {
			// Native Newspack donation form; uses the site's donation settings.
			$inner .= "\n<!-- wp:newspack-blocks/donate /-->";
		} elseif ( '' !== trim( $button_url ) ) {
			$inner .= "\n" . '<!-- wp:buttons {"className":"' . self::CTA_CLASS . '"} -->' . "\n"
				. '<div class="wp-block-buttons ' . self::CTA_CLASS . '"><!-- wp:button -->'
				. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $button_url ) . '">'
				. esc_html( $button_label )
				. "</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
		}

		// Structural only — no baked styling. The look comes from get_design_css()
		// at render time, keyed off the `newspack-contextual-prompt` class, so
		// changing the default design in Campaigns settings restyles every prompt
		// including ones already published (rather than only newly-created ones).
		$group_open = '<!-- wp:group {"className":"newspack-contextual-prompt","layout":{"type":"constrained"}} -->' . "\n"
			. '<div class="wp-block-group newspack-contextual-prompt">';

		return $group_open . "\n" . $inner . "\n</div>\n<!-- /wp:group -->";
	}

	/**
	 * Whether a prompt has been customized in the block editor.
	 *
	 * Self-detecting: compare the stored content against what we would generate
	 * from the prompt's own copy/button meta. Identical means untouched; anything
	 * else means a publisher has styled or restructured it via "Advanced
	 * settings", and we must stop regenerating it.
	 *
	 * Deliberately errs toward "customized" — if the block editor re-serializes
	 * markup on an otherwise no-op save, we preserve the publisher's version
	 * rather than overwriting work.
	 *
	 * @param int $prompt_id The scoped prompt.
	 * @return bool
	 */
	public static function is_customized( $prompt_id ) {
		$prompt_id = (int) $prompt_id;
		$prompt    = $prompt_id ? get_post( $prompt_id ) : null;
		if ( ! $prompt ) {
			return false;
		}

		// Rebuild the baseline with the CTA mode this prompt was BUILT with, not the
		// site's current one. Both are derived from Newspack donations being active,
		// so a publisher moving donations off-platform would otherwise flip every
		// pristine prompt to "customized" at once — each one then claiming a custom
		// design the publisher never made, and routing its copy edits onto the
		// in-place updater, where the CTA hooks no longer match.
		$pristine = self::build_prompt_content(
			(string) get_post_meta( $prompt_id, self::META_BODY, true ),
			(string) get_post_meta( $prompt_id, self::META_BUTTON_LABEL, true ),
			(string) get_post_meta( $prompt_id, self::META_BUTTON_URL, true ),
			self::get_cta_mode( $prompt_id )
		);

		return trim( $prompt->post_content ) !== trim( $pristine );
	}

	/**
	 * The CTA mode a prompt's content was generated with.
	 *
	 * Recorded at build time so the pristine baseline stays stable when the site's
	 * donation platform changes. Prompts created before this was stored fall back to
	 * detecting the mode from their own content.
	 *
	 * @param int $prompt_id Prompt ID.
	 * @return string 'donate_block' or 'button'.
	 */
	private static function get_cta_mode( $prompt_id ) {
		$stored = (string) get_post_meta( $prompt_id, self::META_CTA_MODE, true );
		if ( '' !== $stored ) {
			return $stored;
		}

		return false !== strpos( (string) get_post_field( 'post_content', $prompt_id ), 'newspack-blocks/donate' )
			? self::CTA_MODE_DONATE_BLOCK
			: self::CTA_MODE_BUTTON;
	}

	/**
	 * Update the copy and CTA of a customized prompt without disturbing anything
	 * else the publisher has done to it.
	 *
	 * Targets the generated blocks by their class hooks and rewrites only their
	 * inner text/href, so custom styling, wrapper changes and any extra blocks
	 * (a custom HTML block carrying the newsroom's own CSS, for example) survive.
	 *
	 * @param string $content      Existing block markup.
	 * @param string $body         New copy.
	 * @param string $button_label New button label.
	 * @param string $button_url   New button URL.
	 * @return array { string $content, bool $copy_found }
	 */
	private static function update_copy_in_place( $content, $body, $button_label, $button_url ) {
		$blocks     = parse_blocks( $content );
		$copy_found = self::replace_in_blocks( $blocks, self::COPY_CLASS, esc_html( $body ) );

		if ( '' !== trim( $button_url ) || '' !== trim( $button_label ) ) {
			self::replace_in_blocks( $blocks, self::CTA_CLASS, esc_html( $button_label ), esc_url( $button_url ) );
		}

		return [
			'content'    => serialize_blocks( $blocks ),
			'copy_found' => $copy_found,
		];
	}

	/**
	 * Recursively find the block carrying a class hook and rewrite its text (and
	 * optionally its link href), leaving every other block untouched.
	 *
	 * @param array  $blocks Blocks, by reference.
	 * @param string $class  Class hook to look for.
	 * @param string $text   Already-escaped replacement text.
	 * @param string $href   Already-escaped replacement href, if any.
	 * @return bool Whether a matching block was found.
	 */
	private static function replace_in_blocks( &$blocks, $class, $text, $href = null ) {
		foreach ( $blocks as &$block ) {
			$class_name = $block['attrs']['className'] ?? '';

			if ( is_string( $class_name ) && false !== strpos( $class_name, $class ) ) {
				// Swap the innermost text node, preserving surrounding markup.
				$block['innerHTML'] = self::replace_inner_text( $block['innerHTML'], $text, $href );
				foreach ( $block['innerContent'] as &$chunk ) {
					if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
						$chunk = self::replace_inner_text( $chunk, $text, $href );
					}
				}
				unset( $chunk );

				// A buttons wrapper holds the link in a nested button block.
				if ( null !== $href && ! empty( $block['innerBlocks'] ) ) {
					self::replace_link_in_blocks( $block['innerBlocks'], $text, $href );
				}
				return true;
			}

			if ( ! empty( $block['innerBlocks'] ) && self::replace_in_blocks( $block['innerBlocks'], $class, $text, $href ) ) {
				return true;
			}
		}
		unset( $block );

		return false;
	}

	/**
	 * Replace an element's children (and optionally an href) in a markup chunk.
	 *
	 * Uses preg_replace_callback throughout: the replacement text is untrusted model
	 * output, and preg_replace() would expand `$1` / `${1}` / `\1` sequences inside it
	 * — mangling ordinary donation copy ("Give $5 today" loses the amount) and letting
	 * `${n}` syntax reconstruct markup that esc_html() had already neutralised.
	 *
	 * Targets the element that actually holds the text rather than the first `>…<`
	 * span: in button markup (`<div><a …>Label</a></div>`) the first span is the empty
	 * one before `<a`, so a naive match writes the new label outside the link and
	 * leaves the old one in place.
	 *
	 * @param string $html Markup.
	 * @param string $text Already-escaped replacement text.
	 * @param string $href Already-escaped replacement href, if any.
	 * @return string
	 */
	private static function replace_inner_text( $html, $text, $href = null ) {
		if ( null !== $href && '' !== $href ) {
			$replaced = preg_replace_callback(
				'#(href=")[^"]*(")#',
				function ( $matches ) use ( $href ) {
					return $matches[1] . $href . $matches[2];
				},
				$html,
				1
			);
			if ( null !== $replaced ) {
				$html = $replaced;
			}
		}

		// An anchor owns its label; otherwise replace the outermost element's children.
		$pattern = preg_match( '#<a\b[^>]*>#i', $html )
			? '#(<a\b[^>]*>).*(</a>)#is'
			: '#(<([a-z][a-z0-9]*)\b[^>]*>).*(</\2>)#is';

		$replaced = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $text ) {
				// Closing tag is the last capture in either pattern.
				return $matches[1] . $text . $matches[ count( $matches ) - 1 ];
			},
			$html,
			1
		);

		return null === $replaced ? $html : $replaced;
	}

	/**
	 * Update the link inside a nested button block.
	 *
	 * @param array  $blocks Blocks, by reference.
	 * @param string $text   Already-escaped label.
	 * @param string $href   Already-escaped href.
	 * @return void
	 */
	private static function replace_link_in_blocks( &$blocks, $text, $href ) {
		foreach ( $blocks as &$block ) {
			if ( 'core/button' === ( $block['blockName'] ?? '' ) ) {
				$block['innerHTML'] = self::replace_inner_text( $block['innerHTML'], $text, $href );
				foreach ( $block['innerContent'] as &$chunk ) {
					if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
						$chunk = self::replace_inner_text( $chunk, $text, $href );
					}
				}
				unset( $chunk );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::replace_link_in_blocks( $block['innerBlocks'], $text, $href );
			}
		}
		unset( $block );
	}

	/**
	 * Discard a prompt's custom design, restoring the default treatment.
	 *
	 * @param int $prompt_id The scoped prompt.
	 * @return int|\WP_Error The prompt ID, or an error.
	 */
	public static function reset_prompt_design( $prompt_id ) {
		$prompt_id = (int) $prompt_id;
		$prompt    = $prompt_id ? get_post( $prompt_id ) : null;

		if ( ! $prompt || Newspack_Popups::NEWSPACK_POPUPS_CPT !== $prompt->post_type || ! $prompt->post_parent ) {
			return new \WP_Error( 'newspack_popups_invalid_prompt', __( 'Not a Contextual Prompt.', 'newspack-popups' ), [ 'status' => 400 ] );
		}

		$updated = wp_update_post(
			[
				'ID'           => $prompt_id,
				// Pinned to the mode this prompt was built with, so the rebuilt content
				// matches the baseline is_customized() compares against. Building with
				// the site's current mode instead would leave the prompt reading as
				// "customized" the instant the reset finished.
				'post_content' => self::build_prompt_content(
					(string) get_post_meta( $prompt_id, self::META_BODY, true ),
					(string) get_post_meta( $prompt_id, self::META_BUTTON_LABEL, true ),
					(string) get_post_meta( $prompt_id, self::META_BUTTON_URL, true ),
					self::get_cta_mode( $prompt_id )
				),
			],
			true
		);

		return is_wp_error( $updated ) ? $updated : $prompt_id;
	}

	/**
	 * CSS for the default Contextual Prompt design, built from the design tokens.
	 *
	 * Emitted at render time (see Newspack_Popups::enqueue_contextual_prompt_design())
	 * rather than baked into each prompt's content, so a settings change applies to
	 * every prompt on the site immediately — including already-published ones.
	 *
	 * Only background and accent are publisher-editable today; the rest are
	 * constants here so the out-of-the-box look is unchanged. Promoting one to a
	 * setting means adding a field in Newspack_Popups_Settings and reading it here.
	 * Full visual design is in progress (NPPD-2101).
	 *
	 * @return string CSS.
	 */
	public static function get_design_css() {
		$defaults   = Newspack_Popups_Settings::get_design_defaults();
		$background = (string) get_option( Newspack_Popups_Settings::DESIGN_BACKGROUND_OPTION, '' );
		$accent     = (string) get_option( Newspack_Popups_Settings::DESIGN_ACCENT_OPTION, '' );

		$background = '' !== $background ? $background : $defaults[ Newspack_Popups_Settings::DESIGN_BACKGROUND_OPTION ];
		$accent     = '' !== $accent ? $accent : $defaults[ Newspack_Popups_Settings::DESIGN_ACCENT_OPTION ];

		// Not yet publisher-editable.
		$border_color  = '#e2e4e7';
		$border_width  = '1px';
		$border_radius = '8px';
		$padding       = '24px';

		$css = '.newspack-contextual-prompt{'
			. 'background-color:' . $background . ';'
			. 'border:' . $border_width . ' solid ' . $border_color . ';'
			. 'border-radius:' . $border_radius . ';'
			. 'padding:' . $padding . ';'
			. '}';

		// Plain-button mode only. Prompts using the native donate block follow the
		// site's donation settings, so we deliberately don't restyle those.
		$css .= '.newspack-contextual-prompt .wp-block-button__link{'
			. 'background-color:' . $accent . ';'
			. 'border-color:' . $accent . ';'
			. '}';

		return $css;
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

		// Append rather than replace: this also runs on update, and a publisher may
		// have deliberately filed the prompt under an additional campaign group.
		wp_set_post_terms( $prompt_id, [ (int) $term_id ], $taxonomy, true );
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
	 * Discarding the context match is deliberate — "this one article" is the whole
	 * contract of a post-scoped prompt, so category/post-type targeting is not
	 * meaningful for it. This does not widen where prompts may appear: the
	 * account-related-post guard (checkout, my-account) runs upstream of this
	 * filter, so those surfaces stay suppressed.
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
		$popups = Newspack_Popups_Model::retrieve_scoped_popups( get_the_ID(), $include_unpublished );

		return array_map( [ __CLASS__, 'maybe_apply_override' ], $popups );
	}

	/**
	 * Swap a scoped prompt's content for the site-wide override CTA when the
	 * override ("fund-drive mode") is active.
	 *
	 * Only the content is swapped — position, frequency, campaign group and the
	 * prompt id (and therefore analytics attribution) remain the underlying
	 * prompt's own, so the override "takes over the positions" the way
	 * EngageLine's V1 campaign override did. Stories without a Contextual
	 * Prompt are unaffected.
	 *
	 * @param array $popup A popup object from retrieve_scoped_popups().
	 * @return array The popup, with content swapped while the override is active.
	 */
	private static function maybe_apply_override( $popup ) {
		if ( ! Newspack_Popups_Settings::is_override_active() ) {
			return $popup;
		}

		$label = (string) get_option( 'newspack_contextual_prompts_override_label', '' );

		$popup['content'] = self::build_prompt_content(
			(string) get_option( 'newspack_contextual_prompts_override_body', '' ),
			'' !== trim( $label ) ? $label : __( 'Donate', 'newspack-popups' ),
			(string) get_option( 'newspack_contextual_prompts_override_url', '' )
		);

		return $popup;
	}
}
