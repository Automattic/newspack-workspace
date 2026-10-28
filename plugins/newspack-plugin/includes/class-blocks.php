<?php
/**
 * Newspack Blocks.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use Newspack\Optional_Modules\Collections;
use Newspack\Content_Gate_Countdown_Block;

/**
 * Newspack Blocks Class.
 */
final class Blocks {
	/**
	 * Initialize Hooks.
	 */
	public static function init() {
		// Hooked here, before `init`, because the blocks register on `init`.
		\add_filter( 'block_type_metadata', [ __CLASS__, 'version_block_styles' ] );

		require_once NEWSPACK_ABSPATH . 'src/blocks/reader-registration/index.php';
		require_once NEWSPACK_ABSPATH . 'src/blocks/my-account-button/class-my-account-button-block.php';
		require_once NEWSPACK_ABSPATH . 'src/blocks/content-gate/countdown/class-content-gate-countdown-block.php';
		require_once NEWSPACK_ABSPATH . 'src/blocks/content-gate/countdown-box/class-content-gate-countdown-box-block.php';
		require_once NEWSPACK_ABSPATH . 'src/blocks/contribution-meter/index.php';

		if ( wp_is_block_theme() && class_exists( 'Newspack\Corrections' ) ) {
			require_once NEWSPACK_ABSPATH . 'src/blocks/correction-box/class-correction-box-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/correction-item/class-correction-item-block.php';
		}
		if ( wp_is_block_theme() ) {
			require_once NEWSPACK_ABSPATH . 'src/blocks/avatar/class-avatar-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/byline/class-byline-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/featured-image-caption/class-featured-image-caption-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/author-profile-social/class-author-profile-social-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/author-social-link/class-author-social-link-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/copyright/class-copyright-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/overlay-menu/class-overlay-menu-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/overlay-menu/trigger/class-overlay-menu-trigger-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/overlay-menu/panel/class-overlay-menu-panel-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/overlay-search/class-overlay-search-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/comments-panel/class-comments-panel-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/comments-panel/trigger/class-comments-panel-trigger-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/comments-panel/content/class-comments-panel-content-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/responsive-container/class-responsive-container-block.php';
			require_once NEWSPACK_ABSPATH . 'src/blocks/responsive-container/breakpoint/class-responsive-container-breakpoint-block.php';
			Social_Icons::init();
		}
		if ( Collections::is_module_active() ) {
			require_once NEWSPACK_ABSPATH . 'src/blocks/collections/index.php';
		}

		\add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend_assets' ] );
	}

	/**
	 * Version block.json stylesheets from this plugin's build by their content.
	 *
	 * WordPress versions a block.json `file:` stylesheet by the block's
	 * `version` field, or by the WordPress version when there is none; it uses
	 * the file's mtime only under SCRIPT_DEBUG, so local sites don't show this.
	 * Without a version, an updated stylesheet keeps its URL until WordPress
	 * itself updates, and browsers and the CDN keep serving the cached copy.
	 * A block script with its own `.asset.php` keeps that version; one without
	 * would take this hash too.
	 *
	 * The filter runs for every block on the site, so blocks outside the
	 * `newspack/` namespace return before any lookup. newspack-ads shares that
	 * namespace, so the `dist/` check below is what limits this to stylesheets
	 * from this plugin's build.
	 *
	 * @param array $metadata Block metadata read from block.json.
	 * @return array Metadata with `version` set to the stylesheets' content hash.
	 */
	public static function version_block_styles( $metadata ) {
		if (
			! is_array( $metadata )
			|| ! str_starts_with( (string) ( $metadata['name'] ?? '' ), 'newspack/' )
			|| ! empty( $metadata['version'] )
			|| empty( $metadata['file'] )
		) {
			return $metadata;
		}
		/** This filter is documented in includes/class-newspack.php */
		$dist_dir = realpath( \apply_filters( 'newspack_asset_dist_dir', NEWSPACK_ABSPATH . 'dist/', '' ) );
		if ( ! $dist_dir ) {
			return $metadata;
		}
		$dist_dir = \wp_normalize_path( $dist_dir ) . '/';
		$versions = [];
		foreach ( [ 'style', 'editorStyle', 'viewStyle' ] as $field ) {
			foreach ( (array) ( $metadata[ $field ] ?? [] ) as $style ) {
				if ( ! is_string( $style ) || ! str_starts_with( $style, 'file:' ) ) {
					continue;
				}
				$path = realpath( dirname( $metadata['file'] ) . '/' . \remove_block_asset_path_prefix( $style ) );
				$path = $path ? \wp_normalize_path( $path ) : '';
				if ( ! str_starts_with( $path, $dist_dir ) ) {
					continue;
				}
				$versions[] = Newspack::asset_version( preg_replace( '/\.css$/', '', substr( $path, strlen( $dist_dir ) ) ) );
			}
		}
		$versions = array_values( array_unique( $versions ) );
		if ( 1 === count( $versions ) ) {
			$metadata['version'] = $versions[0];
		} elseif ( $versions ) {
			// The block's stylesheets come from more than one build entry but share
			// one `version`, so derive it from all of their hashes.
			$metadata['version'] = substr( md5( implode( ',', $versions ) ), 0, 20 );
		}
		return $metadata;
	}

