<?php
/**
 * Generate starter content from an existing WP site using its REST API.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Generate starter content from an existing WP site using its REST API.
 */
class Starter_Content_WordPress extends Starter_Content_Provider {

	/**
	 * Option key to store raw scraped data from site for generating starter content.
	 *
	 * @var string
	 */
	private static $existing_content_raw_data_option = '_newspack_existing_content_raw_data';

	/**
	 * Seconds the whole identity import may take, kept under a typical 60-second gateway timeout.
	 *
	 * @var int
	 */
	const IDENTITY_IMPORT_BUDGET = 45;

	/**
	 * Timestamp after which the identity import makes no more requests.
	 *
	 * @var float
	 */
	private static float $identity_deadline = 0.0;

	/**
	 * Initialize starter content data.
	 *
	 * @param array $args Array of info to initialize provider. Needs to contain 'site' with site URL with this one.
	 * @return bool|WP_Error True on success. WP_Error on failure.
	 */
	public static function initialize( $args = [] ) {
		if ( empty( $args['site'] ) || ! wp_http_validate_url( $args['site'] ) ) {
			return new WP_Error(
				'newspack_starter_content',
				sprintf(
					/* translators: %s - Site URL */
					__( 'Invalid site URL: "%s".', 'newspack' ),
					sanitize_text_field( $args['site'] ?? '' )
				)
			);
		}

		$site_url   = trailingslashit( esc_url_raw( $args['site'] ) );
		$wp_api_url = $site_url . 'wp-json/wp/v2/posts?per_page=40';

		$response = wp_safe_remote_get( $wp_api_url, [ 'timeout' => 60 ] ); //phpcs:ignore
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			// Check if the site is a WP site that doesn't have REST API enabled.
			if ( 404 === $response_code && false !== stripos( $response_body, '/wp-content' ) ) {
				return new WP_Error(
					'newspack_starter_content',
					sprintf(
						/* translators: %s - Site URL */
						__( '"%s" is a WordPress site but does not have the REST API enabled.', 'newspack' ),
						sanitize_text_field( $site_url )
					)
				);
			}

			return new WP_Error(
				'newspack_starter_content',
				sprintf(
					/* translators: %s - Site URL */
					__( '"%s" does not appear to be a WordPress site.', 'newspack' ),
					sanitize_text_field( $site_url )
				)
			);
		}

		$response_body = wp_remote_retrieve_body( $response );
		$response_json = json_decode( $response_body, true );
		if ( ! is_array( $response_json ) ) {
			return new WP_Error(
				'newspack_starter_content',
				sprintf(
					/* translators: %s - Site URL */
					__( 'Unable to parse response from "%s".', 'newspack' ),
					sanitize_text_field( $site_url )
				)
			);
		}

		$relevant_info = array_map(
			function( $post_data ) use ( $site_url ) {
				return [
					'title'          => $post_data['title']['rendered'],
					'date'           => $post_data['date'],
					'content'        => $post_data['content']['rendered'],
					'featured_image' => $post_data['featured_media'],
					'site_url'       => $site_url,
				];
			},
			$response_json
		);

