<?php
/**
 * Tests for the content gate's institutional access prompt (NPPD-2222).
 *
 * @package Newspack
 */

use Newspack\Content_Gate;
use Newspack\Content_Gate_Advanced_Settings;
use Newspack\Institution;

/**
 * An anonymous reader on a campus network is served the cached gate, where their
 * IP is never checked. The prompt is the line at the foot of the gate that sends
 * them through the IP check. These tests pin when it renders: only where passing
 * the check could open the article, so no site gains a dead link.
 *
 * @group Content_Gate_Institutional_Access_Prompt
 */
class Test_Institutional_Access_Prompt extends WP_UnitTestCase {

	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

	/**
	 * Stand-in for the publisher's gate layout.
	 */
	const LAYOUT = '<p>Subscribe to keep reading.</p>';

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		delete_option( 'newspack_content_gate_institutional_access_text' );
		Content_Gate_Advanced_Settings::reset_cache();
		Institution::invalidate_cache();
		Institution::reset_matching_cache();
		Content_Gate::flush_gates_cache();
		$this->reset_restriction_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a published institution.
	 *
	 * @param array $rules Institution rules: email_domain, ip_range, reader_data.
	 *
	 * @return int Institution post ID.
	 */
	private function create_institution( array $rules ): int {
		$institution_id = Institution::create( 'Example University', '', $rules );
		Institution::invalidate_cache();
		return $institution_id;
	}

