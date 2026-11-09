<?php
/**
 * Tests for gating single Collection pages.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use Newspack\Content_Gate;
use Newspack\Metering;
use Newspack\Site_Meter;
use Newspack\Collections\Collection_Category_Taxonomy;
use Newspack\Collections\Collection_Meta;
use Newspack\Collections\Gate_Integration;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Query_Helper;
use Newspack\Collections\Settings;

/**
 * Tests for gating single Collection pages and hiding their own buttons.
 */
class Test_Gate_Integration extends \WP_UnitTestCase {
	use Traits\Trait_Collections_Test;
	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

	/**
	 * Text of the gate's layout.
	 *
	 * @var string
	 */
	const LAYOUT_TEXT = 'Register to read this Collection.';

	/**
	 * Label of the Collection's own attachment button.
	 *
	 * @var string
	 */
	const PDF_LABEL = 'Download PDF';

	/**
	 * The published registration gate covering Collections.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * The gate's registration layout.
	 *
	 * @var int
	 */
	private $layout_id;

	/**
	 * The gated Collection.
	 *
	 * @var int
	 */
	private $collection_id;

	/**
	 * URL of the Collection's PDF.
	 *
	 * @var string
	 */
	private $pdf_url;

	/**
	 * Turn the Content Gates feature on for this class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Register Collections, gate them, and give one a PDF button and a Subscribe link.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_restriction_cache();
		$this->reset_gate_state();

		Post_Type::register_post_type();
		Collection_Meta::register_meta();
		Collection_Category_Taxonomy::register_taxonomy();

		// The Collections module is off in tests, so its hooks aren't registered.
		// The test framework restores every hook after each test.
		Gate_Integration::init();
		// Production wiring, in case an earlier test class stripped it.
		add_action( 'the_post', [ Content_Gate::class, 'restrict_post' ], 10, 2 );
		add_filter( 'the_content', [ Content_Gate::class, 'replace_restricted_content' ], Content_Gate::RESTRICTION_PRIORITY );
		add_filter( 'the_content', [ Content_Gate::class, 'handle_restricted_content' ], PHP_INT_MAX );
		add_filter( 'newspack_content_gate_restrict_post', [ Metering::class, 'restrict_post' ], 10, 2 );

		$this->gate_id = Content_Gate::create_gate( [ 'title' => 'Collections Gate' ] );
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ Post_Type::get_post_type() ],
					],
				],
				'registration'  => [ 'active' => true ],
			]
		);
		$this->layout_id = (int) Content_Gate::get_gate( $this->gate_id )['registration']['gate_layout_id'];
		wp_update_post(
			[
				'ID'           => $this->layout_id,
				'post_content' => '<!-- wp:paragraph --><p>' . self::LAYOUT_TEXT . '</p><!-- /wp:paragraph -->',
			]
		);
		update_post_meta( $this->layout_id, 'style', 'inline' );

		$this->collection_id = $this->create_test_collection(
			[
				'post_content' => '<!-- wp:paragraph --><p>Collection body text.</p><!-- /wp:paragraph -->',
			]
		);
		$attachment_id       = self::factory()->attachment->create(
			[
				'file'           => 'collection.pdf',
				'post_mime_type' => 'application/pdf',
			]
		);
		$this->pdf_url       = wp_get_attachment_url( $attachment_id );
		Collection_Meta::set(
			$this->collection_id,
			'ctas',
			[
				[
					'type'  => 'attachment',
					'label' => self::PDF_LABEL,
					'id'    => $attachment_id,
				],
			]
		);
		Settings::update_setting( 'subscribe_link', 'https://example.test/subscribe' );

		wp_set_current_user( 0 );
	}

	/**
	 * Clear the request-scoped state.
	 */
	public function tear_down() {
		$this->reset_restriction_cache();
		$this->reset_gate_state();
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Reset the render state Content_Gate and Metering keep for one request.
	 */
	private function reset_gate_state() {
		$values = [
			'gate_rendered'       => false,
			'is_gated'            => false,
			'is_content_locked'   => false,
			'overlay_gate_output' => false,
			'restricted_content'  => [],
			'article_restriction' => [],
			'pending_gates'       => [],
		];
		foreach ( $values as $property => $value ) {
			$reflection = new \ReflectionProperty( Content_Gate::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( null, $value );
		}
		$reflection = new \ReflectionProperty( Metering::class, 'logged_in_metering_cache' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, [] );
	}

	/**
	 * Open the single Collection page and start its loop, as the template does.
	 */
	private function go_to_collection() {
		$this->go_to( get_permalink( $this->collection_id ) );
		the_post();
	}

	/**
	 * Run render_single_gate() and capture what it prints.
	 *
	 * @return array{0: bool, 1: string} Whether it rendered, and its output.
	 */
	private function render_single_gate() {
		ob_start();
		$rendered = Gate_Integration::render_single_gate( $this->collection_id );
		return [ $rendered, ob_get_clean() ];
	}

	/**
	 * Fetch the Collection over REST.
	 *
	 * @return array Response data.
	 */
	private function get_rest_collection() {
		// Rebuild the server so it has the routes and fields for Collections,
		// which were registered after it was first built.
		add_action( 'rest_api_init', [ Collection_Meta::class, 'register_rest_fields' ] );
		$GLOBALS['wp_rest_server'] = null;
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::get_post_type() . '/' . $this->collection_id ) );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/**
	 * A restricted reader gets the Subscribe link but not the Collection's own buttons.
	 */
	public function test_ctas_hidden_for_restricted_reader_keep_subscribe() {
		$ctas = Query_Helper::get_ctas( $this->collection_id );

		$this->assertSame( [ 'cta--subscribe_link' ], wp_list_pluck( $ctas, 'class' ) );
	}

	/**
	 * A Collection no gate covers keeps all of its buttons.
	 */
	public function test_ctas_shown_for_unrestricted_collection() {
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
			]
		);