	/**
	 * Enqueue blocks scripts and styles for editor.
	 */
	public static function enqueue_block_editor_assets() {
		Newspack::load_common_assets();

		\wp_enqueue_script(
			'newspack-blocks',
			Newspack::plugin_url() . '/dist/blocks.js',
			[],
			Newspack::asset_version( 'blocks' ),
			true
		);
		$script_data = [
			'has_newsletters'                  => class_exists( 'Newspack_Newsletters_Subscription' ),
			'has_reader_activation'            => Reader_Activation::is_enabled(),
			'newsletters_url'                  => Wizards::get_wizard( 'newsletters' )->newsletters_settings_url(),
			'has_google_oauth'                 => Google_OAuth::is_oauth_configured(),
			'google_logo_svg'                  => \Newspack\Newspack_UI_Icons::get_svg( 'google' ),
			'reader_activation_terms'          => Reader_Activation::get_setting( 'terms_text' ),
			'reader_activation_url'            => Reader_Activation::get_setting( 'terms_url' ),
			'has_recaptcha'                    => Recaptcha::can_use_captcha(),
			'recaptcha_url'                    => admin_url( 'admin.php?page=newspack-settings' ),
			'is_block_theme'                   => wp_is_block_theme(),
			'corrections_enabled'              => wp_is_block_theme() && class_exists( 'Newspack\Corrections' ),
			'collections_enabled'              => Collections::is_module_active(),
			'has_memberships'                  => Memberships::is_active(),
			'is_content_gate_countdown_active' => Content_Gate_Countdown_Block::is_active(),
		];
		if ( $script_data['has_memberships'] ) {
			$script_data['content_gate_data'] = [
				// Cast to int: the getter returns false when metering is disabled or there
				// is no gate, and the editor preview parses these as numbers.
				'anonymous_metered_views' => absint( Metering::get_total_metered_views( false ) ),
				'loggedin_metered_views'  => absint( Metering::get_total_metered_views( true ) ),
				'metered_views'           => Metering::get_current_user_metered_views(),
				'metering_period'         => Metering::get_metering_period(),
			];
		}
		\wp_localize_script(
			'newspack-blocks',
			'newspack_blocks',
			$script_data
		);
		\wp_enqueue_style(
			'newspack-blocks',
			Newspack::plugin_url() . '/dist/blocks.css',
			[],
			Newspack::asset_version( 'blocks' )
		);
	}

	/**
	 * Enqueue blocks scripts and styles for frontend.
	 * Only load if we have blocks on the page that need these styles.
	 */
	public static function enqueue_frontend_assets() {
		if ( self::should_load_block_assets() ) {
			\wp_enqueue_style(
				'newspack-blocks-frontend',
				Newspack::plugin_url() . '/dist/blocks.css',
				[],
				Newspack::asset_version( 'blocks' )
			);
		}
	}

	/**
	 * Check if we should load block assets on current page.
	 *
	 * @return bool Whether to load block assets.
	 */
	private static function should_load_block_assets() {
		// Load the block styles if we're on a single or archive Collections page.
		if (
			Collections::is_module_active() &&
			(
				is_post_type_archive( \Newspack\Collections\Post_Type::get_post_type() ) ||
				is_singular( \Newspack\Collections\Post_Type::get_post_type() )
			)
		) {
			return true;
		}

		if ( ! is_singular() ) {
			return false;
		}

		// Load the block styles if we're on a post or page with a block from this plugin.
		$post = get_post( get_the_ID() );
		return $post && false !== strpos( $post->post_content, 'wp:newspack/' );
	}
}
Blocks::init();
