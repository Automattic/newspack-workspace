<?php
/**
 * Tests for the Partner RSS editor's Private Tags labeling (Part A / NPPD-1461):
 * - ajax_search_terms appends "(private)" only for post_tag results
 * - render_content_settings_metabox labels selected tag chips
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;
use Newspack\RSS;
use Newspack\Optional_Modules;

require_once __DIR__ . '/../tags/traits/trait-private-tags-test-helper.php';

/**
 * Tests for the Partner RSS editor's private-tag labeling.
 *
 * @group private-tags
 */
class Test_RSS_Private_Tags extends WP_Ajax_UnitTestCase {

	use Private_Tags_Test_Helper;

	/**
	 * Feed CPT post ID.
	 *
	 * @var int
	 */
	private $feed_post_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->enable_private_tags_feature();
		$this->reset_private_tags_state();

		Optional_Modules::activate_optional_module( 'rss' );
		// Activating the module only flips the option. init() ran at bootstrap while the
		// module was inactive and bailed, so re-run it here (after parent::set_up's
		// $wp_filter snapshot) to actually register RSS's hooks — including the ajax action.
		RSS::init();

		// Promote to admin so the AJAX nonce/cap checks pass.
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$this->feed_post_id = wp_insert_post(
			[
				'post_title'  => 'Test Feed',
				'post_name'   => 'test-feed',
				'post_type'   => RSS::FEED_CPT,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		if ( $this->feed_post_id ) {
			wp_delete_post( $this->feed_post_id, true );
		}
		Optional_Modules::deactivate_optional_module( 'rss' );
		$this->reset_private_tags_state();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// ajax_search_terms.
	// -----------------------------------------------------------------

	/**
	 * Invoke RSS::ajax_search_terms() and capture the JSON response.
	 *
	 * The method ends in wp_send_json() which calls wp_die(); WP's test handler
	 * Throws WPAjaxDieContinueException which we catch. Output is captured via
	 * An output buffer.
	 *
	 * @param string $taxonomy Taxonomy to search.
	 * @param string $search   Search term.
	 * @return array Decoded JSON response, or [] on missing.
	 */
	private function dispatch_ajax_search_terms( $taxonomy, $search = '' ) {
		// check_ajax_referer reads $_REQUEST, which PHP populates only at request start —
		// $_POST writes mid-test don't propagate. Set both so nonce verification works.
		$nonce                = wp_create_nonce( 'newspack_rss_search_terms' );
		$_POST['action']      = 'newspack_rss_search_terms';
		$_POST['nonce']       = $nonce;
		$_POST['taxonomy']    = $taxonomy;
		$_POST['search']      = $search;
		$_REQUEST['nonce']    = $nonce;
		$_REQUEST['taxonomy'] = $taxonomy;
		$_REQUEST['search']   = $search;

		// WP_Ajax_UnitTestCase opens its own output buffer in set_up and its die handler
		// drains it into $this->_last_response via ob_get_clean(); double-buffering would
		// swallow our echo. We must restart a buffer afterwards so tear_down's level
		// matches set_up's (PHPUnit otherwise flags the test as risky).
		$this->_last_response = '';
		try {
			RSS::ajax_search_terms();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		ob_start();

		unset( $_POST['nonce'], $_POST['taxonomy'], $_POST['search'], $_POST['action'] );
		unset( $_REQUEST['nonce'], $_REQUEST['taxonomy'], $_REQUEST['search'] );

		$decoded = json_decode( $this->_last_response, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Ajax search terms labels private post tag result.
	 */
	public function test_ajax_search_terms_labels_private_post_tag_result() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );

		$results = $this->dispatch_ajax_search_terms( 'post_tag' );

		$by_id = [];
		foreach ( $results as $row ) {
			$by_id[ (int) $row['id'] ] = $row['text'];
		}

		$this->assertArrayHasKey( $private, $by_id );
		$this->assertStringEndsWith( '(private)', $by_id[ $private ] );
		$this->assertArrayHasKey( $public, $by_id );
		$this->assertSame( 'Jazz', $by_id[ $public ] );
	}

	/**
	 * The ajax handler is wired to its action — guards against the labeling working in
	 * isolation while the hook registration is broken (the production entry point).
	 */
	public function test_ajax_search_terms_action_is_registered() {
		$this->assertNotFalse(
			has_action( 'wp_ajax_newspack_rss_search_terms', [ RSS::class, 'ajax_search_terms' ] ),
			'RSS::ajax_search_terms() should be wired to the newspack_rss_search_terms ajax action.'
		);
	}

	/**
	 * Ajax search terms does not label non post tag taxonomy.
	 */
	public function test_ajax_search_terms_does_not_label_non_post_tag_taxonomy() {
		// A category that happens to have the private meta flag.
		$cat_id = $this->factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'News',
			]
		);
		update_term_meta( $cat_id, Private_Tags::META_KEY, 1 );

		$results = $this->dispatch_ajax_search_terms( 'category' );

		$by_id = [];
		foreach ( $results as $row ) {
			$by_id[ (int) $row['id'] ] = $row['text'];
		}

		// Guard against a vacuous pass: the category must actually be in the results.
		$this->assertArrayHasKey( $cat_id, $by_id, 'Category search should return the created category.' );
		$this->assertStringNotContainsString( '(private)', $by_id[ $cat_id ], 'Non-post_tag results must not carry the private label.' );
	}

	// -----------------------------------------------------------------
	// render_content_settings_metabox: selected tag chips.
	// -----------------------------------------------------------------

	/**
	 * Render content settings metabox labels selected private tag.
	 */
	public function test_render_content_settings_metabox_labels_selected_private_tag() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );

		// Save both as selected tag_include values for this feed.
		update_post_meta(
			$this->feed_post_id,
			RSS::FEED_SETTINGS_META,
			[
				'num_items_in_feed' => 10,
				'tag_include'       => [ $private, $public ],
			]
		);

		ob_start();
		RSS::render_content_settings_metabox( get_post( $this->feed_post_id ) );
		$out = ob_get_clean();

		// The option for the private tag's value carries (private); the public one doesn't.
		// Matched per-value without coupling to attribute order or inter-tag whitespace.
		$this->assertMatchesRegularExpression(
			'/value="' . $private . '"[^>]*>[^<]*Beastie \(private\)/',
			$out,
			'Selected private tag chip should be labeled (private).'
		);
		$this->assertMatchesRegularExpression(
			'/value="' . $public . '"[^>]*>[^<]*Jazz</',
			$out,
			'Selected public tag chip should render unlabeled.'
		);
		// Public chip must not carry the label.
		$this->assertDoesNotMatchRegularExpression(
			'/value="' . $public . '"[^>]*>[^<]*Jazz \(private\)/',
			$out
		);
	}
}
