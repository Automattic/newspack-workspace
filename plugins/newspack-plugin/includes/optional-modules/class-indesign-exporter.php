<?php
/**
 * InDesign Export module.
 *
 * @package Newspack
 */

namespace Newspack\Optional_Modules;

defined( 'ABSPATH' ) || exit;

use Newspack\Optional_Modules;
use Newspack\Optional_Modules\InDesign_Export\InDesign_Converter;

/**
 * InDesign Export module class.
 */
class InDesign_Exporter {
	/**
	 * Module name for the optional modules system.
	 *
	 * @var string
	 */
	public const MODULE_NAME = 'indesign-export';

	/**
	 * Option name storing the platform header preference.
	 *
	 * Accepts 'auto', 'mac', or 'win'. 'auto' resolves the header at export
	 * time from the requesting browser's User-Agent.
	 *
	 * @var string
	 */
	public const PLATFORM_OPTION = 'newspack_indesign_export_platform';

	/**
	 * Default value for the platform option.
	 *
	 * @var string
	 */
	public const PLATFORM_DEFAULT = 'auto';

	/**
	 * Allowed values for the platform option.
	 *
	 * @var string[]
	 */
	public const ALLOWED_PLATFORMS = [ 'auto', 'mac', 'win' ];

	/**
	 * Option name storing the list of post types whose admin screens get the export action.
	 *
	 * @var string
	 */
	public const POST_TYPES_OPTION = 'newspack_indesign_export_post_types';

	/**
	 * Default value for the post types option.
	 *
	 * @var string[]
	 */
	public const POST_TYPES_DEFAULT = [ 'post' ];

	/**
	 * Option name storing whether photo captions are excluded from exports.
	 *
	 * Photo credits are a separate attribution field and are always exported.
	 *
	 * @var string
	 */
	public const EXCLUDE_CAPTIONS_OPTION = 'newspack_indesign_export_exclude_captions';

	/**
	 * Default value for the exclude-captions option.
	 *
	 * @var bool
	 */
	public const EXCLUDE_CAPTIONS_DEFAULT = false;

	/**
	 * Post types hidden from the admin setting because they have no editorial
	 * "article content" to export (lists, feeds, store products, etc.).
	 *
	 * @var string[]
	 */
	private const EXCLUDED_POST_TYPES = [
		'attachment',
		'partner_rss_feed',  // Newspack RSS feeds.
		'newspack_nl_list',  // Newspack Newsletters subscription lists.
		'newspack_collection', // Newspack Collections.
		'product',           // WooCommerce products.
	];

	/**
	 * Initialize the module.
	 */
	public static function init() {
		if ( ! self::is_feature_enabled() ) {
			return;
		}

		if ( ! Optional_Modules::is_optional_module_active( self::MODULE_NAME ) ) {
			return;
		}

		require_once NEWSPACK_ABSPATH . 'includes/optional-modules/indesign-export/class-indesign-converter.php';

		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );

