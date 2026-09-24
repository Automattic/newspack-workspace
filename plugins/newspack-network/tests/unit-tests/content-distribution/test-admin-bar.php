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

		Admin_Bar::flush_cache();

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
		Admin_Bar::flush_cache();
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
	 * The site list is answered once per request and reused, so the capability
	 * chain, the meta read and the network lookup do not run per hook.
	 */
	public function test_get_sites_is_memoized_until_flushed() {
		$this->assertCount( 3, Admin_Bar::get_sites( $this->post ) );

		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, [] );

		$this->assertCount( 3, Admin_Bar::get_sites( $this->post ), 'The memo should survive a mid-request change.' );

		Admin_Bar::flush_cache();

		$this->assertCount( 1, Admin_Bar::get_sites( $this->post ) );
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
	 * wide primary Distribute button that starts disabled.
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
			'/<button type="button" class="[^"]*newspack-ui__button--primary newspack-ui__button--wide newspack-network-distribute-submit" disabled>Distribute<\/button>/',
			$html
		);

		$this->assertStringContainsString( 'value="' . esc_attr( $this->network[1]['url'] ) . '"', $html );
		$this->assertMatchesRegularExpression(
			'/value="' . preg_quote( esc_attr( $this->network[0]['url'] ), '/' ) . '"[^>]*checked[^>]*disabled/',
			$html
		);
	}

	/**
	 * Distribution is additive and cannot be undone from the front end, and
	 * select-all makes fanning a post out to the whole network one click, so the
	 * markup carries a confirmation step. It starts hidden; the JS reveals it
	 * with the count once a selection is submitted.
	 */
	public function test_render_modal_renders_hidden_confirmation_step() {
		ob_start();
		Admin_Bar::render_modal();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<div class="newspack-network-distribute-confirm [^"]*newspack-ui__stack[^"]*" hidden>/', $html );

		// Written by the JS, which knows the count; empty server-side.
		$this->assertStringContainsString( '<p class="newspack-network-distribute-confirm-message" tabindex="-1"></p>', $html );

		// Wide primary over wide ghost, as on the reader-activation screens.
		$this->assertMatchesRegularExpression(
			'/newspack-ui__button--primary newspack-ui__button--wide newspack-network-distribute-confirm-submit".*newspack-ui__button--ghost newspack-ui__button--wide newspack-network-distribute-back"/',
			$html
		);
	}

	/**
	 * Spacing comes from Newspack UI's stack element rather than from this
	 * plugin's stylesheet, so the modal tracks the design system.
	 */
	public function test_render_modal_lays_out_with_newspack_ui_stacks() {
		ob_start();
		Admin_Bar::render_modal();
		$html = ob_get_clean();

		// Site rows and the button below them.
		$this->assertStringContainsString( 'newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1', $html );
		$this->assertStringContainsString( 'newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5', $html );
		// The two confirmation buttons.
		$this->assertStringContainsString( 'newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2', $html );
		// A checkbox and its site name.
		$this->assertStringContainsString( 'newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--align-center newspack-ui__stack--gap-1', $html );
	}

	/**
	 * A locked row says why it is locked. Assistive tech otherwise reports only
	 * that the checkbox is disabled, with no hint that distribution is additive.
	 */
	public function test_render_modal_describes_already_distributed_rows() {
		$outgoing = new Outgoing_Post( $this->post );
		$outgoing->set_distribution( [ $this->network[0]['url'] ] );

		ob_start();
		Admin_Bar::render_modal();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<input type="checkbox" id="(newspack-network-distribute-site-\d+)"[^>]*checked disabled aria-describedby="\1-note">/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<span id="newspack-network-distribute-site-\d+-note" class="screen-reader-text">Already distributed<\/span>/',
			$html
		);

		// Selectable rows carry no description, so nothing dangles.
		$this->assertSame( 1, substr_count( $html, 'screen-reader-text">Already distributed' ) );
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
	 * The behaviour script hard-depends on the newspack-ui *script* handle, and
	 * WordPress drops a script whose dependency is unregistered. Gating on the
	 * style handle alone would leave a Distribute button that opens nothing.
	 */
	public function test_trigger_and_modal_guarded_without_newspack_ui_script() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		wp_deregister_script( 'newspack-ui' );

		$bar = new WP_Admin_Bar();
		Admin_Bar::admin_bar_menu( $bar );
		$this->assertNull( $bar->get_node( 'newspack-network-distribute' ) );

		ob_start();
		Admin_Bar::render_modal();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The user-facing strings live in the bundle and are translated by the
	 * wp-i18n runtime, so the locale's own plural rules apply rather than a
	 * two-form approximation. The localized payload carries request config only.
	 */
	public function test_enqueue_scripts_localizes_request_config_only() {
		// Cleared so this reads only this call's payload; see decode_localized_payload().
		wp_scripts()->add_data( 'newspack-network-admin-bar', 'data', '' );

		Admin_Bar::enqueue_scripts();

		$payload = $this->decode_localized_payload( wp_scripts()->get_data( 'newspack-network-admin-bar', 'data' ) );

		$this->assertSame( [ 'restUrl', 'nonce', 'defaultStatus' ], array_keys( $payload ) );
		$this->assertStringContainsString( 'content-distribution/distribute/' . $this->post->ID, $payload['restUrl'] );
		$this->assertNotEmpty( $payload['nonce'] );
	}

	/**
	 * The bundle imports @wordpress/i18n, which webpack externalises to
	 * wp.i18n. Unlike the block editor, the front end does not load it for us,
	 * so the handle has to be declared or the script is silently dropped.
	 */
	public function test_enqueue_scripts_depends_on_wp_i18n_and_sets_translations() {
		Admin_Bar::enqueue_scripts();

		$script = wp_scripts()->query( 'newspack-network-admin-bar' );
		$this->assertNotFalse( $script );
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'newspack-network', $script->textdomain );
		$this->assertStringEndsWith( '/languages', $script->translations_path );
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

		// Cleared so this reads only this call's payload; see decode_localized_payload().
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

		// Cleared so this reads only this call's payload; see decode_localized_payload().
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
	 * Decode the admin-bar script's localized payload.
	 *
	 * WP_Scripts::localize() prepends any earlier call's block ahead of a
	 * new one instead of replacing it, so this decodes the LAST
	 * `var newspack_network_admin_bar = {...};` block in the string to read
	 * only the most recent enqueue_scripts() call's payload.
	 *
	 * @param string $data The raw string from wp_scripts()->get_data( $handle, 'data' ).
	 *
	 * @return array The decoded payload.
	 */
	private function decode_localized_payload( $data ) {
		$this->assertIsString( $data );

		$marker = 'var newspack_network_admin_bar = ';
		$pos    = strrpos( $data, $marker );
		$this->assertNotFalse( $pos, 'Localized script marker not found in the "data" extra.' );

		$json    = substr( $data, $pos + strlen( $marker ) );
		$json    = rtrim( $json, ";\n" );
		$payload = json_decode( $json, true );

		$this->assertIsArray( $payload, 'Localized payload did not decode to an array.' );

		return $payload;
	}
}
