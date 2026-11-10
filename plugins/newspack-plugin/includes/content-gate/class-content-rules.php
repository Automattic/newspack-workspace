<?php
/**
 * Newspack Content Gate Content Rules
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * Main class.
 */
class Content_Rules {

	/**
	 * Slug of the content rule that matches posts by how long ago they were published.
	 *
	 * @var string
	 */
	const PUBLISHED_AGE_RULE = 'published_age';

	/**
	 * Slug of the archive rule that matches the archive page of a post type.
	 *
	 * @var string
	 */
	const POST_TYPE_ARCHIVES_RULE = 'post_type_archives';

	/**
	 * Post meta key for a post's own time window, which replaces the duration of every
	 * published-age rule that judges the post. Each rule keeps its own direction.
	 *
	 * @var string
	 */
	const AGE_OVERRIDE_META_KEY = 'newspack_content_gate_age_override';

	/**
	 * Units a published-age duration can be counted in.
	 *
	 * @var string[]
	 */
	const AGE_UNITS = [ 'days', 'months', 'years' ];

	/**
	 * Directions a published-age rule can compare in.
	 *
	 * @var string[]
	 */
	const AGE_COMPARISONS = [ 'more_than', 'less_than' ];

	/**
	 * The largest count a published-age duration takes, so the date arithmetic stays in range.
	 *
	 * @var int
	 */
	const AGE_MAX_COUNT = 1000;

	/**
	 * Get available content rules.
	 *
	 * @return array The content rules.
	 */
	public static function get_content_rules() {
		$content_rules = [
			'post_types' => [
				'name'        => __( 'Post types', 'newspack-plugin' ),
				'options'     => Content_Restriction_Control::get_available_post_types(),
				'default'     => [ 'post' ],
				'description' => __( 'Content types like posts, pages, or listings.', 'newspack-plugin' ),
			],
		];
		$available_taxonomies = Content_Restriction_Control::get_available_taxonomies();
		foreach ( $available_taxonomies as $taxonomy ) {
			$content_rules[ $taxonomy['slug'] ] = [
				'name'        => $taxonomy['label'],
				'default'     => [],
				'description' => $taxonomy['description'],
			];
			// The route respects a custom rest_base; the token field prefers it over a guess.
			$endpoint = \rest_get_route_for_taxonomy_items( $taxonomy['slug'] );
			if ( $endpoint ) {
				$content_rules[ $taxonomy['slug'] ]['endpoint'] = $endpoint;
			}
		}

		$content_rules[ self::PUBLISHED_AGE_RULE ] = [
			'name'         => __( 'Publication date', 'newspack-plugin' ),
			'default'      => [
				'comparison'     => 'more_than',
				'duration_value' => 7,
				'duration_unit'  => 'days',
			],
			'description'  => __( 'Content published more than, or less than, a set time ago. Posts cross the line as they age.', 'newspack-plugin' ),
			'include_only' => true,
		];

		$content_rules['specific_posts'] = [
			'name'         => __( 'Specific posts', 'newspack-plugin' ),
			'default'      => [],
			'description'  => __( 'Restrict specific posts, even if not covered by other rules above.', 'newspack-plugin' ),
			'endpoint'     => '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search',
			'include_only' => true,
		];

		return $content_rules;
	}

	/**
	 * Describe the rules stored on gates that the editor doesn't offer.
	 *
	 * A gate can hold a rule for a taxonomy that isn't on the offered list (for
	 * example one created by the Memberships migration), or for one that no longer
	 * exists. The editor needs a name and a term endpoint to show these.
	 *
	 * @param array $gates Gates, as returned by Content_Gate::get_gates().
	 *
	 * @return array[] Rule definitions keyed by slug. Unregistered slugs are flagged `unavailable`.
	 */
	public static function get_unoffered_content_rules( array $gates ): array {
		// The newsletter rule's slug is named here rather than read from
		// get_premium_newsletter_rules(), which asks the ESP for its lists.
		// The other known slugs are already keys of get_content_rules().
		$known = array_merge( array_keys( self::get_content_rules() ), [ 'newsletters' ] );
		$extra = [];
		foreach ( $gates as $gate ) {
			foreach ( $gate['content_rules'] ?? [] as $rule ) {
				$slug = $rule['slug'] ?? '';
				if ( ! is_string( $slug ) || '' === $slug || in_array( $slug, $known, true ) || isset( $extra[ $slug ] ) ) {
					continue;
				}
				$taxonomy = \get_taxonomy( $slug );
				if ( ! $taxonomy ) {
					$extra[ $slug ] = [
						'name'        => $slug,
						'default'     => [],
						'unavailable' => true,
					];
					continue;
				}
				$extra[ $slug ] = [
					'name'    => $taxonomy->labels->name,
					'default' => [],
				];
				$endpoint       = \rest_get_route_for_taxonomy_items( $slug );
				if ( $endpoint ) {
					$extra[ $slug ]['endpoint'] = $endpoint;
				}
			}
		}
		return $extra;
	}

