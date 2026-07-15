<?php
/**
 * Rewrite rule registrar for profile URLs.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\Registrars;

use NewspackProfiles\Page_Template_Manager;
use NewspackProfiles\Profile_Collections;
use NewspackProfiles\Traits\Singleton;

/**
 * Rewrite_Rule_Registrar class to handle dynamic URL rewrite rules.
 */
class Rewrite_Rule_Registrar {

	use Singleton;

	/**
	 * Constructor for the Rewrite_Rule_Registrar class.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ), 10, 1 );
		add_filter( 'redirect_canonical', array( $this, 'disable_trailing_slash_for_sitemaps' ), 10, 1 );
	}

	/**
	 * Register dynamic URL rewrite rules.
	 */
	public function register_rewrite_rules(): void {
		$profile_collections_config = Profile_Collections::get_instance();

		$base_path = $this->get_base_path();

		foreach ( $profile_collections_config->get_all() as $config ) {
			$slug           = sanitize_title( (string) $config['slug'] );
			$single_page_id = (int) $config['pages']['single'];
			$list_page_id   = (int) $config['pages']['list'];

			add_rewrite_rule(
				'^' . $base_path . '/' . $slug . '/([0-9a-z-]+)/?$',
				sprintf( 'index.php?post_type=%s&np_slug=$matches[1]&np_base=%s&p=%s', Page_Template_Manager::POST_TYPE, $slug, $single_page_id ),
				'top'
			);

			add_rewrite_rule(
				'^' . $base_path . '/' . $slug . '/?$',
				sprintf( 'index.php?post_type=%s&np_base=%s&p=%s', Page_Template_Manager::POST_TYPE, $slug, $list_page_id ),
				'top'
			);

			if ( 'publish' !== $config['status'] ) {
				continue;
			}

			add_rewrite_rule(
				'^' . $base_path . '/' . $slug . '/sitemap-([0-9]+)\.xml$',
				'index.php?np_sitemap=page&np_sitemap_page=$matches[1]&np_base=' . $slug,
				'top'
			);

			add_rewrite_rule(
				'^' . $base_path . '/' . $slug . '/sitemaps?\.xml$',
				'index.php?np_sitemap=index&np_base=' . $slug,
				'top'
			);
		}

		/**
		 * Flush rewrite rules if needed.
		 * This will only run when a profile is added or deleted.
		 * We need to flush rewrite rules to ensure the new rewrite rules for newly added
		 * profiles are registered and old ones are removed.
		 */
		if ( get_option( 'newspack_profiles_flush_rewrite_rules', false ) ) {
			flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules

			delete_option( 'newspack_profiles_flush_rewrite_rules' );
		}
	}

	/**
	 * Add custom query variables.
	 *
	 * @param array $query_vars Existing query variables.
	 *
	 * @return array Modified query variables.
	 */
	public function add_query_vars( array $query_vars ): array {
		$query_vars[] = 'np_slug';
		$query_vars[] = 'np_base';
		$query_vars[] = 'np_sitemap';
		$query_vars[] = 'np_sitemap_page';

		return $query_vars;
	}

	/**
	 * Disable trailing slash redirect for sitemap URLs.
	 *
	 * @param string $redirect_url The redirect URL.
	 *
	 * @return string|false
	 */
	public function disable_trailing_slash_for_sitemaps( $redirect_url ): string|false {
		if ( get_query_var( 'np_sitemap' ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Get the base path for profile URLs.
	 *
	 * @return string The base path (default: 'profiles').
	 */
	public function get_base_path(): string {
		/**
		 * Filters the base path for profile URLs.
		 *
		 * @param string $base_path The base path for profile URLs. Default 'profiles'. Only alphanumeric characters, hyphens, and slashes are allowed.
		 */
		$base_path = apply_filters( 'newspack_profiles_base_path', 'profiles' );

		if ( ! is_string( $base_path ) ) {
			return 'profiles';
		}

		// sanitize the base path to only allow valid URL path characters like letters, numbers, hyphens, and slashes.
		$base_path = preg_replace( '/[^a-zA-Z0-9\-\/]/', '', $base_path );
		$base_path = preg_replace( '/\/+/', '/', $base_path );
		$base_path = trim( $base_path, '/' );

		if ( empty( $base_path ) ) {
			return 'profiles';
		}

		return $base_path;
	}
}
