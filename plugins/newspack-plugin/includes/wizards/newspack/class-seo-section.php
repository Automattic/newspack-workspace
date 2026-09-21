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
		'threads' => [ 'threads.com', 'threads.net' ],
		'tiktok'  => [ 'tiktok.com' ],
	];

	/**
	 * Display names for the profiles in self::OTHER_SOCIAL_HOSTS. Brand names, so they
	 * are not translated.
	 *
	 * @var array
	 */
	const OTHER_SOCIAL_LABELS = [
		'bluesky' => 'Bluesky',
		'threads' => 'Threads',
		'tiktok'  => 'TikTok',
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
					'verification' => [
						'type'                 => 'object',
						'additionalProperties' => [ 'type' => 'string' ],
						'validate_callback'    => 'rest_validate_request_arg',
						'sanitize_callback'    => 'rest_sanitize_request_arg',
					],
					'urls'         => [
						'type'                 => 'object',
						'additionalProperties' => [ 'type' => 'string' ],
						'validate_callback'    => 'rest_validate_request_arg',
						'sanitize_callback'    => 'rest_sanitize_request_arg',
					],
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
	 * @return WP_REST_Response|WP_Error with the info.
	 */
	public function api_update_seo_settings( $request ) {
		$cm = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'wordpress_seo' );
		if ( ! $cm->is_configured() ) {
			return new WP_Error(
				'newspack_missing_required_plugin',
				__( 'The Yoast SEO plugin must be installed and activated to save these settings.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$valid = $this->validate_social_hosts( $request['urls'] ?? [] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
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
			$stored = $this->get_other_social_urls( $cm );
			$merged = $this->merge_other_social_urls( $stored, $urls );
			$cm->set_option( 'other_social_urls', $merged );
			if ( $this->social_urls_reverted( $stored, $merged, $this->get_other_social_urls( $cm ) ) ) {
				return new WP_Error(
					'newspack_seo_social_urls_not_saved',
					__( 'The Yoast SEO plugin rejected one of the social profile URLs, so that list was left unchanged. Check the addresses and try again.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
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
			'search_engines_discouraged' => get_option( 'blog_public', 1 ) < 1,
			'verification'               => [
				'bing'   => $this->get_string_option( $cm, 'msverify' ),
				'google' => $this->get_string_option( $cm, 'googleverify' ),
			],
			'urls'                       => [
				'facebook'  => $this->get_string_option( $cm, 'facebook_site' ),
				'twitter'   => $this->get_string_option( $cm, 'twitter_site' ),
				'instagram' => $this->get_string_option( $cm, 'instagram_url' ),
				'linkedin'  => $this->get_string_option( $cm, 'linkedin_url' ),
				'youtube'   => $this->get_string_option( $cm, 'youtube_url' ),
				'pinterest' => $this->get_string_option( $cm, 'pinterest_url' ),
			],
		];
		foreach ( array_keys( self::OTHER_SOCIAL_HOSTS ) as $key ) {
			$response['urls'][ $key ] = $this->find_social_url( $other_urls, $key );
		}
		return $response;
	}

	/**
	 * Read a Yoast option as a string, tolerating an unconfigured Yoast.
	 *
	 * @param object $cm  Yoast configuration manager.
	 * @param string $key Key of the option to return.
	 * @return string The option value, or an empty string.
	 */
	private function get_string_option( $cm, $key ) {
		$value = $cm->get_option( $key, '' );
		return is_string( $value ) ? $value : '';
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
	 * Reject a submitted profile URL whose scheme or host we can't read back.
	 *
	 * @param array $urls Submitted URLs, keyed by profile.
	 * @return true|WP_Error True when every submitted profile is on a known host.
	 */
	private function validate_social_hosts( $urls ) {
		foreach ( array_intersect_key( $urls, self::OTHER_SOCIAL_HOSTS ) as $key => $url ) {
			if ( '' === $url ) {
				continue;
			}
			$host   = $this->get_host( $url );
			$scheme = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ! in_array( $host, self::OTHER_SOCIAL_HOSTS[ $key ], true ) ) {
				return new WP_Error(
					'newspack_seo_invalid_social_url',
					sprintf(
						/* translators: 1: social network name, 2: the host the profile must be on. */
						__( 'The %1$s profile URL must be on %2$s.', 'newspack-plugin' ),
						self::OTHER_SOCIAL_LABELS[ $key ] ?? $key,
						self::OTHER_SOCIAL_HOSTS[ $key ][0]
					),
					[ 'status' => 400 ]
				);
			}
		}
		return true;
	}

	/**
	 * Detect Yoast reverting `other_social_urls` instead of storing what we sent.
	 *
	 * Yoast validates the list as a unit and, when an entry fails, writes the previously
	 * stored entries back over the new ones. A URL it merely normalizes still changes the
	 * stored list, so only an intended change that leaves the list untouched is a revert.
	 *
	 * @param string[] $before List as stored before the write.
	 * @param string[] $merged List handed to Yoast.
	 * @param string[] $after  List as stored after the write.
	 * @return bool True when Yoast kept the previously stored list.
	 */
	private function social_urls_reverted( $before, $merged, $after ) {
		return $merged !== $before && $after === $before;
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
	 * Yoast validates the list as a unit: when one URL fails it writes the previously
	 * stored entries back over the new ones, so an entry past the end of that older list
	 * survives. The client validates each field before submitting.
	 *
	 * Only the first entry on a host is ours to edit, so clearing a field removes that
	 * one and a second entry on the same host becomes what the next read reports.
	 *
	 * @param string[] $stored Yoast's list as currently stored.
	 * @param array    $urls   Submitted URLs, keyed by profile.
	 * @return string[] The list to save.
	 */
	private function merge_other_social_urls( $stored, $urls ) {
		$submitted = array_intersect_key( $urls, self::OTHER_SOCIAL_HOSTS );
		$merged    = [];
		foreach ( $stored as $url ) {
			$key = $this->match_profile( $url );
			if ( null === $key ) {
				$merged[] = $url;
			} elseif ( array_key_exists( $key, $submitted ) ) {
				if ( '' !== $submitted[ $key ] ) {
					$merged[] = $submitted[ $key ];
				}
				unset( $submitted[ $key ] );
			} else {
				$merged[] = $url;
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
