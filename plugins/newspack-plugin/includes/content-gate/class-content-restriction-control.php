<?php
/**
 * Newspack Content Restriction Control
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Newsletters\Subscription_Lists;

/**
 * Main class.
 */
class Content_Restriction_Control {
	/**
	 * Map of post IDs to gate IDs.
	 *
	 * @var int[]
	 */
	private static $post_gate_id_map = [];

	/**
	 * Map of post IDs to gate layout IDs.
	 *
	 * @var int[]
	 */
	private static $post_gate_layout_id_map = [];

	/**
	 * Request-scoped cache of the gates that apply to a post, keyed by post ID.
	 * The gate set a post matches depends only on the post and the (request-stable)
	 * gate configuration, so it is safe to memoise for the request lifetime. Caches
	 * the empty (no-gates) result too, which matters for the Premium Newsletters
	 * cron loop that evaluates the same post for many readers.
	 *
	 * @var array<int,array>
	 */
	private static $post_gates_map = [];

	/**
	 * Request-scoped cache of a hierarchical term's descendant IDs, keyed by
	 * "{taxonomy}:{term_id}". The term hierarchy is request-stable, so this avoids
	 * re-walking the tree across content rules and across get_post_gates() calls.
	 *
	 * @var array<string,int[]>
	 */
	private static $term_descendants_map = [];

