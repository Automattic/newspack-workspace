<?php
/**
 * Newspack Block Access Control.
 *
 * Per-block visibility control based on content restriction rules.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Block_Visibility class.
 */
class Block_Visibility {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'render_block', [ __CLASS__, 'filter_render_block' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
		add_filter( 'register_block_type_args', [ __CLASS__, 'register_block_type_args' ], 10, 2 );
	}

	/**
	 * Get the list of blocks that can be configured for access control visibility.
	 *
	 * @return array
	 */
	private static function get_target_blocks() {
		/**
		 * Filters the list of blocks that can be configured for access control visibility.
		 *
		 * @param array $target_blocks List of block names.
		 * @return array
		 */
		$target_blocks = apply_filters( 'newspack_content_gate_block_visibility_blocks', [ 'core/group', 'core/stack', 'core/row' ] );
		return $target_blocks;
	}

	/**
	 * Filter rendered block output based on access control attributes.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Block data.
	 * @return string
	 */
	public static function filter_render_block( $block_content, $block ) {
		if ( ! in_array( $block['blockName'] ?? '', self::get_target_blocks(), true ) ) {
			return $block_content;
		}

		// Bypass access control in admin screens so blocks are never hidden from
		// editors during content authoring.
		//
		// REST requests are deliberately NOT exempt: a context check is not a permission
		// check, and access has to be evaluated per requester. Authoring contexts that
		// arrive over REST (block renderer, preview, query-loop rendering inside the
		// editor) are covered by the `edit_post` check further down, which needs a post
		// in scope — where none is set up, it fails closed. Re-adding a blanket REST
		// exemption here would widen what is readable; the REST cases in
		// Newspack_Test_Block_Visibility pin the behaviour.
		if ( is_admin() ) {
			return $block_content;
		}

		$hidden = self::is_hidden_for_user( $block, get_current_user_id(), get_the_ID() );

		// This response now depends on who asked for it, and the page cache in front of
		// it cannot always tell requesters apart. Batcache skips a request only when it
		// carries an X-WP-Nonce header or a wp*/wordpress* cookie; an application
		// password sends neither, so a privileged REST render would be stored and then
		// served to the next anonymous caller. Cancel the store whenever this render
		// shows a block an anonymous reader would not see -- the withheld render is
		// still cached normally, which is the common case and the one worth caching.
		if ( self::render_varies_from_anonymous( $block, get_current_user_id(), get_the_ID() ) ) {
			self::prevent_page_cache();
		}

		return $hidden ? '' : $block_content;
	}

	/**
	 * Whether this render differs from the one an anonymous reader would get.
	 *
	 * Compares the two hide-decisions rather than testing a single direction.
	 * `visibility` is a two-way toggle, and is_hidden_for_user() inverts on it: with
	 * `hidden`, a reader who matches the rules has the block withheld while anonymous
	 * keeps it. That render varies per requester too, so a one-directional check
	 * ("shows a block anonymous would not see") misses it and lets it be cached.
	 *
	 * Split out so the decision is testable without inspecting response headers:
	 * batcache_cancel() and header() are both unobservable under PHPUnit, so a test
	 * written against them asserts nothing.
	 *
	 * Assumes an anonymous render never varies from another anonymous render. The
	 * `institution` rule contradicts that -- it sets supports_anonymous and evaluates
	 * on IP or cookie, so two anonymous readers can legitimately differ and the first
	 * one cached wins. That is pre-existing on the front-end path and unchanged here;
	 * it is tracked separately rather than assumed away.
	 *
	 * @param array    $block   Parsed block.
	 * @param int      $user_id Reader the response was rendered for.
	 * @param int|null $post_id Post in scope, for the edit-capability bypass.
	 * @return bool
	 */
	public static function render_varies_from_anonymous( $block, $user_id, $post_id = null ) {
		return self::is_hidden_for_user( $block, $user_id, $post_id ) !== self::is_hidden_for_user( $block, 0 );
	}

