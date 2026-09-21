<?php
/**
 * Newspack's SEO Section.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use WP_Error, WP_Query;
use Newspack\Configuration_Managers;
use Newspack\Wizards\Wizard_Section;

defined( 'ABSPATH' ) || exit;

/**
 * SEO Section Class.
 */
class SEO_Section extends Wizard_Section {

	/**
	 * Containing wizard slug.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * Profiles Yoast has no dedicated option for, mapped to the hosts that identify
	 * them inside Yoast's catch-all `other_social_urls` list. Anything in that list
	 * we don't recognize belongs to Yoast's own UI and is preserved on save.
	 *
	 * @var array
	 */
	const OTHER_SOCIAL_HOSTS = [
		'bluesky' => [ 'bsky.app' ],
		'threads' => [ 'threads.net', 'threads.com' ],
		'tiktok'  => [ 'tiktok.com' ],
	];

	/**
	 * Register the endpoints needed for the wizard screens.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/seo',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_seo_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->wizard_slug . '/seo',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_seo_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'verification' => [],
					'urls'         => [],
				],
			]
		);
	}

	/**
	 * API endpoint to retrieve all settings necessary to render the SEO wizard.
	 *
	 * @return WP_REST_Response with the info.
	 */
	public function api_get_seo_settings() {
		$response = $this->get_seo_settings();
		return rest_ensure_response( $response );
	}

	/**
	 * Update SEO wizard settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response with the info.
	 */
	public function api_update_seo_settings( $request ) {
		$cm = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'wordpress_seo' );
		if ( isset( $request['urls'] ) ) {
			$urls = $request['urls'];
			if ( isset( $urls['facebook'] ) ) {
				$cm->set_option( 'facebook_site', $urls['facebook'] );
			}
			if ( isset( $urls['twitter'] ) ) {
				$cm->set_option( 'twitter_site', $urls['twitter'] );
			}
			if ( isset( $urls['instagram'] ) ) {
				$cm->set_option( 'instagram_url', $urls['instagram'] );
			}
			if ( isset( $urls['linkedin'] ) ) {
				$cm->set_option( 'linkedin_url', $urls['linkedin'] );
			}
			if ( isset( $urls['youtube'] ) ) {
				$cm->set_option( 'youtube_url', $urls['youtube'] );
			}
			if ( isset( $urls['pinterest'] ) ) {
				$cm->set_option( 'pinterest_url', $urls['pinterest'] );
			}
			if ( isset( $urls['mastodon'] ) ) {
				$cm->set_option( 'mastodon_url', $urls['mastodon'] );
			}
			$cm->set_option( 'other_social_urls', $this->merge_other_social_urls( $cm, $urls ) );
		}
		if ( isset( $request['verification'] ) ) {
			$verification = $request['verification'];
			if ( isset( $verification['bing'] ) ) {
				$cm->set_option( 'msverify', $verification['bing'] );
			}
			if ( isset( $verification['google'] ) ) {
				$cm->set_option( 'googleverify', $verification['google'] );
			}
		}
		$response = $this->get_seo_settings();
		return rest_ensure_response( $response );
	}

	/**
	 * Retrieve all settings necessary to render the SEO wizard.
	 *
	 * @return Array with the info.
	 */
	public function get_seo_settings() {
		$cm          = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'wordpress_seo' );
		$other_urls  = $this->get_other_social_urls( $cm );
		$response    = [
			'search_engines_discouraged' => ! get_option( 'blog_public', 1 ),
			'verification'               => [
				'bing'   => $cm->get_option( 'msverify', '' ),
				'google' => $cm->get_option( 'googleverify', '' ),
			],
			'urls'                       => [
				'facebook'  => $cm->get_option( 'facebook_site', '' ),
				'twitter'   => $cm->get_option( 'twitter_site', '' ),
				'instagram' => $cm->get_option( 'instagram_url', '' ),
				'linkedin'  => $cm->get_option( 'linkedin_url', '' ),
				'youtube'   => $cm->get_option( 'youtube_url', '' ),
				'pinterest' => $cm->get_option( 'pinterest_url', '' ),
				'mastodon'  => $cm->get_option( 'mastodon_url', '' ),
			],
		];
		foreach ( array_keys( self::OTHER_SOCIAL_HOSTS ) as $key ) {
			$response['urls'][ $key ] = $this->find_social_url( $other_urls, $key );
		}
		return $response;
	}

	/**
	 * Read Yoast's `other_social_urls` list, tolerating an unconfigured Yoast.
	 *
	 * @param object $cm Yoast configuration manager.
	 * @return string[] List of URLs.
	 */
	private function get_other_social_urls( $cm ) {
		$urls = $cm->get_option( 'other_social_urls', [] );
		return is_array( $urls ) ? array_values( array_filter( $urls, 'is_string' ) ) : [];
	}

	/**
	 * Find the URL in a list that belongs to one of our recognized profiles.
	 *
	 * @param string[] $urls List of URLs.
	 * @param string   $key  Profile key, as in self::OTHER_SOCIAL_HOSTS.
	 * @return string The matching URL, or an empty string.
	 */
	private function find_social_url( $urls, $key ) {
		foreach ( $urls as $url ) {
			if ( in_array( $this->get_host( $url ), self::OTHER_SOCIAL_HOSTS[ $key ], true ) ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Rebuild Yoast's `other_social_urls` with the submitted profiles, leaving URLs
	 * Yoast's own settings screen added in place.
	 *
	 * Yoast validates the list as a unit: one invalid URL reverts every entry, so the
	 * client validates each field before submitting.
	 *
	 * @param object $cm   Yoast configuration manager.
	 * @param array  $urls Submitted URLs, keyed by profile.
	 * @return string[] The list to save.
	 */
	private function merge_other_social_urls( $cm, $urls ) {
		$submitted = array_intersect_key( $urls, self::OTHER_SOCIAL_HOSTS );
		$merged    = [];
		foreach ( $this->get_other_social_urls( $cm ) as $url ) {
			$key = $this->match_profile( $url );
			if ( null === $key ) {
				$merged[] = $url;
			} elseif ( array_key_exists( $key, $submitted ) ) {
				if ( '' !== $submitted[ $key ] ) {
					$merged[] = $submitted[ $key ];
				}
				unset( $submitted[ $key ] );
			}
		}
		foreach ( $submitted as $url ) {
			if ( '' !== $url ) {
				$merged[] = $url;
			}
		}
		return $merged;
	}

	/**
	 * Identify which of our profiles a URL belongs to.
	 *
	 * @param string $url URL to match.
	 * @return string|null Profile key, or null when the URL is not one of ours.
	 */
	private function match_profile( $url ) {
		$host = $this->get_host( $url );
		foreach ( self::OTHER_SOCIAL_HOSTS as $key => $hosts ) {
			if ( in_array( $host, $hosts, true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Normalized host of a URL, without a `www.` prefix.
	 *
	 * @param string $url URL to parse.
	 * @return string Host, lowercased, or an empty string.
	 */
	private function get_host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}
}