		update_option( self::$existing_content_raw_data_option, $relevant_info );
		return true;
	}

	/**
	 * Number of articles fetched from the existing site.
	 *
	 * @return int
	 */
	public static function get_post_count() {
		return count( (array) get_option( self::$existing_content_raw_data_option, [] ) );
	}

	/**
	 * Create the Xth starter post.
	 *
	 * @param int $post_index Index of post to create.
	 * @return int ID of existing post if already created or ID of created post.
	 */
	public static function create_post( $post_index ) {
		$existing_post_id = self::get_starter_post( $post_index );
		if ( $existing_post_id ) {
			return $existing_post_id;
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$existing_content_raw_data = get_option( self::$existing_content_raw_data_option, [] );
		if ( ! $existing_content_raw_data ) {
			return false;
		}

		if ( empty( $existing_content_raw_data[ $post_index ] ) ) {
			return false;
		}

		$raw_post_data = $existing_content_raw_data[ $post_index ];
		$post_data     = [
			'post_title'   => sanitize_text_field( $raw_post_data['title'] ),
			'post_status'  => 'publish',
			'post_content' => wp_kses_post( $raw_post_data['content'] ),
			'post_date'    => sanitize_text_field( $raw_post_data['date'] ),
		];
		$post_id       = wp_insert_post( $post_data );
		if ( ! $post_id ) {
			return false;
		}

		if ( ! empty( $raw_post_data['featured_image'] ) && ! empty( $raw_post_data['site_url'] ) ) {
			$featured_image_id = (int) $raw_post_data['featured_image'];
			if ( $featured_image_id ) {
				$media_endpoint = trailingslashit( esc_url_raw( $raw_post_data['site_url'] ) ) . 'wp-json/wp/v2/media/' . $featured_image_id;
				$media_response = wp_safe_remote_get( $media_endpoint, [ 'timeout' => 60 ] ); //phpcs:ignore
				if ( ! is_wp_error( $media_response ) && 200 === wp_remote_retrieve_response_code( $media_response ) ) {
					$media_response_body = json_decode( wp_remote_retrieve_body( $media_response ), true );
					$media_src           = $media_response_body['source_url'];

					if ( ! function_exists( 'media_sideload_image' ) ) {
						require_once ABSPATH . 'wp-admin/includes/media.php';
						require_once ABSPATH . 'wp-admin/includes/file.php';
						require_once ABSPATH . 'wp-admin/includes/image.php';
					}

					$image_id = media_sideload_image( $media_src, $post_id, null, 'id' );
					if ( is_numeric( $image_id ) ) {
						set_post_thumbnail( $post_id, $image_id );
					}
				}
			}
		}

		self::mark_starter_post( $post_id, $post_index );

		return $post_id;
	}

	/**
	 * Create the homepage.
	 *
	 * @return post_id ID of existing homepage or ID of created homepage.
	 */
	public static function create_homepage() {
		$existing_homepage_id = self::get_starter_homepage();
		if ( $existing_homepage_id ) {
			return $existing_homepage_id;
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		ob_start();
		?>
		<!-- wp:columns {"className":"is-style-borders"} --><div class="wp-block-columns is-style-borders">

			<!-- wp:column {"width":66.66} --><div class="wp-block-column" style="flex-basis:66.66%">

				<!-- wp:newspack-blocks/homepage-articles {"postsToShow":1,"sectionHeader":"<?php echo esc_html__( 'Featured', 'newspack' ); ?>"} /-->

			</div><!-- /wp:column -->
			<!-- wp:column {"width":33.33} --><div class="wp-block-column" style="flex-basis:33.33%">

				<!-- wp:newspack-blocks/homepage-articles {"showExcerpt":false,"imageShape":"square","showAvatar":false,"postsToShow":6,"mediaPosition":"left","typeScale":2,"imageScale":1,"sectionHeader":"<?php echo esc_html__( 'Latest', 'newspack' ); ?>"} /-->

			</div><!-- /wp:column -->

		</div><!-- /wp:columns -->

		<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/><!-- /wp:separator -->

		<!-- wp:columns {"className":"is-style-borders"} --><div class="wp-block-columns is-style-borders">

			<!-- wp:column --><div class="wp-block-column">

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"postsToShow":1,"typeScale":3,"imageScale":1} /-->

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"showAvatar":false,"postsToShow":3,"mediaPosition":"left","typeScale":2,"imageScale":1} /-->

			</div><!-- /wp:column -->

			<!-- wp:column --><div class="wp-block-column">

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"postsToShow":1,"typeScale":3,"imageScale":1} /-->

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showAuthor":false,"showAvatar":false,"postsToShow":3,"mediaPosition":"left","typeScale":2,"imageScale":1} /-->

			</div><!-- /wp:column -->

			<!-- wp:column --><div class="wp-block-column">

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showImage":false,"showAuthor":false,"showAvatar":false,"postsToShow":1,"mediaPosition":"left","imageScale":1} /-->

				<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showImage":false,"showAuthor":false,"showAvatar":false,"postsToShow":4,"mediaPosition":"left","typeScale":3,"imageScale":1} /-->

			</div><!-- /wp:column -->

		</div><!-- /wp:columns -->
		<?php
		$content = ob_get_clean();

		$post_data = [
			'post_title'   => __( 'Homepage', 'newspack-plugin' ),
			'post_type'    => 'page',
			'post_content' => $content,
		];
		$page_id   = wp_insert_post( $post_data );
		wp_publish_post( $page_id );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		set_theme_mod( 'hide_front_page_title', true );
		self::mark_starter_homepage( $page_id );

		return $page_id;
	}

	/**
	 * Seconds a request may take: its own limit, or less once the identity import is near its deadline.
	 *
	 * @param int $limit The request's own timeout.
	 * @return int Seconds, 0 once less than a second is left.
	 */
	private static function identity_timeout( int $limit ): int {
		return (int) min( $limit, max( 0, floor( self::$identity_deadline - microtime( true ) ) ) );
	}

	/**
	 * Drop the cached copy of these options, so the next read sees a value another request saved meanwhile.
	 *
	 * @param string[] $names Option names.
	 */
	private static function forget_cached_options( array $names ): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/**
	 * Fetch JSON from the moved site. Best effort.
	 *
	 * @param string $url URL.
	 * @return array|null Decoded JSON, null on any failure.
	 */
	private static function fetch_json( string $url ): ?array {
		$timeout = self::identity_timeout( 10 );
		if ( ! $timeout ) {
			return null;
		}
		$response = wp_safe_remote_get( $url, [ 'timeout' => $timeout ] ); //phpcs:ignore
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Download an image from the moved site into the media library. Best effort.
	 *
	 * @param string $src Image URL.
	 * @return int Attachment ID, 0 on failure.
	 */
	private static function sideload_image( string $src ): int {
		if ( ! $src || ! wp_http_validate_url( $src ) ) {
			return 0;
		}
		/** This filter is documented in wp-admin/includes/media.php */
		$extensions = apply_filters( 'image_sideload_extensions', [ 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' ], $src );
		if ( ! preg_match( '/[^\?]+\.(' . implode( '|', array_map( 'preg_quote', $extensions ) ) . ')\b/i', $src, $matches ) ) {
			return 0;
		}
		$timeout = self::identity_timeout( 15 );
		if ( ! $timeout ) {
			return 0;
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$tmp_name = download_url( $src, $timeout );
		if ( is_wp_error( $tmp_name ) ) {
			return 0;
		}
		$id = media_handle_sideload(
			[
				'name'     => wp_basename( $matches[0] ),
				'tmp_name' => $tmp_name,
			]
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp_name );
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Get the URL of a media item on the moved site.
	 *
	 * @param string $site_url Site URL with trailing slash.
	 * @param mixed  $media_id Media ID.
	 * @return string URL, empty if not found.
	 */
	private static function get_media_url( string $site_url, $media_id ): string {
		$media_id = (int) $media_id;
		if ( ! $media_id ) {
			return '';
		}
		$media = self::fetch_json( $site_url . 'wp-json/wp/v2/media/' . $media_id . '?_fields=source_url' );
		return ! empty( $media['source_url'] ) && is_string( $media['source_url'] ) ? esc_url_raw( $media['source_url'] ) : '';
	}

	/**
	 * Find the custom logo image on the moved site's homepage.
	 *
	 * @param string $site_url Site URL with trailing slash.
	 * @return string URL, empty if not found.
	 */
	private static function find_custom_logo_url( string $site_url ): string {
		$timeout = self::identity_timeout( 10 );
		if ( ! $timeout ) {
			return '';
		}
		$response = wp_safe_remote_get(
			$site_url,
			[
				'timeout'             => $timeout,
				'limit_response_size' => 512 * KB_IN_BYTES,
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$processor = new \WP_HTML_Tag_Processor( wp_remote_retrieve_body( $response ) );
		while ( $processor->next_tag( 'img' ) ) {
			if ( $processor->has_class( 'custom-logo' ) ) {
				$src = $processor->get_attribute( 'src' );
				return is_string( $src ) && '' !== $src ? esc_url_raw( \WP_Http::make_absolute_url( $src, $site_url ) ) : '';
			}
		}
		return '';
	}

	/**
	 * Import the identity of the moved site: title, tagline, logo, icon and store currency.
	 *
	 * Best effort: any item that can't be fetched in time is skipped. A logo, icon or currency set in the meantime is kept.
	 *
	 * @param string $site_url URL of the moved site.
	 * @return array Map of item to whether it was imported.
	 */
	public static function import_identity( string $site_url ): array {
		$result = [
			'title'    => false,
			'tagline'  => false,
			'logo'     => false,
			'icon'     => false,
			'currency' => false,
		];
		if ( ! $site_url ) {
			return $result;
		}
		self::$identity_deadline = microtime( true ) + self::IDENTITY_IMPORT_BUDGET;

		$site_url = trailingslashit( esc_url_raw( $site_url ) );
		$index    = self::fetch_json( $site_url . 'wp-json/?_fields=name,description,site_logo,site_icon' );

		if ( ! empty( $index['name'] ) && is_string( $index['name'] ) ) {
			update_option( 'blogname', sanitize_text_field( $index['name'] ) );
			$result['title'] = true;
		}
		if ( ! empty( $index['description'] ) && is_string( $index['description'] ) ) {
			update_option( 'blogdescription', sanitize_text_field( $index['description'] ) );
			$result['tagline'] = true;
		}

		if ( ! get_theme_mod( 'custom_logo' ) ) {
			$logo_url = self::get_media_url( $site_url, $index['site_logo'] ?? 0 );
			if ( ! $logo_url ) {
				$logo_url = self::find_custom_logo_url( $site_url );
			}
			$logo_id = self::sideload_image( $logo_url );
			if ( $logo_id ) {
				self::forget_cached_options( [ 'theme_mods_' . get_option( 'stylesheet' ) ] );
				if ( get_theme_mod( 'custom_logo' ) ) {
					wp_delete_attachment( $logo_id, true );
				} else {
					set_theme_mod( 'custom_logo', $logo_id );
					set_theme_mod( 'header_text', false );
					set_theme_mod( 'header_display_tagline', false );
					$result['logo'] = true;
				}
			}
		}

		if ( ! get_option( 'site_icon' ) ) {
			$icon_id = self::sideload_image( self::get_media_url( $site_url, $index['site_icon'] ?? 0 ) );
			if ( $icon_id ) {
				self::forget_cached_options( [ 'site_icon' ] );
				if ( get_option( 'site_icon' ) ) {
					wp_delete_attachment( $icon_id, true );
				} else {
					update_option( 'site_icon', $icon_id );
					$result['icon'] = true;
				}
			}
		}

		if ( ! get_option( 'woocommerce_currency' ) ) {
			$cart     = self::fetch_json( $site_url . 'wp-json/wc/store/v1/cart' );
			$currency = $cart['totals']['currency_code'] ?? '';
			if ( is_string( $currency ) && in_array( $currency, wp_list_pluck( newspack_get_currencies_options(), 'value' ), true ) ) {
				self::forget_cached_options( [ 'woocommerce_currency' ] );
				if ( ! get_option( 'woocommerce_currency' ) ) {
					update_option( 'woocommerce_currency', $currency );
					update_option( Starter_Content::IMPORTED_CURRENCY_OPTION, $currency, false );
					$result['currency'] = true;
				}
			}
		}

		return $result;
	}
}
