<?php
/**
 * Sitemap generation for profiles.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;
use NewspackProfiles\Traits\Singleton;

const SITEMAP_BATCH_SIZE                = 100;
const SITEMAP_CACHE_EXPIRATION          = 3 * 60 * 60; // 3 hours.
const SITEMAP_BATCH_DELAY_IN_SECONDS    = 2;
const SITEMAP_GENERATION_STALE_DURATION = 1 * 60 * 60; // 1 hour.

/**
 * Sitemap_Generator class to handle sitemap generation for profiles.
 */
class Sitemap_Generator {

	use Singleton;

	/**
	 * Batch action hook prefix.
	 *
	 * @var string
	 */
	private string $batch_action_hook_prefix = 'newspack_profiles_process_sitemap_batch';

	/**
	 * Cron hook for scheduled regeneration.
	 *
	 * @var string
	 */
	private string $cron_hook = 'newspack_profiles_regenerate_sitemaps';

	/**
	 * Option name for sitemap generation states keyed by slug.
	 *
	 * @var string
	 */
	private const GENERATION_STATE_OPTION = 'newspack_profiles_sitemap_generation_state';

	/**
	 * Option name for storing generated sitemaps.
	 *
	 * @var string
	 */
	private const SITEMAP_CACHE_OPTION = 'newspack_profiles_sitemaps';

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'register_active_batch_hooks' ) );
		add_action( 'init', array( $this, 'schedule_sitemap_regeneration' ) );

		add_action( $this->cron_hook, array( $this, 'regenerate_all_sitemaps' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		add_action( 'template_redirect', array( $this, 'handle_sitemap_request' ) );
	}

	/**
	 * Register batch hooks for any in-progress generation.
	 *
	 * @return void
	 */
	public function register_active_batch_hooks(): void {
		$states = $this->get_generation_states();

		foreach ( array_keys( $states ) as $slug ) {
			$this->add_batch_hook( $slug );
		}
	}

	/**
	 * Add custom cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules['three_hours'] = array(
			'interval' => 3 * 60 * 60, // 3 hours in seconds.
			'display'  => __( 'Every 3 Hours', 'newspack-profiles' ),
		);

		return $schedules;
	}

	/**
	 * Schedule sitemap regeneration if not already scheduled.
	 *
	 * @return void
	 */
	public function schedule_sitemap_regeneration(): void {
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_event( time(), 'three_hours', $this->cron_hook );
		}
	}

	/**
	 * Regenerate sitemaps for all collections.
	 *
	 * @return void
	 */
	public function regenerate_all_sitemaps(): void {
		$collections = Profile_Collections::get_instance()->get_all( true );

		foreach ( $collections as $collection ) {
			$this->start_generation( $collection['slug'] );
		}
	}

	/**
	 * Start sitemap generation for a collection.
	 *
	 * @param string $collection_slug The collection slug.
	 *
	 * @return bool
	 */
	public function start_generation( string $collection_slug ): bool {
		if ( $this->is_generation_in_progress( $collection_slug ) ) {
			$this->clear_generation_state( $collection_slug );
		}

		$collection = Profile_Collections::get_instance()->get( $collection_slug );

		if ( empty( $collection ) ) {
			return false;
		}

		$query_builder = Query_Manager::get_query_builder( $collection );

		if ( null === $query_builder || ! $query_builder->has_valid_data_source() ) {
			return false;
		}

		$this->update_generation_state(
			array(
				'status'       => 'processing',
				'cursor'       => '',
				'pages'        => array(),
				'current_page' => 1,
				'created_at'   => time(),
			),
			$collection_slug
		);

		$this->add_batch_hook( $collection_slug );

		wp_schedule_single_event(
			time() + $this->get_sitemap_batch_delay_in_seconds(),
			$this->get_batch_action_hook( $collection_slug ),
			array( $collection_slug )
		);

		return true;
	}

	/**
	 * Process a single batch of sitemap generation.
	 *
	 * @param string $slug The collection slug.
	 *
	 * @return void
	 */
	public function process_batch( string $slug ): void {
		$collection = Profile_Collections::get_instance()->get( $slug );

		if ( empty( $collection ) ) {
			return;
		}

		$state = $this->get_generation_state( $slug );

		if ( ! $state || 'processing' !== $state['status'] ) {
			return;
		}

		$query_builder = Query_Manager::get_query_builder( $collection );

		if ( null === $query_builder || ! $query_builder->has_valid_data_source() ) {
			$this->clear_generation_state( $slug );
			return;
		}

		$list_query = $query_builder->get_list_query( true );

		$data = $list_query->execute(
			array(
				'page_size' => $this->get_sitemap_batch_size(),
				'cursor'    => $state['cursor'],
			)
		);

		if ( is_wp_error( $data ) || empty( $data['results'] ) ) {
			$this->finish_generation( $slug, $state );
			return;
		}

		$batch_slugs = array();

		foreach ( $data['results'] as $row ) {
			if ( ! empty( $row['result']['slug']['value'] ) ) {
				$batch_slugs[] = $row['result']['slug']['value'];
			}
		}

		if ( ! empty( $batch_slugs ) ) {
			$state['pages'][ $state['current_page'] ] = $batch_slugs;
			++$state['current_page'];
		}

		$next_cursor = $data['pagination']['input_variables']['next_page']['cursor'] ?? '';

		if ( empty( $next_cursor ) ) {
			$this->finish_generation( $slug, $state );
			return;
		}

		$state['cursor'] = $next_cursor;

		$this->update_generation_state( $state, $slug );

		wp_schedule_single_event(
			time() + $this->get_sitemap_batch_delay_in_seconds(),
			$this->get_batch_action_hook( $slug ),
			array( $slug )
		);
	}

	/**
	 * Finish sitemap generation for a collection.
	 *
	 * @param string $slug The collection slug.
	 * @param array  $state The generation state.
	 *
	 * @return void
	 */
	private function finish_generation( string $slug, array $state ): void {
		$pages       = $state['pages'];
		$total_pages = count( $pages );

		$sitemap_pages = array();

		foreach ( $pages as $page_num => $slugs ) {
			$sitemap_pages[ $page_num ] = $this->build_sitemap_xml( $slug, $slugs );
		}

		$index_xml = $this->build_sitemap_index_xml( $slug, $total_pages );

		$sitemaps = get_option( self::SITEMAP_CACHE_OPTION, array() );
		$sitemaps = is_array( $sitemaps ) ? $sitemaps : array();

		$sitemaps[ $slug ] = array(
			'index' => $index_xml,
			'pages' => $sitemap_pages,
		);

		update_option( self::SITEMAP_CACHE_OPTION, $sitemaps );

		$this->clear_generation_state( $slug );
	}

	/**
	 * Build sitemap XML for a set of profiles.
	 *
	 * @param string $base_slug The collection base slug.
	 * @param array  $slugs Profile slugs.
	 *
	 * @return string
	 */
	private function build_sitemap_xml( string $base_slug, array $slugs ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		$lastmod = gmdate( 'Y-m-d' );

		$base_path = Rewrite_Rule_Registrar::get_instance()->get_base_path();

		foreach ( $slugs as $slug ) {
			$url = esc_url( home_url( sprintf( '/%s/%s/%s', $base_path, $base_slug, $slug ) ) );

			$xml .= "  <url>\n";
			$xml .= "    <loc>{$url}</loc>\n";
			$xml .= "    <lastmod>{$lastmod}</lastmod>\n";
			$xml .= "    <changefreq>daily</changefreq>\n";
			$xml .= "    <priority>0.8</priority>\n";
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';

		return $xml;
	}

	/**
	 * Build sitemap index XML.
	 *
	 * @param string $base_slug The collection base slug.
	 * @param int    $total_pages Total number of sitemap pages.
	 *
	 * @return string
	 */
	private function build_sitemap_index_xml( string $base_slug, int $total_pages ): string {
		if ( $total_pages <= 0 ) {
			return '';
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		$lastmod = gmdate( 'Y-m-d' );

		$base_path = Rewrite_Rule_Registrar::get_instance()->get_base_path();

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$url = esc_url( home_url( sprintf( '/%s/%s/sitemap-%d.xml', $base_path, $base_slug, $i ) ) );

			$xml .= "  <sitemap>\n";
			$xml .= "    <loc>{$url}</loc>\n";
			$xml .= "    <lastmod>{$lastmod}</lastmod>\n";
			$xml .= "  </sitemap>\n";
		}

		$xml .= '</sitemapindex>';

		return $xml;
	}

	/**
	 * Update generation state for a slug.
	 *
	 * @param array  $state The generation state.
	 * @param string $slug The collection slug.
	 *
	 * @return void
	 */
	private function update_generation_state( array $state, string $slug ): void {
		$states          = $this->get_generation_states();
		$states[ $slug ] = $state;

		update_option( self::GENERATION_STATE_OPTION, $states );
	}

	/**
	 * Get generation state for a slug.
	 *
	 * @param string $slug The collection slug.
	 *
	 * @return array|null
	 */
	private function get_generation_state( string $slug ): ?array {
		$states = $this->get_generation_states();

		return $states[ $slug ] ?? null;
	}

	/**
	 * Check if generation is in progress for a slug.
	 *
	 * @param string $slug The collection slug.
	 *
	 * @return bool
	 */
	public function is_generation_in_progress( string $slug ): bool {
		$state = $this->get_generation_state( $slug );

		return (bool) (
			$state
			&& 'processing' === $state['status']
			&& time() - $state['created_at'] < $this->get_sitemap_generation_stale_duration_in_seconds()
		);
	}

	/**
	 * Clear generation state for a slug.
	 *
	 * @param string $slug The collection slug.
	 *
	 * @return void
	 */
	private function clear_generation_state( string $slug ): void {
		$states = $this->get_generation_states();

		unset( $states[ $slug ] );

		update_option( self::GENERATION_STATE_OPTION, $states );
	}

	/**
	 * Get the action hook name for a collection slug.
	 *
	 * @param string $slug The collection slug.
	 *
	 * @return string
	 */
	private function get_batch_action_hook( string $slug ): string {
		return $this->batch_action_hook_prefix . '_' . sanitize_title( $slug );
	}

	/**
	 * Register the batch hook for a slug.
	 *
	 * @param string $slug The collection slug.
	 *
	 * @return void
	 */
	private function add_batch_hook( string $slug ): void {
		$hook = $this->get_batch_action_hook( $slug );

		if ( ! has_action( $hook, array( $this, 'process_batch' ) ) ) {
			add_action( $hook, array( $this, 'process_batch' ), 10, 1 );
		}
	}

	/**
	 * Fetch all generation states keyed by slug.
	 *
	 * @return array
	 */
	private function get_generation_states(): array {
		$states = get_option( self::GENERATION_STATE_OPTION, array() );

		return is_array( $states ) ? $states : array();
	}

	/**
	 * Handle sitemap requests and serve cached sitemaps.
	 *
	 * @return void
	 */
	public function handle_sitemap_request(): void {
		$np_base    = get_query_var( 'np_base' );
		$np_sitemap = get_query_var( 'np_sitemap' );
		$np_page    = get_query_var( 'np_sitemap_page' );

		if ( empty( $np_base ) || empty( $np_sitemap ) ) {
			return;
		}

		$sitemaps = get_option( self::SITEMAP_CACHE_OPTION, array() );
		$sitemaps = is_array( $sitemaps ) ? $sitemaps : array();

		if ( empty( $sitemaps[ $np_base ] ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$sitemap = $sitemaps[ $np_base ];
		$xml     = '';

		if ( 'index' === $np_sitemap ) {
			$xml = $sitemap['index'];
		} elseif ( ! empty( $np_page ) && isset( $sitemap['pages'][ (int) $np_page ] ) ) {
			$xml = $sitemap['pages'][ (int) $np_page ];
		}

		if ( empty( $xml ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Cache-Control: max-age=' . $this->get_cache_expiration_in_seconds() . ', public' );
		header( 'X-Robots-Tag: noindex, follow' );

		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Clear sitemap cache for a specific collection.
	 *
	 * @param string $collection_slug The collection slug.
	 *
	 * @return void
	 */
	public function clear_collection_sitemap( string $collection_slug ): void {
		$sitemaps = get_option( self::SITEMAP_CACHE_OPTION, array() );

		if ( isset( $sitemaps[ $collection_slug ] ) ) {
			unset( $sitemaps[ $collection_slug ] );
			update_option( self::SITEMAP_CACHE_OPTION, $sitemaps );
		}

		$this->clear_generation_state( $collection_slug );
	}

	/**
	 * Get sitemap batch size.
	 *
	 * @return int
	 */
	private function get_sitemap_batch_size(): int {
		/**
		 * Filter sitemap batch size.
		 *
		 * @param int Default batch size.
		 */
		return apply_filters( 'newspack_profiles_sitemap_batch_size', SITEMAP_BATCH_SIZE );
	}

	/**
	 * Get sitemap cache expiration time in seconds.
	 *
	 * @return int
	 */
	private function get_cache_expiration_in_seconds(): int {
		/**
		 * Filter sitemap cache expiration time in seconds.
		 *
		 * @param int Default expiration time in seconds.
		 */
		return apply_filters( 'newspack_profiles_sitemap_cache_expiration_in_seconds', SITEMAP_CACHE_EXPIRATION );
	}

	/**
	 * Get sitemap batch delay in seconds.
	 *
	 * @return int
	 */
	private function get_sitemap_batch_delay_in_seconds(): int {
		/**
		 * Filter sitemap batch delay in seconds.
		 *
		 * @param int Default batch delay in seconds.
		 */
		return apply_filters( 'newspack_profiles_sitemap_batch_delay_in_seconds', SITEMAP_BATCH_DELAY_IN_SECONDS );
	}

	/**
	 * Get sitemap generation stale duration in seconds.
	 *
	 * @return int
	 */
	private function get_sitemap_generation_stale_duration_in_seconds(): int {
		/**
		 * Filter sitemap generation stale duration in seconds.
		 *
		 * @param int Default stale duration in seconds.
		 */
		return apply_filters( 'newspack_profiles_sitemap_generation_stale_duration_in_seconds', SITEMAP_GENERATION_STALE_DURATION );
	}
}
