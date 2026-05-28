<?php
/**
 * Newspack Network Content Distribution: Linked Post.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Debugger;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Utils\Users as User_Utils;
use WP_Error;
use WP_Post;

/**
 * Incoming Post Class.
 */
class Incoming_Post {
	/**
	 * Post meta key containing the outgoing post ID that is unique accross the
	 * network.
	 */
	const NETWORK_POST_ID_META = 'newspack_network_post_id';

	/**
	 * Post meta key containing the outgoing post full payload.
	 */
	const PAYLOAD_META = 'newspack_network_post_payload';

	/**
	 * Post meta key to determine whether the post is unlinked.
	 */
	const UNLINKED_META = 'newspack_network_post_unlinked';

	/**
	 * Post meta key for linked attachments.
	 */
	const ATTACHMENT_META = 'newspack_network_linked_attachment';

	/**
	 * Post meta key for `status_on_publish`.
	 */
	const STATUS_ON_PUBLISH_META = '_newspack_network_status_on_publish';

	/**
	 * The post ID.
	 *
	 * @var int
	 */
	public $ID = 0;

	/**
	 * The network post ID.
	 *
	 * @var string
	 */
	public $network_post_id = '';

	/**
	 * The post object.
	 *
	 * @var WP_Post
	 */
	private $post = null;

	/**
	 * The outgoing post payload.
	 *
	 * @var array
	 */
	private $payload = [];

	/**
	 * Constructor.
	 *
	 * @param int|array $payload The outgoing post payload or post ID.
	 *
	 * @throws \InvalidArgumentException If the payload is invalid or the post is
	 *                                   not configured for distribution.
	 */
	public function __construct( $payload ) {
		$post = null;

		if ( is_numeric( $payload ) ) {
			$post    = get_post( $payload );
			$payload = get_post_meta( $payload, self::PAYLOAD_META, true );
		}

		$error = self::get_payload_error( $payload );

		if ( is_wp_error( $error ) ) {
			throw new \InvalidArgumentException( esc_html( $error->get_error_message() ) );
		}

		$this->payload         = $payload;
		$this->network_post_id = $payload['network_post_id'];

		if ( ! $post ) {
			$post = $this->query_post();
		}

		if ( $post ) {
			$this->ID   = $post->ID;
			$this->post = $post;
		}

		// Handle partial payload.
		if ( ! empty( $payload['partial'] ) ) {
			$payload = $this->get_payload_from_partial( $payload );
			if ( is_wp_error( $payload ) ) {
				throw new \InvalidArgumentException( esc_html( $payload->get_error_message() ) );
			}
			$this->payload = $payload;
		}
	}

	/**
	 * Transform a distributed author into a WP_User object.
	 *
	 * @param string $remote_url The remote site URL.
	 * @param array  $author     The distributed authors array.
	 *
	 * @return \WP_User|\WP_Error The user object or false on failure.
	 */
	public static function get_incoming_wp_user_author( string $remote_url, array $author ) {

		if ( empty( $author['user_email'] ) ) {
			return new WP_Error( 'missing_email', __( 'Missing email on incoming author creation', 'newspack-network' ) );
		}

		User_Update_Watcher::$enabled = false;

		$insert_array = [
			'role' => 'author',
		];

		foreach ( User_Update_Watcher::$user_props as $prop ) {
			if ( isset( $author[ $prop ] ) ) {
				$insert_array[ $prop ] = $author[ $prop ];
			}
		}

		$user = User_Utils::get_or_create_user_by_email(
			$author['user_email'],
			$remote_url,
			$author['ID'],
			$insert_array
		);

		if ( is_wp_error( $user ) ) {
			Debugger::log( 'Error creating user: ' . $user->get_error_message() );

			return $user;
		}

		foreach ( User_Update_Watcher::get_writable_meta() as $meta_key ) {
			if ( isset( $author[ $meta_key ] ) ) {
				update_user_meta( $user->ID, $meta_key, $author[ $meta_key ] );
			}
		}

		User_Utils::maybe_sideload_avatar( $user->ID, $author, false );

		return $user;
	}

