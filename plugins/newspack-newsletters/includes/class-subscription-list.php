<?php
/**
 * Newspack Newsletters Subscription List
 *
 * @package Newspack
 */

namespace Newspack\Newsletters;

use Newspack_Newsletters;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Class used to represent one Subscription List
 *
 * A list can be either local or remote. Local lists reffer to lists that are created via wp-admin and synced to the ESP as tags or groups.
 * Remote lists are lists that are created and managed on the ESP and synced to the site. Users can store a local title and description that will be used to represent the list on the site.
 */
class Subscription_List {

	use \Newspack_Newsletters_Mailchimp_Subscription_List_Trait;

	/**
	 * Hold the WP_Post object associated with this List.
	 *
	 * @var WP_Post
	 */
	protected $post;

	/**
	 * The meta key where the settings are stored.
	 */
	const META_KEY = 'newspack_nl_provider_settings';

	/**
	 * The prefix used to build the Public iD
	 */
	const PUBLIC_ID_PREFIX = 'newspack-';

	/**
	 * The post meta key used to mark a list as local
	 */
	const TYPE_META = '_type';

	/**
	 * The post meta key used to store the list provider, only present for remote lists
	 */
	const PROVIDER_META = '_provider';

	/**
	 * The post meta key used to store the list remote ID (the ID in the ESP), only present for remote lists
	 */
	const REMOTE_ID_META = '_remote_id';

	/**
	 * The name of the meta key used to store the list's remote name, only present for remote lists
	 */
	const REMOTE_NAME_META = '_remote_name';

	/**
	 * The post meta key used to store the list's subscriber count, only present for Group-based lists.
	 */
	const SUBSCRIBER_COUNT_META = '_subscriber_count';

	/**
	 * Checks if a string $id is in the format of a local Subscription List Public ID
	 *
	 * @see self::get_public_id
	 * @param string $id The ID to be checked.
	 * @return boolean
	 */
	public static function is_local_public_id( $id ) {
		return (bool) self::get_id_from_local_public_id( $id );
	}

	/**
	 * Extracts the numeric ID from a properly formatted Public ID
	 *
	 * @see self::get_public_id
	 * @param string $public_id The Public id.
	 * @return ?int The ID on success, NULL on failure
	 */
	public static function get_id_from_local_public_id( $public_id ) {
		if ( ! is_string( $public_id ) ) {
			return;
		}
		$search = preg_match(
			'/^' . self::PUBLIC_ID_PREFIX . '([0-9]+)$/',
			$public_id,
			$matches
		);
		if ( $search && ! empty( $matches[1] ) ) {
			return (int) $matches[1];
		}
	}

	/**
	 * Gets a Subscription List object by its public_id
	 *
	 * @param string $public_id The list's public ID.
	 * @return ?Subscription_List
	 */
	public static function from_public_id( $public_id ) {
		$public_id = (string) $public_id;
		if ( self::is_local_public_id( $public_id ) ) {
			$post_id = self::get_id_from_local_public_id( $public_id );
			try {
				return new self( $post_id );
			} catch ( \InvalidArgumentException $e ) {
				return;
			}
		}
		return self::from_remote_id( $public_id );
	}

	/**
	 * Gets a Subscription_List object by its remote ID
	 *
	 * @param string $remote_id The remote ID. The ID of the list in the ESP.
	 * @return ?Subscription_List
	 */
	public static function from_remote_id( $remote_id ) {
		$posts = get_posts(
			[
				'post_type'      => Subscription_Lists::CPT,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => self::REMOTE_ID_META,
						'value' => $remote_id,
					],
				],
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		if ( 1 === count( $posts ) ) {
			return new self( $posts[0] );
		}
	}

	/**
	 * Initializes a new Subscription List
	 *
	 * @param WP_Post|int $post_or_id The post object or post ID.
	 * @throws \InvalidArgumentException In case the post is not found.
	 */
	public function __construct( $post_or_id ) {
		if ( ! $post_or_id instanceof WP_Post ) {
			$post_or_id = get_post( (int) $post_or_id );
			if ( ! $post_or_id instanceof WP_Post ) {
				throw new \InvalidArgumentException( 'Post not found' );
			}
		}
		$this->post = $post_or_id;
	}

	/**
	 * Gets the List ID
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->post->ID;
	}

	/**
	 * Returns the Public ID for this List
	 *
	 * Public ID is the ID that will represent this list in all UI forms across the application
	 *
	 * @return string
	 */
	public function get_public_id() {
		if ( $this->is_local() ) {
			return self::PUBLIC_ID_PREFIX . $this->get_id();
		}
		return $this->get_remote_id();
	}

	/**
	 * Gets the List title
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->post->post_title;
	}

	/**
	 * Gets the List description
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->post->post_content;
	}

	/**
	 * Gets the list type. If not defined, defaults to 'local'
	 *
	 * @return string
	 */
	public function get_type() {
		$meta = get_post_meta( $this->get_id(), self::TYPE_META, true );
		return empty( $meta ) || ! is_string( $meta ) || 'remote' !== $meta ? 'local' : 'remote';
	}