		// The list-table bulk and row actions depend on the configured post types,
		// which may include custom post types. CPTs register on `init`, but this
		// module boots at file scope while plugins load — before `init` — so the
		// supported-types list would be resolved before CPTs exist and their
		// `bulk_actions-edit-{$post_type}` filters would never be added. Defer that
		// registration to `init` (priority 20, after CPTs register). Matches the
		// deferred-registration pattern used by the sibling optional modules.
		add_action( 'init', [ __CLASS__, 'register_list_table_actions' ], 20 );
		add_action( 'admin_post_export_indesign_single', [ __CLASS__, 'handle_single_export' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
	}

	/**
	 * Register the list-table bulk and row export actions.
	 *
	 * Hooked to `init` (priority 20) so every configured post type — including
	 * custom post types registered on `init` — is present when the supported
	 * list is resolved. The `bulk_actions-edit-{$post_type}` filter names are
	 * built from that list here; the shared row-action filters are added
	 * alongside them. Both sets of filters fire during list-table render, well
	 * after `init`, so late registration is safe.
	 */
	public static function register_list_table_actions() {
		$supported_post_types = self::get_supported_post_types();
		foreach ( $supported_post_types as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", [ __CLASS__, 'add_bulk_action' ] );
			add_filter( "handle_bulk_actions-edit-{$post_type}", [ __CLASS__, 'handle_bulk_action' ], 100, 3 );
		}

		// WordPress dispatches to `page_row_actions` for hierarchical post types
		// (pages, hierarchical CPTs) and `post_row_actions` for the rest, so hook both.
		add_filter( 'post_row_actions', [ __CLASS__, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ __CLASS__, 'add_row_action' ], 10, 2 );
	}

	/**
	 * Whether the InDesign module is enabled.
	 *
	 * @return bool True if InDesign Exporter is enabled.
	 */
	public static function is_feature_enabled() {
		/**
		 * Filters whether the InDesign Export feature is enabled.
		 *
		 * @param bool $is_enabled Whether the InDesign Export module is enabled.
		 */
		return apply_filters( 'newspack_indesign_export_enabled', true );
	}

	/**
	 * Get supported post types for InDesign export.
	 *
	 * Reads from the site setting (defaulting to the built-in post type) and
	 * then runs the `newspack_indesign_export_supported_post_types` filter so
	 * code-level extension points still work alongside the admin setting.
	 *
	 * @return array Array of supported post type slugs.
	 */
	public static function get_supported_post_types() {
		$supported_post_types = self::get_post_types_setting();

		/**
		 * Filters the post types that support InDesign export.
		 *
		 * @param array $supported_post_types Array of post type names that support InDesign export.
		 */
		return (array) apply_filters( 'newspack_indesign_export_supported_post_types', $supported_post_types );
	}

	/**
	 * Get the stored post types setting, sanitized.
	 *
	 * Constrains the stored slugs to the same "available" list the settings UI
	 * offers (public post types with an admin UI, minus the excluded ones). This
	 * drops slugs whose post type is no longer registered (e.g. a CPT plugin was
	 * deactivated) as well as types intentionally hidden from the picker (lists,
	 * feeds, products, etc.) that may have leaked in via direct option writes or
	 * older configs — so a stored value can never expose export actions for a
	 * type the admin can't see or uncheck. Returns the default when the option is
	 * unset or contains a non-array value.
	 *
	 * @return string[] Sanitized array of post type slugs.
	 */
	public static function get_post_types_setting() {
		$value = get_option( self::POST_TYPES_OPTION, self::POST_TYPES_DEFAULT );
		if ( ! is_array( $value ) ) {
			return self::POST_TYPES_DEFAULT;
		}

		$available = array_column( self::get_available_post_types(), 'value' );

		return array_values(
			array_filter(
				$value,
				static function ( $slug ) use ( $available ) {
					return is_string( $slug ) && in_array( $slug, $available, true );
				}
			)
		);
	}

	/**
	 * Get the list of post types eligible to appear in the admin setting.
	 *
	 * Returns post types registered as public and with an admin UI, excluding
	 * attachments and any post type listed in EXCLUDED_POST_TYPES (lists, feeds,
	 * products, etc. — types with no editorial article content).
	 *
	 * @return array<int, array{value:string, label:string}> Available options.
	 */
	public static function get_available_post_types() {
		$post_types = get_post_types(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'objects'
		);

		/**
		 * Filters the list of post type slugs hidden from the InDesign export
		 * setting. Lets sites add or remove exclusions for custom post types
		 * that aren't editorial content.
		 *
		 * @param string[] $excluded Default exclusions: attachments, RSS feeds,
		 *                           subscription lists, collections, WooCommerce
		 *                           products.
		 */
		$excluded = (array) apply_filters(
			'newspack_indesign_export_excluded_post_types',
			self::EXCLUDED_POST_TYPES
		);

		$options = [];
		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $excluded, true ) ) {
				continue;
			}
			$options[] = [
				'value' => $post_type->name,
				'label' => $post_type->labels->name ?? $post_type->name,
			];
		}

		return $options;
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		$screen = get_current_screen();
		if ( ! in_array( $screen->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		$asset = require NEWSPACK_ABSPATH . 'dist/indesign-export.asset.php';
		wp_enqueue_script(
			'newspack-indesign-export',
			\Newspack\Newspack::plugin_url() . '/dist/indesign-export.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}

	/**
	 * Add bulk action to posts list table.
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public static function add_bulk_action( $bulk_actions ) {
		$bulk_actions['export_indesign'] = __( 'Export as Adobe InDesign', 'newspack-plugin' );
		return $bulk_actions;
	}

	/**
	 * Handle bulk export action.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Action being performed.
	 * @param array  $post_ids    Array of post IDs.
	 * @return string Modified redirect URL.
	 */
	public static function handle_bulk_action( $redirect_to, $doaction, $post_ids ) { // phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement
		if ( 'export_indesign' !== $doaction ) {
			return $redirect_to;
		}

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-posts' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return add_query_arg( 'indesign_export_error', 'nonce', $redirect_to );
		}

		if ( empty( $post_ids ) ) {
			return add_query_arg( 'indesign_export_error', 'no_posts', $redirect_to );
		}

		// Resolve the supported post types once for the whole selection rather than
		// re-running is_post_supported() (and its post-type registry walk) per ID.
		$supported_post_types = self::get_supported_post_types();
		$post_ids             = array_values(
			array_filter(
				$post_ids,
				static function ( $post_id ) use ( $supported_post_types ) {
					$post = get_post( $post_id );
					return $post && in_array( $post->post_type, $supported_post_types, true );
				}
			)
		);
		if ( empty( $post_ids ) ) {
			return add_query_arg( 'indesign_export_error', 'unsupported_post_type', $redirect_to );
		}

		// Gate on the per-object capability rather than the generic `edit_posts`.
		// With arbitrary post types now exportable, a user may be able to edit the
		// selected items (e.g. a custom role scoped to a CPT) without holding the
		// generic cap — and vice versa. This mirrors the per-post check used by the
		// row action and single-export handler.
		$post_ids = array_values(
			array_filter(
				$post_ids,
				static function ( $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				}
			)
		);
		if ( empty( $post_ids ) ) {
			return add_query_arg( 'indesign_export_error', 'capability', $redirect_to );
		}

		self::export_posts( $post_ids );
		exit;
	}

	/**
	 * Add row action to individual posts.
	 *
	 * @param array    $actions Array of row actions.
	 * @param \WP_Post $post    Post object.
	 * @return array Modified row actions.
	 */
	public static function add_row_action( $actions, $post ) {
		if ( in_array( $post->post_type, self::get_supported_post_types(), true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$export_url = wp_nonce_url(
				add_query_arg(
					[
						'action'  => 'export_indesign_single',
						'post_id' => $post->ID,
					],
					admin_url( 'admin-post.php' )
				),
				'export_indesign_single_' . $post->ID
			);
			$actions    = array_merge(
				$actions,
				[
					'export_indesign' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( $export_url ),
						__( 'Export as Adobe InDesign', 'newspack-plugin' )
					),
				]
			);
		}
		return $actions;
	}

	/**
	 * Handle single post export.
	 */
	public static function handle_single_export() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_safe_redirect(
				add_query_arg( 'indesign_export_error', 'capability', admin_url( 'edit.php' ) )
			);
			exit;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'export_indesign_single_' . $post_id ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect(
				add_query_arg( 'indesign_export_error', 'nonce', admin_url( 'edit.php' ) )
			);
			exit;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_safe_redirect(
				add_query_arg( 'indesign_export_error', 'no_posts', admin_url( 'edit.php' ) )
			);
			exit;
		}

		if ( ! self::is_post_supported( $post_id ) ) {
			wp_safe_redirect(
				add_query_arg( 'indesign_export_error', 'unsupported_post_type', admin_url( 'edit.php' ) )
			);
			exit;
		}

		self::export_posts( [ $post_id ] );
		exit;
	}

	/**
	 * Whether the given post may be exported under the current settings.
	 *
	 * Defense in depth — the bulk and row UI actions only appear for
	 * post types in get_supported_post_types(), but the underlying
	 * `admin_post_export_indesign_single` action and bulk handler could
	 * otherwise be invoked with a post of a disabled type by anyone who can
	 * edit that post.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 * @return bool True when the post type is enabled for export.
	 */
	public static function is_post_supported( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		return in_array( $post->post_type, self::get_supported_post_types(), true );
	}

	/**
	 * Export posts as InDesign Tagged Text files.
	 *
	 * @param array $post_ids Array of post IDs to export.
	 */
	private static function export_posts( $post_ids ) {
		$converter        = new InDesign_Converter();
		$platform         = self::resolve_platform();
		$include_captions = ! self::get_exclude_captions_setting();
		$exported_files   = [];

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$content          = $converter->convert_post(
				$post,
				[
					'platform'         => $platform,
					'include_captions' => $include_captions,
				]
			);
			$filename         = self::generate_filename( $post );
			$exported_files[] = [
				'filename' => $filename,
				'content'  => $content,
				'post'     => $post,
			];
		}

		// Single file export.
		if ( 1 === count( $exported_files ) ) {
			self::download_single_file( $exported_files[0] );
		} else {
			// Multiple files export as zip.
			self::download_zip_file( $exported_files );
		}
	}

	/**
	 * Get the configured platform setting.
	 *
	 * @return string One of 'auto', 'mac', 'win'.
	 */
	public static function get_platform_setting() {
		$value = get_option( self::PLATFORM_OPTION, self::PLATFORM_DEFAULT );
		return in_array( $value, self::ALLOWED_PLATFORMS, true ) ? $value : self::PLATFORM_DEFAULT;
	}

	/**
	 * Whether photo captions should be excluded from exports.
	 *
	 * Photo credits are a separate attribution field and are always exported.
	 *
	 * @return bool True when captions should be omitted.
	 */
	public static function get_exclude_captions_setting() {
		return (bool) get_option( self::EXCLUDE_CAPTIONS_OPTION, self::EXCLUDE_CAPTIONS_DEFAULT );
	}

	/**
	 * Map a User-Agent string to a platform.
	 *
	 * Pure helper extracted so the auto-detect branch of resolve_platform()
	 * is testable without spoofing $_SERVER globals.
	 *
	 * @param string $user_agent User-Agent string to inspect.
	 * @return string Either 'mac' or 'win'. Empty/non-Mac strings yield 'win'.
	 */
	public static function sniff_user_agent_platform( $user_agent ) {
		return ( false !== stripos( $user_agent, 'Mac' ) || false !== stripos( $user_agent, 'iPad' ) || false !== stripos( $user_agent, 'iPhone' ) ) ? 'mac' : 'win';
	}

	/**
	 * Resolve the InDesign Tagged Text header platform for the current export.
	 *
	 * Honors the site setting first. When the setting is 'auto', the platform
	 * is sniffed from the requesting browser's User-Agent — InDesign requires
	 * the header to match the host OS or markup is rendered literally. A
	 * non-browser client (WP-CLI, a direct REST call) under 'auto' has no
	 * User-Agent and therefore resolves to 'win'.
	 *
	 * The return value is normalized to exactly 'mac' or 'win' after the filter
	 * runs, so a callback returning 'auto', null, or any other value degrades to
	 * 'win' (the converter's default header) rather than an invalid platform.
	 *
	 * @return string Either 'mac' or 'win'.
	 */
	public static function resolve_platform() {
		$setting    = self::get_platform_setting();
		$user_agent = '';

		if ( 'mac' === $setting || 'win' === $setting ) {
			$platform = $setting;
		} else {
			// The export runs from an authenticated admin request (admin-post.php
			// for a single export, or an edit.php bulk action) that is never cached.
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$platform   = self::sniff_user_agent_platform( $user_agent );
		}

		/**
		 * Filters the resolved platform for an InDesign export.
		 *
		 * @param string $platform   'mac' or 'win'.
		 * @param string $setting    The stored platform setting ('auto', 'mac', or 'win').
		 * @param string $user_agent The User-Agent header from the request after
		 *                           sanitize_text_field() + wp_unslash(), or '' when
		 *                           not consulted (i.e. setting is not 'auto').
		 */
		$platform = apply_filters( 'newspack_indesign_export_platform', $platform, $setting, $user_agent );

		return 'mac' === $platform ? 'mac' : 'win';
	}

	/**
	 * Generate filename for exported post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Generated filename.
	 */
	private static function generate_filename( $post ) {
		$title = sanitize_title( $post->post_title );
		$title = substr( $title, 0, 50 );
		$date  = get_the_date( 'Y-m-d', $post );
		return sprintf( '%d_%s_%s.txt', $post->ID, $title, $date );
	}

	/**
	 * Download single InDesign file.
	 *
	 * @param array $file File data array.
	 */
	private static function download_single_file( $file ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file['filename'] . '"' );
		header( 'Content-Length: ' . strlen( $file['content'] ) );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: 0' );

		echo $file['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Download multiple files as ZIP.
	 *
	 * @param array $files Array of file data.
	 */
	private static function download_zip_file( $files ) {
		$zip          = new \ZipArchive();
		$zip_filename = 'indesign-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.zip';

		$upload_dir = wp_upload_dir();
		$temp_file  = $upload_dir['basedir'] . '/indesign_export_' . uniqid() . '.zip';

		if ( true !== $zip->open( $temp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_safe_redirect(
				add_query_arg( 'indesign_export_error', 'zip_error', admin_url( 'edit.php' ) )
			);
			exit;
		}

		foreach ( $files as $file ) {
			$zip->addFromString( $file['filename'], $file['content'] );
		}

		$zip->close();

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $zip_filename . '"' );
		header( 'Content-Length: ' . filesize( $temp_file ) );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: 0' );

		readfile( $temp_file );

		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}
	}

	/**
	 * Display admin notices for export results.
	 */
	public static function admin_notices() {
		if ( isset( $_GET['indesign_export_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error   = sanitize_text_field( $_GET['indesign_export_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = '';

			switch ( $error ) {
				case 'capability':
					$message = __( 'You do not have permission to export posts.', 'newspack-plugin' );
					break;
				case 'nonce':
					$message = __( 'Security check failed. Please try again.', 'newspack-plugin' );
					break;
				case 'no_posts':
					$message = __( 'No posts were selected for export.', 'newspack-plugin' );
					break;
				case 'unsupported_post_type':
					$message = __( 'The selected post type is not enabled for InDesign export.', 'newspack-plugin' );
					break;
				case 'zip_error':
					$message = __( 'Could not create ZIP file for export.', 'newspack-plugin' );
					break;
				default:
					$message = __( 'An error occurred during export.', 'newspack-plugin' );
			}

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	}
}

InDesign_Exporter::init();