	/**
	 * Create a published gate over all posts with the given access rule groups.
	 *
	 * @param array $access_rules Access rules in grouped format.
	 * @param int   $priority     Gate priority. Lower gates are evaluated first.
	 */
	private function create_gate( array $access_rules, int $priority = 1 ): void {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Gate ' . $priority ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Gate ' . $priority,
				'status'        => 'publish',
				'priority'      => $priority,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'custom_access' => [
					'active'       => true,
					'access_rules' => $access_rules,
				],
			]
		);
		Content_Gate::flush_gates_cache();
		$this->reset_restriction_cache();
	}

	/**
	 * Create a published registration wall over all posts whose registered access
	 * names institutions.
	 *
	 * @param array $registration_rules Registered access rules in grouped format.
	 * @param array $paid_rules         Paid access rules in grouped format; none turns paid access off.
	 */
	private function create_registration_gate( array $registration_rules, array $paid_rules = [] ): void {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Registration wall' ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Registration wall',
				'status'        => 'publish',
				'priority'      => 1,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'       => true,
					'access_rules' => $registration_rules,
				],
				'custom_access' => [
					'active'       => ! empty( $paid_rules ),
					'access_rules' => $paid_rules,
				],
			]
		);
		Content_Gate::flush_gates_cache();
		$this->reset_restriction_cache();
	}

	/**
	 * Visit a gated post and render the gate layout, as a front-end request would.
	 *
	 * @param int    $user_id    Visitor ID, 0 for anonymous.
	 * @param array  $query_args Extra query args on the visited URL.
	 * @param string $layout     The gate layout content to filter.
	 *
	 * @return string The gate layout content after filtering.
	 */
	private function render_gate_as( int $user_id = 0, array $query_args = [], string $layout = self::LAYOUT ): string {
		$gated_post_id = self::factory()->post->create();
		wp_set_current_user( $user_id );
		$this->go_to( add_query_arg( $query_args, get_permalink( $gated_post_id ) ) );
		wp_set_current_user( $user_id );
		$this->assertTrue( Content_Gate::is_post_restricted( $gated_post_id ), 'Sanity: the visitor is gated.' );
		return apply_filters( 'newspack_gate_layout_content', $layout, Content_Gate::get_gate_layout_id() );
	}

	/**
	 * An institution access rule naming the given institutions.
	 *
	 * @param int[] $institution_ids Institution post IDs.
	 *
	 * @return array A single access rule.
	 */
	private function institution_rule( array $institution_ids ): array {
		return [
			'slug'  => 'institution',
			'value' => $institution_ids,
		];
	}

	/**
	 * The link sits below the layout and routes to the query-param check on the
	 * post, host-relative so a library proxy keeps the reader proxied. It carries
	 * none of the visitor's own query args: the gate is page-cached, and args the
	 * cache ignores would be served to every later visitor.
	 */
	public function test_links_anonymous_visitor_to_the_ip_check() {
		$institution_id = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_gate( [ [ $this->institution_rule( [ $institution_id ] ) ] ] );

		$content = $this->render_gate_as( 0, [ 'fbclid' => 'visitor-click-id' ] );

		$this->assertStringStartsWith( self::LAYOUT, $content, 'The layout, and its CTA, come first.' );
		$this->assertMatchesRegularExpression( '#href="/[^"]*[?&]institutional-access=1"#', html_entity_decode( $content ) );
		$this->assertStringNotContainsString( 'visitor-click-id', $content );
		$this->assertStringContainsString( 'On a campus or library network? Check for access.', $content );
	}

	/**
	 * Block layouts, keyed by case: the layout, and the markup the prompt must directly follow.
	 *
	 * @return array[]
	 */
	public function data_block_layouts(): array {
		$checkout = '<!-- wp:newspack-blocks/checkout-button {"text":"Subscribe"} /-->';
		$sign_in  = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#signin_modal">Sign in</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		$column   = '<!-- wp:column --><div class="wp-block-column">' . $checkout . '</div><!-- /wp:column -->';
		return [
			'the last button, inside the frame'  => [
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Subscribe to keep reading.</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group">' . $checkout . $sign_in . '</div><!-- /wp:group --></div><!-- /wp:group -->',
				'<!-- /wp:buttons -->',
			],
			'a row of columns, not a new column' => [
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns">' . $column . $column . '</div><!-- /wp:columns --></div><!-- /wp:group -->',
				'<!-- /wp:columns -->',
			],
		];
	}

	/**
	 * In a block layout the prompt sits next to the calls to action, still inside the
	 * gate's frame, and never splits a row of them.
	 *
	 * @dataProvider data_block_layouts
	 *
	 * @param string $layout      The gate layout.
	 * @param string $follows     Markup the prompt must directly follow.
	 */
	public function test_places_the_link_below_the_calls_to_action( string $layout, string $follows ) {
		$institution_id = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_gate( [ [ $this->institution_rule( [ $institution_id ] ) ] ] );

		$content = $this->render_gate_as( 0, [], $layout );

		$prompt_at = strpos( $content, '<div class="newspack-ui newspack-content-gate__institutional-access">' );
		$this->assertNotFalse( $prompt_at );
		$this->assertStringEndsWith( $follows, substr( $content, 0, $prompt_at ) );
		$this->assertStringEndsWith( '</div><!-- /wp:group -->', $content, 'The frame still closes the layout.' );
		$this->assertSame( 1, substr_count( $content, 'newspack-content-gate__institutional-access' ) );
	}

	/**
	 * Passing the IP check has to be able to open the article. An email-only
	 * institution has no IP to match and a malformed range matches no IP. A group
	 * ANDing the IP institution with a rule an anonymous reader cannot meet, even an
	 * email-only institution rule, still fails after the check.
	 */
	public function test_no_link_when_the_ip_check_cannot_open_the_article() {
		$email_only_id = $this->create_institution( [ 'email_domain' => 'example.test' ] );
		$malformed_id  = $this->create_institution( [ 'ip_range' => 'not-an-ip' ] );
		$ip_id         = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_gate(
			[
				[ $this->institution_rule( [ $email_only_id, $malformed_id ] ) ],
				[
					$this->institution_rule( [ $ip_id ] ),
					[
						'slug'  => 'email_domain',
						'value' => 'example.test',
					],
				],
				[
					$this->institution_rule( [ $ip_id ] ),
					$this->institution_rule( [ $email_only_id ] ),
				],
			]
		);

		$this->assertSame( self::LAYOUT, $this->render_gate_as( 0 ) );
	}

	/**
	 * Only the gate that denied the reader counts. Once a higher-priority gate
	 * restricts, a lower one is never consulted, so its IP institution cannot open
	 * the article.
	 */
	public function test_no_link_when_only_a_lower_priority_gate_admits_the_ip() {
		$institution_id = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.test',
					],
				],
			],
			1
		);
		$this->create_gate( [ [ $this->institution_rule( [ $institution_id ] ) ] ], 2 );

		$this->assertSame( self::LAYOUT, $this->render_gate_as( 0 ) );
	}

	/**
	 * A registration wall whose registered access names an IP institution offers
	 * the check: passing it opens the article.
	 */
	public function test_links_when_registered_access_admits_the_ip() {
		$institution_id = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_registration_gate( [ [ $this->institution_rule( [ $institution_id ] ) ] ] );

		$this->assertStringContainsString( 'institutional-access=1', $this->render_gate_as( 0 ) );
	}

	/**
	 * Registered access's institutions only count the visitor as registered. With
	 * paid access after it, passing the check would trade one wall for the next.
	 */
	public function test_no_link_when_registered_access_admits_the_ip_but_paid_access_follows() {
		$institution_id = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_registration_gate(
			[ [ $this->institution_rule( [ $institution_id ] ) ] ],
			[
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.test',
					],
				],
			]
		);

		$this->assertSame( self::LAYOUT, $this->render_gate_as( 0 ) );
	}

	/**
	 * A signed-in reader's request is uncached, so their IP was already checked
	 * on this very request. The link would only fail for them.
	 */
	public function test_no_link_for_signed_in_readers() {
		$institution_id = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_gate( [ [ $this->institution_rule( [ $institution_id ] ) ] ] );

		$this->assertSame( self::LAYOUT, $this->render_gate_as( self::factory()->user->create() ) );
	}

	/**
	 * The publisher's own copy replaces the default. It is escaped at render, so a
	 * stored value that skipped the settings sanitizer still cannot inject markup.
	 */
	public function test_uses_the_publishers_text() {
		$institution_id = $this->create_institution( [ 'ip_range' => '10.0.0.0/8' ] );
		$this->create_gate( [ [ $this->institution_rule( [ $institution_id ] ) ] ] );
		update_option( 'newspack_content_gate_institutional_access_text', 'Reading from the <b>library</b>?' );
		Content_Gate_Advanced_Settings::reset_cache();

		$content = $this->render_gate_as( 0 );

		$this->assertStringContainsString( '>Reading from the &lt;b&gt;library&lt;/b&gt;?</a>', $content );
		$this->assertStringNotContainsString( 'Check for access', $content );
	}

	/**
	 * The text is rendered into page-cached gate HTML, so changing it flushes the
	 * `batcache` group, where Batcache keeps rendered pages. Otherwise a page warmed
	 * before the save serves the old copy until its entry expires. The flush is
	 * group-scoped, and a save that leaves the text as it was flushes nothing.
	 */
	public function test_changing_the_text_flushes_cached_pages() {
		update_option( 'newspack_content_gate_institutional_access_text', 'On campus?' );
		$GLOBALS['_wp_using_ext_object_cache'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		try {
			wp_cache_set( 'page', 'cached', 'batcache' );
			Content_Gate_Advanced_Settings::update_settings( [ 'institutional_access_text' => 'On campus?' ] );
			$this->assertSame( 'cached', wp_cache_get( 'page', 'batcache' ), 'An unchanged text leaves cached pages alone.' );

			wp_cache_set( 'other', 'cached', 'options' );
			Content_Gate_Advanced_Settings::update_settings( [ 'institutional_access_text' => 'At the library?' ] );
			$this->assertFalse( wp_cache_get( 'page', 'batcache' ), 'A new text flushes cached pages.' );
			$this->assertSame( 'cached', wp_cache_get( 'other', 'options' ), 'Other cache groups survive.' );
		} finally {
			$GLOBALS['_wp_using_ext_object_cache'] = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
}