	/**
	 * Sets the list type
	 *
	 * @param string $type The type to be set. Accepts local or remote.
	 * @return boolean
	 */
	public function set_type( $type ) {
		return update_post_meta( $this->get_id(), self::TYPE_META, 'remote' === $type ? 'remote' : 'local' );
	}

	/**
	 * Gets the label for the list type from the provider class
	 *
	 * @return string
	 */
	public function get_type_label() {
		$provider = Newspack_Newsletters::get_service_provider();
		if ( ! empty( $provider ) ) {
			return $this->is_local() ? $provider::label( 'local_list_explanation', $this->get_public_id() ) : $provider::label( 'list_explanation', $this->get_public_id() );
		}
		return '';
	}

	/**
	 * Checks if the list is active
	 *
	 * @return boolean
	 */
	public function is_active() {
		return 'publish' === $this->post->post_status;
	}

	/**
	 * Checks if the list is local
	 *
	 * @return boolean
	 */
	public function is_local() {
		return 'local' === $this->get_type();
	}

	/**
	 * Gets the provider slug, only present for remote lists
	 *
	 * @return string
	 */
	public function get_provider() {
		return get_post_meta( $this->get_id(), self::PROVIDER_META, true );
	}

	/**
	 * Sets the provider slug, only present for remote lists
	 *
	 * @param string $provider The provider slug.
	 * @return boolean
	 */
	public function set_provider( $provider ) {
		return update_post_meta( $this->get_id(), self::PROVIDER_META, $provider );
	}

	/**
	 * Gets the remote ID, only present for remote lists
	 *
	 * @return string
	 */
	public function get_remote_id() {
		return get_post_meta( $this->get_id(), self::REMOTE_ID_META, true );
	}

	/**
	 * Sets the remote ID, only present for remote lists
	 *
	 * @param string $remote_id The remote ID.
	 * @return boolean
	 */
	public function set_remote_id( $remote_id ) {
		return update_post_meta( $this->get_id(), self::REMOTE_ID_META, $remote_id );
	}

	/**
	 * Gets the link to edit this list. Only local lists can be edited locally.
	 *
	 * In some rest requests, the post type is not registered, so we can't use get_edit_post_link
	 *
	 * @return string
	 */
	public function get_edit_link() {
		return $this->is_local() ? sprintf( admin_url( 'post.php?post=%d&action=edit' ), $this->get_id() ) : '';
	}

	/**
	 * Gets the subscriber count, only present for Group-based lists
	 *
	 * @return string
	 */
	public function get_subscriber_count() {
		$count = get_post_meta( $this->get_id(), self::SUBSCRIBER_COUNT_META, true );
		return empty( $count ) ? 0 : (int) $count;
	}

	/**
	 * Sets the subscriber count, only present for Group-based lists
	 *
	 * @param int $count The subscriber count.
	 * @return boolean
	 */
	public function set_subscriber_count( $count ) {
		return update_post_meta( $this->get_id(), self::SUBSCRIBER_COUNT_META, (int) $count );
	}

	/**
	 * Gets the list's remote name. Only present for remote lists.
	 */
	public function get_remote_name() {
		$remote_name = get_post_meta( $this->get_id(), self::REMOTE_NAME_META, true );
		return ( empty( $remote_name ) ) ? $this->get_title() : $remote_name;
	}

	/**
	 * Sets the list's remote name. Only present for remote lists.
	 *
	 * @param string $remote_name The remote name.
	 * @return boolean
	 */
	public function set_remote_name( $remote_name ) {
		return update_post_meta( $this->get_id(), self::REMOTE_NAME_META, $remote_name );
	}

	/**
	 * Generate the tag name that will be added to the ESP based on the post title
	 *
	 * @param string $prefix The prefix to be added to the tag name.
	 *
	 * @return string
	 */
	public function generate_tag_name( $prefix = 'Newspack: ' ) {
		return $prefix . $this->get_title();
	}

	/**
	 * Returns the settings stored for a provider
	 *
	 * @param string $provider_slug The provider slug.
	 * @return ?array
	 */
	public function get_provider_settings( $provider_slug ) {
		$meta = $this->get_all_providers_settings();
		if ( ! empty( $provider_slug ) && is_array( $meta ) && ! empty( $meta[ $provider_slug ] ) ) {
			return $meta[ $provider_slug ];
		}
		return null;
	}

	/**
	 * Returns the settings stored for the current service provicer
	 *
	 * @return ?array
	 */
	public function get_current_provider_settings() {
		return $this->get_provider_settings( Newspack_Newsletters::service_provider() );
	}

	/**
	 * Checks whether the List is properly configured for the current provider
	 *
	 * @return boolean
	 */
	public function is_configured_for_current_provider() {
		return $this->is_configured_for_provider( Newspack_Newsletters::service_provider() );
	}

	/**
	 * Checks whether the List is properly configured for a provider
	 *
	 * @param string $provider_slug The provider slug.
	 * @return boolean
	 */
	public function is_configured_for_provider( $provider_slug ) {
		if ( ! $this->is_local() ) {
			return $this->get_provider() === $provider_slug;
		}
		$settings = $this->get_provider_settings( $provider_slug );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		return empty( $settings['error'] ) && ! empty( $settings['tag_id'] ) && ! empty( $settings['list'] );
	}