		$ctas = Query_Helper::get_ctas( $this->collection_id );

		$this->assertSame( [ self::PDF_LABEL, 'Subscribe' ], wp_list_pluck( $ctas, 'label' ) );
	}

	/**
	 * An editor sees all of a gated Collection's buttons.
	 */
	public function test_ctas_shown_to_editor() {
		$this->set_current_user_role( 'editor' );

		$ctas = Query_Helper::get_ctas( $this->collection_id );

		$this->assertSame( [ self::PDF_LABEL, 'Subscribe' ], wp_list_pluck( $ctas, 'label' ) );
	}

	/**
	 * On the Collection's own page, code that runs before the loop (a block or
	 * widget in the header) gets no PDF, and the gate doesn't print without the
	 * teaser the loop stages.
	 */
	public function test_ctas_hidden_before_the_loop_on_gated_single() {
		$this->go_to( get_permalink( $this->collection_id ) );
		$this->assertNull( Content_Gate::get_article_restriction( $this->collection_id ), 'Setup: the loop has not decided yet.' );

		$this->assertSame( [ 'Subscribe' ], wp_list_pluck( Query_Helper::get_ctas( $this->collection_id ), 'label' ) );
		[ $rendered, $output ] = $this->render_single_gate();
		$this->assertFalse( $rendered, 'Without the staged teaser, the_content() would print the whole body.' );
		$this->assertSame( '', $output );
	}

	/**
	 * Before the loop, an admin still gets all of the Collection's buttons.
	 */
	public function test_ctas_shown_before_the_loop_for_editor() {
		$this->go_to( get_permalink( $this->collection_id ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( [ self::PDF_LABEL, 'Subscribe' ], wp_list_pluck( Query_Helper::get_ctas( $this->collection_id ), 'label' ) );
	}

	/**
	 * REST hides the PDF from a restricted reader, in both the ctas field and the raw meta.
	 */
	public function test_rest_hides_raw_cta_meta_when_restricted() {
		$meta_key = Collection_Meta::$prefix . 'ctas';

		$data = $this->get_rest_collection();
		$this->assertArrayNotHasKey( $meta_key, $data['meta'] );
		$this->assertNotContains( $this->pdf_url, wp_list_pluck( $data['ctas'], 'url' ) );
		$this->assertContains( 'cta--subscribe_link', wp_list_pluck( $data['ctas'], 'class' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->reset_restriction_cache();

		$data = $this->get_rest_collection();
		$this->assertArrayHasKey( $meta_key, $data['meta'] );
		$this->assertSame( self::PDF_LABEL, $data['meta'][ $meta_key ][0]['label'] );
		$this->assertContains( $this->pdf_url, wp_list_pluck( $data['ctas'], 'url' ) );
	}

	/**
	 * The single page prints the teaser and the inline gate, and hides the PDF.
	 */
	public function test_single_gate_renders_inline_gate() {
		$this->go_to_collection();

		[ $rendered, $output ] = $this->render_single_gate();

		$this->assertTrue( Content_Gate::get_article_restriction( $this->collection_id ), 'The loop restricted the Collection.' );
		$this->assertTrue( $rendered );
		$this->assertStringContainsString( '<div class="entry-content">', $output );
		$this->assertStringContainsString( 'newspack-content-gate__inline-gate', $output );
		$this->assertStringContainsString( self::LAYOUT_TEXT, $output );
		$this->assertSame( [ 'Subscribe' ], wp_list_pluck( Query_Helper::get_ctas( $this->collection_id ), 'label' ) );
	}

	/**
	 * An overlay gate prints here only when no footer hook will print it (block themes).
	 */
	public function test_single_gate_renders_overlay_without_footer_hook() {
		update_post_meta( $this->layout_id, 'style', 'overlay' );
		$this->go_to_collection();

		// Classic theme: the get_footer hook prints the overlay later.
		add_action( 'get_footer', [ Content_Gate::class, 'render_overlay_gate' ], 1 );
		[ $rendered, $output ] = $this->render_single_gate();
		$this->assertTrue( $rendered );
		$this->assertStringNotContainsString( 'newspack-content-gate__overlay-gate', $output, 'The footer hook prints the overlay, not this.' );
		$this->assertStringNotContainsString( 'newspack-content-gate__inline-gate', $output, 'An overlay layout has no inline gate.' );

		// Block theme: nothing else prints it.
		remove_action( 'get_footer', [ Content_Gate::class, 'render_overlay_gate' ], 1 );
		[ $rendered, $output ] = $this->render_single_gate();
		$this->assertTrue( $rendered );
		$this->assertStringContainsString( 'newspack-content-gate__overlay-gate', $output );
		$this->assertStringContainsString( self::LAYOUT_TEXT, $output );
	}

	/**
	 * Before the loop decides, a metered reader gets no PDF. Once metering lets
	 * the view through, the whole page shows, buttons included.
	 */
	public function test_metered_view_keeps_full_page() {
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'registration'  => [ 'active' => false ],
				'custom_access' => [
					'active'       => true,
					// An email domain the factory's addresses don't use.
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.test',
							],
						],
					],
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
						'scope'   => Site_Meter::SCOPE_GATE,
					],
				],
			]
		);
		$this->set_current_user_role( 'subscriber' );
		$this->assertNotEmpty( Content_Gate::is_post_restricted( $this->collection_id ), 'Setup: the gate restricts this reader.' );

		$this->go_to( get_permalink( $this->collection_id ) );
		$this->assertNotContains( self::PDF_LABEL, wp_list_pluck( Query_Helper::get_ctas( $this->collection_id ), 'label' ), 'Before the loop decides, the PDF stays hidden.' );

		the_post();
		$this->assertFalse( Content_Gate::get_article_restriction( $this->collection_id ), 'The loop decided not to restrict this view.' );
		[ $rendered, $output ] = $this->render_single_gate();

		$this->assertFalse( $rendered );
		$this->assertSame( '', $output );
		$this->assertContains( self::PDF_LABEL, wp_list_pluck( Query_Helper::get_ctas( $this->collection_id ), 'label' ) );
	}
}
