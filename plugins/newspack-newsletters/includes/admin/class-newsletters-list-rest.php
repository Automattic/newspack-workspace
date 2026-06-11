<?php
/**
 * REST surface for the Newsletters list DataView.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters;
use WP_Post;

/**
 * Adds a read-only `newspack_newsletters_status` field on the newsletters
 * CPT consolidating `post_status` + sent / scheduled signals, plus the
 * filter-option payload and meta-aware query rewrites the React DataView
 * needs.
 */
class Newsletters_List_REST {
	use Rest_Status_Field;
	use Status_Filter_Builder;

	const IS_PUBLIC_QUERY_PARAM = 'newspack_newsletters_is_public';
	const SEND_LIST_QUERY_PARAM = 'newspack_newsletters_send_list_id';

	/**
	 * Defensive cap on each filter-options query so a site with tens of
	 * thousands of newsletters can't blow up the payload (or the SQL).
	 */
	const FILTER_OPTIONS_LIMIT = 500;

	/**
	 * Boot hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_fields' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_filter(
			'rest_' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT . '_query',
			[ __CLASS__, 'filter_rest_query' ],
			10,
			2
		);
		add_filter(
			'rest_' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT . '_query',
			[ __CLASS__, 'align_status_filter_with_scheduled_meta' ],
			10,
			2
		);
		add_filter(
			'rest_' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT . '_query',
			[ __CLASS__, 'filter_send_list_query' ],
			10,
			2
		);
	}

	/**
	 * Translate `newspack_newsletters_is_public` into a `meta_query`
	 * clause. Strict whitelist: only `1`/`0` (or boolean) accepted.
	 *
	 * @param array            $args    Query args being assembled.
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return array
	 */
	public static function filter_rest_query( $args, $request ) {
		$value = $request->get_param( self::IS_PUBLIC_QUERY_PARAM );

		if ( true === $value || '1' === $value || 1 === $value ) {
			$is_public = true;
		} elseif ( false === $value || '0' === $value || 0 === $value ) {
			$is_public = false;
		} else {
			return $args;
		}

		$clause = $is_public
			? [
				'key'     => 'is_public',
				'value'   => '1',
				'compare' => '=',
			]
			: [
				'relation' => 'OR',
				[
					'key'     => 'is_public',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => 'is_public',
					'value'   => '1',
					'compare' => '!=',
				],
			];

		if ( empty( $args['meta_query'] ) ) {
			$args['meta_query'] = []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$args['meta_query'][] = $clause; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $args;
	}

	/**
	 * Align the Status filter with the kind `get_status_for_post` emits.
	 *
	 * Raw `post_status` filtering misclassifies rows in both directions
	 * (an in-flight publish leaks under Sent; a publish-with-error is
	 * invisible under Draft). Map selection → kinds, widen `post_status`
	 * to every status the kinds' SQL branches reference, and install a
	 * scoped `posts_where` whose OR-branches mirror the renderer.
	 *
	 * @param array            $args    Query args being assembled.
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return array
	 */
	public static function align_status_filter_with_scheduled_meta( $args, $request ) {
		$values = self::parse_status_values( $request->get_param( 'status' ) );
		if ( empty( $values ) ) {
			return $args;
		}

		$wants_sent      = ! empty( array_intersect( $values, [ 'publish', 'private' ] ) );
		$wants_draft     = ! empty( array_intersect( $values, [ 'draft', 'pending', 'auto-draft' ] ) );
		$wants_scheduled = in_array( 'future', $values, true );
		$wants_trash     = in_array( 'trash', $values, true );

		// WP_Query applies `post_status IN (…)` before our `posts_where` fires; anything outside the widened set is unreachable.
		$widened = $values;
		if ( $wants_sent || $wants_draft || $wants_scheduled ) {
			$widened = array_merge( $widened, [ 'publish', 'private' ] );
		}
		if ( $wants_draft || $wants_scheduled ) {
			$widened = array_merge( $widened, [ 'draft', 'pending', 'auto-draft' ] );
		}
		if ( $wants_scheduled ) {
			$widened[] = 'future';
		}
		$widened = array_values( array_unique( $widened ) );
		if ( $widened !== $values ) {
			$args['post_status'] = $widened;
		}

		global $wpdb;
		$bucket_clauses = [];

		if ( $wants_trash ) {
			$bucket_clauses[] = $wpdb->prepare( "{$wpdb->posts}.post_status = %s", 'trash' );
		}
		if ( $wants_sent ) {
			$bucket_clauses[] = $wpdb->prepare(
				"( {$wpdb->posts}.post_status IN (%s, %s) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key IN (%s, %s) AND meta_value <> '' ) )",
				'publish',
				'private',
				'sending_scheduled',
				'scheduling_error'
			);
		}
		if ( $wants_scheduled ) {
			$bucket_clauses[] = $wpdb->prepare(
				"( {$wpdb->posts}.post_status <> %s AND ( {$wpdb->posts}.post_status = %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = %s AND meta_value <> '' ) ) )",
				'trash',
				'future',
				'sending_scheduled'
			);
		}
		if ( $wants_draft ) {
			$bucket_clauses[] = $wpdb->prepare(
				"( NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = %s AND meta_value <> '' ) AND ( {$wpdb->posts}.post_status IN (%s, %s, %s) OR ( {$wpdb->posts}.post_status IN (%s, %s) AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = %s AND meta_value <> '' ) ) ) )",
				'sending_scheduled',
				'draft',
				'pending',
				'auto-draft',
				'publish',
				'private',
				'scheduling_error'
			);
		}

		return self::install_bucket_filter( $args, $bucket_clauses, '_newspack_nl_bucket_token' );
	}

	/**
	 * Narrow to newsletters whose `send_list_id` meta matches one of
	 * the requested IDs. Accepts comma-separated string or array.
	 *
	 * @param array            $args    Query args being assembled.
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return array
	 */
	public static function filter_send_list_query( $args, $request ) {
		$value = $request->get_param( self::SEND_LIST_QUERY_PARAM );
		if ( null === $value || '' === $value ) {
			return $args;
		}

		$raw = is_array( $value ) ? $value : explode( ',', (string) $value );
		$ids = array_values(
			array_filter(
				array_map( 'trim', array_map( 'strval', $raw ) ),
				static function ( $v ) {
					return '' !== $v;
				}
			)
		);
		if ( empty( $ids ) ) {
			return $args;
		}

		$clause = [
			'key'     => 'send_list_id',
			'value'   => $ids,
			'compare' => 'IN',
		];

		if ( empty( $args['meta_query'] ) ) {
			$args['meta_query'] = []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$args['meta_query'][] = $clause; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $args;
	}

	/**
	 * Register the helper route feeding the React list filter dropdowns.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'newspack-newsletters/v1',
			'/newsletters-list/filter-options',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_filter_options' ],
				'permission_callback' => [ __CLASS__, 'rest_filter_options_permission_check' ],
			]
		);
	}

	/**
	 * Same cap a publisher needs to see the newsletters list itself.
	 *
	 * @return bool
	 */
	public static function rest_filter_options_permission_check(): bool {
		$cpt_object = get_post_type_object( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		if ( ! $cpt_object || empty( $cpt_object->cap->edit_posts ) ) {
			return false;
		}
		return current_user_can( $cpt_object->cap->edit_posts );
	}

	/**
	 * One-shot payload of every option list the React filter dropdowns
	 * consume. Scoped to newsletters the current user can edit so the
	 * filters match what the list itself shows.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_get_filter_options() {
		$user_scope = self::build_user_post_scope_sql();
		return rest_ensure_response(
			[
				'authors'    => self::get_authors_used( $user_scope ),
				'categories' => self::get_terms_used( 'category', $user_scope ),
				'tags'       => self::get_terms_used( 'post_tag', $user_scope ),
				'send_lists' => self::get_send_list_ids_used( $user_scope ),
			]
		);
	}

	/**
	 * SQL fragment scoping a `wp_posts p` join to rows the current user
	 * can edit — empty string for `edit_others_posts`, an authorship
	 * filter otherwise.
	 *
	 * @return string
	 */
	private static function build_user_post_scope_sql(): string {
		global $wpdb;
		$cpt_object = get_post_type_object( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		if ( $cpt_object && current_user_can( $cpt_object->cap->edit_others_posts ) ) {
			return '';
		}
		return $wpdb->prepare( ' AND p.post_author = %d', get_current_user_id() );
	}

	/**
	 * Distinct authors of any non-auto-draft newsletter in scope.
	 *
	 * @param string $user_scope_sql User-scope WHERE fragment.
	 * @return array<array{id: int, label: string}>
	 */
	private static function get_authors_used( $user_scope_sql = '' ): array {
		global $wpdb;
		$cpt = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$author_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.post_author
				 FROM {$wpdb->posts} p
				 WHERE p.post_type = %s
				   AND p.post_status NOT IN ( 'auto-draft' )
				   AND p.post_author <> 0" . $user_scope_sql . '
				 ORDER BY p.post_author ASC
				 LIMIT %d',
				$cpt,
				self::FILTER_OPTIONS_LIMIT
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$options = [];
		foreach ( (array) $author_ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( $user ) {
				$options[] = [
					'id'    => (int) $user->ID,
					'label' => (string) $user->display_name,
				];
			}
		}
		usort(
			$options,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		return $options;
	}

	/**
	 * Distinct terms applied to any in-scope newsletter, in the given taxonomy.
	 *
	 * @param string $taxonomy       `category` or `post_tag`.
	 * @param string $user_scope_sql User-scope WHERE fragment.
	 * @return array<array{id: int, label: string}>
	 */
	private static function get_terms_used( $taxonomy, $user_scope_sql = '' ): array {
		global $wpdb;
		$cpt = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT t.term_id AS id, t.name AS label
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE p.post_type = %s
				   AND p.post_status NOT IN ( 'auto-draft' )
				   AND tt.taxonomy = %s" . $user_scope_sql . '
				 ORDER BY t.name ASC
				 LIMIT %d',
				$cpt,
				$taxonomy,
				self::FILTER_OPTIONS_LIMIT
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			static function ( $row ) {
				return [
					'id'    => (int) $row['id'],
					'label' => (string) $row['label'],
				];
			},
			$rows ? $rows : []
		);
	}

	/**
	 * Distinct non-empty `send_list_id` meta values across in-scope
	 * newsletters. Friendly-name resolution deferred; raw IDs ship.
	 *
	 * @param string $user_scope_sql User-scope WHERE fragment.
	 * @return array<array{id: string, label: string}>
	 */
	private static function get_send_list_ids_used( $user_scope_sql = '' ): array {
		global $wpdb;
		$cpt = Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'send_list_id'
				   AND pm.meta_value <> ''
				   AND p.post_type = %s
				   AND p.post_status NOT IN ( 'auto-draft' )" . $user_scope_sql . '
				 ORDER BY pm.meta_value ASC
				 LIMIT %d',
				$cpt,
				self::FILTER_OPTIONS_LIMIT
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			static function ( $id ) {
				return [
					'id'    => (string) $id,
					'label' => (string) $id,
				];
			},
			$ids ? $ids : []
		);
	}

	/**
	 * Register REST fields on the newsletters CPT.
	 */
	public static function register_rest_fields(): void {
		self::register_status_field(
			Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			'newspack_newsletters_status',
			[
				'kind'         => [
					'type' => 'string',
					'enum' => [ 'draft', 'sent', 'scheduled', 'trash' ],
				],
				'sent_at'      => [
					'type' => [ 'integer', 'null' ],
				],
				'scheduled_at' => [
					'type' => [ 'integer', 'null' ],
				],
			]
		);
	}

	/**
	 * Compute the consolidated status payload for a newsletter post.
	 *
	 * Resolution order: trash (so a trashed sent row doesn't mask as
	 * sent) → sent → scheduled (`future` or `sending_scheduled` meta)
	 * → draft as the catch-all.
	 *
	 * @param WP_Post|null $post Post object.
	 * @return array { kind, sent_at, scheduled_at }
	 */
	public static function get_status_for_post( $post ): array {
		$payload = [
			'kind'         => 'draft',
			'sent_at'      => null,
			'scheduled_at' => null,
		];

		if ( ! $post instanceof WP_Post ) {
			return $payload;
		}

		if ( 'trash' === $post->post_status ) {
			$payload['kind'] = 'trash';
			return $payload;
		}

		$sent_at = self::compute_sent_at( $post );
		if ( null !== $sent_at ) {
			$payload['kind']    = 'sent';
			$payload['sent_at'] = $sent_at;
			return $payload;
		}

		if ( 'future' === $post->post_status ) {
			$datetime = get_post_datetime( $post, 'date', 'gmt' );
			if ( $datetime ) {
				$payload['kind']         = 'scheduled';
				$payload['scheduled_at'] = $datetime->getTimestamp();
				return $payload;
			}
		}

		if ( get_post_meta( $post->ID, 'sending_scheduled', true ) ) {
			$payload['kind'] = 'scheduled';
			return $payload;
		}

		return $payload;
	}

	/**
	 * Read-only equivalent of `Newspack_Newsletters::is_newsletter_sent`.
	 *
	 * `is_newsletter_sent` back-fills `newsletter_sent` meta on a stale
	 * row, so calling it from this REST GET would issue a write per
	 * response row. Derive the timestamp without mutating instead.
	 *
	 * @param WP_Post $post Post object.
	 * @return int|null Sent timestamp, or null when not (yet) sent.
	 */
	private static function compute_sent_at( $post ): ?int {
		if ( get_post_meta( $post->ID, 'sending_scheduled', true ) ) {
			return null;
		}
		if ( get_post_meta( $post->ID, 'scheduling_error', true ) ) {
			return null;
		}

		$sent          = (int) get_post_meta( $post->ID, 'newsletter_sent', true );
		$is_published  = in_array( $post->post_status, [ 'publish', 'private' ], true );
		$post_datetime = $is_published ? get_post_datetime( $post, 'date', 'gmt' ) : false;
		$publish_date  = $post_datetime ? $post_datetime->getTimestamp() : 0;

		// Only accept `newsletter_sent` when it matches the publish timestamp — anything else is the stale meta `is_newsletter_sent` would otherwise overwrite.
		if ( 0 < $sent && $sent === $publish_date ) {
			return $sent;
		}

		if ( $publish_date ) {
			return $publish_date;
		}

		return null;
	}
}