	/**
	 * Gets all the settings stored for all providers
	 *
	 * @return array
	 */
	public function get_all_providers_settings() {
		$settings = get_post_meta( $this->get_id(), self::META_KEY, true );
		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Gets a list of all providers slugs this List has configuration for
	 *
	 * @return array
	 */
	public function get_configured_providers() {
		$providers  = array_keys( $this->get_all_providers_settings() );
		$configured = [];
		foreach ( $providers as $provider ) {
			if ( $this->is_configured_for_provider( $provider ) ) {
				$configured[] = $provider;
			}
		}
		return $configured;
	}

	/**
	 * Gets a list of all providers slugs this List has configuration for, excluding the current active provider
	 *
	 * @return array
	 */
	public function get_other_configured_providers() {
		$providers = $this->get_configured_providers();
		return array_values( array_diff( $providers, [ Newspack_Newsletters::service_provider() ] ) );
	}

	/**
	 * Gets a list of all providers names this List has configuration for
	 *
	 * @param boolean $ignore_current Whether to ignore the current provider or not.
	 * @return array
	 */
	public function get_configured_providers_names( $ignore_current = false ) {
		$providers = $ignore_current ? $this->get_other_configured_providers() : $this->get_configured_providers();
		$names     = [];
		foreach ( $providers as $provider_slug ) {
			$provider = Newspack_Newsletters::get_service_provider_instance( $provider_slug );
			if ( is_object( $provider ) ) {
				$names[] = $provider::label( 'name' );
			}
		}
		return $names;
	}

	/**
	 * Gets a list of all providers names this List has configuration for, excluding the current active provider
	 *
	 * @return array
	 */
	public function get_other_configured_providers_names() {
		return $this->get_configured_providers_names( true );
	}

	/**
	 * Checks if this List has any other providers configured other than the current active provider
	 *
	 * @return boolean
	 */
	public function has_other_configured_providers() {
		return ! empty( $this->get_other_configured_providers() );
	}

	/**
	 * Updates the settings of a provider
	 *
	 * @param string $list The list ID readers will be added to when they signup for this List.
	 * @param int    $tag_id The ID of the tag that will be added readers who signup to this List.
	 * @param string $tag_name The name of the tag that will be added readers who signup to this List.
	 * @param string $error The error message in case there was an error creating the tag on the ESP.
	 * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure or if the value passed to the function is the same as the one that is already in the database.
	 */
	public function update_current_provider_settings( $list, $tag_id, $tag_name, $error = '' ) {
		$settings = $this->get_all_providers_settings();
		if ( ! empty( $error ) ) {
			$settings[ Newspack_Newsletters::service_provider() ] = [
				'error' => $error,
			];
		} elseif ( empty( $list ) || empty( $tag_id ) || empty( $tag_name ) ) {
			$settings[ Newspack_Newsletters::service_provider() ]['error'] = __( 'Error: Missing information, try updating this list', 'newspack-newsletters' );
		} else {
			$settings[ Newspack_Newsletters::service_provider() ] = [
				'list'     => $list,
				'tag_id'   => $tag_id,
				'tag_name' => $tag_name,
			];
		}

		return update_post_meta( $this->get_id(), self::META_KEY, $settings );
	}

	/**
	 * Update the list settings.
	 *
	 * This methdos can update three fields: title, description and active.
	 *
	 * @param array[] $fields {
	 *    Array of list configuration. All keys are optional.
	 *
	 *    @type boolean active      Whether the list is available for subscription.
	 *    @type string  title       The list title.
	 *    @type string  description The list description.
	 * }
	 *
	 * @return boolean|WP_Error Whether the lists were updated or error.
	 */
	public function update( $fields ) {
		$post_data = [];
		if ( isset( $fields['active'] ) && $fields['active'] !== $this->is_active() ) {
			$post_data['post_status'] = $fields['active'] ? 'publish' : 'draft';
		}
		if ( ! empty( $fields['title'] ) && $this->get_title() !== $fields['title'] ) {
			$post_data['post_title'] = $fields['title'];
		}
		if ( ! empty( $fields['description'] ) && $this->get_description() !== $fields['description'] ) {
			$post_data['post_content'] = $fields['description'];
		}
		if ( ! empty( $post_data ) ) {
			$post_data['ID'] = $this->get_id();
			wp_update_post( $post_data );
			$this->post = get_post( $this->get_id() );
			return true;
		}
		return false;
	}

	/**
	 * Converts the list to an array, to be used in the configuration object used by the plugin
	 *
	 * @return array
	 */
	public function to_array() {
		return [
			'id'          => $this->get_public_id(),
			'db_id'       => $this->get_id(),
			'title'       => $this->get_title(),
			'name'        => $this->get_title(),
			'description' => $this->get_description(),
			'type'        => $this->get_type(),
			'type_label'  => $this->get_type_label(),
			'edit_link'   => $this->get_edit_link(),
			'active'      => $this->is_active(),
			'remote_name' => $this->get_remote_name(),
		];
	}
}
