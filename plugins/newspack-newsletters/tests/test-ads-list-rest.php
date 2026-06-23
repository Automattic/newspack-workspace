<?php
/**
 * Class Test Ads List REST
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Ads_List_REST;
use Newspack_Newsletters\Ads;

/**
 * Tests the REST surface that the Newsletter Ads list DataView consumes.
 *
 * `get_status_for_post` consolidates `post_status` and the date-driven
 * lifecycle (`start_date` / `expiry_date` meta) into a single
 * `{ kind, starts_at, expires_at }` payload — the React side never
 * re-derives state from raw meta.
 */
class Ads_List_REST_Test extends WP_UnitTestCase {
	/**
	 * Helper: make a newsletter ad with optional overrides and meta.
	 *
	 * @param array $args Post args; meta supplied via `meta_input`.
	 * @return int Post ID.
	 */
	private function make_ad( $args = [] ) {
		return self::factory()->post->create(
			array_merge(
				[
					'post_type'   => Ads::CPT,
					'post_status' => 'draft',
					'post_title'  => 'Test ad',
				],
				$args
			)
		);
	}

	/**
	 * A draft ad has kind=draft and no timestamps.
	 */
	public function test_draft_ad_reports_draft_kind() {
		$post_id = $this->make_ad( [ 'post_status' => 'draft' ] );

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'draft', $status['kind'] );
		$this->assertNull( $status['starts_at'] );
		$this->assertNull( $status['expires_at'] );
	}

	/**
	 * Trash beats every other resolution branch — a previously-published
	 * ad with dates that fall in the active window still reports as
	 * trashed once it's been moved to the trash.
	 */
	public function test_trashed_ad_reports_trash_kind() {
		$post_id = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date'  => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
				],
			]
		);
		wp_trash_post( $post_id );

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'trash', $status['kind'] );
	}

	/**
	 * A published ad with no dates configured is treated as active —
	 * mirroring `Ads::is_ad_active`, which returns true when neither
	 * date is set.
	 */
	public function test_published_ad_with_no_dates_reports_active_kind() {
		$post_id = $this->make_ad( [ 'post_status' => 'publish' ] );

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'active', $status['kind'] );
		$this->assertNull( $status['starts_at'] );
		$this->assertNull( $status['expires_at'] );
	}

	/**
	 * A published ad whose `start_date` is in the future reports
	 * kind=scheduled, with `starts_at` populated. Date comparisons use
	 * the site-local `Y-m-d` granularity, matching `Ads::is_ad_active`.
	 */
	public function test_published_ad_with_future_start_date_reports_scheduled_kind() {
		$start = gmdate( 'Y-m-d', strtotime( '+5 days' ) );
		$post_id = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [ 'start_date' => $start ],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'scheduled', $status['kind'] );
		$this->assertIsInt( $status['starts_at'] );
		$this->assertGreaterThan( time(), $status['starts_at'] );
	}

	/**
	 * A published ad whose `expiry_date` is in the past reports
	 * kind=expired, with `expires_at` populated.
	 */
	public function test_published_ad_with_past_expiry_date_reports_expired_kind() {
		$expiry  = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$post_id = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [ 'expiry_date' => $expiry ],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'expired', $status['kind'] );
		$this->assertIsInt( $status['expires_at'] );
		$this->assertLessThan( time(), $status['expires_at'] );
	}

	/**
	 * A published ad whose dates bracket today reports kind=active and
	 * exposes both `starts_at` and `expires_at` so the React side can
	 * render them in dedicated columns.
	 */
	public function test_published_ad_within_window_reports_active_with_both_timestamps() {
		$post_id = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date'  => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
				],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'active', $status['kind'] );
		$this->assertIsInt( $status['starts_at'] );
		$this->assertIsInt( $status['expires_at'] );
		$this->assertLessThan( time(), $status['starts_at'] );
		$this->assertGreaterThan( time(), $status['expires_at'] );
	}

	/**
	 * Boundary check mirroring `Ads::is_ad_active`: the comparison is
	 * `start <= today` and `expiry >= today`. An ad whose start_date is
	 * today must report as active (not scheduled), and one whose
	 * expiry_date is today must report as active (not expired).
	 */
	public function test_today_boundary_resolves_as_active() {
		$today   = gmdate( 'Y-m-d' );
		$post_id = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date'  => $today,
					'expiry_date' => $today,
				],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'active', $status['kind'] );
	}

	/**
	 * Stale `start_date` / `expiry_date` meta on a draft must not
	 * promote the row out of draft — the lifecycle resolution only
	 * applies to published rows.
	 */
	public function test_draft_with_dates_still_reports_draft() {
		$post_id = $this->make_ad(
			[
				'post_status' => 'draft',
				'meta_input'  => [
					'start_date'  => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
				],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'draft', $status['kind'] );
		$this->assertNull( $status['starts_at'] );
		$this->assertNull( $status['expires_at'] );
	}

	/**
	 * `pending` status falls under the draft bucket — same as the
	 * newsletters list, and matches what publishers see in the
	 * filter dropdown.
	 */
	public function test_pending_ad_reports_draft_kind() {
		$post_id = $this->make_ad( [ 'post_status' => 'pending' ] );

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'draft', $status['kind'] );
	}

	/**
	 * WP-scheduled ads (the standard Publish-Schedule UI sets
	 * `post_status=future`) resolve to `scheduled` regardless of any
	 * `start_date` / `expiry_date` meta — those are date-driven
	 * activation knobs that only matter once the row publishes. The
	 * timestamp comes from `post_date_gmt` (the moment WP will
	 * auto-publish) so the React renderer can show "Starts <date>".
	 */
	public function test_future_ad_reports_scheduled_kind_with_post_date_starts_at() {
		$publish_at = gmdate( 'Y-m-d H:i:s', strtotime( '+5 days' ) );
		$post_id    = $this->make_ad(
			[
				'post_status'   => 'future',
				'post_date'     => $publish_at,
				'post_date_gmt' => $publish_at,
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'scheduled', $status['kind'] );
		$this->assertSame( strtotime( $publish_at . ' UTC' ), $status['starts_at'] );
		$this->assertNull( $status['expires_at'] );
	}

	/**
	 * Stale `start_date` / `expiry_date` meta on a `future` row must
	 * not change the kind — WP's own scheduling owns the lifecycle
	 * until the row publishes, and the meta only matters once it does.
	 */
	public function test_future_ad_with_stale_meta_still_reports_scheduled() {
		$publish_at = gmdate( 'Y-m-d H:i:s', strtotime( '+5 days' ) );
		$post_id    = $this->make_ad(
			[
				'post_status'   => 'future',
				'post_date'     => $publish_at,
				'post_date_gmt' => $publish_at,
				'meta_input'    => [
					'start_date'  => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
				],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'scheduled', $status['kind'] );
	}

	/**
	 * `private` status is treated as publish-equivalent for kind
	 * resolution: a private ad with valid dates is functionally a
	 * published ad with restricted visibility, so it must surface
	 * as active/scheduled/expired the same way. Falling through to
	 * the `draft` default would mislabel the row in the list and
	 * hide it from the lifecycle filters.
	 */
	public function test_private_ad_with_no_dates_reports_active_kind() {
		$post_id = $this->make_ad( [ 'post_status' => 'private' ] );

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'active', $status['kind'] );
	}

	/**
	 * `private` status with a future `start_date` resolves to
	 * `scheduled`, exposing the same `starts_at` timestamp the
	 * publish branch produces.
	 */
	public function test_private_ad_with_future_start_date_reports_scheduled_kind() {
		$start   = gmdate( 'Y-m-d', strtotime( '+5 days' ) );
		$post_id = $this->make_ad(
			[
				'post_status' => 'private',
				'meta_input'  => [ 'start_date' => $start ],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'scheduled', $status['kind'] );
		$this->assertIsInt( $status['starts_at'] );
	}

	/**
	 * `private` status with a past `expiry_date` resolves to
	 * `expired` — same lifecycle treatment as the publish branch.
	 */
	public function test_private_ad_with_past_expiry_date_reports_expired_kind() {
		$expiry  = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$post_id = $this->make_ad(
			[
				'post_status' => 'private',
				'meta_input'  => [ 'expiry_date' => $expiry ],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( 'expired', $status['kind'] );
		$this->assertIsInt( $status['expires_at'] );
	}

	/**
	 * Malformed lifecycle meta must not violate the REST schema's
	 * `integer|null` declaration on `starts_at` / `expires_at` —
	 * `strtotime` returns `false` on garbage input, and the field
	 * normalises that to `null` so the response stays well-typed.
	 */
	public function test_published_ad_with_malformed_dates_normalises_timestamps_to_null() {
		$post_id = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date'  => 'not-a-date',
					'expiry_date' => 'also-not-a-date',
				],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertNull( $status['starts_at'] );
		$this->assertNull( $status['expires_at'] );
	}

	/**
	 * Date-only meta is exposed as a noon-UTC timestamp so the
	 * rendered date stays on the intended calendar day in any
	 * reasonable site timezone — midnight UTC would render as the
	 * previous day for users behind UTC.
	 */
	public function test_published_ad_timestamps_use_noon_utc_for_timezone_safety() {
		$start   = gmdate( 'Y-m-d', strtotime( '+5 days' ) );
		$expiry  = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$post_id = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date'  => $start,
					'expiry_date' => $expiry,
				],
			]
		);

		$status = Ads_List_REST::get_status_for_post( get_post( $post_id ) );

		$this->assertSame( strtotime( $start . ' 12:00:00 UTC' ), $status['starts_at'] );
		$this->assertSame( strtotime( $expiry . ' 12:00:00 UTC' ), $status['expires_at'] );
	}

	/**
	 * The `newspack_newsletters_ad_status` REST field is registered on
	 * the ads CPT so it surfaces on `/wp/v2/newspack_nl_ads_cpt` responses.
	 */
	public function test_rest_field_is_registered_on_ads_cpt() {
		do_action( 'rest_api_init' );

		global $wp_rest_additional_fields;

		$cpt    = Ads::CPT;
		$fields = isset( $wp_rest_additional_fields[ $cpt ] ) ? $wp_rest_additional_fields[ $cpt ] : [];

		$this->assertArrayHasKey( 'newspack_newsletters_ad_status', $fields );
		$this->assertIsCallable( $fields['newspack_newsletters_ad_status']['get_callback'] );
	}

	/**
	 * Helper: build a REST request with the given query params.
	 *
	 * @param array $params Query params keyed by name.
	 * @return WP_REST_Request
	 */
	private function rest_request( $params ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Ads::CPT );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * `kind=trash` translates the kind filter to the native
	 * `post_status=trash` so trashed rows surface.
	 */
	public function test_filter_rest_query_translates_trash_kind_to_post_status() {
		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'trash' ] )
		);

		$this->assertContains( 'trash', (array) $args['post_status'] );
	}

	/**
	 * `kind=draft` covers `draft`, `pending`, and `auto-draft` — the
	 * trio we resolve to the `draft` kind in the column. The filter
	 * and the column have to agree on which rows belong in the bucket.
	 */
	public function test_filter_rest_query_translates_draft_kind_to_full_draft_set() {
		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'draft' ] )
		);

		$post_status = (array) $args['post_status'];
		$this->assertContains( 'draft', $post_status );
		$this->assertContains( 'pending', $post_status );
		$this->assertContains( 'auto-draft', $post_status );
	}

	/**
	 * No status filter param means the request behaves like a normal
	 * CPT query — pass through args untouched.
	 */
	public function test_filter_rest_query_passes_through_when_param_absent() {
		$original = [ 'post_status' => 'publish' ];

		$args = Ads_List_REST::filter_rest_query( $original, $this->rest_request( [] ) );

		$this->assertSame( $original, $args );
	}

	/**
	 * Out-of-whitelist kind values (typos, junk) are ignored — only
	 * the valid kinds in the request take effect. Sending only junk
	 * leaves args untouched.
	 */
	public function test_filter_rest_query_ignores_unknown_kinds() {
		$original = [ 'post_status' => 'publish' ];

		$args = Ads_List_REST::filter_rest_query(
			$original,
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'foo,bar,baz' ] )
		);

		$this->assertSame( $original, $args );
	}

	/**
	 * `tracking_impressions` and `tracking_clicks` are stored as meta
	 * by the tracking layer but were never `register_meta`'d, so they
	 * didn't surface in REST responses. The list registers them on
	 * the ads CPT subtype so the React columns can read them and the
	 * REST `orderby=meta_value_num` path works for sorting.
	 */
	public function test_tracking_metas_are_registered_on_ads_cpt() {
		// `WP_UnitTestCase::reset_post_types()` runs before every test
		// and strips registered meta along with the post type. Re-invoke
		// the registration for this test so we're asserting against
		// our function's effect, not the framework's reset.
		Ads_List_REST::register_meta();

		$this->assertTrue( registered_meta_key_exists( 'post', 'tracking_impressions', Ads::CPT ) );
		$this->assertTrue( registered_meta_key_exists( 'post', 'tracking_clicks', Ads::CPT ) );

		$registered = get_registered_meta_keys( 'post', Ads::CPT );
		$this->assertNotFalse( $registered['tracking_impressions']['show_in_rest'] );
		$this->assertNotFalse( $registered['tracking_clicks']['show_in_rest'] );
	}

	/**
	 * Tracking counters are server-managed telemetry — REST clients
	 * must not be able to update them through the posts endpoint.
	 * `auth_callback => '__return_false'` flips the meta-edit cap to
	 * deny, even for an administrator with full caps; direct
	 * `update_post_meta` calls from the tracking layer are unaffected.
	 */
	public function test_tracking_metas_deny_rest_writes_even_for_admins() {
		Ads_List_REST::register_meta();

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$post_id = $this->make_ad( [ 'post_status' => 'publish' ] );

		$this->assertFalse( current_user_can( 'edit_post_meta', $post_id, 'tracking_impressions' ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $post_id, 'tracking_clicks' ) );

		// Sanity: direct `update_post_meta` from the tracking layer
		// still works — `auth_callback` only gates the cap-check path
		// (REST writes go through there; server-side writes don't).
		$this->assertNotFalse( update_post_meta( $post_id, 'tracking_impressions', 42 ) );
		$this->assertSame( '42', get_post_meta( $post_id, 'tracking_impressions', true ) );
	}

	/**
	 * REST-side schema declares the counters as `readonly: true` so
	 * generated REST clients (and OpenAPI consumers) treat them as
	 * read-only fields, complementing the auth-callback enforcement.
	 */
	public function test_tracking_metas_declare_readonly_rest_schema() {
		Ads_List_REST::register_meta();

		$registered = get_registered_meta_keys( 'post', Ads::CPT );

		foreach ( [ 'tracking_impressions', 'tracking_clicks' ] as $key ) {
			$show_in_rest = $registered[ $key ]['show_in_rest'];
			$this->assertIsArray( $show_in_rest, sprintf( '%s should declare a schema array', $key ) );
			$this->assertArrayHasKey( 'schema', $show_in_rest );
			$this->assertTrue( $show_in_rest['schema']['readonly'] ?? false, sprintf( '%s schema should be readonly', $key ) );
		}
	}

	/**
	 * Mixing a non-publish kind with a publish-driven kind: draft rows
	 * (no meta) and expired published rows must both surface. The OR'd
	 * meta clauses can't simply be AND'd with the wider post_status
	 * union — the draft rows would be filtered out for not having
	 * `expiry_date < today`. Each kind needs its own bucket.
	 */
	public function test_kind_filter_draft_and_expired_returns_both_buckets() {
		$expired = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				],
			]
		);
		$draft   = $this->make_ad( [ 'post_status' => 'draft' ] );
		$pending = $this->make_ad( [ 'post_status' => 'pending' ] );
		$active  = $this->make_ad( [ 'post_status' => 'publish' ] );

		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'draft,expired' ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$this->assertContains( $expired, $query->posts );
		$this->assertContains( $draft, $query->posts );
		$this->assertContains( $pending, $query->posts );
		$this->assertNotContains( $active, $query->posts );
	}

	/**
	 * Multi-kind selection where all selected kinds map to `publish`
	 * status — the meta clauses must be combined with OR so each
	 * bucket's rows surface (not AND, which would never match).
	 */
	public function test_kind_filter_expired_and_scheduled_returns_both_buckets() {
		$expired   = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				],
			]
		);
		$scheduled = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
				],
			]
		);
		$active    = $this->make_ad( [ 'post_status' => 'publish' ] );
		$draft     = $this->make_ad( [ 'post_status' => 'draft' ] );

		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'expired,scheduled' ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$this->assertContains( $expired, $query->posts );
		$this->assertContains( $scheduled, $query->posts );
		$this->assertNotContains( $active, $query->posts );
		$this->assertNotContains( $draft, $query->posts );
	}

	/**
	 * `kind=active` covers published ads that are within their start /
	 * expiry window — including ads with no dates at all (the
	 * `is_ad_active` default). Scheduled, expired, and draft rows must
	 * not leak through.
	 */
	public function test_kind_filter_active_returns_published_ads_within_window() {
		$active_no_dates    = $this->make_ad( [ 'post_status' => 'publish' ] );
		$active_within      = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date'  => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
				],
			]
		);
		$active_today_start = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [ 'start_date' => gmdate( 'Y-m-d' ) ],
			]
		);
		$scheduled          = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
				],
			]
		);
		$expired            = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				],
			]
		);
		$draft              = $this->make_ad( [ 'post_status' => 'draft' ] );

		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'active' ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$this->assertContains( $active_no_dates, $query->posts );
		$this->assertContains( $active_within, $query->posts );
		$this->assertContains( $active_today_start, $query->posts );
		$this->assertNotContains( $scheduled, $query->posts );
		$this->assertNotContains( $expired, $query->posts );
		$this->assertNotContains( $draft, $query->posts );
	}

	/**
	 * `kind=scheduled` includes WP-scheduled ads (`post_status=future`)
	 * alongside publish/private rows whose `start_date` meta is in the
	 * future. Without this, the legacy `?post_status=future` deep link
	 * (and the default list) would drop these rows. Active / expired /
	 * draft must not leak through.
	 */
	public function test_kind_filter_scheduled_includes_future_post_status() {
		$publish_at         = gmdate( 'Y-m-d H:i:s', strtotime( '+5 days' ) );
		$wp_scheduled       = $this->make_ad(
			[
				'post_status'   => 'future',
				'post_date'     => $publish_at,
				'post_date_gmt' => $publish_at,
			]
		);
		$meta_scheduled     = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
				],
			]
		);
		$active             = $this->make_ad( [ 'post_status' => 'publish' ] );
		$draft              = $this->make_ad( [ 'post_status' => 'draft' ] );

		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'scheduled' ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$this->assertContains( $wp_scheduled, $query->posts );
		$this->assertContains( $meta_scheduled, $query->posts );
		$this->assertNotContains( $active, $query->posts );
		$this->assertNotContains( $draft, $query->posts );
	}

	/**
	 * `kind=scheduled` surfaces only published ads whose `start_date`
	 * is in the future — not active, not expired, not draft.
	 */
	public function test_kind_filter_scheduled_returns_only_scheduled_ads() {
		$scheduled = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
				],
			]
		);
		$active    = $this->make_ad( [ 'post_status' => 'publish' ] );
		$expired   = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				],
			]
		);
		$draft     = $this->make_ad( [ 'post_status' => 'draft' ] );

		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'scheduled' ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$this->assertContains( $scheduled, $query->posts );
		$this->assertNotContains( $active, $query->posts );
		$this->assertNotContains( $expired, $query->posts );
		$this->assertNotContains( $draft, $query->posts );
	}

	/**
	 * `private` rows are treated as publish-equivalent for the
	 * lifecycle kinds. The React list requests `private` by default
	 * (see `DEFAULT_STATUSES` in build-query.js), so excluding it
	 * here would make private rows disappear the moment any kind
	 * filter is applied — verified end-to-end against active /
	 * scheduled / expired buckets.
	 */
	public function test_kind_filter_active_includes_private_ads_within_window() {
		$private_active    = $this->make_ad( [ 'post_status' => 'private' ] );
		$private_scheduled = $this->make_ad(
			[
				'post_status' => 'private',
				'meta_input'  => [
					'start_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
				],
			]
		);
		$private_expired   = $this->make_ad(
			[
				'post_status' => 'private',
				'meta_input'  => [
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				],
			]
		);

		$active_query = new WP_Query(
			array_merge(
				Ads_List_REST::filter_rest_query(
					[],
					$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'active' ] )
				),
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);
		$this->assertContains( $private_active, $active_query->posts );
		$this->assertNotContains( $private_scheduled, $active_query->posts );
		$this->assertNotContains( $private_expired, $active_query->posts );

		$scheduled_query = new WP_Query(
			array_merge(
				Ads_List_REST::filter_rest_query(
					[],
					$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'scheduled' ] )
				),
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);
		$this->assertContains( $private_scheduled, $scheduled_query->posts );
		$this->assertNotContains( $private_active, $scheduled_query->posts );

		$expired_query = new WP_Query(
			array_merge(
				Ads_List_REST::filter_rest_query(
					[],
					$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'expired' ] )
				),
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);
		$this->assertContains( $private_expired, $expired_query->posts );
		$this->assertNotContains( $private_active, $expired_query->posts );
	}

	/**
	 * Virtual orderby tokens must be in the enum, else the controller
	 * 400s before `translate_virtual_orderby` can rewrite.
	 */
	public function test_extend_collection_params_adds_virtual_orderby_tokens() {
		$params = Ads_List_REST::extend_collection_params(
			[
				'orderby' => [
					'enum' => [ 'date', 'title' ],
				],
			]
		);

		$enum = $params['orderby']['enum'];
		foreach ( [ 'start_date', 'expiry_date', 'price', 'impressions', 'clicks' ] as $token ) {
			$this->assertContains( $token, $enum );
		}
		$this->assertContains( 'date', $enum );
		$this->assertContains( 'title', $enum );
	}

	/**
	 * Duplicate tokens in the enum clutter the OpenAPI schema.
	 */
	public function test_extend_collection_params_dedupes_existing_tokens() {
		$params = Ads_List_REST::extend_collection_params(
			[
				'orderby' => [
					'enum' => [ 'date', 'price' ],
				],
			]
		);

		$this->assertSame( 1, count( array_keys( $params['orderby']['enum'], 'price', true ) ) );
	}

	/**
	 * Each token encodes meta_key / is_num / order on a per-call query var.
	 */
	public function test_translate_virtual_orderby_encodes_sort_on_query_var() {
		$expectations = [
			'start_date'  => [
				'meta_key' => 'start_date',
				'is_num'   => false,
			],
			'expiry_date' => [
				'meta_key' => 'expiry_date',
				'is_num'   => false,
			],
			'price'       => [
				'meta_key' => 'price',
				'is_num'   => true,
			],
			'impressions' => [
				'meta_key' => 'tracking_impressions',
				'is_num'   => true,
			],
			'clicks'      => [
				'meta_key' => 'tracking_clicks',
				'is_num'   => true,
			],
		];

		foreach ( $expectations as $token => $expected ) {
			$args = Ads_List_REST::translate_virtual_orderby(
				[
					'orderby' => $token,
					'order'   => 'asc',
				],
				$this->rest_request( [] )
			);
			$this->assertSame( 'none', $args['orderby'], sprintf( '%s should suppress default sort', $token ) );
			$this->assertArrayNotHasKey( 'meta_key', $args, sprintf( '%s should not set meta_key', $token ) );
			$sort = $args[ Ads_List_REST::META_SORT_QUERY_VAR ];
			$this->assertSame( $expected['meta_key'], $sort['meta_key'], sprintf( '%s should encode meta_key', $token ) );
			$this->assertSame( $expected['is_num'], $sort['is_num'], sprintf( '%s should encode is_num', $token ) );
			$this->assertSame( 'ASC', $sort['order'], sprintf( '%s should encode order', $token ) );
		}
	}

	/**
	 * Repeat calls must not register new posts_clauses callbacks —
	 * the sort lives on the query var, not in module-level filter state.
	 */
	public function test_translate_virtual_orderby_does_not_accumulate_filter_state() {
		$before = isset( $GLOBALS['wp_filter']['posts_clauses'] ) ? count( $GLOBALS['wp_filter']['posts_clauses']->callbacks[10] ?? [] ) : 0;

		Ads_List_REST::translate_virtual_orderby(
			[
				'orderby' => 'impressions',
				'order'   => 'desc',
			],
			$this->rest_request( [] )
		);
		Ads_List_REST::translate_virtual_orderby(
			[
				'orderby' => 'price',
				'order'   => 'asc',
			],
			$this->rest_request( [] )
		);
		Ads_List_REST::translate_virtual_orderby(
			[
				'orderby' => 'start_date',
				'order'   => 'desc',
			],
			$this->rest_request( [] )
		);

		$after = count( $GLOBALS['wp_filter']['posts_clauses']->callbacks[10] ?? [] );
		$this->assertSame( $before, $after );
	}

	/**
	 * Native and unknown orderby values pass through untouched.
	 */
	public function test_translate_virtual_orderby_passes_through_native_and_unknown_values() {
		foreach ( [ 'title', 'date', 'id', 'unknown' ] as $orderby ) {
			$args = Ads_List_REST::translate_virtual_orderby(
				[ 'orderby' => $orderby ],
				$this->rest_request( [] )
			);
			$this->assertSame( $orderby, $args['orderby'] );
			$this->assertArrayNotHasKey( 'meta_key', $args );
		}
	}

	/**
	 * Setting meta_key without an orderby would silently exclude
	 * rows missing that meta — WP_Query inner-joins on the key.
	 */
	public function test_translate_virtual_orderby_leaves_args_alone_when_orderby_absent() {
		$args = Ads_List_REST::translate_virtual_orderby(
			[ 'post_status' => 'publish' ],
			$this->rest_request( [] )
		);
		$this->assertSame( [ 'post_status' => 'publish' ], $args );
	}

	/**
	 * End-to-end: numeric sort uses numeric comparison, not lexicographic.
	 */
	public function test_translate_virtual_orderby_numeric_sort_uses_numeric_comparison() {
		$cheap     = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [ 'price' => '2' ],
			]
		);
		$expensive = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [ 'price' => '10' ],
			]
		);

		$args = Ads_List_REST::translate_virtual_orderby(
			[
				'orderby' => 'price',
				'order'   => 'asc',
			],
			$this->rest_request( [] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$ordered = array_values( array_intersect( $query->posts, [ $cheap, $expensive ] ) );
		$this->assertSame( [ $cheap, $expensive ], $ordered );
	}

	/**
	 * Decimal prices must keep their fractional ordering — the editor
	 * accepts step=0.01, so 10.01 and 10.99 must not collapse to the
	 * same integer bucket (which would happen under `CAST AS SIGNED`).
	 */
	public function test_translate_virtual_orderby_preserves_decimal_precision_on_price() {
		$cheap_decimal     = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [ 'price' => '10.01' ],
			]
		);
		$expensive_decimal = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [ 'price' => '10.99' ],
			]
		);

		$args = Ads_List_REST::translate_virtual_orderby(
			[
				'orderby' => 'price',
				'order'   => 'asc',
			],
			$this->rest_request( [] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			)
		);

		$ordered = array_values( array_intersect( $query->posts, [ $cheap_decimal, $expensive_decimal ] ) );
		$this->assertSame( [ $cheap_decimal, $expensive_decimal ], $ordered );
	}

	/**
	 * Rows missing the sorted meta must still appear — a plain
	 * `meta_key` inner-join would drop fresh ads without
	 * tracking_impressions, start_date, etc.
	 */
	public function test_translate_virtual_orderby_includes_rows_without_sorted_meta() {
		$cases = [
			[
				'token' => 'impressions',
				'key'   => 'tracking_impressions',
				'value' => 42,
			],
			[
				'token' => 'start_date',
				'key'   => 'start_date',
				'value' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
			],
		];

		foreach ( $cases as $case ) {
			$with_meta    = $this->make_ad(
				[
					'post_status' => 'publish',
					'meta_input'  => [ $case['key'] => $case['value'] ],
				]
			);
			$without_meta = $this->make_ad( [ 'post_status' => 'publish' ] );

			$args = Ads_List_REST::translate_virtual_orderby(
				[
					'orderby' => $case['token'],
					'order'   => 'desc',
				],
				$this->rest_request( [] )
			);

			$query = new WP_Query(
				array_merge(
					$args,
					[
						'post_type'      => Ads::CPT,
						'post_status'    => 'publish',
						'fields'         => 'ids',
						'posts_per_page' => -1,
					]
				)
			);

			$this->assertContains( $with_meta, $query->posts, sprintf( '%s: row with meta should appear', $case['token'] ) );
			$this->assertContains( $without_meta, $query->posts, sprintf( '%s: row without meta should still appear', $case['token'] ) );
		}
	}

	/**
	 * `kind=expired` surfaces only published ads whose `expiry_date`
	 * is in the past — verified end-to-end by running WP_Query with
	 * the filtered args. Active, scheduled, and draft rows must not
	 * leak into the result set.
	 */
	public function test_kind_filter_expired_returns_only_expired_ads() {
		$expired   = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'expiry_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				],
			]
		);
		$active    = $this->make_ad( [ 'post_status' => 'publish' ] );
		$scheduled = $this->make_ad(
			[
				'post_status' => 'publish',
				'meta_input'  => [
					'start_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
				],
			]
		);
		$draft     = $this->make_ad( [ 'post_status' => 'draft' ] );

		$args = Ads_List_REST::filter_rest_query(
			[],
			$this->rest_request( [ Ads_List_REST::STATUS_QUERY_PARAM => 'expired' ] )
		);

		$query = new WP_Query(
			array_merge(
				$args,
				[
					'post_type'      => Ads::CPT,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			)
		);

		$this->assertContains( $expired, $query->posts );
		$this->assertNotContains( $active, $query->posts );
		$this->assertNotContains( $scheduled, $query->posts );
		$this->assertNotContains( $draft, $query->posts );
	}
}
