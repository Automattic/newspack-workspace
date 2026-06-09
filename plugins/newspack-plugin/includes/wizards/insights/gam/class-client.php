<?php
/**
 * Newspack Insights — Google Ad Manager reporting client (NPPD-1662).
 *
 * Generic, metric-agnostic primitives for running GAM ReportService jobs
 * that power Tab 8 (Advertising). The Advertising metric orchestrator
 * (NPPD-1663) builds metric-specific {@see Report_Query} objects on top
 * of these primitives.
 *
 * IMPORTANT — read before modifying:
 *
 *  - This client uses newspack-ads' vendored SOAP library
 *    (googleads/googleads-php-lib, Ad Manager API v202602) cross-plugin.
 *    The reporting *code* lives here in the Insights module; only the
 *    *library* (and the GAM network code) come from newspack-ads. The
 *    library is loaded via NEWSPACK_ADS_COMPOSER_ABSPATH.
 *  - OAuth only. Service-account credentials must NEVER be used here —
 *    they apply to open-source/self-hosted users, not managed customers.
 *    Do NOT call \Newspack_Ads\Providers\GAM_Model::get_api(); that helper
 *    prefers a service account when one is present. The OAuth session is
 *    constructed explicitly below via Google_Services_Connection.
 *  - REST migration (Ad Manager API Beta) is deferred — see NPPD-1664.
 *    Rationale for SOAP-over-REST is in the NPPD-1662 pre-flight findings
 *    and formulas/tab-8-advertising.md.
 *
 * @package Newspack
 */

namespace Newspack\Insights\GAM;

use Newspack\Google_OAuth;
use Newspack\Google_Services_Connection;
use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * GAM reporting client.
 */
class Client {

	/**
	 * The Google Ad Manager OAuth scope. Already part of Newspack's
	 * Google_OAuth::REQUIRED_SCOPES, so any GA4-connected publisher has it.
	 *
	 * @var string
	 */
	const GAM_SCOPE = 'https://www.googleapis.com/auth/admanager';

	/**
	 * Option toggling Google Ad Manager as an active ad provider on the site
	 * (set on the Newspack "Ad Providers" settings page). This is the same
	 * option newspack-ads' GAM provider reads for its own is_active().
	 *
	 * @var string
	 */
	const GAM_ACTIVE_OPTION = '_newspack_advertising_service_google_ad_manager';

	/**
	 * Application name reported to the GAM API session.
	 *
	 * @var string
	 */
	const APPLICATION_NAME = 'Newspack Insights';

	/**
	 * Option storing the (capped) report-job audit trail.
	 *
	 * @var string
	 */
	const AUDIT_LOG_OPTION = 'newspack_insights_gam_audit_log';

	/**
	 * Maximum number of audit entries retained in the option.
	 *
	 * @var int
	 */
	const AUDIT_LOG_MAX = 500;

	/**
	 * Whether Tab 8 (Advertising) should be visible: whether Google Ad
	 * Manager is active on the site.
	 *
	 * Mirrors newspack-ads' GAM provider "active" signal — the Ad Providers
	 * settings toggle ({@see self::GAM_ACTIVE_OPTION}). This is the
	 * tab-visibility gate; it intentionally does NOT require the OAuth scope
	 * or a network code, so the tab still shows (with in-tab diagnostics)
	 * when GAM is enabled but reporting isn't fully wired up yet.
	 *
	 * @return bool
	 */
	public static function is_gam_active() {
		return static::is_newspack_ads_active()
			&& (bool) get_option( self::GAM_ACTIVE_OPTION, false );
	}

	/**
	 * Whether reporting is fully wired up: GAM is active AND the Google OAuth
	 * token carries the GAM scope AND a network code is configured.
	 *
	 * The orchestrator (NPPD-1663) uses this to decide between showing data
	 * and an in-tab "finish connecting" diagnostic. NOTE: this performs a
	 * remote call (the scope check hits Google's tokeninfo endpoint), so call
	 * it deliberately — e.g. once when rendering the tab — never inside a
	 * report polling loop. The run-time guard before each report operation is
	 * the cheaper {@see self::assert_can_run_reports()}.
	 *
	 * @return bool
	 */
	public static function can_run_reports() {
		return static::is_gam_active()
			&& static::has_gam_scope()
			&& '' !== static::get_network_code();
	}