	/**
	 * Log a message.
	 *
	 * If "newspack_log" is available, we'll use it. Otherwise, we'll fallback to
	 * the Network's debugger.
	 *
	 * @param string $message The message to log.
	 * @param string $type    The log type. Either 'error' or 'debug'.
	 *                        Default is 'error'.
	 *
	 * @return void
	 */
	protected function log( $message, $type = 'error' ) {
		if ( method_exists( 'Newspack\Logger', 'newspack_log' ) ) {
			\Newspack\Logger::newspack_log(
				'newspack_network_incoming_post',
				$message,
				[
					'network_post_id' => $this->network_post_id,
					'post_id'         => $this->ID,
					'payload'         => $this->payload,
				],
				$type
			);
		} else {
			$prefix = '[Incoming Post]';
			if ( ! empty( $this->payload ) ) {
				$prefix .= ' ' . $this->payload['network_post_id'];
			}
			Debugger::log( $prefix . ' ' . $message );
		}
	}

	/**
	 * Validate a payload.
	 *
	 * @param array $payload The payload to validate.
	 *
	 * @return WP_Error|null WP_Error if the payload is invalid, null otherwise.
	 */
	public static function get_payload_error( $payload ) {
		if (
			! is_array( $payload ) ||
			empty( $payload['post_id'] ) ||
			empty( $payload['network_post_id'] ) ||
			empty( $payload['sites'] ) ||
			empty( $payload['post_data'] )
		) {
			return new WP_Error( 'invalid_post', __( 'Invalid post payload.', 'newspack-network' ) );
		}

		if ( empty( $payload['sites'] ) ) {
			return new WP_Error( 'not_distributed', __( 'Post is not configured for distribution.', 'newspack-network' ) );
		}

		$site_url = get_bloginfo( 'url' );
		if ( ! in_array( $site_url, $payload['sites'], true ) ) {
			return new WP_Error( 'not_distributed_to_site', __( 'Post is not configured for distribution on this site.', 'newspack-network' ) );
		}
	}

	/**
	 * Get the stored payload for a post.
	 *
	 * @return array The stored payload.
	 */
	public function get_post_payload() {
		if ( ! $this->ID ) {
			return [];
		}

		return get_post_meta( $this->ID, self::PAYLOAD_META, true );
	}

	/**
	 * Get payload from partial.
	 *
	 * @param array $payload The partial payload.
	 *
	 * @return array|WP_Error The full payload or WP_Error on failure.
	 */
	protected function get_payload_from_partial( $payload ) {
		if ( ! $this->ID ) {
			return new WP_Error( 'missing_post', __( 'Partial payload requires an existing post.', 'newspack-network' ) );
		}

		$current_payload       = $this->get_post_payload();
		$current_payload_error = self::get_payload_error( $current_payload );
		if ( is_wp_error( $current_payload_error ) ) {
			return $current_payload_error;
		}

		$payload['post_data'] = array_merge( $current_payload['post_data'], $payload['post_data'] );

		unset( $payload['partial'] );

		return $payload;
	}

	/**
	 * Get the post's original site URL.
	 *
	 * @return string The post original site URL or an empty string if not found.
	 */
	public function get_original_site_url(): string {
		return $this->payload['site_url'] ?? '';
	}

	/**
	 * Get the post original URL.
	 *
	 * @return string The post original post URL. Empty string if not found.
	 */
	public function get_original_post_url() {
		return $this->payload['post_url'] ?? '';
	}

	/**
	 * Get the original post edit URL.
	 *
	 * @return string The post original edit URL. Empty string if not found.
	 */
	public function get_original_post_edit_url() {
		if ( empty( $this->payload['site_url'] ) ) {
			return '';
		}
		return sprintf( '%s/wp-admin/post.php?post=%d&action=edit', $this->payload['site_url'], $this->payload['post_id'] );
	}

