<?php
/**
 * Newspack Network Content Distribution: Outgoing Post.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Utils\Network;
use WP_Error;
use WP_Post;

/**
 * Outgoing Post Class.
 */
class Outgoing_Post {
	/**
	 * The post meta key for the sites the post is distributed to.
	 */
	const DISTRIBUTED_POST_META = 'newspack_network_distributed_sites';

	/**
	 * The post object.
	 *
	 * @var WP_Post
	 */
	protected $post = null;

	/**
	 * Constructor.
	 *
	 * @param WP_Post|int $post The post object or post ID.
	 *
	 * @throws \InvalidArgumentException If the post is invalid.
	 */
	public function __construct( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || empty( $post->ID ) ) {
			throw new \InvalidArgumentException( esc_html( __( 'Invalid post.', 'newspack-network' ) ) );
		}

		if ( ! in_array( $post->post_type, Content_Distribution_Class::get_distributed_post_types() ) ) {
			/* translators: unsupported post type for content distribution */
			throw new \InvalidArgumentException( esc_html( sprintf( __( 'Post type %s is not supported as a distributed outgoing post.', 'newspack-network' ), $post->post_type ) ) );
		}

		$this->post = $post;
	}

	/**
	 * Gets the user data of a WP user to be distributed along with the post.
	 *
	 * @param int|WP_Post $user The user ID or object.
	 *
	 * @return WP_Error|array
	 */
	public static function get_outgoing_wp_user_author( $user ) {
		if ( ! is_a( $user, 'WP_User' ) ) {
			$user = get_user_by( 'ID', $user );
		}

		if ( ! $user ) {
			return new WP_Error( 'Error getting WP User details for distribution. Invalid User' );
		}

		$author = [
			'type' => 'wp_user',
			'ID'   => $user->ID,
		];

		foreach ( User_Update_Watcher::$user_props as $prop ) {
			if ( ! empty( $user->$prop ) ) {
				$author[ $prop ] = $user->$prop;
			}
		}

		// CoAuthors' guest authors have a 'website' property.
		if ( ! empty( $user->website ) ) {
			$author['website'] = $user->website;
		}

		foreach ( User_Update_Watcher::$watched_meta as $meta_key ) {
			$value = get_user_meta( $user->ID, $meta_key, true );
			if ( ! empty( $value ) ) {
				$author[ $meta_key ] = $value;
			}
		}

		return $author;
	}

	/**
	 * Get the post object.
	 *
	 * @return WP_Post The post object.
	 */
	public function get_post() {
		return $this->post;
	}

	/**
	 * Get network post ID.
	 *
	 * @return string The network post ID.
	 */
	public function get_network_post_id() {
		$site_hash = get_option( 'newspack_network_content_distribution_hash' );
		if ( ! $site_hash ) {
			$site_hash = md5( get_bloginfo( 'url' ) );
			update_option( 'newspack_network_content_distribution_hash', $site_hash );
		}
		return md5( $site_hash . $this->post->ID );
	}

	/**
	 * Validate URLs for distribution.
	 *
	 * @param string[] $urls Array of site URLs to distribute the post to.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function validate_distribution( $urls ) {
		if ( in_array( get_bloginfo( 'url' ), $urls, true ) ) {
			return new WP_Error( 'no_own_site', __( 'Cannot distribute to own site.', 'newspack-network' ) );
		}

		$urls_not_in_network = Network::get_non_networked_urls_from_list( $urls );
		if ( ! empty( $urls_not_in_network ) ) {
			return new WP_Error(
				'non_networked_urls',
				sprintf(
					/* translators: %s: list of non-networked URLs */
					__( 'Non-networked URLs were passed to config: %s', 'newspack-network' ),
					implode( ', ', $urls_not_in_network )
				)
			);
		}

		return true;
	}

	/**
	 * Set the distribution configuration for a given post.
	 *
	 * @param int[] $site_urls Array of site URLs to distribute the post to.
	 *
	 * @return array|WP_Error Config array on success, WP_Error on failure.
	 */
	public function set_distribution( $site_urls ) {
		if ( empty( $site_urls ) ) {
			return new WP_Error( 'no_site_urls', __( 'No site URLs provided.', 'newspack-network' ) );
		}

		$error = self::validate_distribution( $site_urls );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$distribution = get_post_meta( $this->post->ID, self::DISTRIBUTED_POST_META, true );
		if ( ! is_array( $distribution ) ) {
			$distribution = [];
		}

		// If there are urls not already in the config, add them. Note that we don't support
		// removing urls from the config.
		$distribution = array_unique( array_merge( $distribution, $site_urls ) );

		$updated = update_post_meta( $this->post->ID, self::DISTRIBUTED_POST_META, $distribution );

		if ( ! $updated ) {
			return new WP_Error( 'update_failed', __( 'Failed to update post distribution.', 'newspack-network' ) );
		}

		return $distribution;
	}

	/**
	 * Remove a site URL from the distribution configuration for a given post.
	 *
	 * @param string $site_url The site URL to remove.
	 *
	 * @return array|WP_Error Config array on success, WP_Error on failure.
	 */
	public function remove_distribution( $site_url ) {
		$distribution = get_post_meta( $this->post->ID, self::DISTRIBUTED_POST_META, true );
		if ( ! is_array( $distribution ) ) {
			$distribution = [];
		}

		$index = array_search( $site_url, $distribution, true );
		if ( false === $index ) {
			return new WP_Error( 'site_not_found', __( 'Site URL not found in post distribution.', 'newspack-network' ) );
		}

		unset( $distribution[ $index ] );

		// Add a meta to allow bypassing the short circuit validation.
		update_post_meta( $this->post->ID, 'newspack_network_remove_distribution', $site_url );

		$updated = update_post_meta( $this->post->ID, self::DISTRIBUTED_POST_META, array_values( $distribution ) );

		if ( ! $updated ) {
			return new WP_Error( 'update_failed', __( 'Failed to update post distribution.', 'newspack-network' ) );
		}

		return $distribution;
	}

	/**
	 * Whether the post is distributed. Optionally provide a $site_url to check if
	 * the post is distributed to that site.
	 *
	 * @param string|null $site_url Optional site URL.
	 *
	 * @return bool
	 */
	public function is_distributed( $site_url = null ) {
		$distributed_post_types = Content_Distribution_Class::get_distributed_post_types();
		if ( ! in_array( $this->post->post_type, $distributed_post_types, true ) ) {
			return false;
		}

		$distribution = $this->get_distribution();
		if ( empty( $distribution ) ) {
			return false;
		}

		if ( ! empty( $site_url ) ) {
			return in_array( $site_url, $distribution, true );
		}

		return true;
	}

	/**
	 * Get the sites the post is distributed to.
	 *
	 * @return array The distribution configuration.
	 */
	public function get_distribution() {
		$config = get_post_meta( $this->post->ID, self::DISTRIBUTED_POST_META, true );
		if ( ! is_array( $config ) ) {
			$config = [];
		}
		return $config;
	}

	/**
	 * Get the payload hash.
	 *
	 * @param array|null $payload Optional payload to hash.
	 *
	 * @return string The payload hash.
	 */
	public function get_payload_hash( $payload = null ) {
		if ( empty( $payload ) ) {
			$payload = $this->get_payload();
		}
		unset( $payload['status_on_publish'] );
		unset( $payload['post_data']['date_gmt'] );
		unset( $payload['post_data']['modified_gmt'] );
		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * Get the post payload for distribution.
	 *
	 * @param string $status_on_publish The post status when creating the post.
	 *
	 * @return array|WP_Error The post payload or WP_Error if the post is invalid.
	 */
	public function get_payload( $status_on_publish = 'draft' ) {
		$post_author = self::get_outgoing_wp_user_author( $this->post->post_author );

		$payload = [
			'site_url'          => get_bloginfo( 'url' ),
			'post_id'           => $this->post->ID,
			'post_url'          => get_permalink( $this->post->ID ),
			'network_post_id'   => $this->get_network_post_id(),
			'sites'             => $this->get_distribution(),
			'status_on_publish' => $status_on_publish,
			'post_data'         => [
				'title'          => html_entity_decode( get_the_title( $this->post->ID ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'author'         => is_wp_error( $post_author ) ? [] : $post_author,
				'post_status'    => $this->post->post_status,
				'date_gmt'       => $this->post->post_date_gmt,
				'modified_gmt'   => $this->post->post_modified_gmt,
				'slug'           => $this->post->post_name,
				'post_type'      => $this->post->post_type,
				'raw_content'    => $this->get_raw_post_content(),
				'content'        => $this->get_processed_post_content(),
				'excerpt'        => $this->post->post_excerpt,
				'comment_status' => $this->post->comment_status,
				'ping_status'    => $this->post->ping_status,
				'taxonomy'       => $this->get_post_taxonomy_terms(),
				'thumbnail_url'  => $this->get_post_thumbnail_url(),
				'post_meta'      => $this->get_post_meta(),
				'media_data'     => $this->get_post_media_data(),
			],
		];

		/**
		 * Filter the payload's post_data to let others add to it.
		 *
		 * @param array   $post_data The post_data to filter
		 * @param WP_Post $post      The post object for the outgoing post.
		 */
		$payload['post_data'] = apply_filters( 'newspack_network_outgoing_payload_post_data', $payload['post_data'], $this->post );

		return $payload;
	}

	/**
	 * Get a partial payload for distribution.
	 *
	 * @param string[] $post_data_keys Keys in the post_data array to include in
	 *                                 the partial payload.
	 *
	 * @return array|WP_Error The partial payload or WP_Error if any of the keys were not found.
	 */
	public function get_partial_payload( $post_data_keys ) {
		if ( is_string( $post_data_keys ) ) {
			$post_data_keys = [ $post_data_keys ];
		}

		$payload = $this->get_payload();
		foreach ( $post_data_keys as $post_data_key ) {
			if ( ! isset( $payload['post_data'][ $post_data_key ] ) ) {
				return new WP_Error( 'key_not_found', __( 'Key not found in payload.', 'newspack-network' ) );
			}
		}

		// Mark the payload as partial.
		$payload['partial'] = true;

		$post_data = [];
		foreach ( $post_data_keys as $post_data_key ) {
			$post_data[ $post_data_key ] = $payload['post_data'][ $post_data_key ];
		}

		// Always add the date and modified date to the partial payload.
		$post_data['date_gmt']     = $payload['post_data']['date_gmt'];
		$post_data['modified_gmt'] = $payload['post_data']['modified_gmt'];

		$payload['post_data'] = $post_data;

		return $payload;
	}

	/**
	 * Get the raw post content for distribution.
	 *
	 * @return string The raw post content.
	 */
	protected function get_raw_post_content() {
		if ( ! use_block_editor_for_post_type( $this->post->post_type ) ) {
			return $this->post->post_content;
		}

		if ( ! has_blocks( $this->post->post_content ) ) {
			return $this->post->post_content;
		}

		$blocks = array_map(
			[ Blocks::class, 'process_outgoing_block' ],
			parse_blocks( $this->post->post_content )
		);

		return serialize_blocks( $blocks );
	}

	/**
	 * Get the processed post content for distribution.
	 *
	 * @return string The post content.
	 */
	protected function get_processed_post_content() {
		global $wp_embed;
		/**
		 * Remove autoembed filter so that actual URL will be pushed and not the generated markup.
		 */
		remove_filter( 'the_content', [ $wp_embed, 'autoembed' ], 8 );
		// Filter documented in WordPress core.
		$post_content = apply_filters( 'the_content', $this->get_raw_post_content() );
		add_filter( 'the_content', [ $wp_embed, 'autoembed' ], 8 );
		return $post_content;
	}

	/**
	 * Get post taxonomy terms for distribution.
	 *
	 * @return array The taxonomy term data.
	 */
	protected function get_post_taxonomy_terms() {
		return Taxonomy_Terms::get_post_taxonomy_terms( $this->post );
	}

	/**
	 * Get the post thumbnail URL.
	 *
	 * @return string The post thumbnail URL.
	 */
	protected function get_post_thumbnail_url() {
		add_filter( 'jetpack_photon_override_image_downsize', '__return_true' );
		$thumbnail_url = get_the_post_thumbnail_url( $this->post->ID, 'full' );
		remove_filter( 'jetpack_photon_override_image_downsize', '__return_true' );
		if ( ! $thumbnail_url ) {
			return '';
		}
		return $thumbnail_url;
	}

	/**
	 * Get post meta for distribution.
	 *
	 * @return array The post meta data.
	 */
	protected function get_post_meta() {
		$ignored_keys = Content_Distribution_Class::get_ignored_post_meta_keys();

		$meta = get_post_meta( $this->post->ID );

		if ( empty( $meta ) ) {
			return [];
		}

		$meta = array_filter(
			$meta,
			function( $value, $key ) use ( $ignored_keys ) {
				// Filter out ignored keys.
				return ! in_array( $key, $ignored_keys, true );
			},
			ARRAY_FILTER_USE_BOTH
		);

		// Unserialize meta values and reformat the array.
		$meta = array_reduce(
			array_keys( $meta ),
			function( $carry, $key ) use ( $meta ) {
				$carry[ $key ] = array_map(
					function( $v ) {
						return maybe_unserialize( $v );
					},
					$meta[ $key ]
				);
				return $carry;
			},
			[]
		);

		/**
		 * Filters the post meta data for distribution.
		 *
		 * @param array   $meta The post meta data.
		 * @param WP_Post $post The post object.
		 */
		return apply_filters( 'newspack_network_distributed_post_meta', $meta, $this->post );
	}

	/**
	 * Get the post attachment data for distribution.
	 *
	 * @return array The post attachment data.
	 */
	protected function get_post_media_data() {
		$attachment_data = [];

		add_filter( 'jetpack_photon_override_image_downsize', '__return_true' );

		$thumbnail_id = get_post_thumbnail_id( $this->post->ID );
		if ( $thumbnail_id ) {
			$metadata = wp_get_attachment_metadata( $thumbnail_id );
			$attachment_data[ $thumbnail_id ] = [
				'title'       => get_the_title( $thumbnail_id ),
				'description' => get_the_content( null, false, $thumbnail_id ),
				'url'         => wp_get_attachment_url( $thumbnail_id ),
				'metadata'    => $metadata,
				'srcset'      => wp_get_attachment_image_srcset( $thumbnail_id ),
				'width'       => $metadata['width'] ?? 'none',
				'height'      => $metadata['height'] ?? 'none',
				'caption'     => wp_get_attachment_caption( $thumbnail_id ),
				'credit'      => get_post_meta( $thumbnail_id, '_media_credit', true ),
				'credit_url'  => get_post_meta( $thumbnail_id, '_media_credit_url', true ),
				'alt'         => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
				'featured'    => true,
			];
		}

		$content     = apply_filters( 'the_content', get_the_content( null, false, get_post( $this->post->ID ) ) );
		$attachments = self::get_content_attachments( $content );
		foreach ( $attachments as $attachment ) {
			if ( isset( $attachment_data[ $attachment->ID ] ) ) {
				continue;
			}
			$metadata = wp_get_attachment_metadata( $attachment->ID );
			$attachment_data[ $attachment->ID ] = [
				'title'       => get_the_title( $attachment->ID ),
				'description' => get_the_content( null, false, $attachment->ID ),
				'url'         => wp_get_attachment_url( $attachment->ID ),
				'metadata'    => $metadata,
				'srcset'      => wp_get_attachment_image_srcset( $attachment->ID ),
				'width'       => $metadata['width'] ?? 'none',
				'height'      => $metadata['height'] ?? 'none',
				'caption'     => wp_get_attachment_caption( $attachment->ID ),
				'credit'      => get_post_meta( $attachment->ID, '_media_credit', true ),
				'credit_url'  => get_post_meta( $attachment->ID, '_media_credit_url', true ),
				'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'featured'    => false,
			];
		}

		remove_filter( 'jetpack_photon_override_image_downsize', '__return_true' );
		return $attachment_data;
	}

	/**
	 * Get the attachments given a content string.
	 *
	 * @param string $content The content to search for attachment posts.
	 *
	 * @return WP_Post[] The attachment posts.
	 */
	public static function get_content_attachments( $content ) {
		$pattern = '/<img[^>]+src="([^"]+)"[^>]*>/i';
		preg_match_all( $pattern, $content, $matches );

		if ( empty( $matches ) ) {
			return [];
		}

		$attachment_ids = [];
		foreach ( $matches[0] as $image_tag ) {
			$attachment_id = null;
			if ( preg_match( '/wp-image-(\d+)/i', $image_tag, $m ) ) {
				$attachment_id = $m[1];
			} elseif ( preg_match( '/data-attachment-id="(\d+)"/i', $image_tag, $m ) ) {
				$attachment_id = $m[1];
			} elseif ( preg_match( '/data-id="(\d+)"/i', $image_tag, $m ) ) {
				$attachment_id = $m[1];
			} elseif ( preg_match( '/id="(\d+)"/i', $image_tag, $m ) ) {
				$attachment_id = $m[1];
			}
			if ( empty( $attachment_id ) ) {
				continue;
			}
			$attachment = get_post( $attachment_id );
			if ( ! $attachment ) {
				continue;
			}
			$attachment_ids[] = $attachment;
		}
		return $attachment_ids;
	}
}
