<?php
/**
 * Contextual Prompt synced pattern.
 *
 * Owns the `wp_block` post every Contextual Prompt instance references: seeding
 * it on demand with a locked Group holding the bound copy paragraph and the CTA
 * for the site's donation platform, and the one compare-and-swap write helper
 * every later change to its markup goes through.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contextual Prompt pattern class.
 */
final class Newspack_Popups_Contextual_Prompt_Pattern {
	const OPTION_PATTERN_ID     = 'newspack_contextual_prompts_pattern_id';
	const OPTION_STAMPED_ACCENT = 'newspack_contextual_prompts_stamped_accent';
	const MARKER_CLASS          = 'newspack-contextual-prompt';
	const BOUND_NAME            = 'Prompt Copy';
	const SEEDING_LOCK_OPTION   = 'newspack_contextual_prompts_seeding';
	const SEEDING_LOCK_TTL      = 30;

	/**
	 * The pattern's own name, in its Group metadata. Not translated: it is stored
	 * in post content, where a locale switch must not rewrite it.
	 */
	const PATTERN_NAME = 'Contextual Prompt';

	/**
	 * Every block in the pattern is editable but fixed in place: instances are
	 * meant to differ by copy alone.
	 */
	const BLOCK_LOCK = [
		'move'   => true,
		'remove' => true,
	];

