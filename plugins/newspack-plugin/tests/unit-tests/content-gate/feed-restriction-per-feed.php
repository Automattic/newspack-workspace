<?php
/**
 * Tests for the per-feed restriction override on partner RSS feeds.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Gate_Advanced_Settings;
use Newspack\Optional_Modules;
use Newspack\RSS;

require_once __DIR__ . '/../../mocks/filter-input-mock.php';

/**
 * A partner feed carries its own restriction mode, which outranks the site-wide
 * one in both directions: a feed licensed to a syndication partner can be
 * exempted without opening every feed on the site, and a feed the site leaves
 * open can be closed on its own.
 *
 * Because that setting can switch the paywall off, the tests below also pin the
 * three ways it must refuse to: an unpublished feed, a user who cannot change
 * the site-wide setting, and a stored value nobody recognizes.
 *
 * The site-wide mode is the plumbing these tests build on — see
 * feed-restriction.php for its own coverage.
 *
 * @group content-gate
 */
class Test_Feed_Restriction_Per_Feed extends \WP_UnitTestCase {

	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

	/**
	 * Gated post content. Paragraph three is past the gate's default two visible
	 * paragraphs, so its marker only survives in an unrestricted feed.
	 */
	const POST_CONTENT = '<!-- wp:paragraph --><p>FREE_ONE paragraph one.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>FREE_TWO paragraph two.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>PAID_THREE paragraph three.</p><!-- /wp:paragraph -->';

	/**
	 * Gated post ID.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * The partner feed the overrides are set on.
	 *
	 * @var int
	 */
	private $feed_post_id;

	/**
	 * A second published partner feed, which never stores an override.
	 *
	 * @var int
	 */
	private $sibling_feed_post_id;

	/**
	 * Enable the Content Gates feature flag for this class only.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * A published gate restricting all posts, one gated post, and two partner
	 * feeds inheriting the site-wide mode, consumed as an anonymous reader.
	 */
	public function set_up() {
		parent::set_up();

		// RSS::init() bails when the module is inactive, and it already ran at
		// plugin load — before this suite could activate it. Re-run it so the
		// module's hooks, the restriction override among them, are really wired.
		Optional_Modules::activate_optional_module( 'rss' );
		RSS::init();
		// init() only hooks this onto `init`, which fired long ago. Without the
		// CPT registered its capabilities don't exist, so the save path's
		// capability check answers false and every save test passes vacuously.
		RSS::register_feed_cpt();

		$gate_id = Content_Gate::create_gate( [ 'title' => 'Per-feed Gate' ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Per-feed Gate',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_id'              => 0,
				],
			]
		);