	/**
	 * Find the post from the payload's network post ID.
	 *
	 * @return WP_Post|null The post or null if not found.
	 */
	protected function query_post() {
		$posts = get_posts(
			[
				'post_type'      => Content_Distribution_Class::get_distributed_post_types(),
				'post_status'    => [
					'publish',
					'pending',
					'draft',
					'auto-draft',
					'future',
					'private',
					'inherit',
					'trash',
				],
				'posts_per_page' => 1,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => self::NETWORK_POST_ID_META,
						'value' => $this->network_post_id,
					],
				],
			]
		);
		if ( empty( $posts ) ) {
			return null;
		}

		return $posts[0];
	}

	/**
	 * Get the post.
	 *
	 * @return WP_Post|null The post or null if not found.
	 */
	public function get_post() {
		return $this->post;
	}

	/**
	 * Set a post as unlinked.
	 *
	 * This will prevent the post from being updated when the distributed post is
	 * updated.
	 *
	 * @param bool $unlinked Whether to set the post as unlinked. Default is true.
	 *
	 * @return void|WP_Error Void on success, WP_Error on failure.
	 */
	public function set_unlinked( $unlinked = true ) {
		if ( ! $this->ID ) {
			return new WP_Error( 'invalid_post', __( 'Invalid post.', 'newspack-network' ) );
		}
		update_post_meta( $this->ID, self::UNLINKED_META, $unlinked ? 1 : 0 );

		// If the post is being re-linked, update content.
		if ( ! $unlinked ) {
			self::insert();
		}
	}

	/**
	 * Whether a post is unlinked.
	 *
	 * @return bool
	 */
	protected function is_unlinked(): bool {
		return (bool) get_post_meta( $this->ID, self::UNLINKED_META, true );
	}

	/**
	 * Whether a post is linked.
	 *
	 * This helper method is to improve readability.
	 *
	 * @return bool
	 */
	public function is_linked(): bool {
		return $this->ID && ! $this->is_unlinked();
	}

	/**
	 * Update the post meta for a linked post.
	 *
	 * This will delete existing post meta that are not in the payload and update
	 * the existing post meta.
	 *
	 * Ignored keys will not be managed, meaning they will not be deleted or
	 * updated.
	 *
	 * @return void
	 */
	protected function update_meta() {
		$data = $this->payload['post_data']['post_meta'];

		$ignored_keys = Content_Distribution_Class::get_ignored_post_meta_keys();

		// Delete existing post meta that are not in the payload.
		$post_meta = get_post_meta( $this->ID );
		foreach ( $post_meta as $meta_key => $meta_value ) {
			if (
				! in_array( $meta_key, $ignored_keys, true ) &&
				! array_key_exists( $meta_key, $data )
			) {
				delete_post_meta( $this->ID, $meta_key );
			}
		}

		if ( empty( $data ) ) {
			return;
		}

		foreach ( $data as $meta_key => $meta_value ) {
			if ( ! in_array( $meta_key, $ignored_keys, true ) ) {
				if ( 1 === count( $meta_value ) ) {
					update_post_meta( $this->ID, $meta_key, $meta_value[0] );
				} else {
					$value = get_post_meta( $this->ID, $meta_key, false );
					if ( $value !== $meta_value ) {
						delete_post_meta( $this->ID, $meta_key );
						foreach ( $meta_value as $item ) {
							add_post_meta( $this->ID, $meta_key, $item );
						}
					}
				}
			}
		}
	}

	/**
	 * Upload the thumbnail for a linked post.
	 */
	protected function upload_thumbnail() {
		$thumbnail_url         = $this->payload['post_data']['thumbnail_url'];
		$payload               = $this->get_post_payload();
		$current_thumbnail_id  = get_post_thumbnail_id( $this->ID );
		$current_thumbnail_url = $payload ? $payload['post_data']['thumbnail_url'] : '';

		// Bail if the post has a thumbnail and the thumbnail URL is the same.
		if (
			$current_thumbnail_id &&
			$payload &&
			$current_thumbnail_url === $thumbnail_url
		) {
			return;
		}

		// Handle Jetpack Photon URLs so we can compare the underlying image URLs.
		$photon_pattern = '/^https:\/\/i[0-9]\.wp\.com\/([^?]+)(\?.*)?$/';
		if ( preg_match( $photon_pattern, $thumbnail_url ) || preg_match( $photon_pattern, $current_thumbnail_url ) ) {
			$strip_photon = function( $url ) use ( $photon_pattern ) {
				if ( preg_match( $photon_pattern, $url, $matches ) ) {
					return 'https://' . $matches[1];
				}
				return $url;
			};
			if ( $strip_photon( $thumbnail_url ) === $strip_photon( $current_thumbnail_url ) ) {
				return;
			}
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $thumbnail_url, $this->ID, '', 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			self::log( 'Failed to upload featured image for post ' . $this->ID . ' with message: ' . $attachment_id->get_error_message() );

			return;
		}

		/**
		 * Update the attachment post meta.
		 */
		$media_data = $this->payload['post_data']['media_data'];
		if ( ! empty( $media_data ) ) {
			$meta = array_filter(
				$media_data,
				function( $media_item ) {
					return $media_item['featured'];
				}
			);
			if ( ! empty( $meta ) ) {
				$meta = array_shift( $meta );
				if ( ! empty( $meta['caption'] ) ) {
					wp_update_post(
						[
							'ID'           => $attachment_id,
							'post_excerpt' => $meta['caption'],
						]
					);
				}
				if ( ! empty( $meta['alt'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $meta['alt'] );
				}
				if ( ! empty( $meta['credit'] ) ) {
					update_post_meta( $attachment_id, '_media_credit', $meta['credit'] );
				}
				if ( ! empty( $meta['credit_url'] ) ) {
					update_post_meta( $attachment_id, '_media_credit_url', $meta['credit_url'] );
				}
			}
		}
		update_post_meta( $attachment_id, self::ATTACHMENT_META, true );

		set_post_thumbnail( $this->ID, $attachment_id );
	}

	/**
	 * Update the taxonomy terms for a linked post.
	 *
	 * @return void
	 */
	protected function update_taxonomy_terms() {
		$ignored_taxonomies = Taxonomy_Terms::get_ignored_taxonomies();
		$data               = $this->payload['post_data']['taxonomy'];
		foreach ( $data as $taxonomy => $terms ) {
			if ( in_array( $taxonomy, $ignored_taxonomies, true ) ) {
				continue;
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$term_ids = Taxonomy_Terms::get_or_create_term_ids( $terms, $taxonomy );

			if ( is_wp_error( $term_ids ) ) {
				self::log( 'Failed to get or create term IDs for taxonomy ' . $taxonomy . ' with message: ' . $term_ids->get_error_message() );
				continue;
			}
			if ( $term_ids ) {
				$result = wp_set_object_terms( $this->ID, $term_ids, $taxonomy );
				if ( is_wp_error( $result ) ) {
					self::log( 'Failed to set terms for taxonomy ' . $taxonomy . ' with message: ' . $result->get_error_message() );
				}
			} elseif ( empty( $terms ) ) {
				// If there are no terms, remove all terms from the taxonomy.
				wp_set_object_terms( $this->ID, [], $taxonomy );
			}
		}
	}

	/**
	 * Update the object payload.
	 *
	 * @param array $payload The payload to update the object with.
	 *
	 * @return WP_Error|void WP_Error on failure.
	 */
	protected function update_payload( $payload ) {
		$error = self::get_payload_error( $payload );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		// Do not update if network post ID mismatches.
		if ( $this->network_post_id !== $payload['network_post_id'] ) {
			return new WP_Error( 'mismatched_post_id', __( 'Mismatched post ID.', 'newspack-network' ) );
		}

		// Handle partial payload.
		if ( ! empty( $payload['partial'] ) ) {
			$payload = $this->get_payload_from_partial( $payload );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
		}

		$this->payload = $payload;
	}

	/**
	 * Handle the distributed post deletion.
	 *
	 * If the post is linked, it'll be trashed.
	 *
	 * The distributed post payload will be removed so the unlinked post can be
	 * treated as a regular standalone post.
	 *
	 * We'll keep the network post ID and unlinked meta in case the original post
	 * gets restored from a backup.
	 *
	 * @return void
	 */
	public function delete() {
		// Bail if there's no post to delete.
		if ( ! $this->ID ) {
			return;
		}
		if ( $this->is_linked() ) {
			wp_trash_post( $this->ID );
		}
		delete_post_meta( $this->ID, self::PAYLOAD_META );
	}

	/**
	 * Get the post content for insertion.
	 *
	 * @return string The post content.
	 */
	protected function get_post_content() {
		$post_data = $this->payload['post_data'];
		$post_type = $post_data['post_type'];

		if ( ! use_block_editor_for_post_type( $post_type ) ) {
			return $post_data['content'];
		}

		if ( ! has_blocks( $post_data['raw_content'] ) ) {
			return $post_data['content'];
		}

		$blocks = array_map(
			[ Blocks::class, 'process_incoming_block' ],
			parse_blocks( $post_data['raw_content'] )
		);

		return serialize_blocks( $blocks );
	}

	/**
	 * Insert the incoming post.
	 *
	 * This will create or update an existing post and the stored payload.
	 *
	 * @param array $payload Optional payload to insert the post with.
	 *
	 * @return int|WP_Error The linked post ID or WP_Error on failure.
	 */
	public function insert( $payload = [] ) {
		if ( ! empty( $payload ) ) {
			$error = $this->update_payload( $payload );
			if ( is_wp_error( $error ) ) {
				self::log( 'Failed to update payload: ' . $error->get_error_message() );

				return $error;
			}
		}

		// Make sure we have the latest post data before continuing.
		if ( $this->ID ) {
			$this->post = get_post( $this->ID );
		}

		$post_data = $this->payload['post_data'];
		$post_type = $post_data['post_type'];

		/**
		 * Do not insert if payload is older than the linked post's stored payload.
		 */
		$current_payload = $this->get_post_payload();
		if (
			! empty( $current_payload ) &&
			$current_payload['post_data']['modified_gmt'] > $post_data['modified_gmt']
		) {
			self::log( 'Linked post content is newer than the post payload.' );

			return new WP_Error( 'old_modified_date', __( 'Linked post content is newer than the post payload.', 'newspack-network' ) );
		}

		$postarr = [
			'ID'             => $this->ID,
			'post_date_gmt'  => $post_data['date_gmt'],
			'post_title'     => $post_data['title'],
			'post_name'      => $post_data['slug'],
			'post_content'   => $this->get_post_content(),
			'post_excerpt'   => $post_data['excerpt'],
			'post_type'      => $post_type,
			'comment_status' => $post_data['comment_status'],
			'ping_status'    => $post_data['ping_status'],
		];

		$is_new_post = ! $this->ID;

		// Insert the post if it's linked or a new post.
		if ( $is_new_post || $this->is_linked() ) {

			/**
			 * Post status handling.
			 *
			 * If post is being published, apply the stored `status_on_publish`
			 * override if one exists. For new posts, use the incoming payload
			 * value instead. If no override is configured, the post status is
			 * left unchanged for existing posts and defaults to the incoming
			 * status for new ones. If post is being scheduled (future) and
			 * `status_on_publish` is a non-publish status, keep the node post
			 * in that status. This prevents WP cron from scheduling
			 * `publish_future_post` and auto-publishing the node post.
			 * Otherwise, use the post status from the payload.
			 */
			if ( $post_data['post_status'] === 'publish' ) {
				if ( $is_new_post ) {
					$postarr['post_status'] = isset( $this->payload['status_on_publish'] ) ? $this->payload['status_on_publish'] : $post_data['post_status'];
				} else {
					$status_on_publish = get_post_meta( $this->ID, self::STATUS_ON_PUBLISH_META, true );
					if ( $status_on_publish ) {
						$postarr['post_status'] = $status_on_publish;
					}
				}
			} elseif ( $post_data['post_status'] === 'future' ) {
				if ( $is_new_post ) {
					if ( isset( $this->payload['status_on_publish'] ) ) {
						$status_on_publish = $this->payload['status_on_publish'];
					} else {
						$status_on_publish = '';
					}
				} else {
					$status_on_publish = get_post_meta( $this->ID, self::STATUS_ON_PUBLISH_META, true );
				}

				if ( $status_on_publish && 'publish' !== $status_on_publish ) {
					$postarr['post_status'] = $status_on_publish;
				} else {
					// If status_on_publish is 'publish' or unset, mirror the hub's schedule.
					$postarr['post_status'] = 'future';
				}
			} else {
				$postarr['post_status'] = $post_data['post_status'];
			}

			$postarr['post_author'] = 0;
			if ( ! empty( $post_data['author'] ) ) {
				$post_author = self::get_incoming_wp_user_author( $this->get_original_site_url(), $post_data['author'] );
				if ( is_wp_error( $post_author ) ) {
					self::log( 'Failed to get post author with message: ' . $post_author->get_error_message() );
				} else {
					$postarr['post_author'] = $post_author->ID;
				}
			}

			/**
			 * Fires right before an incoming post is saved.
			 *
			 * @param array        $post_data         The post_data part of the payload.
			 * @param WP_Post|null $post              The post object or null if it has not been created yet.
			 * @param string       $original_site_url The original site URL.
			 */
			do_action( 'newspack_network_incoming_post_before_save', $post_data, $this->post, $this->get_original_site_url() );

			// Remove filters that may alter content updates.
			remove_all_filters( 'content_save_pre' );

			if ( $this->ID ) {
				$post_id = wp_update_post( $postarr, true );
			} else {
				$post_id = wp_insert_post( $postarr, true );
			}

			if ( is_wp_error( $post_id ) ) {
				self::log( 'Failed to insert post with message: ' . $post_id->get_error_message() );

				return $post_id;
			}

			// The wp_insert_post() function might return `0` on failure.
			if ( ! $post_id ) {
				self::log( 'Failed to insert post.' );

				return new WP_Error( 'insert_error', __( 'Error inserting post.', 'newspack-network' ) );
			}

			// Handle `status_on_publish` meta.
			if ( $post_data['post_status'] !== 'publish' && $is_new_post ) {
				// Store the publish status for new posts.
				if ( isset( $this->payload['status_on_publish'] ) ) {
					// Only store the meta if the key is present. An absent status_on_publish
					// means no override was configured — the node will fall back to safe
					// defaults when the hub later sends 'publish' or 'future'.
					update_post_meta(
						$post_id,
						self::STATUS_ON_PUBLISH_META,
						$this->payload['status_on_publish']
					);
				}
			} elseif ( $post_data['post_status'] === 'publish' && ! $is_new_post ) {
				// Clean up the meta for published posts so it's not re-published after
				// being unpublished.
				delete_post_meta( $this->ID, self::STATUS_ON_PUBLISH_META );
			}

			// Update the object.
			$this->ID   = $post_id;
			$this->post = get_post( $this->ID );

			/**
			 * Fires right after an incoming post is saved.
			 *
			 * @param array   $post_data         The post_data part of the payload.
			 * @param WP_Post $post              The post object.
			 * @param string  $original_site_url The original site URL.
			 */
			do_action( 'newspack_network_incoming_post_after_save', $post_data, $this->post, $this->get_original_site_url() );

			// Handle post meta.
			$this->update_meta();

			// Handle thumbnail.
			$thumbnail_url = $post_data['thumbnail_url'];
			if ( $thumbnail_url ) {
				$this->upload_thumbnail();
			} else {
				// Delete thumbnail for existing post if it's not set in the payload.
				$current_thumbnail_id = get_post_thumbnail_id( $this->ID );
				if ( $current_thumbnail_id ) {
					delete_post_thumbnail( $this->ID );
				}
			}

			// Handle taxonomy terms.
			$this->update_taxonomy_terms();

			// Handle the post modified date.
			$this->update_post_modified_date();
		}

		update_post_meta( $this->ID, self::PAYLOAD_META, $this->payload );
		update_post_meta( $this->ID, self::NETWORK_POST_ID_META, $this->network_post_id );

		/**
		 * Fires after an incoming post is inserted.
		 *
		 * @param int   $post_id   The post ID.
		 * @param bool  $is_linked Whether the post is linked.
		 * @param array $payload   The post payload.
		 */
		do_action( 'newspack_network_incoming_post_inserted', $this->ID, $this->is_linked(), $this->payload );

		return $this->ID;
	}

	/**
	 * Update the post modified date.
	 *
	 * Need to update directly into the database after the post is inserted/updated
	 * to override the automatic date update.
	 *
	 * @return void
	 */
	protected function update_post_modified_date() {
		$post_data = $this->payload['post_data'];
		if ( ! empty( $post_data['modified_gmt'] ) ) {
			$post_modified_gmt = $post_data['modified_gmt'];

			// calculate the local time of the post modified date.
			$post_modified = get_date_from_gmt( $post_modified_gmt );

			// update the post modified date.
			global $wpdb;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->posts,
				[
					'post_modified'     => $post_modified,
					'post_modified_gmt' => $post_modified_gmt,
				],
				[ 'ID' => $this->ID ]
			);

			// Clear the post cache.
			wp_cache_delete( $this->ID, 'posts' );
		}
	}
}