	/**
	 * Post meta key for exempting a post from access control restrictions.
	 *
	 * @var string
	 */
	const IS_EXEMPT_META_KEY = 'newspack_content_restriction_is_exempt';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_filter( 'newspack_is_post_restricted', [ __CLASS__, 'is_post_restricted' ], 10, 2 );
	}

	/**
	 * Get the post types that can be restricted.
	 */
	public static function get_available_post_types() {
		$available_post_types = array_values(
			array_map(
				function( $post_type ) {
					return [
						'value' => $post_type->name,
						'label' => $post_type->label,
					];
				},
				get_post_types(
					[
						'public'       => true,
						'show_in_rest' => true,
						'_builtin'     => false,
					],
					'objects'
				)
			)
		);

		return apply_filters(
			'newspack_content_gate_supported_post_types',
			array_merge(
				[
					[
						'value' => 'post',
						'label' => 'Posts',
					],
					[
						'value' => 'page',
						'label' => 'Pages',
					],
				],
				$available_post_types
			)
		);
	}

	/**
	 * Get the taxonomies that can be restricted.
	 * By default, this includes all public taxonomies that apply to available post types.
	 *
	 * @return array Array of taxonomies.
	 */
	public static function get_available_taxonomies() {
		$available_taxonomies = [
			[
				'slug'        => 'category',
				'label'       => 'Categories',
				'description' => __( 'Content within specific categories.', 'newspack-plugin' ),
			],
			[
				'slug'        => 'post_tag',
				'label'       => 'Tags',
				'description' => __( 'Content labeled with certain tags.', 'newspack-plugin' ),
			],
		];

		return apply_filters(
			'newspack_content_gate_supported_taxonomies',
			$available_taxonomies
		);
	}

	/**
	 * Get post gates.
	 *
	 * @param int $post_id Optional post ID.
	 *
	 * @return array Array of post gates.
	 */
	public static function get_post_gates( $post_id = null ) {
		$post_id = $post_id ?? \get_the_ID();
		if ( ! $post_id ) {
			return [];
		}
		if ( array_key_exists( $post_id, self::$post_gates_map ) ) {
			return self::$post_gates_map[ $post_id ];
		}
		$is_newsletter = false;
		if ( class_exists( 'Newspack\Newsletters\Subscription_Lists' ) && Subscription_Lists::CPT && get_post_type( $post_id ) === Subscription_Lists::CPT ) {
			$is_newsletter = true;
		}

		$gates = Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish', $is_newsletter );
		if ( empty( $gates ) ) {
			self::$post_gates_map[ $post_id ] = [];
			return self::$post_gates_map[ $post_id ];
		}

		$post_gates = [];
		foreach ( $gates as $gate ) {
			if ( 'publish' !== $gate['status'] ) {
				continue;
			}

			// Skip gates that have neither registration nor custom access active.
			if ( empty( $gate['registration']['active'] ) && empty( $gate['custom_access']['active'] ) ) {
				continue;
			}

			$content_rules = $gate['content_rules'];

			// Inclusion override: if this post ID is listed in any specific_posts rule
			// for this gate, the gate applies regardless of other rules.
			$specific_match = false;
			foreach ( $content_rules as $content_rule ) {
				if ( 'specific_posts' === $content_rule['slug']
					&& ! empty( $content_rule['value'] )
					&& in_array( (int) $post_id, array_map( 'intval', (array) $content_rule['value'] ), true )
				) {
					$specific_match = true;
					break;
				}
			}
			if ( $specific_match ) {
				$post_gates[] = $gate;
				continue;
			}

			// Exclusion rules are always-applied carve-outs: content matching an
			// exclusion is never gated, regardless of the match mode. The match mode
			// (default AND) only governs how inclusion rules combine. A gate with no
			// inclusion rules applies to everything that isn't carved out.
			$match                 = 'any' === ( $gate['content_rules_match'] ?? 'all' ) ? 'any' : 'all';
			$has_non_specific_rule = false;
			$has_inclusion_rule    = false;
			$inclusion_satisfied   = 'all' === $match; // AND starts satisfied; OR needs a hit.
			$is_excluded           = false;
			foreach ( $content_rules as $content_rule ) {
				if ( 'specific_posts' === $content_rule['slug'] ) {
					// Override-only; already evaluated above.
					continue;
				}
				$has_non_specific_rule = true;
				$matches               = self::rule_matches_post( $content_rule, $post_id );
				$is_exclusion          = isset( $content_rule['exclusion'] ) && $content_rule['exclusion'];

				if ( $is_exclusion ) {
					// rule_matches_post() returns false for an exclusion when the post
					// IS in the excluded set; such a post is carved out entirely.
					if ( ! $matches ) {
						$is_excluded = true;
						break;
					}
					continue; // A non-applying exclusion neither gates nor carves out.
				}

				// Inclusion rule: fold into the AND/OR combination.
				$has_inclusion_rule = true;
				if ( 'all' === $match ) {
					if ( ! $matches ) {
						$inclusion_satisfied = false;
					}
				} elseif ( $matches ) {
					$inclusion_satisfied = true;
				}
			}

			// If the gate ONLY had a specific_posts rule and we got here, the override
			// didn't match — don't include this gate.
			if ( ! $has_non_specific_rule ) {
				continue;
			}

			// Carved out by an exclusion rule — never gated, regardless of match mode.
			if ( $is_excluded ) {
				continue;
			}

			// With inclusion rules present, the post must satisfy their combination.
			if ( $has_inclusion_rule && ! $inclusion_satisfied ) {
				continue;
			}

			$post_gates[] = $gate;
		}
		self::$post_gates_map[ $post_id ] = $post_gates;
		return $post_gates;
	}

	/**
	 * Whether a single (non specific_posts) content rule matches the post.
	 *
	 * @param array $content_rule Rule with 'slug', 'value', optional 'exclusion'.
	 * @param int   $post_id      Post ID.
	 *
	 * @return bool
	 */
	private static function rule_matches_post( $content_rule, $post_id ) {
		$is_exclusion = isset( $content_rule['exclusion'] ) && $content_rule['exclusion'];

		if ( 'post_types' === $content_rule['slug'] ) {
			$in = in_array( get_post_type( $post_id ), $content_rule['value'], true );
			return $is_exclusion ? ! $in : $in;
		}

		if ( 'newsletters' === $content_rule['slug'] ) {
			return in_array( $post_id, array_map( 'intval', $content_rule['value'] ), true );
		}

		$taxonomy = get_taxonomy( $content_rule['slug'] );
		if ( ! $taxonomy ) {
			return false;
		}
		$terms = wp_get_post_terms( $post_id, $content_rule['slug'], [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		if ( ! $is_exclusion && ! $terms ) {
			return false;
		}
		$target_terms = self::expand_hierarchical_terms( (array) $content_rule['value'], $taxonomy );
		$overlap      = ! empty( array_intersect( $terms, $target_terms ) );
		return $is_exclusion ? ! $overlap : $overlap;
	}

	/**
	 * Expand a set of taxonomy term IDs to include descendant terms when the
	 * taxonomy is hierarchical.
	 *
	 * A content rule targeting a parent term should also match content assigned
	 * only to that term's descendants, mirroring WooCommerce Memberships' cascade
	 * behavior. Expansion happens at evaluation time so newly-added child terms
	 * are covered without re-saving the rule. Non-hierarchical taxonomies (e.g.
	 * tags) have no descendants and are returned as integer-cast IDs unchanged.
	 *
	 * Descendant lookups are memoised per (taxonomy, term) for the request so the
	 * tree is walked at most once per term, even across many rules and posts (e.g.
	 * the Premium Newsletters cron loop).
	 *
	 * @param array        $term_ids Term IDs from a content rule's value (may be stored as strings).
	 * @param \WP_Taxonomy $taxonomy Taxonomy object the term IDs belong to.
	 *
	 * @return int[] De-duplicated term IDs including descendants.
	 */
	private static function expand_hierarchical_terms( array $term_ids, \WP_Taxonomy $taxonomy ): array {
		$term_ids = array_map( 'intval', $term_ids );
		if ( ! $taxonomy->hierarchical ) {
			return $term_ids;
		}
		$expanded = $term_ids;
		foreach ( $term_ids as $term_id ) {
			$cache_key = $taxonomy->name . ':' . $term_id;
			if ( ! isset( self::$term_descendants_map[ $cache_key ] ) ) {
				$children = get_term_children( $term_id, $taxonomy->name );
				self::$term_descendants_map[ $cache_key ] = is_wp_error( $children ) ? [] : array_map( 'intval', $children );
			}
			$expanded = array_merge( $expanded, self::$term_descendants_map[ $cache_key ] );
		}
		return array_values( array_unique( $expanded ) );
	}

	/**
	 * Whether the post is restricted for the current user.
	 *
	 * @param bool     $is_post_restricted Whether the post is restricted for the current or given user.
	 * @param int      $post_id            Post ID.
	 * @param int|null $user_id            Optional user ID to check access for.
	 *
	 * @return bool
	 */
	public static function is_post_restricted( $is_post_restricted, $post_id = null, $user_id = null ) {
		// Don't apply our restriction strategy if Woo Memberships is active.
		if ( Memberships::is_active() ) {
			return $is_post_restricted;
		}

		// Return early if this post is exempt from access control restrictions.
		if ( $post_id && get_post_meta( $post_id, self::IS_EXEMPT_META_KEY, true ) ) {
			return false;
		}

		// Return early if the post is already restricted for the current user.
		if ( $is_post_restricted ) {
			return $is_post_restricted;
		}

		$user_id = $user_id ?? get_current_user_id();

		// Don't restrict this post for users who can edit it.
		if ( ! empty( $post_id ) && user_can( $user_id, 'edit_post', $post_id ) ) {
			return false;
		}

		$post_gates = self::get_post_gates( $post_id );
		if ( empty( $post_gates ) ) {
			return false;
		}

		// Return if the post gate has already been determined.
		if ( ! empty( self::$post_gate_id_map[ $post_id . '_' . $user_id ] ) ) {
			return true;
		}

		foreach ( $post_gates as $gate ) {
			$gate_layout_id = null;
			$is_restricted  = false;
			// Tracks the anonymous-bypass result so the same custom_access rules don't get
			// evaluated twice in the second pass below. Stays null for non-anonymous calls.
			$anonymous_bypass_passed = null;

			// If registration mode is active.
			if ( ! empty( $gate['registration']['active'] ) ) {
				// Check if user is logged in.
				if ( $user_id === 0 ) {
					// Anonymous visitors can still pass via the gate's custom_access rules if they
					// match a populated rule with `supports_anonymous` (currently only `institution`).
					// An unpopulated rule (e.g., institution rule with no institutions selected) must
					// not grant access — Access_Rules treats an empty value as "no constraint" and
					// returns true, which would silently bypass registration here.
					$anonymous_bypass_passed = ! empty( $gate['custom_access']['active'] )
						&& Access_Rules::evaluate_anonymous_rules( $gate['custom_access']['access_rules'] ?? [] );
					$is_restricted  = ! $anonymous_bypass_passed;
					$gate_layout_id = $gate['registration']['gate_layout_id'] ?? $gate['id'];
				} elseif ( ! empty( $gate['registration']['require_verification'] ) ) {
					// Check if email verification is required.
					$user = get_user_by( 'id', $user_id );
					if ( ! $user || ! \get_user_meta( $user->ID, Reader_Activation::EMAIL_VERIFIED, true ) ) {
						$is_restricted  = true;
						$gate_layout_id = $gate['registration']['gate_layout_id'] ?? $gate['id'];
					}
				}
			}

			// If custom_access mode is active and we didn't already evaluate it above for an anonymous bypass.
			if ( ! $is_restricted && null === $anonymous_bypass_passed && ! empty( $gate['custom_access']['active'] ) ) {
				$access_rules = $gate['custom_access']['access_rules'] ?? [];
				if ( ! empty( $access_rules ) && ! Access_Rules::evaluate_rules( $access_rules, $user_id ) ) {
					$is_restricted  = true;
					$gate_layout_id = $gate['custom_access']['gate_layout_id'] ?? $gate['id'];
				}
			}

			if ( $is_restricted && $gate_layout_id ) {
				self::$post_gate_id_map[ $post_id . '_' . $user_id ] = $gate['id'];
				self::$post_gate_layout_id_map[ $post_id . '_' . $user_id ] = $gate_layout_id;
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the current gate post ID for the current user.
	 *
	 * Looks up the cache entry written by the most recent is_post_restricted()
	 * call for the current user. Calls made for a *different* user (e.g.
	 * queue workers, REST callbacks iterating over readers) write to their
	 * own cache slot and do not surface here.
	 *
	 * @param int $post_id Post ID. If not given, uses the current post ID.
	 *
	 * @return int|false
	 */
	public static function get_gate_post_id( $post_id = null ) {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return false;
		}
		if ( is_singular() ) {
			$post_id = $post_id ? $post_id : get_queried_object_id();
		}
		if ( ! $post_id ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( ! empty( self::$post_gate_id_map[ $post_id . '_' . $user_id ] ) ) {
			return self::$post_gate_id_map[ $post_id . '_' . $user_id ];
		}
		return false;
	}

	/**
	 * Get the current gate layout ID for the current user.
	 *
	 * Looks up the cache entry written by the most recent is_post_restricted()
	 * call for the current user. Calls made for a *different* user (e.g.
	 * queue workers, REST callbacks iterating over readers) write to their
	 * own cache slot and do not surface here.
	 *
	 * @param int $post_id Post ID. If not given, uses the current post ID.
	 *
	 * @return int|false
	 */
	public static function get_gate_layout_id( $post_id = null ) {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return false;
		}
		if ( is_singular() ) {
			$post_id = $post_id ? $post_id : get_queried_object_id();
		}
		if ( ! $post_id ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( ! empty( self::$post_gate_layout_id_map[ $post_id . '_' . $user_id ] ) ) {
			return self::$post_gate_layout_id_map[ $post_id . '_' . $user_id ];
		}
		return false;
	}

	/**
	 * Register post meta for the exemption flag.
	 */
	public static function register_meta() {
		$post_types = array_column( (array) self::get_available_post_types(), 'value' );
		foreach ( $post_types as $post_type ) {
			\register_meta(
				'post',
				self::IS_EXEMPT_META_KEY,
				[
					'object_subtype' => $post_type,
					'show_in_rest'   => true,
					'type'           => 'boolean',
					'default'        => false,
					'single'         => true,
					'auth_callback'  => function() {
						return current_user_can( 'edit_others_posts' );
					},
				]
			);
		}
	}
}
Content_Restriction_Control::init();