	/**
	 * Register the hooks that keep the pattern out of reach. Registered whether
	 * or not the feature is on: rolling it back must not expose the pattern to
	 * deletion, since deleting it would empty every instance a re-enabled site
	 * still carries. Each callback reads the record raw and no-ops without one,
	 * so a site that never seeded a pattern is unaffected.
	 */
	public static function init_protection() {
		add_filter( 'map_meta_cap', [ __CLASS__, 'protect_pattern' ], 10, 4 );
		add_filter( 'pre_delete_post', [ __CLASS__, 'prevent_pattern_deletion' ], 10, 2 );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'lock_pattern_editor' ], 10, 2 );
		add_filter( 'rest_wp_block_query', [ __CLASS__, 'hide_pattern_from_collections' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'hide_pattern_from_admin_list' ] );
		add_filter( 'wp_count_posts', [ __CLASS__, 'hide_pattern_from_counts' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ __CLASS__, 'lock_pattern_title' ], 10, 2 );
		add_filter( 'rest_pre_insert_wp_block', [ __CLASS__, 'prevent_pattern_duplication' ], 10, 2 );
	}

	/**
	 * Keep the pattern's title fixed. The editor's Rename action is not
	 * capability-gated the way Delete is, so a rename is reverted at the data
	 * layer instead; the rest of the save (design, description) goes through.
	 *
	 * @param array $data    Slashed post data about to be written.
	 * @param array $postarr Raw post array, carrying the target ID.
	 *
	 * @return array
	 */
	public static function lock_pattern_title( $data, $postarr ) {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id || (int) ( $postarr['ID'] ?? 0 ) !== $pattern_id ) {
			return $data;
		}

		$current = get_post( $pattern_id );
		if ( $current ) {
			$data['post_title'] = wp_slash( $current->post_title );
		}

		return $data;
	}

	/**
	 * Refuse a second pattern built around the prompt. The editor's Duplicate
	 * action would create a look-alike wp_block whose design edits silently go
	 * nowhere, so a new pattern may not carry the marker.
	 *
	 * @param stdClass        $prepared_post Post object about to be inserted.
	 * @param WP_REST_Request $request       The create/update request.
	 *
	 * @return stdClass|WP_Error
	 */
	public static function prevent_pattern_duplication( $prepared_post, $request ) {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id || ! empty( $prepared_post->ID ) ) {
			return $prepared_post;
		}

		if ( false === strpos( (string) ( $prepared_post->post_content ?? '' ), self::MARKER_CLASS ) ) {
			return $prepared_post;
		}

		return new WP_Error(
			'newspack_popups_contextual_prompt_duplicate',
			__( 'A new pattern cannot contain the Contextual Prompt.', 'newspack-popups' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Register hooks.
	 */
	public static function init() {
		// \Newspack\Donations may not be loaded when hooks register, so the option
		// name it owns is spelled out rather than read off the class. Configuring
		// the platform for the first time adds the option rather than updating
		// one, so both hooks are needed.
		add_action( 'update_option_newspack_reader_revenue_platform', [ __CLASS__, 'repair' ] );
		add_action( 'add_option_newspack_reader_revenue_platform', [ __CLASS__, 'repair' ] );
		// Newspack_Popups_Settings is included after this runs, so the option it
		// owns is spelled out too. Hooked on the option rather than on the endpoint
		// that writes it, so a flip made over WP-CLI leaves the same site: the
		// first opt-in adds the option, and withdrawing it is an opt-out.
		add_action( 'add_option_newspack_contextual_prompts_enabled', [ __CLASS__, 'follow_opt_in_added' ], 10, 2 );
		add_action( 'update_option_newspack_contextual_prompts_enabled', [ __CLASS__, 'follow_opt_in_changed' ], 10, 2 );
		add_action( 'delete_option_newspack_contextual_prompts_enabled', [ __CLASS__, 'follow_opt_in_withdrawn' ] );
	}

	/**
	 * The site opting in for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  The opt-in state stored.
	 */
	public static function follow_opt_in_added( $option, $value ) {
		self::follow_opt_in( $value );
	}

	/**
	 * The site changing its mind.
	 *
	 * @param mixed $old_value The previous opt-in state.
	 * @param mixed $value     The opt-in state stored.
	 */
	public static function follow_opt_in_changed( $old_value, $value ) {
		self::follow_opt_in( $value );
	}

	/**
	 * The opt-in withdrawn entirely, which is an opt-out.
	 */
	public static function follow_opt_in_withdrawn() {
		self::follow_opt_in( false );
	}

	/**
	 * Follow the site's opt-in: the pattern is the feature's design, so opting out
	 * takes it off the site and opting back in puts it back.
	 *
	 * Opting out never deletes it. Every prompt already published references the
	 * pattern by id and carries its story-specific copy as an override on that
	 * reference, so the prompts a newsroom wrote come back only if this same
	 * pattern does — a newsroom that pauses AI use over a policy and resumes
	 * months later keeps its work. While the site is opted out the instances stay
	 * in the content and render nothing.
	 *
	 * @param mixed $enabled The opt-in state.
	 */
	private static function follow_opt_in( $enabled ) {
		if ( ! $enabled ) {
			self::retire_pattern();
			return;
		}

		// Restored, never seeded: seeding stays on demand, so opting in writes
		// nothing until the wizard or the editor asks for the pattern — which is
		// also what puts a pattern back on a site that lost its own.
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( $pattern_id && 'wp_block' === get_post_type( $pattern_id ) && 'publish' !== get_post_status( $pattern_id ) ) {
			self::restore_pattern( $pattern_id );
		}
	}

	/**
	 * Unpublish the pattern, so an opted-out site carries no Contextual Prompt
	 * design: it leaves the patterns browser and the editor, and an instance
	 * resolves to nothing. Trashed by preference; a site that disables the trash
	 * deletes on trash instead, which the deletion guard refuses, so the status is
	 * written directly there. get_pattern_id() restores either in place.
	 */
	private static function retire_pattern() {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id || 'wp_block' !== get_post_type( $pattern_id ) || 'publish' !== get_post_status( $pattern_id ) ) {
			return;
		}

		wp_trash_post( $pattern_id );

		if ( 'publish' === get_post_status( $pattern_id ) ) {
			self::update_pattern_post(
				[
					'ID'          => $pattern_id,
					'post_status' => 'draft',
				]
			);
		}
	}

	/**
	 * Deny deleting the pattern: every instance references it, so losing it would
	 * empty them all. And deny editing it to anyone but an administrator: it is
	 * the design every prompt on the site renders, not a post an author owns —
	 * core reads the same capability to hide "Edit original" and to refuse the
	 * editor route. The raw option is what the guard compares against — a
	 * capability check must never seed.
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Context, with the object ID — or the post itself —
	 *                          at index 0.
	 *
	 * @return string[]
	 */
	public static function protect_pattern( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, [ 'delete_post', 'edit_post' ], true ) || empty( $args[0] ) ) {
			return $caps;
		}

		// map_meta_cap passes on whatever the caller gave current_user_can(), which
		// for a post capability may be the post object.
		$object_id  = (int) ( $args[0] instanceof WP_Post ? $args[0]->ID : $args[0] );
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id || $object_id !== $pattern_id ) {
			return $caps;
		}

		if ( 'delete_post' === $cap || ! user_can( $user_id, 'manage_options' ) ) {
			return [ 'do_not_allow' ];
		}

		return $caps;
	}

	/**
	 * Refuse to delete the pattern outright, whatever asks. The capability guard
	 * covers what the publisher can reach, but wp_delete_post() checks none — and
	 * an opted-out site keeps its pattern in the trash, where core's scheduled
	 * purge would take it after EMPTY_TRASH_DAYS and orphan every instance the
	 * site still carries. A pattern the record does not name is nobody's to keep,
	 * including the orphan a losing seeder deletes.
	 *
	 * @param WP_Post|false|null $delete Whether to short-circuit the deletion.
	 * @param WP_Post            $post   The post about to be deleted.
	 *
	 * @return WP_Post|false|null
	 */
	public static function prevent_pattern_deletion( $delete, $post ) {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );

		return $pattern_id && (int) $post->ID === $pattern_id ? false : $delete;
	}

	/**
	 * Keep the pattern out of the patterns browser and the inserter: a post takes
	 * one prompt, placed from the Contextual Prompt panel, so the pattern is not a
	 * thing to insert by hand. Only collections are filtered — the single-item
	 * route is how an instance resolves its content, and how the editor opens the
	 * pattern to edit its design.
	 *
	 * @param array $args Query arguments for the collection.
	 *
	 * @return array
	 */
	public static function hide_pattern_from_collections( $args ) {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id ) {
			return $args;
		}

		$exclude   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : [];
		$exclude[] = $pattern_id;

		// One id, on a collection only the editor requests: the VIP caution is about
		// exclusion sets large enough to defeat the index.
		$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in

		return $args;
	}

	/**
	 * Keep the pattern out of the Patterns list table too. It is not a pattern the
	 * publisher composes with — it is the design behind a feature, edited from the
	 * Contextual Prompts screen — and a row offering Edit, Trash and Export for
	 * something none of those may do to it is a dead end. The single-post routes
	 * are untouched, so the design still opens from the wizard.
	 *
	 * @param WP_Query $query The query about to run.
	 */
	public static function hide_pattern_from_admin_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'wp_block' !== $query->get( 'post_type' ) ) {
			return;
		}

		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id ) {
			return;
		}

		$exclude   = (array) $query->get( 'post__not_in' );
		$exclude[] = $pattern_id;

		// One id, on the patterns screen only: the VIP caution is about exclusion
		// sets large enough to defeat the index.
		$query->set( 'post__not_in', $exclude ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
	}

	/**
	 * And out of the counts the screen's status links are built from, so the
	 * pattern is not a row nobody can see.
	 *
	 * @param stdClass $counts Post counts by status.
	 * @param string   $type   Post type the counts are for.
	 *
	 * @return stdClass
	 */
	public static function hide_pattern_from_counts( $counts, $type ) {
		if ( 'wp_block' !== $type ) {
			return $counts;
		}

		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		$status     = $pattern_id ? get_post_status( $pattern_id ) : false;
		if ( $status && isset( $counts->$status ) && $counts->$status > 0 ) {
			--$counts->$status;
		}

		return $counts;
	}

	/**
	 * Hide block locking in the editor that opens the pattern: its locks are what
	 * keep instances uniform, so they are not the publisher's to lift.
	 *
	 * @param array                   $settings Block editor settings.
	 * @param WP_Block_Editor_Context $context  Editor context.
	 *
	 * @return array
	 */
	public static function lock_pattern_editor( $settings, $context ) {
		if ( ! empty( $context->post ) && (int) $context->post->ID === (int) get_option( self::OPTION_PATTERN_ID, 0 ) ) {
			$settings['canLockBlocks'] = false;
		}

		return $settings;
	}

	/**
	 * The pattern post ID, seeding on demand. A record pointing at a post that is
	 * merely unpublished — trashed, drafted — is restored in place: every instance
	 * references it by id, so a replacement would empty them all. Only a record
	 * pointing at nothing is re-seeded.
	 *
	 * @return int Pattern post ID, or 0 when seeding failed.
	 */
	public static function get_pattern_id() {
		$id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( $id && 'wp_block' === get_post_type( $id ) ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				self::restore_pattern( $id );
			}
			return $id;
		}

		// Hold a lock while inserting, so two concurrent first calls can't both seed
		// a pattern. A caller that loses the claim yields rather than seeding a
		// second one: instances made against it would never be managed again. It
		// answers with the winner's pattern only once that pattern exists — a
		// record still pointing at nothing is no answer, and a caller handed one
		// would address instances at a hole.
		$claim = self::claim_seeding_lock();
		if ( ! $claim ) {
			$recorded = (int) get_option( self::OPTION_PATTERN_ID, 0 );

			return $recorded && 'wp_block' === get_post_type( $recorded ) ? $recorded : 0;
		}

		try {
			$new_id = self::insert_pattern();
			if ( ! $new_id ) {
				return 0;
			}

			return self::finish_seed( $new_id );
		} finally {
			self::release_seeding_lock( $claim );
		}
	}

	/**
	 * Insert the pattern post, under the site's own locale. Seeding runs from the
	 * admin or the REST API, where the language in effect is the seeding
	 * administrator's — while the title and copy this stores are the site's, read
	 * by every reader and every later publisher.
	 *
	 * @return int The inserted post ID, or 0 when the insert failed.
	 */
	private static function insert_pattern() {
		$locale   = get_locale();
		$switched = $locale !== determine_locale() && switch_to_locale( $locale );

		try {
			$new_id = wp_insert_post(
				wp_slash(
					[
						'post_type'    => 'wp_block',
						'post_status'  => 'publish',
						'post_title'   => __( 'Contextual Prompt', 'newspack-popups' ),
						'post_excerpt' => self::pattern_description(),
						'post_content' => self::build_pattern_content(),
					]
				)
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		return is_wp_error( $new_id ) ? 0 : (int) $new_id;
	}

	/**
	 * What the pattern says about itself in the editor's summary panel.
	 *
	 * @return string
	 */
	private static function pattern_description() {
		return __( 'The Contextual Prompt design used across the site. Changes here apply to every story; the copy itself is written in each story.', 'newspack-popups' );
	}

	/**
	 * Put the pattern back to the design this plugin seeds, discarding whatever
	 * has been made of it since — the way out of an edit that went wrong, in an
	 * editor where the usual answer, deleting the pattern and starting again, is
	 * denied. Only the design is replaced: the copy each prompt carries lives on
	 * its own instance and is untouched.
	 *
	 * @return bool Whether the pattern was reset.
	 */
	public static function reset_pattern() {
		$pattern_id = self::get_pattern_id();
		if ( ! $pattern_id ) {
			return false;
		}

		if ( ! self::save_pattern_content( $pattern_id, self::build_pattern_content() ) ) {
			return false;
		}

		// The description is the pattern editor's to edit too, and it describes
		// what this pattern is for rather than what it looks like.
		self::update_pattern_post(
			[
				'ID'           => $pattern_id,
				'post_excerpt' => self::pattern_description(),
			]
		);

		return true;
	}

	/**
	 * Whether the stored design differs from the one this plugin seeds — which is
	 * the only state a reset has anything to undo.
	 *
	 * Both sides are parsed and re-serialized with their attributes in a settled
	 * order first: opening the pattern and saving it rewrites the block markup in
	 * the editor's own order, and that is not an edit to the design.
	 *
	 * @return bool
	 */
	public static function is_design_modified() {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id || 'wp_block' !== get_post_type( $pattern_id ) ) {
			return false;
		}

		return self::canonical_content( get_post( $pattern_id )->post_content ) !== self::canonical_content( self::build_pattern_content() );
	}

	/**
	 * Block markup in a form two serializations of the same design agree on:
	 * attributes in key order, and the class lists the editor writes in its own
	 * order settled into one.
	 *
	 * @param string $content Serialized block markup.
	 *
	 * @return string
	 */
	private static function canonical_content( $content ) {
		return trim( serialize_blocks( self::canonicalize_blocks( parse_blocks( $content ) ) ) );
	}

	/**
	 * Settle every block's attributes and markup, innermost included.
	 *
	 * @param array $blocks Parsed blocks.
	 *
	 * @return array
	 */
	private static function canonicalize_blocks( $blocks ) {
		foreach ( $blocks as $index => $block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$blocks[ $index ]['attrs'] = self::sort_attrs_deep( $block['attrs'] );
			}
			if ( isset( $block['innerHTML'] ) ) {
				$blocks[ $index ]['innerHTML'] = self::sort_html_classes( $block['innerHTML'] );
			}
			foreach ( $block['innerContent'] ?? [] as $chunk_index => $chunk ) {
				$blocks[ $index ]['innerContent'][ $chunk_index ] = self::sort_html_classes( $chunk );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::canonicalize_blocks( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

	/**
	 * Sort the class list of every tag in a markup chunk.
	 *
	 * @param mixed $html Markup chunk, or null for a child placeholder.
	 *
	 * @return mixed
	 */
	private static function sort_html_classes( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$classes = $processor->get_attribute( 'class' );
			if ( ! is_string( $classes ) ) {
				continue;
			}

			$tokens = preg_split( '/\s+/', trim( $classes ), -1, PREG_SPLIT_NO_EMPTY );
			sort( $tokens );
			$processor->set_attribute( 'class', implode( ' ', $tokens ) );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Sort an attribute array by key, recursively.
	 *
	 * @param array $attrs Block attributes.
	 *
	 * @return array
	 */
	private static function sort_attrs_deep( $attrs ) {
		ksort( $attrs );
		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) ) {
				$attrs[ $key ] = self::sort_attrs_deep( $value );
			}
		}

		return $attrs;
	}

	/**
	 * Claim the right to seed, the way core's upgrader claims its lock: an
	 * INSERT IGNORE, which exactly one of two concurrent first loads can win.
	 * add_option() would report success to both — it is a cached existence check
	 * followed by an INSERT ... ON DUPLICATE KEY UPDATE. The claim is timestamped
	 * rather than expiring on its own, so a request that died mid-seed blocks
	 * seeding for seconds rather than for good, and the reclaim is conditional on
	 * the stale value so only one of the callers that find it can take it.
	 *
	 * The held value is read from the table rather than through get_option(),
	 * whose cache the raw INSERT above does not refresh.
	 *
	 * @return string|null The claim held, which releasing it is conditional on,
	 *                     or null when this caller may not seed.
	 */
	private static function claim_seeding_lock() {
		global $wpdb;

		$claim   = (string) time();
		$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options API cannot express an atomic claim.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				self::SEEDING_LOCK_OPTION,
				$claim
			)
		);
		if ( $claimed ) {
			return $claim;
		}

		$held = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The claim above is not in the options cache.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SEEDING_LOCK_OPTION )
		);
		if ( null === $held || time() - (int) $held < self::SEEDING_LOCK_TTL ) {
			return null;
		}

		$reclaimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options API cannot express an atomic claim.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$claim,
				self::SEEDING_LOCK_OPTION,
				$held
			)
		);

		return 1 === (int) $reclaimed ? $claim : null;
	}

	/**
	 * Release the claim, and only the claim: a request whose own claim went stale
	 * and was reclaimed while it worked would otherwise delete the lock its
	 * successor is holding, and hand a third request the right to seed a second
	 * pattern.
	 *
	 * @param string $claim The value this request claimed with.
	 */
	private static function release_seeding_lock( $claim ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options API cannot express a conditional delete.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::SEEDING_LOCK_OPTION,
				$claim
			)
		);

		wp_cache_delete( self::SEEDING_LOCK_OPTION, 'options' );
	}

	/**
	 * Record the pattern just inserted, unless another request recorded a live one
	 * while it was being written. The record is what every instance is made
	 * against, so a losing seeder adopts that pattern and drops its own: keeping
	 * it would leave a pattern nothing manages, and instances nothing can repair.
	 * A record pointing at nothing is the stale one this call is replacing.
	 *
	 * The delete guard denies the recorded id only, so the orphan is deletable —
	 * and wp_delete_post() checks no capability in any case.
	 *
	 * @param int $new_id The inserted pattern post ID.
	 *
	 * @return int The pattern ID to use.
	 */
	private static function finish_seed( $new_id ) {
		$recorded = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( $recorded && $recorded !== $new_id && 'wp_block' === get_post_type( $recorded ) ) {
			wp_delete_post( $new_id, true );
			return $recorded;
		}

		update_option( self::OPTION_PATTERN_ID, $new_id );

		return $new_id;
	}

	/**
	 * Republish the pattern rather than seeding a replacement. wp_untrash_post()
	 * restores to draft, so the publish follows it.
	 *
	 * @param int $pattern_id Pattern post ID.
	 */
	private static function restore_pattern( $pattern_id ) {
		if ( 'trash' === get_post_status( $pattern_id ) ) {
			wp_untrash_post( $pattern_id );
		}

		if ( 'publish' !== get_post_status( $pattern_id ) ) {
			self::update_pattern_post(
				[
					'ID'          => $pattern_id,
					'post_status' => 'publish',
				]
			);
		}
	}

	/**
	 * Write markup to the pattern post, swapping it in only while the row still
	 * carries the revision the content was derived from. The compare and the swap
	 * are the one statement: a check followed by a write leaves a window in which
	 * a save from the pattern editor lands and is then overwritten with content
	 * read from before it. A write naming no revision swaps on whatever is stored
	 * now, which is the same statement against a revision read a moment earlier.
	 *
	 * The row is written directly, so the values go in unslashed: slashing is
	 * wp_update_post()'s unslash to undo, and the escapes serialize_blocks()
	 * emits would survive it into the stored markup.
	 *
	 * @param int         $pattern_id Pattern post ID.
	 * @param string      $content    Serialized block markup.
	 * @param string|null $read_at    The post_modified_gmt the content was read
	 *                                at, or null to read the current one.
	 *
	 * @return bool Whether the write landed.
	 */
	public static function save_pattern_content( $pattern_id, $content, $read_at = null ) {
		global $wpdb;

		$pattern_id = (int) $pattern_id;
		$read_at    = null === $read_at ? self::read_modified_gmt( $pattern_id ) : $read_at;
		if ( null === $read_at ) {
			return false;
		}

		$written = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The Posts API cannot express a conditional write.
			$wpdb->posts,
			[
				'post_content'      => $content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			],
			[
				'ID'                => $pattern_id,
				'post_modified_gmt' => $read_at,
			]
		);

		// No row matched: the pattern moved on under this write.
		if ( ! $written ) {
			return false;
		}

		clean_post_cache( $pattern_id );

		return true;
	}

	/**
	 * When the pattern was last written, read from the table: the post cache
	 * still holds whatever this request read, which is the copy a freshness
	 * check exists to doubt.
	 *
	 * @param int $pattern_id Pattern post ID.
	 *
	 * @return string|null Post modified time in GMT, or null when the post is gone.
	 */
	private static function read_modified_gmt( $pattern_id ) {
		global $wpdb;

		return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cached read cannot answer whether the cache is stale.
			$wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", (int) $pattern_id )
		);
	}

	/**
	 * Update the pattern post's own fields — its status — with KSES suspended.
	 * wp_update_post() carries the stored markup through the write, and a status
	 * change can land in a context with no unfiltered_html, where filtering would
	 * mangle the publisher's own content and repair would then stabilize on the
	 * mangled copy. Markup is not written here: save_pattern_content() swaps the
	 * row itself, which no filter sees.
	 *
	 * @param array $postarr Post data, unslashed.
	 *
	 * @return bool Whether the write landed.
	 */
	private static function update_pattern_post( $postarr ) {
		// Restored rather than re-initialized unconditionally: a context that never
		// had the filters must not come out of this with them installed.
		$filtered = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $filtered ) {
			kses_remove_filters();
		}

		try {
			$result = wp_update_post( wp_slash( $postarr ) );
		} finally {
			if ( $filtered ) {
				kses_init_filters();
			}
		}

		return ! is_wp_error( $result ) && (bool) $result;
	}

	/**
	 * Reconcile the stored pattern with the site: its CTA with the current
	 * donation platform, its donate color with the theme's accent, the marker
	 * class and the bound name every instance is addressed through with the ones
	 * this class relies on. Runs when the platform changes and, defensively, on
	 * the first instance of a request — a pattern the editor opens has to show
	 * what readers actually see.
	 *
	 * The record is read raw and never seeded: a hook must not create the
	 * pattern.
	 */
	public static function repair() {
		$pattern_id = (int) get_option( self::OPTION_PATTERN_ID, 0 );
		if ( ! $pattern_id || 'wp_block' !== get_post_type( $pattern_id ) ) {
			return;
		}

		// The site wants the native CTA but the block is gone (Newspack Blocks
		// deactivated). Persisting that fallback would discard the publisher's
		// donate configuration for good; the render path still stands in.
		if ( self::wants_donate_block() && ! self::use_donate_block() ) {
			return;
		}

		$post    = get_post( $pattern_id );
		$read_at = $post->post_modified_gmt;
		$blocks  = parse_blocks( $post->post_content );
		$stamp   = null;

		foreach ( $blocks as $index => $group ) {
			if ( ! self::is_prompt_card( $group ) ) {
				continue;
			}

			// Each runs, then the results are weighed: written back on their own,
			// because the CTA branches below can bail out of persisting the group.
			$restored = [
				self::restore_marker_class( $group ),
				self::restore_copy_binding( $group['innerBlocks'] ),
				self::repin_bound_name( $group['innerBlocks'] ),
			];
			if ( in_array( true, $restored, true ) ) {
				$blocks[ $index ] = $group;
			}

			// Before normalization, which can replace the child the record
			// describes.
			$restamped = self::maybe_restamp_accent( $group );

			$before = Newspack_Popups_Contextual_Prompt_Render::find_cta( $group );
			$group  = Newspack_Popups_Contextual_Prompt_Render::normalize_cta( $group );
			$after  = Newspack_Popups_Contextual_Prompt_Render::find_cta( $group );

			// Nothing is configured to point a CTA at, so normalization dropped it.
			// Persisting that fallback would discard the publisher's CTA for good —
			// the pattern takes no inserts, so nothing could put one back; the
			// render path still stands in.
			if ( null !== $before && null === $after ) {
				continue;
			}

			$was_donate = 'newspack-blocks/donate' === ( $before['name'] ?? '' );
			$is_donate  = 'newspack-blocks/donate' === ( $after['name'] ?? '' );
			if ( $was_donate !== $is_donate ) {
				$stamp = $is_donate ? (string) ( $group['innerBlocks'][ $after['index'] ]['attrs']['buttonColor'] ?? '' ) : '';
			} elseif ( null !== $restamped ) {
				$stamp = $restamped;
			}

			$blocks[ $index ] = $group;
		}

		$content = serialize_blocks( $blocks );
		if ( $content === $post->post_content ) {
			return;
		}

		// The record describes the stored pattern's donate child, so it is only
		// truthful once that pattern has actually been written — which a pattern
		// saved from the editor since it was read here refuses, rather than
		// overwriting the publisher's edit with content derived from before it.
		if ( self::save_pattern_content( $pattern_id, $content, $read_at ) && null !== $stamp ) {
			self::record_stamp( $stamp );
		}
	}

	/**
	 * Whether a parsed block is the prompt card. The marker class is what the
	 * render pipeline, the editor and the analytics stamp all key on, so a card
	 * the publisher stripped it from has to be recognizable by something else to
	 * be repairable at all: the name the seed writes into the Group's metadata.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_prompt_card( $block ) {
		if ( 'core/group' !== ( $block['blockName'] ?? '' ) ) {
			return false;
		}

		return false !== strpos( (string) ( $block['attrs']['className'] ?? '' ), self::MARKER_CLASS )
			|| self::PATTERN_NAME === ( $block['attrs']['metadata']['name'] ?? '' );
	}

	/**
	 * Hold the card to its marker class. The Additional CSS classes field can
	 * take it off, and it is the card's whole identity — analytics, placement and
	 * the handling of a detached card all find the card by it. Classes the
	 * publisher added are theirs and are kept.
	 *
	 * The saved wrapper carries the class too, and core validates a block against
	 * what it would serialize now: restoring one without the other is the block
	 * recovery prompt on the next open.
	 *
	 * @param array $group Parsed prompt card, mutated in place.
	 * @return bool Whether anything changed.
	 */
	private static function restore_marker_class( &$group ) {
		$classes = trim( (string) ( $group['attrs']['className'] ?? '' ) );
		if ( in_array( self::MARKER_CLASS, preg_split( '/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY ), true ) ) {
			return false;
		}

		$group['attrs']['className'] = '' === $classes ? self::MARKER_CLASS : $classes . ' ' . self::MARKER_CLASS;

		$marked = self::mark_wrapper( $group['innerHTML'] ?? '' );
		if ( null !== $marked ) {
			$group['innerHTML'] = $marked;
		}

		foreach ( $group['innerContent'] ?? [] as $chunk_index => $chunk ) {
			$marked = self::mark_wrapper( $chunk );
			if ( null !== $marked ) {
				$group['innerContent'][ $chunk_index ] = $marked;
				break;
			}
		}

		return true;
	}

	/**
	 * Add the marker class to a markup chunk's opening tag.
	 *
	 * @param mixed $html Markup chunk, or null for a child placeholder.
	 * @return string|null The marked markup, or null when there was no tag to mark.
	 */
	private static function mark_wrapper( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return null;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() || $processor->has_class( self::MARKER_CLASS ) ) {
			return null;
		}

		$processor->add_class( self::MARKER_CLASS );

		return $processor->get_updated_html();
	}

	/**
	 * Restore the copy paragraph's override binding. The pattern editor's
	 * Overrides control can switch it off, and the binding is the address every
	 * prompt's copy is written to: without it each instance renders the pattern's
	 * own copy and the story-specific copy already written is orphaned. So the
	 * first paragraph — the copy child — is bound back under the seeded name.
	 *
	 * @param array $blocks Card children, mutated in place.
	 * @return bool Whether anything changed.
	 */
	private static function restore_copy_binding( &$blocks ) {
		foreach ( $blocks as $index => $block ) {
			if ( 'core/paragraph' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$bindings = $block['attrs']['metadata']['bindings'] ?? [];
			foreach ( $bindings as $binding ) {
				if ( 'core/pattern-overrides' === ( $binding['source'] ?? '' ) ) {
					return false;
				}
			}

			$bindings['__default']                             = [ 'source' => 'core/pattern-overrides' ];
			$blocks[ $index ]['attrs']['metadata']['name']     = self::BOUND_NAME;
			$blocks[ $index ]['attrs']['metadata']['bindings'] = $bindings;

			return true;
		}

		return false;
	}

	/**
	 * Hold the pattern to exactly one bound field: the copy paragraph. Its name is
	 * the key every instance's copy is stored under, not a label — renaming it in
	 * the pattern editor would orphan the copy of every prompt already written, so
	 * the seeded name is pinned back. Overrides enabled on any other child would
	 * share that one key, so their bindings are dropped.
	 *
	 * @param array $blocks Parsed blocks, mutated in place.
	 * @return bool Whether anything changed.
	 */
	private static function repin_bound_name( &$blocks ) {
		$changed = false;

		foreach ( $blocks as $index => $block ) {
			$bound = false;
			foreach ( $block['attrs']['metadata']['bindings'] ?? [] as $binding ) {
				if ( 'core/pattern-overrides' === ( $binding['source'] ?? '' ) ) {
					$bound = true;
					break;
				}
			}

			if ( $bound && 'core/paragraph' === ( $block['blockName'] ?? '' ) ) {
				if ( self::BOUND_NAME !== ( $block['attrs']['metadata']['name'] ?? '' ) ) {
					$blocks[ $index ]['attrs']['metadata']['name'] = self::BOUND_NAME;
					$changed = true;
				}
			} elseif ( $bound ) {
				self::strip_overrides_binding( $blocks[ $index ] );
				$changed = true;
			}

			if ( ! empty( $block['innerBlocks'] ) && self::repin_bound_name( $blocks[ $index ]['innerBlocks'] ) ) {
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Drop a block's pattern-overrides binding, and the name that keyed it once
	 * nothing else binds by it. Bindings from other sources are the publisher's.
	 *
	 * @param array $block Parsed block, mutated in place.
	 */
	private static function strip_overrides_binding( &$block ) {
		$bindings = $block['attrs']['metadata']['bindings'];
		foreach ( $bindings as $key => $binding ) {
			if ( 'core/pattern-overrides' === ( $binding['source'] ?? '' ) ) {
				unset( $bindings[ $key ] );
			}
		}

		if ( ! empty( $bindings ) ) {
			$block['attrs']['metadata']['bindings'] = $bindings;
			return;
		}

		unset( $block['attrs']['metadata']['bindings'], $block['attrs']['metadata']['name'] );
		if ( empty( $block['attrs']['metadata'] ) ) {
			unset( $block['attrs']['metadata'] );
		}
	}

	/**
	 * Follow the theme's accent color, but only on a donate child still carrying
	 * the color the seed stamped: anything else is the publisher's own choice.
	 * With no record — a site seeded off-site, or before the record existed —
	 * seeded and chosen colors are indistinguishable, so nothing is touched.
	 *
	 * The record is not written here: it describes the stored pattern, so only a
	 * caller that goes on to store the mutated group may move it.
	 *
	 * @param array $group Parsed prompt card, mutated in place.
	 * @return string|null The color stamped, or null when nothing was restamped.
	 */
	public static function maybe_restamp_accent( &$group ) {
		$recorded = (string) get_option( self::OPTION_STAMPED_ACCENT, '' );
		if ( '' === $recorded || ! self::use_donate_block() ) {
			return null;
		}

		$accent = self::get_accent_color();
		if ( ! $accent || $accent === $recorded ) {
			return null;
		}

		foreach ( $group['innerBlocks'] ?? [] as $index => $child ) {
			if ( 'newspack-blocks/donate' !== ( $child['blockName'] ?? '' ) || $recorded !== ( $child['attrs']['buttonColor'] ?? '' ) ) {
				continue;
			}
			$group['innerBlocks'][ $index ]['attrs']['buttonColor'] = $accent;
			return $accent;
		}

		return null;
	}

	/**
	 * The pattern's serialized markup.
	 *
	 * @return string
	 */
	public static function build_pattern_content() {
		return serialize_blocks( [ self::build_group() ] );
	}

	/**
	 * The prompt card: a marker-classed Group that takes no further blocks,
	 * holding the bound copy paragraph and the CTA.
	 *
	 * @return array Parsed core/group block.
	 */
	private static function build_group() {
		$text_color = self::get_text_color_slug();
		$font_size  = self::get_font_size_slug();
		$classes    = 'wp-block-group ' . self::MARKER_CLASS . ' has-text-color has-' . $text_color . '-color has-background has-' . $font_size . '-font-size';
		$wrapper    = '<div class="' . $classes . '" style="border-radius:10px;background-color:#f7f7f7;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">';

		return [
			'blockName'    => 'core/group',
			'attrs'        => [
				'metadata'     => [ 'name' => self::PATTERN_NAME ],
				'className'    => self::MARKER_CLASS,
				'templateLock' => 'insert',
				'lock'         => self::BLOCK_LOCK,
				'textColor'    => $text_color,
				'style'        => [
					'color'   => [ 'background' => '#f7f7f7' ],
					'border'  => [ 'radius' => '10px' ],
					'spacing' => [
						'padding'  => [
							'top'    => 'var:preset|spacing|50',
							'right'  => 'var:preset|spacing|50',
							'bottom' => 'var:preset|spacing|50',
							'left'   => 'var:preset|spacing|50',
						],
						'blockGap' => 'var:preset|spacing|30',
					],
				],
				'fontSize'     => $font_size,
				'layout'       => [ 'type' => 'constrained' ],
			],
			'innerBlocks'  => [ self::build_copy_child(), self::build_cta_child() ],
			'innerHTML'    => $wrapper . '</div>',
			'innerContent' => [ "\n" . $wrapper, null, "\n\n", null, "</div>\n" ],
		];
	}

	/**
	 * The copy paragraph, bound to pattern overrides so each instance carries its
	 * own story-specific copy. Seeded with a general ask, which is what an
	 * instance nobody has written copy for falls back to — a working prompt
	 * rather than a card that suppresses itself.
	 *
	 * @return array Parsed core/paragraph block.
	 */
	private static function build_copy_child() {
		$copy = esc_html__( 'Reporting like this takes time and costs money. If you value it, consider supporting our newsroom.', 'newspack-popups' );

		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [
				'metadata' => [
					'name'     => self::BOUND_NAME,
					'bindings' => [ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
				],
				'lock'     => self::BLOCK_LOCK,
			],
			'innerBlocks'  => [],
			'innerHTML'    => "\n<p>" . $copy . "</p>\n",
			'innerContent' => [ "\n<p>" . $copy . "</p>\n" ],
		];
	}

	/**
	 * The CTA for the site's donation platform.
	 *
	 * @return array Parsed CTA block.
	 */
	private static function build_cta_child() {
		return self::use_donate_block() ? self::build_donate_child() : self::build_buttons_child();
	}

	/**
	 * The native donate block, stamped with the theme's accent color.
	 *
	 * Only the callers that persist the result record the stamp. A render-time
	 * rebuild is thrown away when the request ends, and recording it would leave
	 * the record describing a child no stored pattern carries — which the restamp
	 * would then read as a color the publisher chose, and never touch again.
	 *
	 * @param bool $record Whether to record the stamp.
	 *
	 * @return array Parsed newspack-blocks/donate block.
	 */
	public static function build_donate_child( $record = true ) {
		$attrs  = [ 'className' => 'is-style-modern' ];
		$accent = self::get_accent_color();
		if ( $accent ) {
			$attrs['buttonColor'] = $accent;
		}
		$attrs['lock'] = self::BLOCK_LOCK;

		if ( $record ) {
			self::record_stamp( (string) $accent );
		}

		return [
			'blockName'    => 'newspack-blocks/donate',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * Record the color the stored pattern's donate child was stamped with, so a
	 * later restamp can tell it from one the publisher chose. Nothing stamped
	 * means nothing to record.
	 *
	 * @param string $color Hex color, or an empty string.
	 */
	private static function record_stamp( $color ) {
		if ( '' === $color ) {
			delete_option( self::OPTION_STAMPED_ACCENT );
			return;
		}

		update_option( self::OPTION_STAMPED_ACCENT, $color );
	}

	/**
	 * A single link button to the donor landing page. Seeded without a
	 * destination when none is configured — core saves such a button href-less,
	 * and the render pipeline drops it rather than showing a dead ask.
	 *
	 * The site-wide override passes its own destination and label; everything
	 * else takes the donation settings.
	 *
	 * @param string|null $url  Button destination, or null for the configured one.
	 * @param string|null $text Button label, unescaped, or null for the default.
	 *
	 * @return array Parsed core/buttons block.
	 */
	public static function build_buttons_child( $url = null, $text = null ) {
		$url    = null === $url ? self::get_button_url() : (string) $url;
		$text   = null === $text ? self::get_button_text() : (string) $text;
		$href   = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';
		$anchor = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . esc_html( $text ) . '</a></div>';

		return [
			'blockName'    => 'core/buttons',
			'attrs'        => [ 'lock' => self::BLOCK_LOCK ],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/button',
					'attrs'        => '' !== $url ? [ 'url' => $url ] : [],
					'innerBlocks'  => [],
					'innerHTML'    => "\n" . $anchor . "\n",
					'innerContent' => [ "\n" . $anchor . "\n" ],
				],
			],
			'innerHTML'    => '<div class="wp-block-buttons"></div>',
			'innerContent' => [ "\n" . '<div class="wp-block-buttons">', null, "</div>\n" ],
		];
	}

	/**
	 * Whether the CTA is the native Newspack donate block rather than a plain
	 * button. Defaults to true when the publisher uses Newspack (WooCommerce)
	 * donations — then reader conversions classify as donations in analytics /
	 * Insights. Falls back to a plain button for off-site donation setups, and
	 * whenever the donate block itself isn't registered (Newspack Blocks
	 * inactive) — an unregistered child would render nothing, losing the ask.
	 *
	 * @return bool
	 */
	public static function use_donate_block() {
		return self::wants_donate_block() && \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' );
	}

	/**
	 * Whether the site's donation settings call for the native CTA, before asking
	 * whether the block is there to render it. The two differ exactly while
	 * Newspack Blocks is inactive, which is a reason to fall back for one render
	 * — not to rewrite what the publisher configured.
	 *
	 * The class guard is method_exists(), which is false for a class that isn't
	 * loaded — and \Newspack\Donations may well not be.
	 *
	 * @return bool
	 */
	public static function wants_donate_block() {
		$default = method_exists( '\Newspack\Donations', 'is_platform_wc' ) && \Newspack\Donations::is_platform_wc();

		/**
		 * Filters whether Contextual Prompts render the native donate block.
		 *
		 * @param bool $use_donate_block Whether to use the donate block.
		 */
		return (bool) apply_filters( 'newspack_contextual_prompts_use_donate_block', $default );
	}

	/**
	 * The palette slug the card's text is seeded with. The two theme families name
	 * their body-text color differently, and a slug the active palette does not
	 * declare would leave the editor showing a color it cannot resolve.
	 *
	 * @return string Palette color slug.
	 */
	public static function get_text_color_slug() {
		return wp_is_block_theme() ? 'contrast' : 'dark-gray';
	}

	/**
	 * The typography preset the card's text is seeded with. Both families offer an
	 * "M" step, under different slugs — the classic theme declares no `medium`, and
	 * a slug it does not declare would leave the size control empty with no CSS
	 * behind the class.
	 *
	 * @return string Font size slug.
	 */
	public static function get_font_size_slug() {
		return wp_is_block_theme() ? 'medium' : 'normal';
	}

	/**
	 * The theme's accent color: the "accent" palette color on block themes, the
	 * Newspack primary color on the classic theme.
	 *
	 * @return string|null Hex color, or null when none can be resolved.
	 */
	public static function get_accent_color() {
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );
		foreach ( [ 'custom', 'theme' ] as $origin ) {
			foreach ( $palette[ $origin ] ?? [] as $color ) {
				if ( 'accent' === ( $color['slug'] ?? '' ) && ! empty( $color['color'] ) ) {
					return $color['color'];
				}
			}
		}

		if ( function_exists( 'Newspack\newspack_get_theme_colors' ) ) {
			$colors = \Newspack\newspack_get_theme_colors();
			if ( ! empty( $colors['primary_color'] ) ) {
				return $colors['primary_color'];
			}
		}

		return null;
	}

	/**
	 * The button CTA's destination.
	 *
	 * @return string
	 */
	public static function get_button_url() {
		return Newspack_Popups::get_donor_landing_url();
	}

	/**
	 * The button CTA's label.
	 *
	 * @return string
	 */
	public static function get_button_text() {
		return __( 'Donate', 'newspack-popups' );
	}
}
