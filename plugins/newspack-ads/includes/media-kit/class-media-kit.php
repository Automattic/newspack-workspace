<?php
/**
 * Newspack Ads Media Kit Page.
 *
 * Adapted from https://github.com/10up/publisher-media-kit/.
 *
 * @package Newspack
 */

namespace Newspack_Ads;

define( 'NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS', dirname( NEWSPACK_ADS_PLUGIN_FILE ) . '/includes/media-kit/block-patterns/' );
define( 'NEWSPACK_ADS_MEDIA_KIT_URL', plugin_dir_url( __FILE__ ) );

require_once NEWSPACK_ADS_ABSPATH . '/includes/media-kit/class-media-kit-block-patterns.php';

/**
 * Newspack Ads Media Kit Page Class.
 */
final class Media_Kit {
	const PAGE_META_NAME = 'newspack_ads_pmk_page';
	const ADMIN_NOTICE_TRANSIENT_NAME = 'newspack_ads_pmk_admin_notice';

	/**
	 * Initialize settings.
	 */
	public static function init() {
		register_activation_hook( NEWSPACK_ADS_PLUGIN_FILE, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( NEWSPACK_ADS_PLUGIN_FILE, [ __CLASS__, 'deactivate' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'init', [ __CLASS__, 'add_cli_commands' ] );
	}

	/**
	 * Add admin notices.
	 */
	public static function admin_notices() {
		/* Check transient, if available display notice */
		if ( get_transient( self::ADMIN_NOTICE_TRANSIENT_NAME ) ) {
			$media_kit_id   = get_option( self::PAGE_META_NAME );
			$media_kit_link = $media_kit_id ? get_edit_post_link( $media_kit_id ) : admin_url( 'edit.php?post_type=page' );
			?>
			<div class="updated notice is-dismissible">
				<p>
				<?php
					/* translators: %s is the link to the Media Kit editing page. */
					echo wp_kses_post( sprintf( __( 'A "Media Kit" page has been created! Please <a href="%s">click here</a> to edit and publish the page.', 'newspack-ads' ), esc_url( $media_kit_link ) ) );
				?>
				</p>
			</div>
			<?php
			/* Delete transient, only display this notice once. */
			delete_transient( self::ADMIN_NOTICE_TRANSIENT_NAME );
		}
	}

	/**
	 * Deactivate.
	 */
	public static function deactivate() {
		delete_option( self::PAGE_META_NAME );
	}

	/**
	 * Activate.
	 */
	public static function activate() {
		// Create a Media Kit page.
		self::create_media_kit_page();
	}

	/**
	 * Get existing Media Kit Page post ID.
	 *
	 * @return int|false Post ID or false if the media kit page was not found.
	 */
	public static function get_existing_page_id() {
		$args = [
			'post_type'      => [ 'page' ],
			'post_status'    => [ 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key' => self::PAGE_META_NAME,
				],
			],
		];

		// The query to get the Media Kit page ID.
		$query = new \WP_Query( $args );
		return $query->posts[0] ?? false;
	}

	/**
	 * Get Media Kit page status.
	 */
	public static function get_page_status() {
		$post_id = self::get_existing_page_id();
		if ( ! $post_id ) {
			return 'non-existent';
		}
		return get_post_status( $post_id );
	}

	/**
	 * A function to create a Publisher Media Kit page automatically.
	 *
	 * @throws \Exception Throws exception on Media Kit page creation failure.
	 * @return int|false Post ID or false if the media kit page was not created.
	 */
	public static function create_media_kit_page() {
		$post_ID = self::get_existing_page_id();

		// Restore original Post Data.
		wp_reset_postdata();

		if ( ! empty( $post_ID ) ) {
			return false;
		}

		global $wp_version;

		$current_user = wp_get_current_user();

		// Get block patterns to insert in a page.
		ob_start();
		?>

		<!-- wp:group {"align":"full","style":{"spacing":{"blockGap":"0","margin":{"bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignfull media-kit-page__wrapper media-kit-page__wrapper--no-margins" style="margin-bottom:var(--wp--preset--spacing--80)">

			<?php
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'intro.php';
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'audience.php';
			?>

		</div><!-- /wp:group -->

		<!-- wp:group {"align":"full","style":{"spacing":{"blockGap":"var:preset|spacing|80"}},"className":"media-kit-page__wrapper","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignfull media-kit-page__wrapper">

			<?php
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'why-us.php';
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'ad-specs.php';
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'rates.php';
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'contact-compact.php';
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'packages.php';
			include NEWSPACK_ADS_MEDIA_KIT_BLOCK_PATTERNS . 'contact.php';
			?>

		</div><!-- /wp:group -->

		<?php
		$pmk_page_content = ob_get_clean();

		// Create post object.
		$page = [
			'post_title'   => __( 'Media Kit', 'newspack-ads' ),
			'post_status'  => 'draft',
			'post_author'  => $current_user->ID,
			'post_type'    => 'page',
			'post_name'    => 'media-kit',
			'post_content' => wp_kses_post( $pmk_page_content ),
		];

		// Insert the post into the database.
		$post_ID = wp_insert_post( $page );

		if ( is_wp_error( $post_ID ) || 0 === $post_ID ) {
			throw new \Exception( esc_html( $post_ID->get_error_message() ) );
		}

		// Insert post meta for identity.
		add_post_meta( $post_ID, self::PAGE_META_NAME, 1 );

		// Apply blank page template.
		if ( get_template() === 'newspack-block-theme' ) {
			update_post_meta( $post_ID, '_wp_page_template', 'page/blank-footer' );
		} else {
			update_post_meta( $post_ID, '_wp_page_template', 'no-header-footer.php' );
			update_post_meta( $post_ID, 'newspack_hide_page_title', true );
		}

		add_option( self::PAGE_META_NAME, $post_ID );

		set_transient( self::ADMIN_NOTICE_TRANSIENT_NAME, true, 5 );

		\flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
		return $post_ID;
	}

	/**
	 * Equeue frontend scripts.
	 */
	public static function enqueue_scripts() {
		// Get current page content and check for the special class used in the patterns, or block usage.
		$page_content = get_the_content();
		$has_pattern = strpos( $page_content, 'media-kit-page__wrapper' ) >= 0;
		$has_block = strpos( $page_content, '<!-- wp:newspack/tabs' ) >= 0;
		if ( $has_pattern || $has_block ) {
			\wp_register_style(
				'newspack-ads-media-kit-frontend',
				Core::plugin_url( 'dist/media-kit-frontend.css' ),
				null,
				filemtime( dirname( NEWSPACK_ADS_PLUGIN_FILE ) . '/dist/media-kit-frontend.css' )
			);
			\wp_style_add_data( 'newspack-ads-media-kit-frontend', 'rtl', 'replace' );
			\wp_enqueue_style( 'newspack-ads-media-kit-frontend' );
		}
		if ( $has_block ) {
			\wp_enqueue_script(
				'newspack-ads-media-kit-frontend',
				Core::plugin_url( 'dist/media-kit-frontend.js' ),
				[],
				NEWSPACK_ADS_VERSION,
				true
			);
		}
	}

	/**
	 * Handle the Media Kit Page creation CLI command.
	 */
	public static function cli_create_media_kit_page() {
		$post_id = self::create_media_kit_page();
		if ( $post_id === false ) {
			$existing_page_id = self::get_existing_page_id();
			if ( $existing_page_id === false ) {
				\WP_CLI::warning( __( 'Media Kit page creation failed, and no existing page was found.', 'newspack-ads' ) );
				return;
			}
			/* translators: %d is the post ID. */
			\WP_CLI::warning( sprintf( __( 'Media Kit page already created - post ID: %d.', 'newspack-ads' ), $existing_page_id ) );
			return;
		}
		/* translators: %d is the post ID. */
		\WP_CLI::success( sprintf( __( 'Media Kit page created successfully - post ID: %d', 'newspack-ads' ), $post_id ) );
	}

	/**
	 * Register the 'newspack-listings import' WP CLI command.
	 */
	public static function add_cli_commands() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'newspack-ads create-media-kit-page',
			[ __CLASS__, 'cli_create_media_kit_page' ],
			[
				'shortdesc' => 'Create a Media Kit page.',
				'synopsis'  => [],
			]
		);
	}
}
Media_Kit::init();
