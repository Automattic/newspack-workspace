<?php
/**
 * Newspack Newsletters Subscription Lists
 *
 * @package Newspack
 */

namespace Newspack\Newsletters;

use Newspack_Newsletters;
use Newspack_Newsletters_Settings;
use Newspack_Newsletters_Subscription;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Newsletters Subscription Lists class.
 *
 * Subscriptions Lists are Lists which readers can subscribe to. AKA Newsletters.
 *
 * Each List is associated with a Audience/List in the Provider and can be associated to one or more tags in the provider
 */
class Subscription_Lists {

	/**
	 * CPT for Newsletter Lists.
	 */
	const CPT = 'newspack_nl_list';

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'init', [ __CLASS__, 'migrate_lists' ], 11 );

		add_filter( 'wp_editor_settings', [ __CLASS__, 'filter_editor_settings' ], 10, 2 );
		add_action( 'save_post', [ __CLASS__, 'save_post' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_enqueue_scripts' ] );

		add_action( 'edit_form_before_permalink', [ __CLASS__, 'edit_form_before_permalink' ] );
		add_action( 'edit_form_top', [ __CLASS__, 'edit_form_top' ] );
	}

	/**
	 * Add custom CSS to the List post type edit screen
	 */
	public static function admin_enqueue_scripts() {

		if ( ! self::should_initialize_local_lists() ) {
			return;
		}

		if ( get_current_screen()->post_type === self::CPT ) {
			wp_enqueue_style(
				'newspack-newsletters-subscription-list-editor',
				plugins_url( '../css/subscription-list-editor.css', __FILE__ ),
				[],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'css/subscription-list-editor.css' )
			);
		}
	}

	/**
	 * Check if we should initialize the Subscription lists
	 *
	 * @return boolean
	 */
	public static function should_initialize_local_lists() {
		// We only need this on admin.
		if ( ! is_admin() ) {
			return false;
		}

		// If Service Provider is not configured yet.
		if ( 'manual' === Newspack_Newsletters::service_provider() || ! Newspack_Newsletters::is_service_provider_configured() ) {
			return false;
		}

		$provider = Newspack_Newsletters::get_service_provider();

		// Only init if current provider supports local lists.
		return $provider::$support_local_lists;
	}

	/**
	 * Disable Rich text editing from the editor
	 *
	 * @param array  $settings The settings to be filtered.
	 * @param string $editor_id The editor identifier.
	 * @return array
	 */
	public static function filter_editor_settings( $settings, $editor_id ) {

		if ( ! self::should_initialize_local_lists() ) {
			return $settings;
		}

		if ( 'content' === $editor_id && get_current_screen()->post_type === self::CPT ) {
			$settings['tinymce']       = false;
			$settings['quicktags']     = false;
			$settings['media_buttons'] = false;
		}

		return $settings;
	}

	/**
	 * Register the custom post type
	 *
	 * @return void
	 */
	public static function register_post_type() {

		$labels = array(
			'name'                  => _x( 'Subscription Lists', 'Post Type General Name', 'newspack-newsletters' ),
			'singular_name'         => _x( 'Subscription List', 'Post Type Singular Name', 'newspack-newsletters' ),
			'menu_name'             => __( 'Subscription Lists', 'newspack-newsletters' ),
			'name_admin_bar'        => __( 'Subscription Lists', 'newspack-newsletters' ),
			'archives'              => __( 'Subscription Lists', 'newspack-newsletters' ),
			'attributes'            => __( 'Subscription Lists', 'newspack-newsletters' ),
			'parent_item_colon'     => __( 'Parent Subscription List', 'newspack-newsletters' ),
			'all_items'             => __( 'Subscription Lists', 'newspack-newsletters' ),
			'add_new_item'          => __( 'Add new list', 'newspack-newsletters' ),
			'add_new'               => __( 'Add New', 'newspack-newsletters' ),
			'new_item'              => __( 'New Subscription List', 'newspack-newsletters' ),
			'edit_item'             => __( 'Edit list', 'newspack-newsletters' ),
			'update_item'           => __( 'Update list', 'newspack-newsletters' ),
			'view_item'             => __( 'View list', 'newspack-newsletters' ),
			'view_items'            => __( 'View Subscription Lists', 'newspack-newsletters' ),
			'search_items'          => __( 'Search Subscription List', 'newspack-newsletters' ),
			'not_found'             => __( 'Not found', 'newspack-newsletters' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'newspack-newsletters' ),
			'featured_image'        => __( 'Featured Image', 'newspack-newsletters' ),
			'set_featured_image'    => __( 'Set featured image', 'newspack-newsletters' ),
			'remove_featured_image' => __( 'Remove featured image', 'newspack-newsletters' ),
			'use_featured_image'    => __( 'Use as featured image', 'newspack-newsletters' ),
			'insert_into_item'      => __( 'Insert into item', 'newspack-newsletters' ),
			'uploaded_to_this_item' => __( 'Uploaded to this item', 'newspack-newsletters' ),
			'items_list'            => __( 'Items list', 'newspack-newsletters' ),
			'items_list_navigation' => __( 'Items list navigation', 'newspack-newsletters' ),
			'filter_items_list'     => __( 'Filter items list', 'newspack-newsletters' ),
		);
		$args   = array(
			'label'                => __( 'Subscription List', 'newspack-newsletters' ),
			'description'          => __( 'Newsletter Subscription list', 'newspack-newsletters' ),
			'labels'               => $labels,
			'supports'             => array( 'title', 'editor' ),
			'hierarchical'         => false,
			'public'               => Newspack_Newsletters_Subscription::has_subscription_management(), // public true only to allow it to be restricted by Memberships. All params affected by public are also explicitly set.
			'exclude_from_search'  => false,
			'publicly_queryable'   => false,
			'show_in_nav_menus'    => false,
			'show_ui'              => true,
			'show_in_menu'         => false,
			'can_export'           => false,
			'capability_type'      => 'page',
			'show_in_rest'         => false,
			'delete_with_user'     => false,
			'register_meta_box_cb' => [ __CLASS__, 'add_metabox' ],
		);
		register_post_type( self::CPT, $args );
	}

	/**
	 * Adds post type metaboxes
	 *
	 * @param WP_Post $post The current post.
	 * @return void
	 */
	public static function add_metabox( $post ) {

		if ( ! self::should_initialize_local_lists() ) {
			return;
		}

		add_meta_box(
			'newspack-newsletters-list-metabox',
			__( 'Provider settings' ),
			[ __CLASS__, 'metabox_content' ],
			self::CPT,
			'side',
			'high'
		);
	}

	/**
	 * Outputs metabox content
	 *
	 * @param WP_Post $post The current post.
	 * @return void
	 */
	public static function metabox_content( $post ) {
		$subscription_list = new Subscription_List( $post );
		$current_provider  = Newspack_Newsletters::get_service_provider();
		$empty_message     = '';
		$current_settings  = array_merge(
			[
				'list'     => null,
				'tag_id'   => null,
				'tag_name' => null,
				'error'    => null,
			],
			(array) $subscription_list->get_current_provider_settings()
		);

		if ( empty( $current_settings ) ) {

			$empty_message = sprintf(
				// translators: %s is the provider name. Ex: Mailchimp.
				__( 'This list is not yet configured for %s. Please use the fields below to configure where readers should be added to.' ),
				'<b>' . esc_html( $current_provider::label( 'name' ) ) . '</b>'
			);

		}

		$lists = $current_provider->get_lists();

		wp_nonce_field( 'newspack_newsletters_save_list', 'newspack_newsletters_save_list_nonce' );

		?>
		<div class="misc-pub-section">
			<?php if ( ! empty( $empty_message ) ) : ?>
				<p>
					<?php echo wp_kses( $empty_message, 'data' ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $current_settings['error'] ) ) : ?>
				<div class="notice notice-error">
					<p>
						<?php echo esc_html( $current_settings['error'] ); ?>
					</p>
				</div>
			<?php endif; ?>
			<label for="newspack_newsletters_list">
				<?php echo esc_html( $current_provider::label( 'List' ) ); ?>:
			</label>
			<select name="newspack_newsletters_list" id="newspack_newsletters_list" style="width: 100%">
				<?php foreach ( $lists as $list ) : ?>

					<?php
					// Some providers (mailchimp) register some special types of list that are not the native ESP lists. Here we want only the native lists.
					if ( ! empty( $list['type'] ) ) {
						continue;
					}
					?>

					<option value="<?php echo esc_attr( $list['id'] ); ?>" <?php selected( $current_settings['list'], $list['id'] ); ?> >
						<?php echo esc_html( $list['name'] ); ?>
					</option>

				<?php endforeach; ?>
			</select>
		</div>

		<div class="misc-pub-section">
			<?php if ( ! empty( $current_settings['tag_name'] ) ) : ?>
				<p>
					<?php echo esc_html( $current_provider::label( 'tag_metabox_after_save' ) ); ?>
				</p>
				<p class="subscription-list-tag">
					<?php echo esc_html( $current_settings['tag_name'] ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php echo esc_html( $current_provider::label( 'tag_metabox_before_save' ) ); ?>
				</p>
			<?php endif; ?>
			<?php
			/**
			 * Fires after the tag field in the list metabox.
			 *
			 * @param array $current_settings The current list settings.
			 */
			do_action( 'newspack_newsletters_subscription_lists_metabox_after_tag', $current_settings );
			?>
		</div>

		<?php if ( $subscription_list->has_other_configured_providers() ) : ?>
			<div class="misc-pub-section">
				<p>
					<?php esc_html_e( 'Other providers this list is already configured for:', 'newspack-newsletters' ); ?>
					<?php echo esc_html( implode( ', ', $subscription_list->get_other_configured_providers_names() ) ); ?>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save post callback
	 *
	 * @param int $post_id The ID of the post being saved.
	 * @return void
	 */
	public static function save_post( $post_id ) {

		if ( ! self::should_initialize_local_lists() ) {
			return;
		}

		$post_type = sanitize_text_field( $_POST['post_type'] ?? '' );

		if ( self::CPT !== $post_type ) {
			return;
		}

		if ( ! isset( $_POST['newspack_newsletters_save_list_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( $_POST['newspack_newsletters_save_list_nonce'] ), 'newspack_newsletters_save_list' )
		) {
			return;
		}

		/*
		 * If this is an autosave, our form has not been submitted,
		 * so we don't want to do anything.
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
			return;
		}

		$list              = sanitize_text_field( $_POST['newspack_newsletters_list'] ?? '' );
		$subscription_list = new Subscription_List( $post_id );

		// All lists created via UI are local lists.
		// Regular lists are created via Subscription_Lists::create_remote_list().
		$subscription_list->set_type( 'local' );

		if ( empty( $list ) ) {
			return;
		}

		$provider            = Newspack_Newsletters::get_service_provider();
		$tag_prefix          = $provider::label( 'tag_prefix' );
		$new_tag_name        = $subscription_list->generate_tag_name( $tag_prefix );
		$current_settings    = $subscription_list->get_current_provider_settings();
		$tag_id              = $current_settings['tag_id'] ?? false;
		$current_tag_name    = $current_settings['tag_name'] ?? $subscription_list->generate_tag_name( $tag_prefix );
		$error               = '';
		$needs_remote_update = $new_tag_name !== $current_tag_name; // Name was changed locally, needs to be updated on the ESP.
		$needs_local_update  = false;

		if ( $tag_id ) {
			// Check if tag still exists on the ESP. Will return a new name if name was changed on the ESP's dashboard.
			$esp_tag_name = $provider->get_esp_local_list_by_id( $current_settings['tag_id'], $list );
			if ( is_wp_error( $esp_tag_name ) ) {
				// Tag was not found. We need to create a new one. In Mailchimp, this can happen if you changed the Audience.
				$tag_id             = false; // Force create a new tag.
				$new_tag_name       = $subscription_list->generate_tag_name( $tag_prefix );
				$needs_local_update = false;
			} elseif ( $esp_tag_name !== $current_tag_name ) {
				// Tag name was changed on the ESP's dashboard. We need to update the local tag name.
				$needs_local_update = true;
			}
		}

		if ( ! $tag_id ) {
			// Get an existing tag id in the ESP or create a new one.
			$tag_id = $provider->get_esp_local_list_id( $new_tag_name, true, $list );
			if ( is_wp_error( $tag_id ) ) {
				$error = $tag_id->get_error_message();
			}
		}

		// Sync tag name with ESP. If tag name was changed on both ends, local changes will have precedence.
		if ( $needs_remote_update ) {
			$provider->update_esp_local_list( $tag_id, $new_tag_name, $list );
		} elseif ( $needs_local_update ) {
			$new_tag_name = $esp_tag_name;
			wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => str_replace( $tag_prefix, '', $new_tag_name ),
				]
			);
		}

		$subscription_list->update_current_provider_settings( $list, $tag_id, $new_tag_name, $error );
	}

	/**
	 * Methods for fetching Subscription Lists
	 *
	 * Note: This was built under the assumption that there will never be too many (hundreds) of Lists, so these methods will not scale for large lists.
	 *
	 * If we see that the number of lists grows too much, we might need to refactor these methods and how we store Lists metadata in order to be able to perform more performatic queries using Meta_Queries.
	 */

	/**
	 * Get all Subscription Lists
	 *
	 * @return Subscription_List[]
	 */
	public static function get_all() {
		$posts   = get_posts(
			[
				'post_type'      => self::CPT,
				'posts_per_page' => -1,
				'post_status'    => 'any',
			]
		);
		$objects = [];
		foreach ( $posts as $post ) {
			$objects[] = new Subscription_List( $post );
		}
		return $objects;
	}

	/**
	 * Get Subscription Lists based on a callback to filter them
	 *
	 * @param callable $callback The callback used to filter Lists. It must be a function that takes a Subscription_List instance as argument and returns a boolean whether to include the list to the results or not.
	 * @return Subscription_List[]
	 */
	public static function get_filtered( $callback ) {
		$lists = self::get_all();
		return array_values(
			array_filter(
				$lists,
				function ( $list ) use ( $callback ) {
					return call_user_func( $callback, $list );
				}
			)
		);
	}

	/**
	 * Get Lists that are configured to a given provider
	 *
	 * @param string $provider_slug The provider slug to get lists configured for.
	 * @return Subscription_List[]
	 */
	public static function get_configured_for_provider( $provider_slug ) {
		return self::get_filtered(
			function ( $list ) use ( $provider_slug ) {
				return $list->is_configured_for_provider( $provider_slug );
			}
		);
	}

	/**
	 * Get Lists that are configured for the current provider
	 *
	 * @return Subscription_List[]
	 */
	public static function get_configured_for_current_provider() {
		$lists = self::get_filtered(
			function ( $list ) {
				return $list->is_configured_for_current_provider();
			}
		);

		/**
		 * Filters the available lists for the current provider.
		 *
		 * @param Subscription_List[] $lists The lists that are available for the current provider.
		 */
		return apply_filters( 'newspack_newsletters_subscription_lists', $lists );
	}

	/**
	 * Gets the list object from a list definition fetched from the ESP. If not found, the list will be created in the database
	 *
	 * @param array[] $list {
	 *    Array of list configuration. Fields are required.
	 *
	 *    @type string  id         The list id in the ESP.
	 *    @type string  title       The list title.
	 * }
	 * @throws \Exception If the list is invalid.
	 * @return Subscription_List
	 */
	public static function get_or_create_remote_list( $list ) {
		if ( empty( $list['id'] ) || empty( $list['title'] ) ) {
			throw new \Exception( 'Invalid list' );
		}

		$subscriber_count = ! empty( $list['subscriber_count'] ) ? (int) $list['subscriber_count'] : 0;
		$subscriber_count = ! empty( $list['member_count'] ) ? (int) $list['member_count'] : 0; // Tags have member_count instead of subscriber_count.
		$saved_list       = Subscription_List::from_public_id( $list['id'] );
		if ( $saved_list ) {

			$has_customized_title = $saved_list->get_title() !== $saved_list->get_remote_name();

			if ( $list['title'] !== $saved_list->get_remote_name() ) {
				// The remote name has changed, let's update it locally.
				$saved_list->set_remote_name( $list['title'] );

				// Only update the title if it was not customized by the user.
				if ( ! $has_customized_title ) {
					$saved_list->update( [ 'title' => $list['title'] ] );
				}
			}

			// Update subscriber count, if available.
			if ( 0 > $subscriber_count ) {
				$saved_list->set_subscriber_count( $subscriber_count );
			}
			return $saved_list;
		}

		return self::create_remote_list( $list['id'], $list['title'], null, $subscriber_count );
	}

	/**
	 * Creates a remote list
	 *
	 * @param string $remote_id The ID of the list in the ESP.
	 * @param string $name The name of the list.
	 * @param string $provider_slug The provider slug to create the list for. Default is the current configured provider.
	 * @param int    $subscriber_count The number of subscribers in the list, if available.
	 * @return Subscription_List|WP_Error
	 */
	public static function create_remote_list( $remote_id, $name, $provider_slug = null, $subscriber_count = 0 ) {
		$post_id = wp_insert_post(
			[
				'post_type'   => self::CPT,
				'post_status' => 'draft',
				'post_title'  => $name,
			]
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$list = new Subscription_List( $post_id );
		$list->set_remote_id( $remote_id );
		$list->set_remote_name( $name );
		$list->set_type( 'remote' );
		if ( is_null( $provider_slug ) ) {
			$provider = Newspack_Newsletters::get_service_provider();
		} else {
			$provider = Newspack_Newsletters::get_service_provider_instance( $provider_slug );
		}
		if ( ! empty( $provider ) ) {
			$list->set_provider( $provider->service );
		}
		if ( 0 > $subscriber_count ) {
			$list->set_subscriber_count( $subscriber_count );
		}
		return $list;
	}

	/**
	 * Update the lists settings.
	 *
	 * This function retrieves the list of lists configured in the site and updates them all at once.
	 *
	 * Remote Lists that are not part of the provided array will be deleted.
	 * Local lists that are not part of the array will be disabled.
	 *
	 * @param array[] $lists {
	 *    Array of list configuration.
	 *
	 *    @type string  id          The list id in the ESP (not the ID in the DB)
	 *    @type boolean active      Whether the list is available for subscription.
	 *    @type string  title       The list title.
	 *    @type string  description The list description.
	 * }
	 *
	 * @return boolean|WP_Error Whether the lists were updated or error.
	 */
	public static function update_lists( $lists ) {
		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_provider', __( 'Provider is not set.' ) );
		}
		$lists = Newspack_Newsletters_Subscription::sanitize_lists( $lists );
		if ( empty( $lists ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_lists', __( 'Invalid list configuration.' ) );
		}

		$existing_ids = [];

		foreach ( $lists as $list ) {

			$stored_list = Subscription_List::from_public_id( $list['id'] );

			// If a remote list was not found, create one.
			if ( ! $stored_list instanceof Subscription_List && ! Subscription_List::is_local_public_id( $list['id'] ) ) {
				$stored_list = self::get_or_create_remote_list( $list );
			}

			if ( ! $stored_list instanceof Subscription_List ) {
				continue;
			}

			$existing_ids[] = $stored_list->get_id();
			$stored_list->update( $list );

		}

		// Clean up. Lists that are not in the new config deactivated.
		$all_lists = self::get_all();
		foreach ( $all_lists as $list ) {
			if ( ! in_array( $list->get_id(), $existing_ids, true ) ) {
				$list->update( [ 'active' => false ] );
			}
		}

		return true;
	}

	/**
	 * Deletes a list from the database
	 *
	 * @param Subscription_List $list The list to be deleted.
	 * @return bool
	 */
	public static function delete_list( Subscription_List $list ) {
		return wp_delete_post( $list->get_id() );
	}

	/**
	 * Clean up stored lists that no longer exist in the ESP.
	 *
	 * @param array  $existing_ids The list of IDs that exist in the ESP. All other remote lists will be deleted.
	 * @param string $provider_slug The provider slug to clean up lists for. Default is the current configured provider.
	 * @param bool   $delete_local If true, delete all local lists as well.
	 * @return void
	 */
	public static function garbage_collector( $existing_ids, $provider_slug = null, $delete_local = false ) {
		if ( is_null( $provider_slug ) ) {
			$provider      = Newspack_Newsletters::get_service_provider();
			$provider_slug = $provider->service;
		}
		$all_lists = self::get_all();
		foreach ( $all_lists as $list ) {
			if ( ( $delete_local || ! $list->is_local() ) && $provider_slug === $list->get_provider() && ! in_array( $list->get_id(), $existing_ids ) ) {
				self::delete_list( $list );
			}
		}
	}

	/**
	 * Get the URL to add a new Subscription List if the current provider supports it Empty string otherwise
	 *
	 * @return ?string
	 */
	public static function get_add_new_url() {
		if ( self::should_initialize_local_lists() ) {
			return admin_url( 'post-new.php?post_type=' . self::CPT );
		}
	}

	/**
	 * Outputs a title for the description field in the post editor.
	 */
	public static function edit_form_before_permalink() {

		if ( ! self::should_initialize_local_lists() ) {
			return;
		}

		if ( self::CPT === get_post_type() ) {
			printf( '<h2>%s</h2>', esc_html__( 'Description', 'newspack-newsletters' ) );
		}
	}

	/**
	 * Outputs a link back to the Settings page above the title in the post editor.
	 */
	public static function edit_form_top() {

		if ( ! self::should_initialize_local_lists() ) {
			return;
		}

		if ( self::CPT === get_post_type() ) {
			?>
			<a href="<?php echo esc_url( Newspack_Newsletters_Settings::get_settings_url() ); ?>">
				&lt;&lt;
				<?php esc_html_e( 'Back to Subscription Lists management', 'newspack-newsletters' ); ?>
			</a>
			<?php
		}
	}

	/**
	 * Migrates the lists from the old options to the new CPT.
	 *
	 * @return void
	 */
	public static function migrate_lists() {
		$migrated_option_name = '_newspack_newsletters_lists_migrated';
		if ( get_option( $migrated_option_name ) ) {
			return;
		}

		$providers = [ 'active_campaign', 'mailchimp', 'campaign_monitor', 'constant_contact' ];

		foreach ( $providers as $provider ) {
			$option_name = sprintf( '_newspack_newsletters_%s_lists', $provider );
			$lists       = get_option( $option_name );
			if ( empty( $lists ) ) {
				continue;
			}

			foreach ( $lists as $list_id => $list ) {

				if ( Subscription_List::is_local_public_id( $list_id ) ) {
					continue;
				}

				$list['id']  = $list_id;
				$list_object = self::get_or_create_remote_list( $list, $provider );
				$list_object->update( $list );
				$list_object->set_provider( $provider );

			}
		}

		add_option( $migrated_option_name, true );
		// Workaround the options bug on persistent cache.
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
