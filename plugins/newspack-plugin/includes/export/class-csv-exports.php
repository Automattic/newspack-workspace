<?php
/**
 * Newspack CSV exports controller.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Batched CSV exports of WooCommerce Subscriptions and WP users, triggered
 * from the admin list tables and exporting the current filtered view.
 *
 * The export is AJAX-stepped (WooCommerce product-exporter style): the
 * browser drives one page per request against wp_ajax_newspack_csv_export,
 * the exporter appends rows to a temp file under
 * uploads/newspack-csv-exports/, and a nonce-protected download streams and
 * deletes the file. A daily cron sweeps abandoned files.
 */
final class CSV_Exports {

	/**
	 * AJAX action driving the stepped export.
	 */
	const AJAX_ACTION = 'newspack_csv_export';

	/**
	 * Nonce action for the AJAX steps.
	 */
	const AJAX_NONCE_ACTION = 'newspack-csv-export';

	/**
	 * GET action for the file download.
	 */
	const DOWNLOAD_ACTION = 'newspack_download_csv_export';

	/**
	 * Nonce action for the file download.
	 */
	const DOWNLOAD_NONCE_ACTION = 'newspack-csv-export-download';

	/**
	 * Cron hook sweeping abandoned export files.
	 */
	const CLEANUP_CRON_HOOK = 'newspack_csv_export_cleanup';