	/**
	 * Submit an asynchronous report job.
	 *
	 * @param int          $network_code The publisher's GAM network code.
	 * @param Report_Query $query        The report query to run.
	 * @return string The report job ID.
	 *
	 * @throws \RuntimeException If not connected, the network code is not
	 *                           the publisher's own, the library is
	 *                           unavailable, or the API call fails.
	 */
	public static function run_report_job( $network_code, Report_Query $query ) {
		self::assert_can_run_reports();
		self::assert_own_network_code( $network_code );

		// Build the session and job up front; failures here are precondition
		// errors, not failed submissions, so they must NOT be audit-logged.
		$service    = self::get_report_service( (int) $network_code );
		$report_job = self::build_report_job( $query );

		$job_id  = '';
		$success = false;
		try {
			$result  = $service->runReportJob( $report_job );
			$job_id  = (string) $result->getId();
			$success = true;
			return $job_id;
		} catch ( \Exception $e ) {
			throw self::friendly_api_error( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- friendly_api_error() returns a RuntimeException with an already-escaped message.
		} finally {
			self::log_report_job( (int) $network_code, $query, $job_id, $success );
		}
	}

	/**
	 * Poll a report job's status once.
	 *
	 * @param int    $network_code The publisher's GAM network code.
	 * @param string $job_id       The report job ID.
	 * @return string One of the {@see Report_Job_Status} constants.
	 *
	 * @throws \RuntimeException If not connected or the network code is not
	 *                           the publisher's own.
	 */
	public static function get_report_job_status( $network_code, $job_id ) {
		self::assert_can_run_reports();
		self::assert_own_network_code( $network_code );
		return self::fetch_status( self::get_report_service( (int) $network_code ), $job_id );
	}

	/**
	 * Get the gzip download URL for a completed report job.
	 *
	 * @param int    $network_code The publisher's GAM network code.
	 * @param string $job_id       The report job ID.
	 * @return string The download URL.
	 *
	 * @throws \RuntimeException If the job is not COMPLETED, or on the same
	 *                           conditions as {@see self::get_report_job_status()}.
	 */
	public static function get_report_download_url( $network_code, $job_id ) {
		self::assert_can_run_reports();
		self::assert_own_network_code( $network_code );

		// One session for both the status check and the download, so we don't
		// build a second service (and trigger a second OAuth refresh) and risk
		// the token expiring between the two calls.
		$service = self::get_report_service( (int) $network_code );
		$status  = self::fetch_status( $service, $job_id );
		if ( Report_Job_Status::COMPLETED !== $status ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'GAM report job %s is not complete (status: %s).', (string) $job_id, $status ) )
			);
		}
		return self::fetch_download_url( $service, $job_id );
	}

	/**
	 * Fetch a completed report's download URL through a given service,
	 * translating GAM API errors into clearer messages.
	 *
	 * @param \Google\AdsApi\AdManager\v202602\ReportService $service The service.
	 * @param string                                         $job_id  The job ID.
	 * @return string The download URL.
	 *
	 * @throws \RuntimeException On a (translated) API error.
	 */
	protected static function fetch_download_url( $service, $job_id ) {
		try {
			return (string) $service->getReportDownloadUrlWithOptions( $job_id, self::build_download_options() );
		} catch ( \Exception $e ) {
			throw self::friendly_api_error( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- friendly_api_error() returns a RuntimeException with an already-escaped message.
		}
	}

	/**
	 * Download a gzipped CSV report and parse it into rows.
	 *
	 * @param string $url The GAM download URL.
	 * @return array<int,array<string,string>> Array of associative rows
	 *               keyed by the CSV header columns.
	 *
	 * @throws \RuntimeException On HTTP failure.
	 */
	public static function fetch_and_parse_csv( $url ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Report downloads run in background pre-warm (not a request hot path) and can be large, so a longer timeout is intentional.
		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( 'GAM report download failed: ' . $response->get_error_message() ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'GAM report download returned a non-200 response.' );
		}
		return self::parse_gzipped_csv( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Convert a GAM micro-currency value to a standard float
	 * (1,000,000 micros = 1.0).
	 *
	 * @param int|string $micros Micro-currency value.
	 * @return float
	 */
	public static function normalize_currency_micros( $micros ) {
		return (float) $micros / 1000000.0;
	}

	/**
	 * Read the (capped) report-job audit trail.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_audit_log() {
		$log = get_option( self::AUDIT_LOG_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}

	/**
	 * Build the SOAP ReportService for a session bound to the given
	 * network code, authenticated with the publisher's OAuth credentials.
	 *
	 * Isolated in its own method so the SOAP-touching path can be mocked in
	 * tests (the googleads library is not autoloaded in the unit-test env).
	 *
	 * @param int $network_code The GAM network code.
	 * @return \Google\AdsApi\AdManager\v202602\ReportService
	 *
	 * @throws \RuntimeException If credentials or the library are unavailable.
	 */
	protected static function get_report_service( $network_code ) {
		self::ensure_library_loaded();

		$credentials = Google_Services_Connection::get_oauth2_credentials();
		if ( ! $credentials ) {
			throw new \RuntimeException( 'No Google OAuth credentials available for GAM reporting.' );
		}

		$config  = [
			'AD_MANAGER' => [
				'applicationName' => self::APPLICATION_NAME,
				'networkCode'     => (string) $network_code,
			],
		];
		$session = ( new \Google\AdsApi\AdManager\AdManagerSessionBuilder() )
			->from( new \Google\AdsApi\Common\Configuration( $config ) )
			->withOAuth2Credential( $credentials )
			->build();

		return ( new \Google\AdsApi\AdManager\v202602\ServiceFactory() )->createReportService( $session );
	}

	/**
	 * Fetch and normalize a report job's status through a given service,
	 * translating GAM API errors into clearer messages.
	 *
	 * @param \Google\AdsApi\AdManager\v202602\ReportService $service The service.
	 * @param string                                         $job_id  The job ID.
	 * @return string One of the {@see Report_Job_Status} constants.
	 *
	 * @throws \RuntimeException On a (translated) API error.
	 */
	protected static function fetch_status( $service, $job_id ) {
		try {
			return Report_Job_Status::normalize( $service->getReportJobStatus( $job_id ) );
		} catch ( \Exception $e ) {
			throw self::friendly_api_error( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- friendly_api_error() returns a RuntimeException with an already-escaped message.
		}
	}

	/**
	 * Translate a GAM SOAP exception into a RuntimeException with an
	 * actionable message for the common, recognizable error conditions
	 * (mirrors newspack-ads' GAM_Api error mapping). Unknown errors fall back
	 * to the raw API message.
	 *
	 * @param \Exception $e The caught exception.
	 * @return \RuntimeException
	 */
	protected static function friendly_api_error( \Exception $e ) {
		$error_strings = [];
		if ( method_exists( $e, 'getErrors' ) ) {
			foreach ( (array) $e->getErrors() as $error ) {
				if ( is_object( $error ) && method_exists( $error, 'getErrorString' ) ) {
					$error_strings[] = $error->getErrorString();
				}
			}
		}
		$message_map = [
			'AuthenticationError.NETWORK_NOT_FOUND'     => 'The configured GAM network code is invalid for the connected Google account.',
			'AuthenticationError.NETWORK_API_ACCESS_DISABLED' => 'API access is disabled for this Google Ad Manager account.',
			'AuthenticationError.NO_NETWORKS_TO_ACCESS' => 'The connected Google account has no accessible Google Ad Manager networks.',
			'PermissionError.PERMISSION_DENIED'         => 'The connected Google account does not have permission to run Google Ad Manager reports.',
		];
		foreach ( $message_map as $code => $message ) {
			if ( in_array( $code, $error_strings, true ) ) {
				return new \RuntimeException( esc_html( $message ) );
			}
		}
		return new \RuntimeException( esc_html( 'Google Ad Manager API error: ' . $e->getMessage() ) );
	}

	/**
	 * Translate a {@see Report_Query} value object into a SOAP ReportJob.
	 *
	 * @param Report_Query $query The query.
	 * @return \Google\AdsApi\AdManager\v202602\ReportJob
	 */
	protected static function build_report_job( Report_Query $query ) {
		$report_query = new \Google\AdsApi\AdManager\v202602\ReportQuery();
		$report_query->setDimensions( $query->dimensions );
		$report_query->setColumns( $query->columns );
		$report_query->setDateRangeType( $query->date_range_type );

		if ( 'CUSTOM_DATE' === $query->date_range_type ) {
			self::assert_valid_custom_dates( $query );
			$report_query->setStartDate( self::to_gam_date( $query->start_date ) );
			$report_query->setEndDate( self::to_gam_date( $query->end_date ) );
		}

		if ( ! empty( $query->pql_filter ) ) {
			$report_query->setStatement( new \Google\AdsApi\AdManager\v202602\Statement( $query->pql_filter ) );
		}

		$report_job = new \Google\AdsApi\AdManager\v202602\ReportJob();
		$report_job->setReportQuery( $report_query );
		return $report_job;
	}

	/**
	 * Build the gzip CSV download options.
	 *
	 * @return \Google\AdsApi\AdManager\v202602\ReportDownloadOptions
	 */
	protected static function build_download_options() {
		$options = new \Google\AdsApi\AdManager\v202602\ReportDownloadOptions();
		$options->setExportFormat( \Google\AdsApi\AdManager\v202602\ExportFormat::CSV_DUMP );
		$options->setUseGzipCompression( true );
		return $options;
	}

	/**
	 * Validate that a CUSTOM_DATE query carries well-formed start/end dates.
	 * Guards against the default empty-string dates producing a SOAP
	 * Date(0, 0, 0) and a hard-to-diagnose API error downstream.
	 *
	 * @param Report_Query $query The query.
	 * @return void
	 *
	 * @throws \RuntimeException If either date is missing or not YYYY-MM-DD.
	 */
	protected static function assert_valid_custom_dates( Report_Query $query ) {
		$dates = [
			'start_date' => $query->start_date,
			'end_date'   => $query->end_date,
		];
		foreach ( $dates as $label => $value ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				throw new \RuntimeException(
					esc_html( sprintf( 'GAM report %s must be a YYYY-MM-DD date for a CUSTOM_DATE range; got "%s".', $label, (string) $value ) )
				);
			}
			list( $year, $month, $day ) = self::parse_ymd( $value );
			if ( ! checkdate( $month, $day, $year ) ) {
				throw new \RuntimeException(
					esc_html( sprintf( 'GAM report %s is not a valid calendar date: "%s".', $label, $value ) )
				);
			}
		}
	}

	/**
	 * Build a SOAP Date object from a 'YYYY-MM-DD' string.
	 *
	 * @param string $ymd Date string.
	 * @return \Google\AdsApi\AdManager\v202602\Date
	 */
	protected static function to_gam_date( $ymd ) {
		list( $year, $month, $day ) = self::parse_ymd( $ymd );
		return new \Google\AdsApi\AdManager\v202602\Date( $year, $month, $day );
	}

	/**
	 * Parse a 'YYYY-MM-DD' string into [ year, month, day ] integers.
	 *
	 * @param string $ymd Date string.
	 * @return array{0:int,1:int,2:int}
	 */
	protected static function parse_ymd( $ymd ) {
		$parts = array_map( 'intval', explode( '-', (string) $ymd ) );
		return [
			isset( $parts[0] ) ? $parts[0] : 0,
			isset( $parts[1] ) ? $parts[1] : 0,
			isset( $parts[2] ) ? $parts[2] : 0,
		];
	}

	/**
	 * Decompress and parse a gzipped GAM CSV dump into associative rows.
	 *
	 * A gzip payload (what GAM returns) is detected by its magic bytes, so a
	 * corrupt/truncated gzip is reported as an error rather than silently
	 * parsed as text; non-gzip input is tolerated as already-decompressed.
	 *
	 * Notes: rows are split on newlines, so CSV fields containing embedded
	 * newlines are not supported (GAM CSV_DUMP exports do not use them); and
	 * header column names are assumed unique (GAM dumps qualify them, e.g.
	 * `Dimension.DATE`), so duplicate names would collapse to one key.
	 *
	 * @param string $body Raw (gzipped) response body.
	 * @return array<int,array<string,string>>
	 *
	 * @throws \RuntimeException If a gzip payload cannot be decompressed.
	 */
	protected static function parse_gzipped_csv( $body ) {
		$body = (string) $body;
		if ( 0 === strncmp( $body, "\x1f\x8b", 2 ) ) {
			$decoded = function_exists( 'gzdecode' ) ? @gzdecode( $body ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gzdecode emits a warning on a corrupt payload; we convert that to a thrown error below.
			if ( false === $decoded ) {
				throw new \RuntimeException( 'Failed to decompress the GAM report download.' );
			}
			$csv = $decoded;
		} else {
			$csv = $body;
		}

		// Strip a leading UTF-8 BOM (trim() does not) so the first header key is clean.
		$csv = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );

		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
		if ( empty( $lines ) || '' === $lines[0] ) {
			return [];
		}

		$header = str_getcsv( array_shift( $lines ) );
		$rows   = [];
		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$values = str_getcsv( $line );
			$row    = [];
			foreach ( $header as $index => $key ) {
				$row[ $key ] = isset( $values[ $index ] ) ? $values[ $index ] : '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Whether newspack-ads (which vendors the SOAP library and owns the
	 * network code) is active.
	 *
	 * @return bool
	 */
	protected static function is_newspack_ads_active() {
		return class_exists( '\Newspack_Ads\Providers\GAM_Model' );
	}

	/**
	 * Whether the saved Google OAuth token carries the GAM scope.
	 *
	 * @return bool
	 */
	protected static function has_gam_scope() {
		return class_exists( '\Newspack\Google_OAuth' ) && Google_OAuth::token_has_scope( self::GAM_SCOPE );
	}

	/**
	 * Resolve the publisher's GAM network code, server-side only.
	 *
	 * Never accepts a network code from request input. The authoritative
	 * source is newspack-ads' GAM_Model::get_active_network_code(), which
	 * returns the single active code. Only the raw-option fallback (used when
	 * newspack-ads is unavailable) can be comma-delimited, in which case the
	 * first entry is taken as a best effort. Both the run-time guard and the
	 * site-isolation check ({@see self::assert_own_network_code()}) resolve
	 * through this one method, so they always agree on "this publisher's
	 * network".
	 *
	 * @return string The network code, or '' if none.
	 */
	protected static function get_network_code() {
		if ( class_exists( '\Newspack_Ads\Providers\GAM_Model' ) ) {
			$code = \Newspack_Ads\Providers\GAM_Model::get_active_network_code();
		} else {
			$code = get_option( '_newspack_ads_gam_network_code', '' );
		}
		if ( is_string( $code ) && false !== strpos( $code, ',' ) ) {
			$parts = explode( ',', $code );
			$code  = trim( $parts[0] );
		}
		return (string) $code;
	}

	/**
	 * Cheap, local-only guard run before each report operation.
	 *
	 * Deliberately does NOT re-verify the OAuth scope: that is a remote
	 * tokeninfo call (see {@see self::can_run_reports()}) and must not run on
	 * every poll. A missing/invalid scope instead surfaces as a translated
	 * API error from the SOAP call itself.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If GAM is not active or no network code is set.
	 */
	protected static function assert_can_run_reports() {
		if ( ! self::is_gam_active() || '' === self::get_network_code() ) {
			throw new \RuntimeException( 'Cannot run a GAM report: GAM is not active or no network code is configured.' );
		}
	}

	/**
	 * Assert that the given network code matches the publisher's own,
	 * server-resolved code. Site-isolation guard: a site can only ever
	 * query its own GAM network.
	 *
	 * @param int|string $network_code The network code passed by the caller.
	 * @return void
	 *
	 * @throws \RuntimeException If the code does not match.
	 */
	protected static function assert_own_network_code( $network_code ) {
		if ( (string) $network_code !== self::get_network_code() ) {
			throw new \RuntimeException( 'Refusing to run a GAM report against a network code that is not this publisher\'s own.' );
		}
	}

	/**
	 * Ensure the googleads SOAP library is loaded (from newspack-ads'
	 * vendored autoloader).
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the library cannot be loaded.
	 */
	protected static function ensure_library_loaded() {
		if ( class_exists( '\Google\AdsApi\AdManager\AdManagerSessionBuilder' ) ) {
			return;
		}
		if ( defined( 'NEWSPACK_ADS_COMPOSER_ABSPATH' ) && file_exists( NEWSPACK_ADS_COMPOSER_ABSPATH . 'autoload.php' ) ) {
			require_once NEWSPACK_ADS_COMPOSER_ABSPATH . 'autoload.php';
		}
		if ( ! class_exists( '\Google\AdsApi\AdManager\AdManagerSessionBuilder' ) ) {
			throw new \RuntimeException( 'The GAM SOAP library is unavailable; newspack-ads must be active.' );
		}
	}

	/**
	 * Append a report-job submission to the audit trail (minimal, to be
	 * consolidated with the wider Insights audit log later).
	 *
	 * @param int          $network_code The network code.
	 * @param Report_Query $query        The query that was run.
	 * @param string       $job_id       The returned job ID (empty on failure).
	 * @param bool         $success      Whether submission succeeded.
	 * @return void
	 */
	protected static function log_report_job( $network_code, Report_Query $query, $job_id, $success ) {
		$entry = [
			'time'         => gmdate( 'c' ),
			'network_code' => (string) $network_code,
			'query_hash'   => $query->hash(),
			'user_id'      => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'success'      => (bool) $success,
			'job_id'       => (string) $job_id,
		];

		if ( class_exists( '\Newspack\Logger' ) ) {
			Logger::log( $entry, 'NEWSPACK-INSIGHTS-GAM', $success ? 'info' : 'error' );
		}

		$log = self::get_audit_log();
		$log[] = $entry;
		if ( count( $log ) > self::AUDIT_LOG_MAX ) {
			$log = array_slice( $log, - self::AUDIT_LOG_MAX );
		}
		update_option( self::AUDIT_LOG_OPTION, $log, false );
	}
}
