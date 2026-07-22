<?php
/**
 * Class TestAdminBar
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Admin;
use Newspack_Network\Content_Distribution\Admin_Bar;
use Newspack_Network\Content_Distribution\Incoming_Post;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Hub\Node as Hub_Node;
use Newspack_Network\Site_Role;
use WP_Admin_Bar;
use WP_Post;

/**
 * Test the Admin_Bar class.
 */
class TestAdminBar extends \WP_UnitTestCase {
	/**
	 * "Mocked" network nodes.
	 *
	 * @var array
	 */
	protected $network = [
		[
			'id'    => 1234,
			'title' => 'Test Node',
			'url'   => 'https://node.test',
		],
		[
			'id'    => 5678,
			'title' => 'Test Node 2',
			'url'   => 'https://other-node.test',
		],
	];

	/**
	 * A post owned by this site.
	 *
	 * @var WP_Post
	 */
	protected $post;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		update_option( Site_Role::OPTION_NAME, Site_Role::NODE_ROLE );
		update_option( 'newspack_node_hub_url', 'https://hub.test' );
		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, $this->network );

		$this->post = $this->factory->post->create_and_get( [ 'post_type' => 'post' ] );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to( get_permalink( $this->post ) );

		wp_register_style( 'newspack-ui', 'https://example.test/newspack-ui.css', [], '1.0' );
		wp_register_script( 'newspack-ui', 'https://example.test/newspack-ui.js', [], '1.0', true );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		delete_option( 'newspack_node_hub_url' );
		delete_option( Hub_Node::HUB_NODES_SYNCED_OPTION );
		wp_deregister_style( 'newspack-ui' );
		wp_deregister_script( 'newspack-ui' );
		parent::tear_down();
	}

	/**
	 * A permitted user on a distributable post sees the menu.
	 */
	public function test_should_render_for_distributable_post() {
		$this->assertTrue( Admin_Bar::should_render( $this->post ) );
	}

	/**
	 * Post types outside the distributable list are excluded.
	 */
	public function test_should_not_render_for_unsupported_post_type() {
		register_post_type( 'not_distributable', [ 'public' => true ] );
		$other = $this->factory->post->create_and_get( [ 'post_type' => 'not_distributable' ] );

		$this->assertFalse( Admin_Bar::should_render( $other ) );
	}

	/**
	 * The site's static front page is not distributable, even though 'page'
	 * is a distributed post type.
	 */
	public function test_should_not_render_for_front_page() {
		$front = $this->factory->post->create_and_get( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front->ID );

		$this->assertFalse( Admin_Bar::should_render( $front ) );

		delete_option( 'show_on_front' );
		delete_option( 'page_on_front' );
	}

	/**
	 * The page assigned as the posts page (Settings > Reading) is not
	 * distributable either.
	 */
	public function test_should_not_render_for_posts_page() {
		$front      = $this->factory->post->create_and_get( [ 'post_type' => 'page' ] );
		$posts_page = $this->factory->post->create_and_get( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front->ID );
		update_option( 'page_for_posts', $posts_page->ID );

		$this->assertFalse( Admin_Bar::should_render( $posts_page ) );

		delete_option( 'show_on_front' );
		delete_option( 'page_on_front' );
		delete_option( 'page_for_posts' );
	}

	/**
	 * An ordinary page, distinct from the front and posts pages, still
	 * renders; publishers can still distribute an ethics policy or an
	 * event listing from the front end.
	 */
	public function test_should_render_for_ordinary_page() {
		$front = $this->factory->post->create_and_get( [ 'post_type' => 'page' ] );
		$other = $this->factory->post->create_and_get( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front->ID );

		$this->assertTrue( Admin_Bar::should_render( $other ) );

		delete_option( 'show_on_front' );
		delete_option( 'page_on_front' );
	}

	/**
	 * A page ID left over in 'page_for_posts' from an earlier "A static
	 * page" configuration is not structural once the site switches back to
	 * "Your latest posts": WordPress retains the option value but
	 * WP_Query::parse_query() only treats it as the posts page when
	 * 'show_on_front' is 'page'. The front-end 'page_on_front' default of 0
	 * already can't collide with a real post ID; this covers the less
	 * obvious stale-option case for genuine equivalence with is_home().
	 */
	public function test_should_render_for_page_matching_stale_page_for_posts_when_posts_on_front() {
		$page = $this->factory->post->create_and_get( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_for_posts', $page->ID );

		$this->assertTrue( Admin_Bar::should_render( $page ) );

		delete_option( 'show_on_front' );
		delete_option( 'page_for_posts' );
	}

	/**
	 * A syndicated copy cannot be re-distributed.
	 */
	public function test_should_not_render_for_incoming_post() {
		update_post_meta( $this->post->ID, Incoming_Post::PAYLOAD_META, [ 'title' => 'Payload' ] );

		$this->assertFalse( Admin_Bar::should_render( $this->post ) );
	}

	/**
	 * Users without the capability see nothing.
	 */
	public function test_should_not_render_without_capability() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertFalse( Admin_Bar::should_render( $this->post ) );
	}

	/**
	 * A site with no network peers has nothing to distribute to.
	 */
	public function test_should_not_render_without_network_sites() {
		delete_option( Hub_Node::HUB_NODES_SYNCED_OPTION );
		delete_option( 'newspack_node_hub_url' );
		delete_option( Site_Role::OPTION_NAME );

		$this->assertFalse( Admin_Bar::should_render( $this->post ) );
	}

	/**
	 * An invalid post is handled without a fatal.
	 */
	public function test_should_not_render_for_missing_post() {
		$this->assertFalse( Admin_Bar::should_render( 999999 ) );
	}

	/**
	 * Every network site is returned, none distributed yet.
	 */
	public function test_get_sites_with_no_distribution() {
		$sites = Admin_Bar::get_sites( $this->post );

		$this->assertCount( 3, $sites );
		foreach ( $sites as $site ) {
			$this->assertFalse( $site['distributed'] );
			$this->assertNotEmpty( $site['name'] );
		}
	}

	/**
	 * Sites already distributed to are flagged.
	 */
	public function test_get_sites_flags_distributed() {
		$outgoing = new Outgoing_Post( $this->post );
		$outgoing->set_distribution( [ $this->network[0]['url'] ] );

		$sites      = Admin_Bar::get_sites( $this->post );
		$by_url     = array_column( $sites, 'distributed', 'url' );

		$this->assertTrue( $by_url[ $this->network[0]['url'] ] );
		$this->assertFalse( $by_url[ $this->network[1]['url'] ] );
	}

	/**
	 * A stored distribution URL with a trailing slash still matches the live
	 * network site URL, which has none.
	 */
	public function test_get_sites_flags_distributed_with_trailing_slash() {
		update_post_meta( $this->post->ID, Outgoing_Post::DISTRIBUTED_POST_META, [ $this->network[0]['url'] . '/' ] );

		$sites  = Admin_Bar::get_sites( $this->post );
		$by_url = array_column( $sites, 'distributed', 'url' );

		$this->assertTrue( $by_url[ $this->network[0]['url'] ] );
	}

	/**
	 * Site URLs are normalised so they compare against stored distribution.
	 */
	public function test_get_sites_untrailingslashes_urls() {
		foreach ( Admin_Bar::get_sites( $this->post ) as $site ) {
			$this->assertSame( untrailingslashit( $site['url'] ), $site['url'] );
		}
	}

	/**
	 * A post the menu should not render for yields no sites.
	 */
	public function test_get_sites_is_empty_when_not_rendering() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( [], Admin_Bar::get_sites( $this->post ) );
	}

	/**
	 * No nodes are added when the menu should not render.
	 */
	public function test_admin_bar_menu_skipped_without_capability() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$bar = new WP_Admin_Bar();

		Admin_Bar::admin_bar_menu( $bar );

		$this->assertNull( $bar->get_node( 'newspack-network-distribute' ) );
	}

	/**
	 * The trigger is a real link so it is natively clickable and keyboard-
	 * activatable; the JS preventDefault()s the '#' and opens the modal.
	 */
	public function test_admin_bar_menu_trigger_is_a_link() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();

		Admin_Bar::admin_bar_menu( $bar );

		$node = $bar->get_node( 'newspack-network-distribute' );
		$this->assertNotNull( $node );
		$this->assertSame( '#', $node->href );
	}

	/**
	 * The distribution UI now lives in a wp_footer modal, so the trigger has
	 * no admin-bar children.
	 */
	public function test_admin_bar_menu_has_no_child_nodes() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();

		Admin_Bar::admin_bar_menu( $bar );

		$this->assertNotNull( $bar->get_node( 'newspack-network-distribute' ) );
		$children = array_filter(
			$bar->get_nodes() ? $bar->get_nodes() : [],
			function ( $node ) {
				return 'newspack-network-distribute' === $node->parent;
			}
		);
		$this->assertCount( 0, $children );
		$this->assertNull( $bar->get_node( 'newspack-network-distribute-form' ) );
	}

	/**
	 * The wp_footer modal is a Newspack UI small modal (wrapped in .newspack-ui
	 * so its buttons/checkboxes are styled): a select-all, one checkbox per site
	 * carrying its URL, already-distributed sites checked and disabled, and a
	 * primary Distribute button (label in a span for the loading state) that
	 * starts disabled.
	 */
	public function test_render_modal_renders_checkbox_list() {
		$outgoing = new Outgoing_Post( $this->post );
		$outgoing->set_distribution( [ $this->network[0]['url'] ] );

		ob_start();
		Admin_Bar::render_modal();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<div class="newspack-ui"><div id="newspack-network-distribute-modal" class="newspack-ui__modal-container"', $html );
		$this->assertStringContainsString( 'newspack-ui__modal newspack-ui__modal--small', $html );
		$this->assertStringContainsString( 'newspack-network-distribute-all-toggle', $html );

		$this->assertMatchesRegularExpression(
			'/<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-network-distribute-submit"[^>]*disabled><span>/',
			$html
		);

		$this->assertStringContainsString( 'value="' . esc_attr( $this->network[1]['url'] ) . '"', $html );
		$this->assertMatchesRegularExpression(
			'/value="' . preg_quote( esc_attr( $this->network[0]['url'] ), '/' ) . '"[^>]*checked[^>]*disabled/',
			$html
		);
	}

	/**
	 * The select-all toggle is omitted when there is only one target site.
	 */
	public function test_render_modal_omits_select_all_for_single_site() {
		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, [] );

		ob_start();
		Admin_Bar::render_modal();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'newspack-network-distribute-site', $html );
		$this->assertStringNotContainsString( 'newspack-network-distribute-all-toggle', $html );
	}

	/**
	 * Without the toolbar there is no trigger and no enqueued assets, so the
	 * footer markup must not be printed either.
	 */
	public function test_render_modal_skipped_when_admin_bar_hidden() {
		add_filter( 'show_admin_bar', '__return_false' ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- simulating a user's own toolbar preference, not removing it site-wide.

		ob_start();
		Admin_Bar::render_modal();
		$html = ob_get_clean();

		remove_filter( 'show_admin_bar', '__return_false' );
		// Cleared so later tests recompute it instead of inheriting this false.
		unset( $GLOBALS['show_admin_bar'] );

		$this->assertSame( '', $html );
	}

	/**
	 * The modal markup is echoed raw, so site names must be escaped.
	 */
	public function test_render_modal_escapes_site_names() {
		update_option(
			Hub_Node::HUB_NODES_SYNCED_OPTION,
			[
				[
					'id'    => 4242,
					'title' => 'A & B <script>',
					'url'   => 'https://escaped.test',
				],
			]
		);

		ob_start();
		Admin_Bar::render_modal();
		$html = ob_get_clean();

		$this->assertStringContainsString( esc_html( 'A & B <script>' ), $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * With Newspack UI unavailable (its style handle unregistered), neither the
	 * trigger nor the modal render — the feature soft-depends on newspack-plugin.
	 */
	public function test_trigger_and_modal_guarded_without_newspack_ui() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		wp_deregister_style( 'newspack-ui' );

		$bar = new WP_Admin_Bar();
		Admin_Bar::admin_bar_menu( $bar );
		$this->assertNull( $bar->get_node( 'newspack-network-distribute' ) );

		ob_start();
		Admin_Bar::render_modal();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The enqueued script is localized with the ARIA-live announcement strings.
	 */
	public function test_enqueue_scripts_localizes_i18n_strings() {
		// Cleared so this reads only this call's payload; see get_localized_data().
		wp_scripts()->add_data( 'newspack-network-admin-bar', 'data', '' );

		Admin_Bar::enqueue_scripts();

		$data = wp_scripts()->get_data( 'newspack-network-admin-bar', 'data' );

		$this->assertIsString( $data );
		$this->assertStringContainsString( '"i18n":{', $data );
		$this->assertStringContainsString( 'Distribute (%s)', $data );
		$this->assertStringContainsString( 'Distributed to %s network sites.', $data );
		$this->assertStringContainsString( 'Could not distribute: %s', $data );
		$this->assertStringContainsString( 'The request timed out. Please try again.', $data );
	}

	/**
	 * The localized payload carries a distinct, fully translatable sentence
	 * for each distribution status, so the front end can announce which
	 * status the post landed as on the receiving site without interpolating
	 * a status word into a single template.
	 */
	public function test_enqueue_scripts_localizes_status_specific_distributed_strings() {
		// Cleared so this reads only this call's payload; see get_localized_data().
		wp_scripts()->add_data( 'newspack-network-admin-bar', 'data', '' );

		Admin_Bar::enqueue_scripts();

		$data = wp_scripts()->get_data( 'newspack-network-admin-bar', 'data' );

		$this->assertIsString( $data );
		$this->assertStringContainsString( 'Distributed to %s network sites as drafts.', $data );
		$this->assertStringContainsString( 'Distributed to %s network sites as pending review.', $data );
		$this->assertStringContainsString( 'Distributed to %s network sites and published.', $data );
	}

	/**
	 * The plural-aware i18n keys are `{ singular, plural }` objects, not flat
	 * strings: the front end's pluralize() picks a form from the pair at
	 * runtime. A regression that flattens one of these keys back into a
	 * single string would still contain the plural wording checked above,
	 * so this decodes the payload and asserts the object shape and the
	 * singular strings directly.
	 */
	public function test_enqueue_scripts_localizes_plural_object_shape() {
		// Cleared so this reads only this call's payload; see get_localized_data().
		wp_scripts()->add_data( 'newspack-network-admin-bar', 'data', '' );

		Admin_Bar::enqueue_scripts();

		$data = wp_scripts()->get_data( 'newspack-network-admin-bar', 'data' );
		$i18n = $this->decode_localized_i18n( $data );

		foreach ( [ 'distributed', 'distributedAsDraft', 'distributedAsPending', 'distributedAsPublish' ] as $key ) {
			$this->assertIsArray( $i18n[ $key ], "i18n.$key should be a { singular, plural } object." );
			$this->assertSame( [ 'singular', 'plural' ], array_keys( $i18n[ $key ] ), "i18n.$key should only carry singular/plural keys." );
			$this->assertNotEmpty( $i18n[ $key ]['singular'] );
			$this->assertNotEmpty( $i18n[ $key ]['plural'] );
		}

		$this->assertIsString( $i18n['submitCount'] );
		$this->assertSame( 'Distribute (%s)', $i18n['submitCount'] );
		$this->assertSame( 'Distributed to %s network site.', $i18n['distributed']['singular'] );
		$this->assertSame( 'Distributed to %s network site as a draft.', $i18n['distributedAsDraft']['singular'] );
		$this->assertSame( 'Distributed to %s network site as pending review.', $i18n['distributedAsPending']['singular'] );
		$this->assertSame( 'Distributed to %s network site and published.', $i18n['distributedAsPublish']['singular'] );

		$this->assertIsString( $i18n['timeout'] );
		$this->assertSame( 'The request timed out. Please try again.', $i18n['timeout'] );
	}

	/**
	 * Nothing is enqueued or localized when the user has turned off the
	 * front-end admin bar in their profile; _wp_admin_bar_init() bails and
	 * no nodes exist, so the script and its site list have nothing to do.
	 */
	public function test_enqueue_scripts_skipped_when_admin_bar_hidden() {
		wp_dequeue_script( 'newspack-network-admin-bar' );

		add_filter( 'show_admin_bar', '__return_false' ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- simulating a user's own toolbar preference, not removing it site-wide.

		Admin_Bar::enqueue_scripts();

		remove_filter( 'show_admin_bar', '__return_false' );
		// Cleared so later tests recompute it instead of inheriting this false.
		unset( $GLOBALS['show_admin_bar'] );

		$this->assertFalse( wp_script_is( 'newspack-network-admin-bar', 'enqueued' ) );
	}

	/**
	 * The localized payload carries the configured default distribution
	 * status, not just the REST route's own 'draft' fallback.
	 */
	public function test_enqueue_scripts_localizes_configured_default_status() {
		update_option( Admin::DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME, 'publish' );

		// Cleared so this reads only this call's payload; see get_localized_data().
		wp_scripts()->add_data( 'newspack-network-admin-bar', 'data', '' );

		Admin_Bar::enqueue_scripts();

		$data = wp_scripts()->get_data( 'newspack-network-admin-bar', 'data' );

		delete_option( Admin::DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME );

		$this->assertIsString( $data );
		$this->assertStringContainsString( '"defaultStatus":"publish"', $data );
	}

	/**
	 * The localized payload falls back to 'draft' when the option is unset.
	 */
	public function test_enqueue_scripts_localizes_default_status_fallback() {
		delete_option( Admin::DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME );

		// Cleared so this reads only this call's payload; see get_localized_data().
		wp_scripts()->add_data( 'newspack-network-admin-bar', 'data', '' );

		Admin_Bar::enqueue_scripts();

		$data = wp_scripts()->get_data( 'newspack-network-admin-bar', 'data' );

		$this->assertIsString( $data );
		$this->assertStringContainsString( '"defaultStatus":"draft"', $data );
	}

	/**
	 * The bundle declares the Newspack UI dependency so it loads after
	 * newspack-ui.js and can call window.newspackUI, and inherits its styles.
	 */
	public function test_enqueue_scripts_depends_on_newspack_ui() {
		Admin_Bar::enqueue_scripts();

		$script = wp_scripts()->query( 'newspack-network-admin-bar' );
		$this->assertNotFalse( $script );
		$this->assertContains( 'newspack-ui', $script->deps );

		$style = wp_styles()->query( 'newspack-network-admin-bar' );
		$this->assertNotFalse( $style );
		$this->assertContains( 'newspack-ui', $style->deps );
	}

	/**
	 * Nothing is enqueued when Newspack UI is unavailable.
	 */
	public function test_enqueue_scripts_skipped_without_newspack_ui() {
		// Cleared so a prior test's enqueue does not leak into this assertion.
		wp_dequeue_script( 'newspack-network-admin-bar' );
		wp_deregister_style( 'newspack-ui' );

		Admin_Bar::enqueue_scripts();

		$this->assertFalse( wp_script_is( 'newspack-network-admin-bar', 'enqueued' ) );
	}

	/**
	 * Decode the 'i18n' branch of the admin-bar script's localized payload.
	 *
	 * WP_Scripts::localize() prepends any earlier call's block ahead of a
	 * new one instead of replacing it, so this decodes the LAST
	 * `var newspack_network_admin_bar = {...};` block in the string to read
	 * only the most recent enqueue_scripts() call's payload.
	 *
	 * @param string $data The raw string from wp_scripts()->get_data( $handle, 'data' ).
	 *
	 * @return array The decoded 'i18n' branch of the payload.
	 */
	private function decode_localized_i18n( $data ) {
		$marker = 'var newspack_network_admin_bar = ';
		$pos    = strrpos( $data, $marker );
		$this->assertNotFalse( $pos, 'Localized script marker not found in the "data" extra.' );

		$json    = substr( $data, $pos + strlen( $marker ) );
		$json    = rtrim( $json, ";\n" );
		$payload = json_decode( $json, true );

		$this->assertIsArray( $payload, 'Localized payload did not decode to an array.' );
		$this->assertArrayHasKey( 'i18n', $payload );

		return $payload['i18n'];
	}
}