		$this->post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => self::POST_CONTENT,
			]
		);

		$this->feed_post_id         = $this->create_feed( 'syndication-partner' );
		$this->sibling_feed_post_id = $this->create_feed( 'other-partner' );

		wp_set_current_user( 0 );
		update_option( 'rss_use_excerpt', 0 );
		update_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'restrict_feeds', 1, false );
		$this->set_site_feed_mode( Content_Gate_Advanced_Settings::FEED_MODE_TRUNCATE );
	}

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		$_GET  = [];
		$_POST = [];
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		$feed_ids = get_posts(
			[
				'post_type'   => RSS::FEED_CPT,
				'post_status' => 'any',
				'numberposts' => 20,
				'fields'      => 'ids',
			]
		);
		foreach ( $feed_ids as $feed_id ) {
			wp_delete_post( $feed_id, true );
		}
		wp_delete_post( $this->post_id, true );
		$this->reset_restriction_cache();
		$this->reset_override_cache();
		unregister_post_type( RSS::FEED_CPT );
		Optional_Modules::deactivate_optional_module( 'rss' );

		delete_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'restrict_feeds' );
		delete_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'feed_restriction_mode' );
		delete_option( 'rss_use_excerpt' );
		Content_Gate_Advanced_Settings::reset_cache();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Create a partner feed.
	 *
	 * @param string $slug        Feed slug, which is also its query-arg value.
	 * @param string $post_status Feed post status.
	 *
	 * @return int Feed post ID.
	 */
	private function create_feed( $slug, $post_status = 'publish' ) {
		return wp_insert_post(
			[
				'post_type'   => RSS::FEED_CPT,
				'post_title'  => 'Feed ' . $slug,
				'post_name'   => $slug,
				'post_status' => $post_status,
			]
		);
	}

	/**
	 * Discard RSS's per-request memo of the resolved override.
	 *
	 * It assumes one `partner-feed` arg and one set of feed settings per
	 * request — true in production, false across cases here, which change the
	 * stored mode and re-request the same feed. Private deliberately: nothing in
	 * production needs to discard it, so the reset stays in the test layer.
	 */
	private function reset_override_cache() {
		$override_cache_property = new \ReflectionProperty( RSS::class, 'restriction_override_cache' );
		$override_cache_property->setAccessible( true );
		$override_cache_property->setValue( null, [] );
	}

	/**
	 * Set the site-wide feed restriction mode and clear the settings cache.
	 *
	 * @param string $mode One of the Content_Gate_Advanced_Settings FEED_MODE_* values.
	 */
	private function set_site_feed_mode( $mode ) {
		update_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'feed_restriction_mode', $mode, false );
		Content_Gate_Advanced_Settings::reset_cache();
	}

	/**
	 * Store a restriction mode on a partner feed.
	 *
	 * @param string   $mode         One of the RSS FEED_RESTRICTION_* values.
	 * @param int|null $feed_post_id Feed to set it on; defaults to the main one.
	 */
	private function set_feed_restriction_mode( $mode, $feed_post_id = null ) {
		$feed_post_id = $feed_post_id ?? $this->feed_post_id;
		$settings     = RSS::get_feed_settings( $feed_post_id );

		$settings[ RSS::FEED_RESTRICTION_SETTING ] = $mode;
		update_post_meta( $feed_post_id, RSS::FEED_SETTINGS_META, $settings );
		$this->reset_override_cache();
	}

	/**
	 * Run a callback inside a real request for a partner feed.
	 *
	 * `go_to()` clears `$_GET` and repopulates it from the target URL, and
	 * RSS::get_feed_url() carries the `partner-feed` arg — so the arg the
	 * override reads arrives the same way a live request delivers it.
	 *
	 * @param callable $callback     Runs with the feed query in scope.
	 * @param int|null $feed_post_id Feed to request; defaults to the main one.
	 *
	 * @return mixed The callback's return value.
	 */
	private function in_partner_feed( callable $callback, $feed_post_id = null ) {
		$this->go_to( RSS::get_feed_url( get_post( $feed_post_id ?? $this->feed_post_id ) ) );
		$this->assertTrue( is_feed(), 'Request should be a feed.' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Asserting on the superglobal go_to() just populated.
		$this->assertNotEmpty( $_GET[ RSS::FEED_QUERY_ARG ] ?? '', 'Feed request should carry the partner-feed arg.' );

		$result = $callback();
		wp_reset_postdata();

		return $result;
	}

	/**
	 * The rendered feed body for the gated post in the current feed request.
	 *
	 * @return string
	 */
	private function gated_post_feed_content() {
		$content = '';
		ob_start();
		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $this->post_id ) {
				$content = get_the_content_feed( 'rss2' );
			}
		}
		ob_end_clean();
		return $content;
	}

	/**
	 * The post IDs the current feed request yields.
	 *
	 * @return int[]
	 */
	private function feed_post_ids() {
		$ids = [];
		while ( have_posts() ) {
			the_post();
			$ids[] = get_the_ID();
		}
		return $ids;
	}

	/**
	 * Submit the feed editor's form as a given user.
	 *
	 * @param int   $user_id Acting user.
	 * @param array $fields  POSTed fields, beyond the nonce.
	 */
	private function submit_feed_settings( $user_id, array $fields ) {
		wp_set_current_user( $user_id );
		$_POST = array_merge(
			[ 'newspack_rss_enhancements_nonce' => wp_create_nonce( 'newspack_rss_enhancements_nonce' ) ],
			$fields
		);
		RSS::save_settings( $this->feed_post_id );
		$_POST = [];
		wp_set_current_user( 0 );
		$this->reset_override_cache();
	}

	/**
	 * The mode currently stored on the main partner feed.
	 *
	 * @return string
	 */
	private function stored_feed_restriction_mode() {
		return RSS::get_feed_settings( $this->feed_post_id )[ RSS::FEED_RESTRICTION_SETTING ];
	}

	/**
	 * The reported regression: a partner feed configured for full content still
	 * served the gate teaser, because the site-wide truncate mode was the only
	 * word on it. Setting the feed's own mode to "off" exempts that one feed.
	 */
	public function test_per_feed_off_serves_full_content_where_the_site_truncates() {
		$this->assertStringNotContainsString(
			'PAID_THREE',
			$this->in_partner_feed( [ $this, 'gated_post_feed_content' ] ),
			'Without an override the partner feed should inherit the site-wide truncate mode.'
		);

		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_OFF );

		$this->assertStringContainsString(
			'PAID_THREE',
			$this->in_partner_feed( [ $this, 'gated_post_feed_content' ] ),
			'A feed set to "off" should carry the full body of a restricted post.'
		);
	}

	/**
	 * The other direction: a feed can be closed while the site leaves feeds open.
	 */
	public function test_per_feed_exclude_drops_posts_the_site_leaves_in() {
		update_option( Content_Gate_Advanced_Settings::OPTION_PREFIX . 'restrict_feeds', 0, false );
		Content_Gate_Advanced_Settings::reset_cache();

		$this->assertContains(
			$this->post_id,
			$this->in_partner_feed( [ $this, 'feed_post_ids' ] ),
			'With site-wide feed restriction off the restricted post should be listed.'
		);

		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_EXCLUDE );

		$this->assertNotContains(
			$this->post_id,
			$this->in_partner_feed( [ $this, 'feed_post_ids' ] ),
			'A feed set to "exclude" should drop restricted posts even when the site leaves feeds open.'
		);
	}

	/**
	 * An override belongs to the feed that stores it. A sibling partner feed on
	 * the same site keeps the site-wide mode.
	 */
	public function test_override_applies_only_to_the_feed_that_stores_it() {
		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_OFF );

		$this->assertStringNotContainsString(
			'PAID_THREE',
			$this->in_partner_feed( [ $this, 'gated_post_feed_content' ], $this->sibling_feed_post_id ),
			'One feed exempting itself should not exempt another partner feed.'
		);
	}

	/**
	 * A feed nobody published must not be able to switch the paywall off.
	 *
	 * `get_page_by_path()` matches on slug and post type alone, so a draft feed
	 * resolves from its public URL exactly like a published one — which would
	 * otherwise put every gated article in full at a URL that passed no review.
	 */
	public function test_unpublished_feed_cannot_disable_restriction() {
		$draft_feed_id = $this->create_feed( 'draft-partner', 'draft' );
		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_OFF, $draft_feed_id );

		$this->assertStringNotContainsString(
			'PAID_THREE',
			$this->in_partner_feed( [ $this, 'gated_post_feed_content' ], $draft_feed_id ),
			'A draft feed should not be able to serve restricted posts in full.'
		);
	}

	/**
	 * The override only speaks for a feed's main query. Outside one the
	 * `partner-feed` arg is meaningless, and the site-wide mode stands.
	 */
	public function test_override_is_ignored_outside_a_feed_request() {
		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_OFF );

		// After go_to(), which clears $_GET and repopulates it from the URL.
		$this->go_to( get_permalink( $this->post_id ) );
		$_GET[ RSS::FEED_QUERY_ARG ] = 'syndication-partner';
		$this->assertFalse( is_feed(), 'A permalink request should not be a feed.' );

		$this->assertSame(
			Content_Gate_Advanced_Settings::FEED_MODE_TRUNCATE,
			Content_Gate_Advanced_Settings::get_feed_restriction_mode( [ 'query' => $GLOBALS['wp_query'] ] ),
			'A non-feed request should resolve to the site-wide mode.'
		);
	}

	/**
	 * An unrecognized stored mode falls back to the mode passed in rather than
	 * disabling restriction: a corrupt setting must not open a gated feed.
	 *
	 * Asserted on RSS's own guard, because Content_Gate_Advanced_Settings
	 * re-validates the filter's return and would mask its absence.
	 */
	public function test_unknown_stored_mode_falls_back_to_the_inherited_mode() {
		$this->set_feed_restriction_mode( 'nonsense' );

		$resolved_mode = $this->in_partner_feed(
			function () {
				return RSS::apply_feed_restriction_override(
					Content_Gate_Advanced_Settings::FEED_MODE_TRUNCATE,
					[ 'query' => $GLOBALS['wp_query'] ]
				);
			}
		);

		$this->assertSame( Content_Gate_Advanced_Settings::FEED_MODE_TRUNCATE, $resolved_mode );
	}

	/**
	 * A stored mode that is not even a string — meta written by a migration, a
	 * filter, or WP-CLI rather than by the editor — resolves like any other
	 * unrecognized value instead of failing the whole feed request.
	 */
	public function test_non_string_stored_mode_does_not_break_the_feed() {
		$this->set_feed_restriction_mode( [ 'corrupt' ] );

		$resolved_mode = $this->in_partner_feed(
			function () {
				return RSS::apply_feed_restriction_override(
					Content_Gate_Advanced_Settings::FEED_MODE_TRUNCATE,
					[ 'query' => $GLOBALS['wp_query'] ]
				);
			}
		);

		$this->assertSame( Content_Gate_Advanced_Settings::FEED_MODE_TRUNCATE, $resolved_mode );
	}

	/**
	 * The warning has to agree with what the feed actually serves. An
	 * unrecognized stored mode falls back to the inherited one at runtime, so
	 * inheriting an unrestricted site means the feed does publish restricted
	 * articles in full — and the editor has to say so.
	 */
	public function test_warning_follows_an_unrecognized_mode_to_its_inherited_result() {
		$this->assertTrue(
			RSS::feed_serves_unrestricted_full_content(
				[
					RSS::FEED_RESTRICTION_SETTING => 'nonsense',
					'full_content'                => true,
				],
				Content_Gate_Advanced_Settings::FEED_MODE_OFF
			),
			'An unrecognized mode inherits, so an unrestricted site should still warn.'
		);
	}

	/**
	 * Remove the override callback whichever priority it is registered at, so a
	 * test can re-register it in a chosen order. Spans both the shipped priority
	 * and the default, so the helper survives a change to either.
	 */
	private function unhook_restriction_override() {
		foreach ( [ 5, 10 ] as $priority ) {
			remove_filter( 'newspack_content_gate_feed_restriction_mode', [ RSS::class, 'apply_feed_restriction_override' ], $priority );
		}
	}

	/**
	 * An integration that must force a feed's mode — newspack-manager does this
	 * for the Pugpig app feed, whose entitlement the app handles itself — has to
	 * outrank a publisher's per-feed setting, or a publisher can close the app's
	 * feed from the RSS editor and break the app.
	 *
	 * Asserted for both registration orders: the guarantee is priority, not the
	 * order two plugins happen to load in.
	 *
	 * @dataProvider integration_registration_orders
	 *
	 * @param bool $integration_first Whether the integration hooks on first.
	 */
	public function test_an_integration_outranks_the_per_feed_mode( $integration_first ) {
		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_EXCLUDE );
		$force_off = function () {
			return Content_Gate_Advanced_Settings::FEED_MODE_OFF;
		};

		$this->unhook_restriction_override();
		if ( $integration_first ) {
			add_filter( 'newspack_content_gate_feed_restriction_mode', $force_off );
			RSS::init();
		} else {
			RSS::init();
			add_filter( 'newspack_content_gate_feed_restriction_mode', $force_off );
		}

		$resolved_mode = $this->in_partner_feed(
			function () {
				return Content_Gate_Advanced_Settings::get_feed_restriction_mode( [ 'query' => $GLOBALS['wp_query'] ] );
			}
		);
		remove_filter( 'newspack_content_gate_feed_restriction_mode', $force_off );

		$this->assertSame( Content_Gate_Advanced_Settings::FEED_MODE_OFF, $resolved_mode );
	}

	/**
	 * Cases for test_an_integration_outranks_the_per_feed_mode.
	 *
	 * @return array[]
	 */
	public function integration_registration_orders() {
		return [
			'integration hooks first' => [ true ],
			'override hooks first'    => [ false ],
		];
	}

	/**
	 * Capture the Content Settings metabox as an administrator sees it.
	 *
	 * @return string
	 */
	private function render_content_settings() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		RSS::render_content_settings_metabox( get_post( $this->feed_post_id ) );
		$markup = ob_get_clean();
		wp_set_current_user( 0 );
		return $markup;
	}

	/**
	 * A feed whose mode an integration forces gets the reason, not a control
	 * that silently does nothing.
	 */
	public function test_a_locked_feed_shows_the_reason_instead_of_the_control() {
		$lock_reason = 'The app integration controls this feed.';
		$lock        = function () use ( $lock_reason ) {
			return $lock_reason;
		};
		add_filter( 'newspack_rss_feed_restriction_locked', $lock );
		$markup = $this->render_content_settings();
		remove_filter( 'newspack_rss_feed_restriction_locked', $lock );

		$this->assertStringContainsString( $lock_reason, $markup );
		$this->assertStringNotContainsString( 'newspack-rss-content-restriction-mode', $markup, 'A locked feed should not offer the select.' );
	}

	/**
	 * The companion: with nothing locking it, the control renders.
	 */
	public function test_an_unlocked_feed_offers_the_control() {
		$this->assertStringContainsString( 'newspack-rss-content-restriction-mode', $this->render_content_settings() );
	}

	/**
	 * The editor warning fires exactly when a feed both opts out of restriction
	 * and asks for full content — the combination that publishes complete gated
	 * articles at a public URL.
	 *
	 * @dataProvider full_content_leak_cases
	 *
	 * @param string $stored_mode    Mode stored on the feed.
	 * @param bool   $full_content   Whether the feed serves full content.
	 * @param string $inherited_mode Mode the feed inherits.
	 * @param bool   $expected_warn  Whether the warning should show.
	 */
	public function test_full_content_leak_warning_condition( $stored_mode, $full_content, $inherited_mode, $expected_warn ) {
		$this->assertSame(
			$expected_warn,
			RSS::feed_serves_unrestricted_full_content(
				[
					RSS::FEED_RESTRICTION_SETTING => $stored_mode,
					'full_content'                => $full_content,
				],
				$inherited_mode
			)
		);
	}

	/**
	 * Cases for test_full_content_leak_warning_condition.
	 *
	 * @return array[]
	 */
	public function full_content_leak_cases() {
		$off      = Content_Gate_Advanced_Settings::FEED_MODE_OFF;
		$truncate = Content_Gate_Advanced_Settings::FEED_MODE_TRUNCATE;
		$inherit  = RSS::FEED_RESTRICTION_INHERIT;

		return [
			'off plus full content'          => [ $off, true, $truncate, true ],
			'off but excerpts only'          => [ $off, false, $truncate, false ],
			'inheriting a site-wide off'     => [ $inherit, true, $off, true ],
			'restricting feed, full content' => [ $truncate, true, $off, false ],
		];
	}

	/**
	 * An absent field means the control was not on the form, not "clear it" — so
	 * an unrelated save by a user who cannot see the control keeps the mode a
	 * publisher chose.
	 */
	public function test_save_preserves_the_stored_mode_when_the_field_is_absent() {
		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_EXCLUDE );
		$administrator_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->submit_feed_settings( $administrator_id, [ 'num_items_in_feed' => 7 ] );

		$this->assertSame( Content_Gate_Advanced_Settings::FEED_MODE_EXCLUDE, $this->stored_feed_restriction_mode() );
		$this->assertSame( 7, RSS::get_feed_settings( $this->feed_post_id )['num_items_in_feed'], 'The rest of the save should still apply.' );
	}

	/**
	 * Editing a partner feed needs only the CPT's mapped `edit_posts`, which
	 * Authors hold; the site-wide setting this overrides needs `manage_options`.
	 * A POST from the weaker role must not move the paywall.
	 */
	public function test_save_ignores_the_mode_from_a_user_who_cannot_set_it() {
		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_EXCLUDE );
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$this->submit_feed_settings(
			$author_id,
			[
				'num_items_in_feed'           => 7,
				RSS::FEED_RESTRICTION_SETTING => Content_Gate_Advanced_Settings::FEED_MODE_OFF,
			]
		);

		$settings = RSS::get_feed_settings( $this->feed_post_id );
		$this->assertSame( 7, $settings['num_items_in_feed'], 'An Author should still be able to save the feed.' );
		$this->assertSame(
			Content_Gate_Advanced_Settings::FEED_MODE_EXCLUDE,
			$settings[ RSS::FEED_RESTRICTION_SETTING ],
			'An Author should not be able to move the restriction mode.'
		);
	}

	/**
	 * A mode outside the allowlist normalizes to inherit rather than being
	 * stored and later rejected at read time.
	 */
	public function test_save_normalizes_an_unknown_mode_to_inherit() {
		$this->set_feed_restriction_mode( Content_Gate_Advanced_Settings::FEED_MODE_EXCLUDE );
		$administrator_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->submit_feed_settings( $administrator_id, [ RSS::FEED_RESTRICTION_SETTING => 'nonsense' ] );

		$this->assertSame( RSS::FEED_RESTRICTION_INHERIT, $this->stored_feed_restriction_mode() );
	}
}