	/**
	 * Get premium newsletter content rules.
	 *
	 * @return array The premium newsletter content rules.
	 */
	public static function get_premium_newsletter_rules() {
		$newsletters_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );
		$lists                             = $newsletters_configuration_manager->get_lists();

		if ( is_wp_error( $lists ) || ! is_array( $lists ) ) {
			return [];
		}

		return [
			'newsletters' => [
				'name'         => __( 'Lists', 'newspack-plugin' ),
				'default'      => [],
				'description'  => __( 'Newsletter subscription lists.', 'newspack-plugin' ),
				'endpoint'     => 'newspack-newsletters/v1/lists',
				'include_only' => true,
			],
		];
	}

	/**
	 * Get the archive rules a gate can hold.
	 *
	 * These are separate from content rules: they decide whether a gate covers an
	 * archive page, not which posts it restricts.
	 *
	 * @return array The archive rules, keyed by slug.
	 */
	public static function get_archive_rules(): array {
		return [
			self::POST_TYPE_ARCHIVES_RULE => [
				'name'    => __( 'Archive pages', 'newspack-plugin' ),
				'options' => Content_Restriction_Control::get_available_archive_post_types(),
				'default' => [],
			],
		];
	}

	/**
	 * Get the archive rules for a gate.
	 *
	 * @param int $post_id Gate post ID.
	 *
	 * @return array The archive rules, without any that have an empty value.
	 */
	public static function get_gate_archive_rules( int $post_id ): array {
		$rules = \get_post_meta( $post_id, 'archive_rules', true );
		if ( ! is_array( $rules ) ) {
			return [];
		}
		return array_values(
			array_filter(
				$rules,
				function( $rule ) {
					return is_array( $rule ) && ! empty( $rule['value'] );
				}
			)
		);
	}

	/**
	 * Update the archive rules for a gate.
	 *
	 * @param int   $post_id Gate post ID.
	 * @param array $rules   Array of archive rules.
	 */
	public static function update_gate_archive_rules( int $post_id, array $rules ): void {
		\update_post_meta( $post_id, 'archive_rules', $rules );
	}

	/**
	 * Whether a gate's archive rules cover the archive page of any of the post types.
	 *
	 * Only post types offered right now count, so a stored choice for a post type
	 * that is switched off does nothing until it is offered again.
	 *
	 * @param array    $archive_rules The gate's archive rules.
	 * @param string[] $post_types    Post types of the archive page being viewed.
	 *
	 * @return bool
	 */
	public static function gate_matches_archive( array $archive_rules, array $post_types ): bool {
		$offered = wp_list_pluck( Content_Restriction_Control::get_available_archive_post_types(), 'value' );
		foreach ( $archive_rules as $rule ) {
			if ( self::POST_TYPE_ARCHIVES_RULE !== ( $rule['slug'] ?? '' ) || ! is_array( $rule['value'] ?? null ) ) {
				continue;
			}
			if ( array_intersect( $rule['value'], $post_types, $offered ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the content rules for a gate.
	 *
	 * @param int $post_id Gate post ID.
	 *
	 * @return array The content rules.
	 */
	public static function get_gate_content_rules( $post_id ) {
		$rules = \get_post_meta( $post_id, 'content_rules', true );

		// Filter out rules with empty array-like values.
		return is_array( $rules ) ? array_values(
			array_filter(
				$rules,
				function( $rule ) {
					if ( ! isset( $rule['value'] ) || ! is_array( $rule['value'] ) ) {
						return true;
					}
					return count( $rule['value'] ) > 0;
				}
			)
		) : [];
	}

	/**
	 * Update content rules for bypassing a content gate.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $rules   Array of post content rules.
	 *
	 * @return void
	 */
	public static function update_gate_content_rules( $post_id, $rules ) {
		\update_post_meta( $post_id, 'content_rules', $rules );
	}

	/**
	 * Get the rule-combination mode for a gate.
	 *
	 * @param int $post_id Gate post ID.
	 *
	 * @return string 'all' (AND, default) or 'any' (OR).
	 */
	public static function get_gate_content_rules_match( $post_id ) {
		$value = \get_post_meta( $post_id, 'content_rules_match', true );
		return 'any' === $value ? 'any' : 'all';
	}

	/**
	 * Update the rule-combination mode for a gate.
	 *
	 * @param int    $post_id Gate post ID.
	 * @param string $value   'all' or 'any'.
	 *
	 * @return void
	 */
	public static function update_gate_content_rules_match( $post_id, $value ) {
		\update_post_meta( $post_id, 'content_rules_match', 'any' === $value ? 'any' : 'all' );
	}

	/**
	 * Sanitize a published-age rule value.
	 *
	 * @param mixed $value Raw value: `comparison`, `duration_value` and `duration_unit`.
	 *
	 * @return array{comparison: string, duration_value: int, duration_unit: string}|\WP_Error The sanitized value, or an error when any part is unusable.
	 */
	public static function sanitize_published_age_value( mixed $value ): array|\WP_Error {
		if ( ! is_array( $value ) ) {
			return self::invalid_published_age_error();
		}
		$comparison = $value['comparison'] ?? '';
		$duration   = self::sanitize_age_duration( $value );
		if ( ! in_array( $comparison, self::AGE_COMPARISONS, true ) || ! $duration ) {
			return self::invalid_published_age_error();
		}
		return array_merge( [ 'comparison' => $comparison ], $duration );
	}

	/**
	 * The error a gate save returns for a published-age rule it cannot read.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_published_age_error(): \WP_Error {
		return new \WP_Error(
			'invalid_content_rule_value',
			sprintf(
				// translators: %d: the largest number the rule takes.
				__( 'The publication date rule needs a whole number of days, months or years, from 1 to %d.', 'newspack-plugin' ),
				self::AGE_MAX_COUNT
			),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Sanitize an age duration: a whole number from 1 to AGE_MAX_COUNT, in a known unit.
	 *
	 * @param mixed $value Raw value with `duration_value` and `duration_unit`.
	 *
	 * @return array{duration_value: int, duration_unit: string}|null The duration, or null when either part is unusable.
	 */
	public static function sanitize_age_duration( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		// A count typed as "7.5" or "7 days" is refused rather than truncated to a different window.
		$count = filter_var(
			$value['duration_value'] ?? null,
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => 1,
					'max_range' => self::AGE_MAX_COUNT,
				],
			]
		);
		$unit  = $value['duration_unit'] ?? '';
		if ( false === $count || ! in_array( $unit, self::AGE_UNITS, true ) ) {
			return null;
		}
		return [
			'duration_value' => $count,
			'duration_unit'  => $unit,
		];
	}

	/**
	 * The time window a post sets for itself, which replaces a published-age rule's duration.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array|null `duration_value` and `duration_unit`, or null when the post sets none.
	 */
	public static function get_post_age_override( int $post_id ): ?array {
		return self::sanitize_age_duration( \get_post_meta( $post_id, self::AGE_OVERRIDE_META_KEY, true ) );
	}

	/**
	 * Whether a published-age rule matches the post, judged against the current time.
	 *
	 * The comparison runs on every request, so a post crosses the line as it ages with
	 * nothing scheduled to move it. A post that is not published yet has a zero age.
	 *
	 * @param mixed                   $value   The rule's stored value.
	 * @param int                     $post_id Post ID.
	 * @param \DateTimeImmutable|null $now     The time to judge against. Defaults to the current time.
	 *
	 * @return bool False for a malformed value, so a damaged rule never matches.
	 */
	public static function post_matches_published_age( mixed $value, int $post_id, ?\DateTimeImmutable $now = null ): bool {
		$value = self::sanitize_published_age_value( $value );
		if ( is_wp_error( $value ) ) {
			return false;
		}
		$override = self::get_post_age_override( $post_id );
		if ( $override ) {
			$value = array_merge( $value, $override );
		}

		$now       = $now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$published = $now;
		$post      = \get_post( $post_id );
		if ( $post && 'publish' === $post->post_status ) {
			// An import can leave a published post's GMT date zeroed; REST derives it from the local date the same way.
			$published_gmt = '0000-00-00 00:00:00' === $post->post_date_gmt ? \get_gmt_from_date( $post->post_date ) : $post->post_date_gmt;
			$published     = new \DateTimeImmutable( $published_gmt, new \DateTimeZone( 'UTC' ) );
		}
		// The line falls N calendar units after publication, so "1 month" follows the calendar rather than 30 days.
		$line          = $published->modify( sprintf( '+%d %s', $value['duration_value'], $value['duration_unit'] ) );
		$is_older_than = $now >= $line;
		return 'more_than' === $value['comparison'] ? $is_older_than : ! $is_older_than;
	}
}