	/**
	 * Keep the current response out of the page cache.
	 *
	 * Batcache exposes batcache_cancel() for this; DONOTCACHEPAGE is not honoured by
	 * the build running on Atomic, so it is not enough on its own. The header covers
	 * any other cache in the path and is a no-op once output has started.
	 */
	private static function prevent_page_cache() {
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, max-age=0', true );
		}
	}

	/**
	 * Whether a block should be withheld from a given reader.
	 *
	 * Split out of filter_render_block() so callers that are not rendering — the
	 * excerpt path in particular — can ask the same question for a specific reader
	 * rather than the current one. Returning false means "show it", which covers
	 * every pass-through case: a non-target block, no active gates, no active rules.
	 *
	 * @param array    $block   Parsed block.
	 * @param int      $user_id Reader to evaluate against. 0 for logged-out.
	 * @param int|null $post_id Post the block is being rendered in, or null when
	 *                          there is no post context. Only used for the
	 *                          edit-capability bypass.
	 * @return bool
	 */
	public static function is_hidden_for_user( $block, $user_id, $post_id = null ) {
		if ( ! in_array( $block['blockName'] ?? '', self::get_target_blocks(), true ) ) {
			return false;
		}

		// This predicate evaluates rules itself rather than asking
		// `newspack_is_post_restricted`, so it needs the dependency stated explicitly.
		// Without it, with Audience Management off, a members-only block stayed hidden
		// while the article around it rendered in full — a hole in the page and no
		// registration surface to fill it.
		//
		// Reader Activation alone, NOT Content_Gate::is_gating_active(): block
		// visibility is flag-independent by design — this class registers
		// unconditionally, its editor panel loads without `NEWSPACK_CONTENT_GATES`,
		// and in `custom` mode a block needs no gate at all, just a registration rule.
		// So the feature is live on sites that never enabled content gates, and ANDing
		// in the constant would make blocks they deliberately hid render to everyone.
		// What this rule actually depends on is a reader's ability to register, which
		// is Reader Activation. So on a constant-off site with Reader Activation on,
		// blocks keep hiding while page-level gating is off — that asymmetry is left
		// in place knowingly, because block visibility predates the flag and is used
		// without it.
		//
		// Lives in this shared predicate rather than in filter_render_block() so the
		// excerpt path answers the same way: with Reader Activation off, an excerpt
		// must not withhold a block the page around it renders in full.
		// filter_render_block()'s admin and REST bypass still returns before reaching
		// here, so editor and REST renders don't pay for the option read.
		if ( ! Reader_Activation::is_enabled() ) {
			return false;
		}

		$mode       = $block['attrs']['newspackAccessControlMode'] ?? 'gate';
		$visibility = $block['attrs']['newspackAccessControlVisibility'] ?? 'visible';
		$gate_ids   = [];
		$rules      = [];

		if ( 'gate' === $mode ) {
			$gate_ids = array_filter( array_map( 'intval', $block['attrs']['newspackAccessControlGateIds'] ?? [] ) );
			if ( empty( $gate_ids ) ) {
				return false;
			}
			if ( ! self::has_active_gates( $gate_ids ) ) {
				return false;
			}
		} else {
			$rules = $block['attrs']['newspackAccessControlRules'] ?? [];

			// Defensive cast: the block parser can occasionally yield a stdClass for
			// object-typed attributes (e.g. after JSON round-trips).
			if ( is_object( $rules ) ) {
				$rules = (array) $rules;
			} elseif ( ! is_array( $rules ) ) {
				$rules = [];
			}

			$has_registration = ! empty( $rules['registration']['active'] );
			$has_access_rules = ! empty( $rules['custom_access']['active'] )
								&& ! empty( $rules['custom_access']['access_rules'] );

			if ( ! $has_registration && ! $has_access_rules ) {
				return false;
			}
		}

		// Don't restrict content for users who can edit the post it's in.
		if ( ! empty( $post_id ) && user_can( $user_id, 'edit_post', $post_id ) ) {
			return false;
		}

		$user_matches = ( 'gate' === $mode )
			? self::evaluate_gate_rules_for_user( $gate_ids, $user_id )
			: self::evaluate_rules_for_user( $rules, $user_id );

		return 'visible' === $visibility ? ! $user_matches : $user_matches;
	}

	/**
	 * Whether any block in the content carries access-control attributes.
	 *
	 * A withheld block always carries newspackAccessControlGateIds or
	 * newspackAccessControlRules, so content without that substring has nothing
	 * to strip. Callers use this to skip parse_blocks()/serialize_blocks()
	 * entirely, and to tell a post that uses the gate from one that does not.
	 *
	 * @param string $content Serialized block content.
	 * @return bool
	 */
	public static function has_access_control( $content ) {
		return false !== strpos( (string) $content, 'newspackAccessControl' );
	}

	/**
	 * Remove blocks that are withheld from a logged-out reader.
	 *
	 * Evaluated against the anonymous reader (user 0) rather than the current
	 * one. That is deliberate: Newspack_Blocks_Caching keys cached block markup
	 * without a user dimension, so reader-varying output would be served across
	 * readers. This does not guarantee an identical result for every anonymous
	 * visitor, though: the "institution" access rule supports anonymous
	 * evaluation and depends on request context (IP/cookie), so it can still
	 * vary between two anonymous requests.
	 *
	 * @param string $content Serialized block content.
	 * @return string Content with withheld blocks removed.
	 */
	public static function strip_blocks_hidden_from_public( $content ) {
		if ( ! self::has_access_control( $content ) ) {
			return $content;
		}
		if ( ! has_blocks( $content ) ) {
			return $content;
		}

		// A homepage runs this twice per post -- once from the priority-10 excerpt
		// filter, then again inside the priority-11 closure whose result discards the
		// first pass -- and each call is a full parse_blocks() plus recursive walk plus
		// serialize_blocks(). The three call sites cannot coordinate, so memoize here
		// instead. Keyed on the content because the decision is evaluated against the
		// anonymous reader either way, and pure within a request.
		$key = md5( $content );
		if ( ! isset( self::$strip_cache[ $key ] ) ) {
			self::$strip_cache[ $key ] = serialize_blocks( self::strip_hidden( parse_blocks( $content ) ) );
		}
		return self::$strip_cache[ $key ];
	}

	/**
	 * Recursive half of strip_blocks_hidden_from_public().
	 *
	 * Core's serialize_block() walks innerContent and consumes one innerBlocks
	 * entry per null marker, so dropping a child means dropping its marker too.
	 * Filtering innerBlocks alone runs the index past the end and throws from
	 * inside core.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private static function strip_hidden( $blocks ) {
		$kept = [];

		foreach ( $blocks as $block ) {
			if ( self::is_hidden_for_user( $block, 0 ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$inner_blocks  = [];
				$inner_content = [];
				$index         = 0;

				// innerContent is always set by parse_blocks(), but this method is public
				// API another plugin can call with a hand-built array, where a missing
				// key would silently drop every child.
				foreach ( (array) ( $block['innerContent'] ?? [] ) as $chunk ) {
					if ( is_string( $chunk ) ) {
						$inner_content[] = $chunk;
						continue;
					}
					$child = $block['innerBlocks'][ $index++ ] ?? null;
					if ( null === $child ) {
						continue;
					}
					// The recursive call evaluates the child itself and returns nothing
					// when it is withheld, so testing the result replaces a second
					// is_hidden_for_user() call here and makes $stripped[0] safe by
					// construction rather than by the two evaluations agreeing.
					$stripped = self::strip_hidden( [ $child ] );
					if ( empty( $stripped ) ) {
						continue;
					}
					$inner_blocks[]  = $stripped[0];
					$inner_content[] = null;
				}

				$block['innerBlocks']  = $inner_blocks;
				$block['innerContent'] = $inner_content;
			}

			$kept[] = $block;
		}

		return $kept;
	}

	/**
	 * Register block attributes server-side for target block types.
	 *
	 * @param array  $args       Block type arguments.
	 * @param string $block_type Block type name.
	 * @return array
	 */
	public static function register_block_type_args( $args, $block_type ) {
		if ( ! in_array( $block_type, self::get_target_blocks(), true ) ) {
			return $args;
		}

		$args['attributes'] = array_merge(
			$args['attributes'] ?? [],
			[
				'newspackAccessControlVisibility' => [
					'type'    => 'string',
					'default' => 'visible',
				],
				'newspackAccessControlMode'       => [
					'type'    => 'string',
					'default' => 'gate',
				],
				'newspackAccessControlGateIds'    => [
					'type'    => 'array',
					'default' => [],
					'items'   => [
						'type' => 'integer',
					],
				],
				'newspackAccessControlRules'      => [
					'type'    => 'object',
					'default' => (object) [],
				],
			]
		);
		return $args;
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		// No panel while the rules it configures are inert for readers — offering
		// controls that do nothing is worse than offering none. Matches the predicate
		// filter_render_block() enforces on, so the panel is present exactly when the
		// settings it writes have an effect.
		//
		// Only the panel. register_block_type_args() stays unconditional on purpose:
		// the attributes must keep round-tripping through save and load, so a block
		// configured before Audience Management was switched off still carries its
		// settings and starts applying again the moment it is switched back on.
		//
		// That works because core bootstraps the PHP-registered attributes into the
		// client registry independently of our script: get_block_editor_server_block_settings()
		// prints them on `wp-blocks`, the bootstrap reducer keeps that first definition,
		// and processBlockType merges it under the block's JS settings — which for
		// core/group carry no `attributes` key. So the parser keeps and re-serializes
		// newspackAccessControl* even with this script suppressed. Registering these
		// attributes ONLY from the JS side would therefore be silent data loss on every
		// re-save; keep the PHP registration primary. The same caveat applies to any
		// block added through `newspack_content_gate_block_visibility_blocks` that is
		// not registered in PHP.
		if ( ! Reader_Activation::is_enabled() ) {
			return;
		}

		$available_post_types = array_column(
			Content_Restriction_Control::get_available_post_types(),
			'value'
		);
		// get_post_type() returns false in the Site Editor / widget screens where
		// no post is in context — in_array( false, [...], true ) is false, so the
		// asset is correctly suppressed. This mirrors the guard in Content_Gate.
		if ( ! in_array( get_post_type(), $available_post_types, true ) ) {
			return;
		}

		$asset_file = dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-block-visibility.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'newspack-content-gate-block-visibility',
			Newspack::plugin_url() . '/dist/content-gate-block-visibility.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'newspack-content-gate-block-visibility',
			Newspack::plugin_url() . '/dist/content-gate-block-visibility.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'newspack-content-gate-block-visibility',
			'newspackBlockVisibility',
			[
				'target_blocks'          => self::get_target_blocks(),
				'available_access_rules' => Access_Rules::get_access_rules_for_client(),
				'available_gates'        => array_values(
					array_map(
						function( $gate ) {
							return [
								'id'    => $gate['id'],
								'title' => $gate['title'],
							];
						},
						Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' )
					)
				),
			]
		);
	}

	/**
	 * Per-request cache: keyed by "{user_id}:{md5(rules)}" or "gate:{user_id}:{md5(gate_ids)}".
	 *
	 * @var bool[]
	 */
	private static $rules_match_cache = [];

	/**
	 * Per-request cache of stripped content, keyed by md5 of the input.
	 *
	 * @var string[]
	 */
	private static $strip_cache = [];

	/**
	 * Reset the per-request caches. Used in unit tests only.
	 */
	public static function reset_cache_for_tests() {
		self::$rules_match_cache = [];
		self::$strip_cache       = [];
	}

	/**
	 * Public wrapper for tests. Calls evaluate_rules_for_user().
	 *
	 * @param array $rules   Rules array.
	 * @param int   $user_id User ID.
	 * @return bool
	 */
	public static function evaluate_rules_for_user_public( $rules, $user_id ) {
		return self::evaluate_rules_for_user( $rules, $user_id );
	}

	/**
	 * Evaluate whether a user matches the block's custom access rules (with caching).
	 *
	 * @param array $rules   Parsed newspackAccessControlRules attribute.
	 * @param int   $user_id User ID (0 for logged-out).
	 * @return bool True if user matches (should be treated as "matching reader").
	 */
	private static function evaluate_rules_for_user( $rules, $user_id ) {
		$cache_key = $user_id . ':' . md5( wp_json_encode( $rules ) );
		if ( isset( self::$rules_match_cache[ $cache_key ] ) ) {
			return self::$rules_match_cache[ $cache_key ];
		}

		$result                            = self::compute_rules_match( $rules, $user_id );
		self::$rules_match_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Return true if at least one gate in the list is published and accessible.
	 *
	 * Used as an early-exit guard in filter_render_block() so that a block whose
	 * only gates have all been deleted or unpublished is treated as unrestricted,
	 * regardless of the block's visibility setting.
	 *
	 * @param int[] $gate_ids Array of np_content_gate post IDs.
	 * @return bool
	 */
	private static function has_active_gates( $gate_ids ) {
		foreach ( $gate_ids as $gate_id ) {
			$gate = Content_Gate::get_gate( $gate_id );
			if ( ! \is_wp_error( $gate ) && 'publish' === $gate['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate whether a user matches any of the given gate's access rules (with caching).
	 *
	 * Assumes at least one gate in $gate_ids is active; call has_active_gates() first
	 * when a pass-through fallback is needed for fully-inactive gate lists.
	 *
	 * @param int[] $gate_ids Array of np_content_gate post IDs.
	 * @param int   $user_id  User ID (0 for logged-out).
	 * @return bool
	 */
	private static function evaluate_gate_rules_for_user( $gate_ids, $user_id ) {
		$cache_key = 'gate:' . $user_id . ':' . md5( wp_json_encode( $gate_ids ) );
		if ( isset( self::$rules_match_cache[ $cache_key ] ) ) {
			return self::$rules_match_cache[ $cache_key ];
		}

		$result                            = self::compute_gate_rules_match( $gate_ids, $user_id );
		self::$rules_match_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Compute whether a user matches the access rules of any of the given gates (uncached).
	 *
	 * @param int[] $gate_ids Array of np_content_gate post IDs.
	 * @param int   $user_id  User ID (0 for logged-out).
	 * @return bool
	 */
	private static function compute_gate_rules_match( $gate_ids, $user_id ) {
		$has_active_gate = false;

		foreach ( $gate_ids as $gate_id ) {
			$gate = Content_Gate::get_gate( $gate_id );

			// Deleted gate: Content_Gate::get_gate() returns WP_Error when the post
			// doesn't exist. Unpublished gates have status !== 'publish'. Both are
			// skipped so only currently-active gates impose restrictions.
			if ( \is_wp_error( $gate ) || 'publish' !== $gate['status'] ) {
				continue;
			}

			$has_active_gate = true;

			$rules = [
				'registration'  => $gate['registration'],
				'custom_access' => $gate['custom_access'],
			];

			// OR logic: the user passes if they satisfy any single active gate's rules.
			if ( self::compute_rules_match( $rules, $user_id ) ) {
				return true;
			}
		}

		// All gates were deleted or unpublished → no active restriction → pass-through.
		return ! $has_active_gate;
	}

	/**
	 * Compute whether a user matches the block's access rules (uncached).
	 *
	 * @param array $rules   Parsed newspackAccessControlRules attribute.
	 * @param int   $user_id User ID (0 for logged-out).
	 * @return bool
	 */
	private static function compute_rules_match( $rules, $user_id ) {
		$registration  = $rules['registration'] ?? [];
		$custom_access = $rules['custom_access'] ?? [];

		$registration_passes = true;
		if ( ! empty( $registration['active'] ) ) {
			if ( ! $user_id ) {
				$registration_passes = false;
			} elseif ( ! empty( $registration['require_verification'] ) ) {
				$registration_passes = (bool) get_user_meta( $user_id, Reader_Activation::EMAIL_VERIFIED, true );
			}
		}

		$access_passes = true;
		if ( ! empty( $custom_access['active'] ) && ! empty( $custom_access['access_rules'] ) ) {
			// Gate-derived rules carry the gate's stored setting; rules parsed from
			// block attributes never contain the key, so block-attribute visibility
			// is deliberately always grace-ON — the block editor exposes no
			// payment-recovery toggle, and a reader in the retry window should see
			// member-only blocks just as they can pass the gate itself.
			$rule_context  = [ 'payment_recovery_grace' => $custom_access['payment_recovery_grace'] ?? true ];
			$access_passes = Access_Rules::evaluate_rules( $custom_access['access_rules'], $user_id, $rule_context );
		}

		// AND logic: both must pass when both are configured.
		return $registration_passes && $access_passes;
	}
}
Block_Visibility::init();