	/**
	 * Subdirectory of uploads holding in-progress export files.
	 */
	const EXPORTS_DIR = 'newspack-csv-exports';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Export buttons on the three list tables (HPOS subscriptions, legacy
		// CPT subscriptions, users).
		\add_action( 'woocommerce_order_list_table_extra_tablenav', [ __CLASS__, 'render_subscriptions_button_hpos' ], 10, 2 );
		\add_action( 'manage_posts_extra_tablenav', [ __CLASS__, 'render_subscriptions_button_cpt' ] );
		\add_action( 'manage_users_extra_tablenav', [ __CLASS__, 'render_users_button' ] );

		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_enqueue_scripts' ] );
		\add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_export' ] );
		\add_action( 'admin_init', [ __CLASS__, 'download_export_file' ] );

		// The cleanup sweep is armed lazily by the first export (see
		// schedule_cleanup()); sites that never export keep a clean cron array.
		\add_action( self::CLEANUP_CRON_HOOK, [ __CLASS__, 'cleanup_stale_files' ] );
		\register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'cron_deactivate' ] );
		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CLEANUP_CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			\add_action( 'init', [ __CLASS__, 'cron_deactivate' ] );
		}
	}

	/**
	 * Whether the current user may run an export of the given type.
	 *
	 * The users export additionally requires manage_woocommerce because the
	 * CSV carries WooCommerce billing PII (addresses, phone numbers) that
	 * list_users alone does not imply access to.
	 *
	 * @param string $type Export type: 'subscriptions' or 'users'.
	 * @return bool
	 */
	private static function current_user_can_export( string $type ): bool {
		if ( 'subscriptions' === $type ) {
			return \current_user_can( 'manage_woocommerce' ) && function_exists( 'wcs_get_subscriptions' );
		}
		if ( 'users' === $type ) {
			// The WooCommerce check matters beyond loading the exporter framework:
			// manage_woocommerce persists on the administrator role after WC is
			// deactivated, which would otherwise render a dead button.
			return class_exists( 'WooCommerce' ) && \current_user_can( 'list_users' ) && \current_user_can( 'manage_woocommerce' );
		}
		return false;
	}

	/**
	 * Generate a temp filename for an export run. The random suffix makes the
	 * in-progress file path unguessable; the type prefix binds the filename to
	 * its export type (see validate_export_filename()).
	 *
	 * @param string $type Export type: 'subscriptions' or 'users'.
	 * @return string
	 */
	public static function generate_export_filename( string $type ): string {
		return sprintf( 'newspack-%s-export-%s-%s.csv', $type, gmdate( 'Y-m-d' ), \wp_generate_password( 12, false, false ) );
	}

	/**
	 * Whether a client-supplied filename belongs to the given export type.
	 *
	 * Capability checks are per-type, so a filename must not be allowed to
	 * cross types (e.g. a subscriptions-capable user replaying a users-export
	 * filename through the subscriptions download path).
	 *
	 * @param string $filename Sanitized filename.
	 * @param string $type     Export type: 'subscriptions' or 'users'.
	 * @return bool
	 */
	public static function validate_export_filename( string $filename, string $type ): bool {
		return 0 === strpos( $filename, "newspack-{$type}-export-" );
	}

	/**
	 * Load WooCommerce's batch exporter abstract and the Newspack exporter
	 * classes. WC only loads the abstracts on demand, so this must run inside
	 * the AJAX/download/CLI handlers, never at plugin boot.
	 *
	 * @return bool False when WooCommerce (or its export framework) is unavailable.
	 */
	public static function load_exporter_dependencies(): bool {
		if ( ! defined( 'WC_ABSPATH' ) ) {
			return false;
		}
		if ( ! class_exists( 'WC_CSV_Batch_Exporter', false ) ) {
			$abstract = WC_ABSPATH . 'includes/export/abstract-wc-csv-batch-exporter.php';
			// Guards against WC restructuring the export framework: degrade to
			// a clear error instead of a fatal.
			if ( ! file_exists( $abstract ) ) {
				return false;
			}
			require_once $abstract;
		}
		require_once __DIR__ . '/class-subscriptions-csv-exporter.php';
		require_once __DIR__ . '/class-users-csv-exporter.php';
		return true;
	}

	/**
	 * Get an exporter instance for a type.
	 *
	 * @param string $type Export type: 'subscriptions' or 'users'.
	 * @return CSV_Batch_Exporter|null
	 */
	public static function get_exporter( string $type ): ?CSV_Batch_Exporter {
		if ( ! self::load_exporter_dependencies() ) {
			return null;
		}
		if ( 'subscriptions' === $type ) {
			return new Subscriptions_CSV_Exporter();
		}
		if ( 'users' === $type ) {
			return new Users_CSV_Exporter();
		}
		return null;
	}

	/**
	 * Render the export button on the HPOS subscriptions list.
	 *
	 * @param string $order_type Order type of the list table.
	 * @param string $which      'top' or 'bottom'.
	 */
	public static function render_subscriptions_button_hpos( $order_type, $which ) {
		if ( 'shop_subscription' !== $order_type || 'top' !== $which ) {
			return;
		}
		if ( ! self::current_user_can_export( 'subscriptions' ) ) {
			return;
		}
		self::render_export_button( 'subscriptions' );
	}

	/**
	 * Render the export button on the legacy CPT subscriptions list.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	public static function render_subscriptions_button_cpt( $which ) {
		if ( 'top' !== $which || 'shop_subscription' !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}
		if ( ! self::current_user_can_export( 'subscriptions' ) ) {
			return;
		}
		self::render_export_button( 'subscriptions' );
	}

	/**
	 * Render the export button on the users list.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	public static function render_users_button( $which ) {
		if ( 'top' !== $which || ! self::current_user_can_export( 'users' ) ) {
			return;
		}
		self::render_export_button( 'users' );
	}

	/**
	 * Render an export button with its status element.
	 *
	 * The export follows the list's current filters; list sorting is not
	 * carried over (rows are ordered by ID for stable pagination). It is a
	 * point-in-time snapshot: rows added while it runs land past the moving
	 * window, and rows that leave the filtered set mid-run trigger the
	 * incomplete-export notice in ajax_export().
	 *
	 * @param string $type Export type: 'subscriptions' or 'users'.
	 */
	private static function render_export_button( string $type ) {
		printf(
			'<div class="alignleft actions newspack-csv-export-wrap"><button type="button" class="button newspack-csv-export" data-export="%1$s" aria-describedby="newspack-csv-export-desc-%1$s">%2$s</button> <span id="newspack-csv-export-desc-%1$s" class="screen-reader-text">%3$s</span><span class="newspack-csv-export__status" hidden></span><span class="newspack-csv-export__announce screen-reader-text" role="status"></span></div>',
			\esc_attr( $type ),
			\esc_html__( 'Export CSV', 'newspack-plugin' ),
			\esc_html__( 'Exports the current filtered view as CSV, as a point-in-time snapshot. List sorting is not applied to the export.', 'newspack-plugin' )
		);
	}

	/**
	 * Enqueue the export script on the three list-table screens.
	 */
	public static function admin_enqueue_scripts() {
		$screen = \get_current_screen();
		if ( ! $screen ) {
			return;
		}
		// Enqueue only where the matching export button actually renders.
		$screen_ids = [];
		if ( self::current_user_can_export( 'users' ) ) {
			$screen_ids[] = 'users';
		}
		if ( self::current_user_can_export( 'subscriptions' ) && function_exists( 'wcs_get_page_screen_id' ) ) {
			$screen_ids[] = 'edit-shop_subscription';
			$screen_ids[] = \wcs_get_page_screen_id( 'shop_subscription' );
		}
		if ( ! in_array( $screen->id, $screen_ids, true ) ) {
			return;
		}
		\wp_enqueue_script(
			'newspack-csv-export',
			Newspack::plugin_url() . '/dist/csv-export.js',
			[],
			Newspack::asset_version( 'csv-export' ),
			true
		);
		\wp_enqueue_style(
			'newspack-csv-export',
			Newspack::plugin_url() . '/dist/csv-export.css',
			[],
			Newspack::asset_version( 'csv-export' )
		);
		\wp_localize_script(
			'newspack-csv-export',
			'newspackCsvExport',
			[
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => \wp_create_nonce( self::AJAX_NONCE_ACTION ),
				'labels'  => [
					'exporting' => __( 'Exporting…', 'newspack-plugin' ),
					'done'      => __( 'Export complete, downloading…', 'newspack-plugin' ),
					'error'     => __( 'Export failed. Please try again.', 'newspack-plugin' ),
				],
			]
		);
	}

	/**
	 * AJAX handler: process one page of the export.
	 */
	public static function ajax_export() {
		\check_ajax_referer( self::AJAX_NONCE_ACTION, 'security' );

		$type = isset( $_POST['export'] ) ? \sanitize_key( \wp_unslash( $_POST['export'] ) ) : '';
		if ( ! self::current_user_can_export( $type ) ) {
			\wp_send_json_error(
				[ 'message' => __( 'You do not have permission to export this data.', 'newspack-plugin' ) ],
				403
			);
		}

		$exporter = self::get_exporter( $type );
		if ( ! $exporter ) {
			\wp_send_json_error(
				[ 'message' => __( 'CSV export requires WooCommerce with its export framework available.', 'newspack-plugin' ) ]
			);
		}

		$step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 1;

		// The server names the file on step 1 (random suffix = unguessable
		// path); subsequent steps echo it back. is_string() guards keep
		// array-shaped params (filename[]=x) on the graceful-error path, and
		// the type-prefix check keeps a filename bound to its export type.
		$posted_filename = isset( $_POST['filename'] ) && is_string( $_POST['filename'] )
			? \sanitize_file_name( \wp_unslash( $_POST['filename'] ) )
			: '';
		if ( $step > 1 && '' !== $posted_filename && self::validate_export_filename( $posted_filename, $type ) ) {
			$filename = $posted_filename;
		} else {
			$step     = 1;
			$filename = self::generate_export_filename( $type );
		}
		$exporter->set_filename( $filename );

		$list_params = [];
		if ( ! empty( $_POST['list_args'] ) && is_string( $_POST['list_args'] ) ) {
			\wp_parse_str( ltrim( \wp_unslash( $_POST['list_args'] ), '?' ), $list_params ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$list_params = \wc_clean( $list_params );
		}
		$exporter->set_list_params( $list_params );

		// First export on the site arms the daily stale-file sweep.
		self::schedule_cleanup();

		$exporter->set_page( $step );
		$exporter->generate_file();

		$percent = $exporter->get_percent_complete();
		// The run's total is pinned to page 1 (see CSV_Batch_Exporter::
		// pin_total_rows()), so a result set that shrinks mid-run ends on an
		// empty page rather than a percentage that quietly overshoots 100.
		// Finish on either, and say so when the run stopped short — which is
		// ended_short(), not a percentage below 100: the percentage is back at
		// exactly 100 whenever the shrinkage was smaller than a page.
		$ended_short = $exporter->ended_short();
		if ( $percent >= 100 || $ended_short ) {
			// An unwritable uploads dir fails silently in the WC exporter;
			// surface it instead of serving an empty CSV.
			$file_path = $exporter->get_export_file_path();
			if ( $exporter->get_total_exported() > 0 && ( ! file_exists( $file_path ) || 0 === filesize( $file_path ) ) ) {
				\wp_send_json_error(
					[ 'message' => __( 'The export file could not be written. Please check uploads directory permissions.', 'newspack-plugin' ) ]
				);
			}
			// The run is over: drop its pinned total rather than leaving the
			// transient to expire on its own.
			$exporter->clear_pinned_total();
			\wp_send_json_success(
				[
					'step'       => 'done',
					'percentage' => 100,
					'notice'     => $ended_short
						? __( 'Rows were removed or changed while the export was running, so this file may be incomplete. Run the export again for a fresh snapshot.', 'newspack-plugin' )
						: '',
					'url'        => \add_query_arg(
						[
							'action'   => self::DOWNLOAD_ACTION,
							'nonce'    => \wp_create_nonce( self::DOWNLOAD_NONCE_ACTION ),
							'export'   => $type,
							'filename' => rawurlencode( $filename ),
						],
						\admin_url()
					),
				]
			);
		}
		\wp_send_json_success(
			[
				'step'       => $step + 1,
				'percentage' => $percent,
				'filename'   => $filename,
			]
		);
	}

	/**
	 * Serve a completed export file (and delete it once sent).
	 *
	 * Unlike WC core's product-export download, this re-checks capabilities
	 * in addition to the nonce.
	 */
	public static function download_export_file() {
		if ( ! isset( $_GET['action'] ) || self::DOWNLOAD_ACTION !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! \wp_verify_nonce( \sanitize_key( \wp_unslash( $_GET['nonce'] ?? '' ) ), self::DOWNLOAD_NONCE_ACTION ) ) {
			\wp_die( \esc_html__( 'Invalid download link.', 'newspack-plugin' ), '', 403 );
		}

		$type = isset( $_GET['export'] ) ? \sanitize_key( \wp_unslash( $_GET['export'] ) ) : '';
		if ( ! self::current_user_can_export( $type ) ) {
			\wp_die( \esc_html__( 'You do not have permission to download this export.', 'newspack-plugin' ), '', 403 );
		}

		$exporter = self::get_exporter( $type );
		if ( ! $exporter ) {
			\wp_die( \esc_html__( 'CSV export requires WooCommerce with its export framework available.', 'newspack-plugin' ), '', 500 );
		}
		if ( empty( $_GET['filename'] ) || ! is_string( $_GET['filename'] ) ) {
			\wp_die( \esc_html__( 'Invalid download link.', 'newspack-plugin' ), '', 403 );
		}
		// set_filename() runs sanitize_file_name(), killing any path traversal;
		// the prefix check binds the filename to the capability-checked type.
		$filename = \sanitize_file_name( \wp_unslash( $_GET['filename'] ) );
		if ( ! self::validate_export_filename( $filename, $type ) ) {
			\wp_die( \esc_html__( 'Invalid download link.', 'newspack-plugin' ), '', 403 );
		}
		$exporter->set_filename( $filename );
		// A served export is deleted on send; a replayed link would otherwise
		// quietly download a headers-only CSV.
		if ( ! file_exists( $exporter->get_export_file_path() ) ) {
			\wp_die( \esc_html__( 'This download link has expired. Please run the export again.', 'newspack-plugin' ), '', 410 );
		}
		// Streamed rather than WC's export(), which loads the whole file into
		// memory and can OOM on a large PII export (see stream_export()).
		$exporter->stream_export();
	}

	/**
	 * Schedule the daily sweep of abandoned export files. Called from the
	 * export entry points (not boot), so only sites that actually export
	 * carry the recurring event.
	 */
	public static function schedule_cleanup() {
		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CLEANUP_CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			return;
		}
		if ( ! \wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
			\wp_schedule_event( time(), 'daily', self::CLEANUP_CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cleanup sweep (plugin deactivation / NEWSPACK_CRON_DISABLE).
	 */
	public static function cron_deactivate() {
		\wp_clear_scheduled_hook( self::CLEANUP_CRON_HOOK );
	}

	/**
	 * Delete export files older than a day (abandoned mid-export or never
	 * downloaded). Completed downloads are deleted at send time; this is the
	 * safety net for the rest.
	 */
	public static function cleanup_stale_files() {
		$upload_dir = \wp_upload_dir();
		$dir        = \trailingslashit( $upload_dir['basedir'] ) . self::EXPORTS_DIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( \trailingslashit( $dir ) . '*.csv*' );
		if ( ! $files ) {
			return;
		}
		foreach ( $files as $file ) {
			$modified = filemtime( $file );
			if ( false !== $modified && time() - $modified > DAY_IN_SECONDS ) {
				@unlink( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
		}
	}
}
CSV_Exports::init();
