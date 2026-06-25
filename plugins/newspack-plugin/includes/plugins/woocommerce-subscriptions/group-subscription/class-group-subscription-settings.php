<?php
/**
 * Settings for Newspack Group Subscriptions.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 */
class Group_Subscription_Settings {
	/**
	 * Default group subscription settings.
	 */
	const DEFAULT_SETTINGS = [
		'enabled' => false,
		'limit'   => 0,
		'name'    => '',
	];

	/**
	 * Prefix for group subscription meta keys.
	 */
	const GROUP_SUBSCRIPTION_META_PREFIX = '_newspack_group_subscription_';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Add Group Subscription options to subscription and variable subscription product admin pages.
		\add_filter( 'newspack_custom_product_options', [ __CLASS__, 'add_custom_product_options' ] );
		\add_filter( 'newspack_custom_product_pricing_options', [ __CLASS__, 'add_custom_product_pricing_options' ] );

		// Add Group Subscription options to subscription admin pages.
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_enqueue_scripts' ] );
		\add_action( 'add_meta_boxes', [ __CLASS__, 'add_group_subscription_meta_box' ], 26, 2 );
		\add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save_group_subscription_meta' ], 10, 2 );
		\add_action( 'wp_ajax_newspack_group_subscription_search_users', [ __CLASS__, 'ajax_search_users' ] );

		// Customize subscription column in admin list table for group subscriptions.
		\add_filter( 'woocommerce_subscription_list_table_column_content', [ __CLASS__, 'filter_subscription_column_content' ], 10, 3 );

		// Group subscription filter dropdown on subscription list table.
		\add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ __CLASS__, 'add_group_subscription_filter' ] );
		\add_action( 'restrict_manage_posts', [ __CLASS__, 'add_group_subscription_filter' ] );

		// Filter subscription list table query by group status.
		\add_filter( 'woocommerce_shop_subscription_list_table_prepare_items_query_args', [ __CLASS__, 'filter_subscriptions_by_group' ] );
		\add_filter( 'request', [ __CLASS__, 'filter_subscriptions_by_group_cpt' ] );

		// Clear group subscription IDs cache when product group settings change.
		\add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'maybe_clear_cache_on_product_save' ] );

		// Clear group subscription IDs cache when a subscription is trashed or deleted.
		\add_action( 'wp_trash_post', [ __CLASS__, 'maybe_clear_cache_on_subscription_delete' ] );
		\add_action( 'before_delete_post', [ __CLASS__, 'maybe_clear_cache_on_subscription_delete' ] );
		\add_action( 'woocommerce_trash_subscription', [ __CLASS__, 'clear_group_subscription_ids_cache' ] );
		\add_action( 'woocommerce_delete_subscription', [ __CLASS__, 'clear_group_subscription_ids_cache' ] );

		// Include group name in subscription search.
		\add_filter( 'woocommerce_shop_subscription_search_fields', [ __CLASS__, 'add_group_name_search_field' ] );
		\add_filter( 'woocommerce_order_table_search_query_meta_keys', [ __CLASS__, 'add_group_name_hpos_search_field' ] );
		\add_filter( 'posts_join', [ __CLASS__, 'search_group_name_join' ], 10, 2 );
		\add_filter( 'posts_search', [ __CLASS__, 'search_group_name_where' ], 10, 2 );

		// Publisher-configurable group label settings. The editing UI lives in the
		// Audience wizard's Groups tab; this registration only guards storage
		// validation when the options are written directly.
		\add_action( 'admin_init', [ __CLASS__, 'register_label_settings' ] );
	}

	/**
	 * Enqueue admin scripts.
	 */
	public static function admin_enqueue_scripts() {
		if ( ! function_exists( 'wcs_get_page_screen_id' ) ) {
			return;
		}
		$screen = \get_current_screen();
		$is_subscription_screen = in_array(
			$screen->id,
			[
				'edit-shop_subscription',
				'shop_subscription',
				\wcs_get_page_screen_id( 'shop_subscription' ),
			],
			true
		);
		if ( ! $is_subscription_screen ) {
			return;
		}
		\wp_enqueue_script(
			'newspack-group-subscription-admin',
			Newspack::plugin_url() . '/dist/group-subscription-admin.js',
			[],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
		\wp_enqueue_style(
			'newspack-group-subscription-admin',
			Newspack::plugin_url() . '/dist/group-subscription-admin.css',
			[],
			NEWSPACK_PLUGIN_VERSION
		);
		\wp_localize_script(
			'newspack-group-subscription-admin',
			'newspackGroupSubscriptions',
			[
				'apiUrl'                => \rest_url( Group_Subscription_API::NAMESPACE ),
				'apiNonce'              => \wp_create_nonce( 'wp_rest' ),
				'placeholder'           => __( 'Search for a reader...', 'newspack-plugin' ),
				'invalid_email_message' => __( 'Please enter a valid email address.', 'newspack-plugin' ),
				'success_message'       => __( 'Invitation sent successfully.', 'newspack-plugin' ),
				'pending_label'         => __( '(pending)', 'newspack-plugin' ),
			]
		);
	}

	/**
	 * Add custom product options.
	 *
	 * @param array $custom_options Keyed array of custom product options.
	 *
	 * @return array Keyed array of custom product options.
	 */
	public static function add_custom_product_options( $custom_options ) {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return $custom_options;
		}
		$custom_options['newspack_group_subscription_enabled'] = [
			'id'            => self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled',
			'label'         => __( 'Group subscription', 'newspack-plugin' ),
			'description'   => __( 'Enable group subscriptions for this product.', 'newspack-plugin' ),
			'default'       => self::DEFAULT_SETTINGS['enabled'],
			'product_types' => [ 'subscription', 'subscription_variation' ],
			'type'          => 'boolean',
			'wrapper_class' => 'show_if_subscription',
		];
		return $custom_options;
	}

	/**
	 * Add custom product pricing options.
	 *
	 * @param array $custom_product_pricing_options Keyed array of custom product pricing options.
	 *
	 * @return array Keyed array of custom product pricing options.
	 */
	public static function add_custom_product_pricing_options( $custom_product_pricing_options ) {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return $custom_product_pricing_options;
		}
		$custom_product_pricing_options['newspack_group_subscription_limit'] = [
			'id'                => self::GROUP_SUBSCRIPTION_META_PREFIX . 'limit',
			'wrapper_class'     => 'show_if_newspack_group_subscription_enabled',
			'label'             => __( 'Group subscription member limit', 'newspack-plugin' ),
			'desc_tip'          => true,
			'description'       => __( 'Set the maximum number of members for group subscriptions. Set to 0 to allow an unlimited number of group members.', 'newspack-plugin' ),
			'default'           => self::DEFAULT_SETTINGS['limit'],
			'product_types'     => [ 'subscription', 'subscription_variation' ],
			'type'              => 'number',
			'custom_attributes' => [
				'step' => 1,
				'min'  => 0,
			],
		];
		return $custom_product_pricing_options;
	}

	/**
	 * Filter the subscription list table column content to show group name
	 * and member count for group subscriptions.
	 *
	 * @param string           $column_content The column content HTML.
	 * @param \WC_Subscription $subscription   The subscription object.
	 * @param string           $column         The column name.
	 *
	 * @return string The filtered column content.
	 */
	public static function filter_subscription_column_content( $column_content, $subscription, $column ) {
		if ( 'order_title' !== $column ) {
			return $column_content;
		}
		if ( ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return $column_content;
		}
		$settings     = self::get_subscription_settings( $subscription );
		$members      = Group_Subscription::get_members( $subscription );
		$member_count = count( $members );
		$limit        = $settings['limit'] > 0
			? $settings['limit']
			: __( 'unlimited', 'newspack-plugin' );

		$group_markup = sprintf(
			'<div class="newspack-group-subscription__column-info"><a href="%s"><strong>%s</strong></a> (%s)</div>',
			\esc_url( $subscription->get_edit_order_url() ),
			\esc_html( $settings['name'] ),
			\esc_html(
				sprintf(
					/* translators: 1: member count, 2: member limit or "unlimited" */
					__( '%1$s of %2$s members', 'newspack-plugin' ),
					$member_count,
					$limit
				)
			)
		);

		// Prepend the group info before the standard WCS column markup so any
		// status pills, preview affordances, or future additions from WCS are preserved.
		return $group_markup . $column_content;
	}

	/**
	 * Get the group subscription settings for a product.
	 *
	 * @param WC_Product|int $product The product object or ID.
	 *
	 * @return array The group subscription settings.
	 */
	public static function get_product_settings( $product ) {
		$settings = self::DEFAULT_SETTINGS;
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $settings;
		}
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = \wc_get_product( $product );
		}
		if ( ! $product ) {
			return $settings;
		}
		$enabled_meta        = $product->get_meta( self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', true );
		$limit_meta          = $product->get_meta( self::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', true );
		$settings['enabled'] = '' !== $enabled_meta ? \wc_string_to_bool( $enabled_meta ) : $settings['enabled']; // Empty string means the meta is unset; any other value, including 'no' or false, is a real override.
		$settings['limit']   = '' !== $limit_meta ? (int) $limit_meta : $settings['limit']; // Empty string means the meta is unset; any other value, including '0', is a real override.

		/**
		 * Filter the group subscription settings for a product.
		 *
		 * @param array $settings The group subscription settings.
		 * @param WC_Product $product The product object.
		 */
		return apply_filters( 'newspack_group_subscription_product_settings', $settings, $product );
	}

	/**
	 * Get the group subscription settings for a subscription.
	 *
	 * @param WC_Subscription|int $subscription The subscription object or ID.
	 *
	 * @return array The group subscription settings.
	 */
	public static function get_subscription_settings( $subscription ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription || ! function_exists( 'wcs_get_canonical_product_id' ) ) {
			return self::DEFAULT_SETTINGS;
		}
		$product_id          = WooCommerce_Subscriptions::get_subscription_product_id( $subscription );
		$product             = ( $product_id && function_exists( 'wc_get_product' ) ) ? \wc_get_product( $product_id ) : null;
		$settings            = self::get_product_settings( $product ? $product : $product_id );
		$enabled_meta        = $subscription->get_meta( self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', true );
		$limit_meta          = $subscription->get_meta( self::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', true );
		$name_meta           = $subscription->get_meta( self::GROUP_SUBSCRIPTION_META_PREFIX . 'name', true );
		$settings['enabled'] = '' !== $enabled_meta ? \wc_string_to_bool( $enabled_meta ) : $settings['enabled']; // Empty string means the meta is unset; any other value, including 'no' or false, is a real override.
		$settings['limit']   = '' !== $limit_meta ? (int) $limit_meta : $settings['limit']; // Empty string means the meta is unset; any other value, including '0', is a real override.
		if ( $name_meta ) {
			$settings['name'] = $name_meta;
		} else {
			$product_name     = $product ? trim( (string) $product->get_name() ) : '';
			$settings['name'] = '' !== $product_name ? $product_name : Group_Subscription::get_label( 'singular' );
		}

		/**
		 * Filter the group subscription settings for a subscription.
		 *
		 * @param array $settings The group subscription settings.
		 * @param WC_Subscription $subscription The subscription object.
		 */
		return apply_filters( 'newspack_group_subscription_settings', $settings, $subscription );
	}

	/**
	 * Update group subscription settings for a subscription.
	 *
	 * @param WC_Subscription|int $subscription The subscription object or ID.
	 * @param array               $settings The group subscription settings.
	 */
	public static function update_subscription_settings( $subscription, $settings ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return;
		}
		$previous_settings = self::get_subscription_settings( $subscription );
		$should_save       = false;
		$changed_keys      = [];
		foreach ( $settings as $key => $value ) {
			if ( ! isset( self::DEFAULT_SETTINGS[ $key ] ) ) {
				continue;
			}
			// Normalize both values to the same type before comparing to avoid
			// false-positive changes (e.g. comparing 'yes' string to bool true).
			$previous_value = $previous_settings[ $key ];
			if ( is_bool( self::DEFAULT_SETTINGS[ $key ] ) ) {
				$value          = \wc_bool_to_string( $value );
				$previous_value = \wc_bool_to_string( $previous_value );
			} elseif ( is_int( self::DEFAULT_SETTINGS[ $key ] ) ) {
				$value = absint( $value );
			}
			if ( $value !== $previous_value ) {
				$subscription->update_meta_data( self::GROUP_SUBSCRIPTION_META_PREFIX . $key, $value );
				$should_save    = true;
				$changed_keys[] = $key;
			}
		}
		if ( $should_save ) {
			$subscription->save();

			// Clear the cached group subscription IDs only when the enabled value actually changed.
			if ( in_array( 'enabled', $changed_keys, true ) ) {
				self::clear_group_subscription_ids_cache();
			}
		}
	}

	/**
	 * Add Group Subscription meta box to subscription admin pages.
	 *
	 * @param string                  $post_type The post type of the current post being edited.
	 * @param WP_Post|WC_Subscription $post_or_subscription The post or subscription currently being edited.
	 */
	public static function add_group_subscription_meta_box( $post_type, $post_or_subscription ) {
		if ( ! Content_Gate::is_newspack_feature_enabled() || ! function_exists( 'wcs_is_subscription' ) || ! \wcs_is_subscription( $post_or_subscription ) ) {
			return;
		}
		\add_meta_box(
			'newspack-group-subscription',
			__( 'Group subscription', 'newspack-plugin' ),
			[ __CLASS__, 'add_group_subscription_options' ],
			$post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Add Group Subscription options to subscription admin pages.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 */
	public static function add_group_subscription_options( $subscription ) {
		if ( ! $subscription || ! Content_Gate::is_newspack_feature_enabled() || ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return;
		}
		$settings = self::get_subscription_settings( $subscription );
		$product  = \wc_get_product( WooCommerce_Subscriptions::get_subscription_product_id( $subscription ) );
		$members = Group_Subscription::get_members( $subscription );
		$invites = Group_Subscription_Invite::get_invites( $subscription );
		?>
		<div class="newspack-group-subscription__container" data-subscription-id="<?php echo \esc_attr( $subscription->get_id() ); ?>">
			<input type="hidden" name="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled_baseline' ); ?>" value="<?php echo \esc_attr( \wc_bool_to_string( $settings['enabled'] ) ); ?>" />
			<input type="hidden" name="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'limit_baseline' ); ?>" value="<?php echo \esc_attr( (int) $settings['limit'] ); ?>" />
			<input type="hidden" name="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'name_baseline' ); ?>" value="<?php echo \esc_attr( $settings['name'] ); ?>" />
			<div class="newspack-group-subscription__settings">
				<h3><?php \esc_html_e( 'Settings', 'newspack-plugin' ); ?></h3>
				<p>
					<em>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: The product edit link or 'the product' if no product is found. */
							__( 'Changing these settings will override settings inherited from %s.', 'newspack-plugin' ),
							$product ? '<a href="' . \admin_url( 'post.php?post=' . ( $product->get_parent_id() ?: $product->get_id() ) . '&action=edit' ) . '">' . $product->get_name() . '</a>' : __( 'the product', 'newspack-plugin' ) // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
						)
					);
					?>
					</em>
				</p>
				<p>
					<label for="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' ); ?>">
						<input
							type="checkbox"
							id="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' ); ?>"
							name="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' ); ?>"
							value="yes"
							<?php checked( $settings['enabled'], true ); ?>
						/>
						<?php \esc_html_e( 'Group subscription enabled', 'newspack-plugin' ); ?>
					</label>
				</p>
				<div class="form-row">
					<?php
					\woocommerce_wp_text_input(
						[
							'id'            => self::GROUP_SUBSCRIPTION_META_PREFIX . 'name',
							'name'          => self::GROUP_SUBSCRIPTION_META_PREFIX . 'name',
							'label'         => __( 'Group subscription name', 'newspack-plugin' ),
							'value'         => $settings['name'],
							'type'          => 'text',
							'wrapper_class' => 'show_if_newspack_group_subscription_enabled',
						]
					);
					?>
				</div>
				<div class="form-row">
					<?php
					$pricing_options = self::add_custom_product_pricing_options( [] );
					foreach ( $pricing_options as $option_key => $option_config ) {
						if ( $option_key === 'newspack_group_subscription_limit' ) {
							$option_config['value'] = $settings['limit'];
							\woocommerce_wp_text_input( $option_config );
							break;
						}
					}
					?>
				</div>
			</div>
			<div class="newspack-group-subscription__members show_if_newspack_group_subscription_enabled" >
				<h3>
					<?php
					echo wp_kses_post(
						sprintf(
							// translators: %d: The number of group members.
							__( 'Group members (<span class="newspack-group-subscription__members-count">%d</span>)', 'newspack-plugin' ),
							count( $members ) + count( array_values( $invites ) )
						)
					);
					?>
				</h3>
				<ul class="newspack-group-subscription__members-list">
					<?php
					foreach ( $members as $member_id ) :
						$user = get_user_by( 'id', $member_id );
						if ( ! $user || ! Reader_Activation::is_user_reader( $user ) ) {
							continue;
						}
						?>
						<li>
							<a class="newspack-group-subscription__member-user-link" href="<?php echo \esc_url( \get_edit_user_link( $user->ID ) ); ?>"><?php echo \esc_html( $user->user_email ); ?></a>
							<a title="<?php \esc_attr_e( 'Remove', 'newspack-plugin' ); ?>" href="#" class="newspack-group-subscription__remove-member" data-user-id="<?php echo \esc_attr( $user->ID ); ?>">
								&#215;
								<span class="screen-reader-text"><?php \esc_html_e( 'Remove', 'newspack-plugin' ); ?></span>
						</a>
						</li>
						<?php
					endforeach;
					foreach ( array_values( $invites ) as $invite ) :
						$is_expired = Group_Subscription_Invite::is_invite_expired( $invite );
						?>
						<li data-email="<?php echo \esc_attr( $invite['email'] ); ?>">
							<span class="newspack-group-subscription__pending-invite"><?php echo \esc_html( $invite['email'] ); ?></span> <span class="newspack-group-subscription__pending-invite-label"><?php echo \esc_html( $is_expired ? __( '(expired)', 'newspack-plugin' ) : __( '(pending)', 'newspack-plugin' ) ); ?></span>
							<a title="<?php \esc_attr_e( 'Cancel', 'newspack-plugin' ); ?>" href="#" class="newspack-group-subscription__cancel-invite">
								&#215;
								<span class="screen-reader-text"><?php \esc_html_e( 'Cancel', 'newspack-plugin' ); ?></span>
						</a>
						</li>
						<?php
					endforeach;
					?>
				</ul>
			</div>
			<div class="newspack-group-subscription__add-member show_if_newspack_group_subscription_enabled form-row">
				<h3><?php \esc_html_e( 'Add new group members', 'newspack-plugin' ); ?></h3>
				<select id="_newspack_group_subscription_member_ids" name="_newspack_group_subscription_member_ids[]">
					<option value="">
						<?php echo \esc_html( 'Select a reader...' ); ?>
					</option>
				</select>
				<div class="newspack-group-subscription__invite-member">
					<label for="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'invite_email' ); ?>"><?php \esc_html_e( 'Or, send an invitation to a new reader:', 'newspack-plugin' ); ?></label>
					<input type="email" name="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'invite_email' ); ?>" id="<?php echo \esc_attr( self::GROUP_SUBSCRIPTION_META_PREFIX . 'invite_email' ); ?>" placeholder="<?php \esc_attr_e( 'Email address', 'newspack-plugin' ); ?>" />
					<button type="submit" class="button button-primary"><?php \esc_html_e( 'Invite', 'newspack-plugin' ); ?></button>
				</div>
			</div>
		</div>
						<?php
	}

	/**
	 * Save Group Subscription meta to a subscription.
	 *
	 * @param int             $subscription_id Subscription ID.
	 * @param WC_Subscription $subscription Optional. Subscription object. Default null - will be loaded from the ID.
	 */
	public static function save_group_subscription_meta( $subscription_id, $subscription = null ) {
		if ( ! function_exists( 'wcs_is_subscription' ) || ! function_exists( 'wcs_get_subscription' ) || ! function_exists( 'wc_clean' ) || ! \wcs_is_subscription( $subscription_id ) ) {
			return;
		}

		// Verify save nonce. See: WCS_Meta_Box_Subscription_Data::save().
		if ( empty( $_POST['woocommerce_meta_nonce'] ) || ! \wp_verify_nonce( \wc_clean( \wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		// Get subscription object.
		$subscription = is_a( $subscription, 'WC_Subscription' ) ? $subscription : \wcs_get_subscription( $subscription_id );
		$prefix       = self::GROUP_SUBSCRIPTION_META_PREFIX;

		$submitted = [
			'enabled' => isset( $_POST[ $prefix . 'enabled' ] ),
			'limit'   => isset( $_POST[ $prefix . 'limit' ] )
				? absint( wp_unslash( $_POST[ $prefix . 'limit' ] ) )
				: 0,
			'name'    => isset( $_POST[ $prefix . 'name' ] )
				? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'name' ] ) )
				: '',
		];

		$changed = [];
		foreach ( [ 'enabled', 'limit', 'name' ] as $key ) {
			$baseline_field = $prefix . $key . '_baseline';
			if ( ! isset( $_POST[ $baseline_field ] ) ) {
				continue;
			}
			$baseline_raw = sanitize_text_field( wp_unslash( $_POST[ $baseline_field ] ) );
			switch ( $key ) {
				case 'enabled':
					$baseline_value = \wc_string_to_bool( $baseline_raw );
					break;
				case 'limit':
					$baseline_value = absint( $baseline_raw );
					break;
				default:
					$baseline_value = $baseline_raw;
					break;
			}
			if ( $submitted[ $key ] !== $baseline_value ) {
				$changed[ $key ] = $submitted[ $key ];
			}
		}

		if ( ! empty( $changed ) ) {
			self::update_subscription_settings( $subscription, $changed );
		}

		// Effective group status can flip via inherited product settings without a meta write; refresh the cached ID set when it changed.
		// On the Add-subscription screen the product line item may not be linked yet, so this read can resolve the un-inherited
		// default and leave the cached ID set briefly stale. That is harmless: it only drives the admin list-table group filter and
		// self-heals via the transient's TTL plus the product save/trash/delete clear hooks. It is never an access-control path.
		if ( isset( $_POST[ $prefix . 'enabled_baseline' ] ) ) {
			$baseline_enabled = \wc_string_to_bool( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'enabled_baseline' ] ) ) );
			if ( $baseline_enabled !== self::get_subscription_settings( $subscription )['enabled'] ) {
				self::clear_group_subscription_ids_cache();
			}
		}
	}

	/**
	 * Add a group subscription filter dropdown to the subscription list table.
	 *
	 * @param string $order_type The order type (post type or HPOS order type).
	 */
	public static function add_group_subscription_filter( $order_type = '' ) {
		if ( '' === $order_type ) {
			$order_type = isset( $GLOBALS['typenow'] ) ? $GLOBALS['typenow'] : '';
		}

		if ( 'shop_subscription' !== $order_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['_newspack_group_subscription'] ) ? \sanitize_text_field( \wp_unslash( $_GET['_newspack_group_subscription'] ) ) : '';

		?>
		<label class="screen-reader-text" for="_newspack_group_subscription"><?php \esc_html_e( 'Filter by group subscription status', 'newspack-plugin' ); ?></label>
		<select name="_newspack_group_subscription" id="_newspack_group_subscription">
			<option value=""><?php \esc_html_e( 'All subscriptions', 'newspack-plugin' ); ?></option>
			<option value="group" <?php selected( $selected, 'group' ); ?>><?php \esc_html_e( 'Group subscriptions', 'newspack-plugin' ); ?></option>
			<option value="non-group" <?php selected( $selected, 'non-group' ); ?>><?php \esc_html_e( 'Non-group subscriptions', 'newspack-plugin' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Add the group subscription name meta key to the subscription search fields.
	 *
	 * @param array $search_fields The search fields.
	 *
	 * @return array The search fields with the group name meta key added.
	 */
	public static function add_group_name_search_field( $search_fields ) {
		$search_fields[] = self::GROUP_SUBSCRIPTION_META_PREFIX . 'name';
		return $search_fields;
	}

	/**
	 * Add the group subscription name meta key to the HPOS order search meta keys.
	 *
	 * This filter fires for all order types, so we guard it to only apply
	 * on the subscription admin list table screen.
	 *
	 * @param array $meta_keys The meta keys to search.
	 *
	 * @return array The meta keys with the group name meta key added.
	 */
	public static function add_group_name_hpos_search_field( $meta_keys ) {
		if ( ! function_exists( 'get_current_screen' ) || ! function_exists( 'wcs_get_page_screen_id' ) ) {
			return $meta_keys;
		}
		$screen = \get_current_screen();
		if ( ! $screen || $screen->id !== \wcs_get_page_screen_id( 'shop_subscription' ) ) {
			return $meta_keys;
		}
		$meta_keys[] = self::GROUP_SUBSCRIPTION_META_PREFIX . 'name';
		return $meta_keys;
	}

	/**
	 * Join the postmeta table for group name search in WP_Query.
	 *
	 * @param string    $join  The JOIN clause.
	 * @param \WP_Query $query The WP_Query instance.
	 *
	 * @return string The modified JOIN clause.
	 */
	public static function search_group_name_join( $join, $query ) {
		global $wpdb;
		if ( ! self::is_subscription_search_query( $query ) ) {
			return $join;
		}
		$join .= " LEFT JOIN {$wpdb->postmeta} AS np_group_name ON ( {$wpdb->posts}.ID = np_group_name.post_id AND np_group_name.meta_key = '" . esc_sql( self::GROUP_SUBSCRIPTION_META_PREFIX . 'name' ) . "' ) ";
		return $join;
	}

	/**
	 * Extend the search WHERE clause to include group name meta in WP_Query.
	 *
	 * @param string    $search The search WHERE clause.
	 * @param \WP_Query $query  The WP_Query instance.
	 *
	 * @return string The modified search WHERE clause.
	 */
	public static function search_group_name_where( $search, $query ) {
		global $wpdb;
		if ( ! self::is_subscription_search_query( $query ) || empty( $search ) ) {
			return $search;
		}
		$term = $query->get( 's' );
		if ( empty( $term ) ) {
			return $search;
		}
		$like      = '%' . $wpdb->esc_like( $term ) . '%';
		$or_clause = $wpdb->prepare( '( np_group_name.meta_value LIKE %s )', $like );

		// Wrap the existing search clause in an outer OR with our group name match.
		// $search is in the form " AND (...)" — preserve the leading " AND " and
		// wrap whatever inner clause WP_Query produced. Robust against `exact`,
		// `sentence`, multi-term, and other plugins' posts_search filters that
		// may have already modified the inner shape.
		$inner  = preg_replace( '/^\s*AND\s+/', '', $search );
		$search = " AND ( {$inner} OR {$or_clause} ) ";

		return $search;
	}

	/**
	 * Check if a WP_Query is a search query for shop_subscription post type.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 *
	 * @return bool Whether this is a subscription search query.
	 */
	private static function is_subscription_search_query( $query ) {
		return $query->is_search() && 'shop_subscription' === $query->get( 'post_type' );
	}

	/**
	 * Transient key for caching group subscription IDs.
	 */
	const GROUP_SUBSCRIPTION_IDS_TRANSIENT = 'newspack_group_subscription_ids';

	/**
	 * Get all subscription IDs that are group subscriptions.
	 *
	 * Collects IDs from two sources:
	 * 1. Subscriptions with the group enabled meta set directly.
	 * 2. Subscriptions whose product has group subscriptions enabled (inheritance).
	 *
	 * Results are cached in a transient for 5 minutes.
	 *
	 * @return int[] Array of subscription IDs.
	 */
	public static function get_group_subscription_ids() {
		$cached = \get_transient( self::GROUP_SUBSCRIPTION_IDS_TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		$meta_key = self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled';

		// 1. Subscription IDs with group enabled meta set directly.
		// Uses wcs_get_subscriptions which properly handles meta_query in both
		// CPT and HPOS modes (via wcs_get_orders_with_meta_query internally).
		$enabled_ids = [];
		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			$enabled_ids = array_keys(
				\wcs_get_subscriptions(
					[
						'subscriptions_per_page' => -1,
						'subscription_status'    => 'any',
						'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							[
								'key'   => $meta_key,
								'value' => 'yes',
							],
						],
					]
				)
			);
		}

		// 2. Subscription IDs whose product has group subscriptions enabled.
		$product_ids = \get_posts( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts
			[
				'post_type'      => [ 'product', 'product_variation' ],
				'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'fields'         => 'ids',
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		$product_sub_ids = [];
		if ( ! empty( $product_ids ) && function_exists( 'wcs_get_subscriptions_for_product' ) && function_exists( 'wc_get_product' ) ) {
			foreach ( $product_ids as $product_id ) {
				$product = \wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}
				// Skip variable parent products: variations have their own group settings
				// and do not inherit from the parent.
				if ( $product->is_type( [ 'variable', 'variable-subscription' ] ) ) {
					continue;
				}
				$product_sub_ids = array_merge(
					$product_sub_ids,
					array_keys( \wcs_get_subscriptions_for_product( $product_id ) )
				);
			}
		}

		// 3. Remove subscriptions that explicitly opted out via an 'enabled = no' override.
		// A subscription's own meta takes precedence over product inheritance.
		$opted_out_ids = [];
		if ( ! empty( $product_sub_ids ) && function_exists( 'wcs_get_subscriptions' ) ) {
			$opted_out_ids = array_keys(
				\wcs_get_subscriptions(
					[
						'subscriptions_per_page' => -1,
						'subscription_status'    => 'any',
						'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							[
								'key'   => $meta_key,
								'value' => 'no',
							],
						],
					]
				)
			);
		}

		$product_sub_ids = array_diff( $product_sub_ids, $opted_out_ids );

		$result = array_values( array_unique( array_merge( $enabled_ids, $product_sub_ids ) ) );

		\set_transient( self::GROUP_SUBSCRIPTION_IDS_TRANSIENT, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Clear the group subscription IDs transient cache.
	 *
	 * Called when group subscription settings change to ensure the filter
	 * reflects current state.
	 */
	public static function clear_group_subscription_ids_cache() {
		\delete_transient( self::GROUP_SUBSCRIPTION_IDS_TRANSIENT );
	}

	/**
	 * Clear the group subscription IDs cache when a product's group settings
	 * may have changed.
	 *
	 * @param int $product_id The product ID being saved.
	 */
	public static function maybe_clear_cache_on_product_save( $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' ] ) || \get_post_meta( $product_id, self::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', true ) ) {
			self::clear_group_subscription_ids_cache();
		}
	}

	/**
	 * Clear the group subscription IDs cache when a subscription is trashed
	 * or permanently deleted (CPT mode).
	 *
	 * @param int $post_id The post ID being trashed/deleted.
	 */
	public static function maybe_clear_cache_on_subscription_delete( $post_id ) {
		if ( 'shop_subscription' === \get_post_type( $post_id ) ) {
			self::clear_group_subscription_ids_cache();
		}
	}

	/**
	 * Filter the subscription list table query by group subscription status (HPOS).
	 *
	 * @param array $query_args The query args for the list table.
	 *
	 * @return array The filtered query args.
	 */
	public static function filter_subscriptions_by_group( $query_args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['_newspack_group_subscription'] ) ) {
			return $query_args;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = \sanitize_text_field( \wp_unslash( $_GET['_newspack_group_subscription'] ) );
		if ( ! in_array( $filter, [ 'group', 'non-group' ], true ) ) {
			return $query_args;
		}

		return self::apply_group_filter( $query_args, $filter, self::get_group_subscription_ids() );
	}

	/**
	 * Filter the subscription list by group subscription status (CPT mode).
	 *
	 * Uses the 'request' filter, which is the same approach WCS uses for its
	 * own product/customer/payment method filters on edit.php.
	 *
	 * @param array $query_vars The query vars for the admin list table request.
	 *
	 * @return array The filtered query vars.
	 */
	public static function filter_subscriptions_by_group_cpt( $query_vars ) {
		global $typenow;

		if ( ! is_admin() || 'shop_subscription' !== $typenow ) {
			return $query_vars;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['_newspack_group_subscription'] ) ) {
			return $query_vars;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = \sanitize_text_field( \wp_unslash( $_GET['_newspack_group_subscription'] ) );
		if ( ! in_array( $filter, [ 'group', 'non-group' ], true ) ) {
			return $query_vars;
		}

		return self::apply_group_filter( $query_vars, $filter, self::get_group_subscription_ids() );
	}

	/**
	 * Apply the group subscription filter to a set of query args by mutating
	 * post__in / post__not_in. Shared by the HPOS and CPT filter callbacks.
	 *
	 * @param array  $args      The query args (HPOS) or query vars (CPT).
	 * @param string $filter    Either 'group' or 'non-group'.
	 * @param int[]  $group_ids The pre-collected group subscription IDs.
	 *
	 * @return array The mutated args.
	 */
	private static function apply_group_filter( $args, $filter, $group_ids ) {
		if ( 'group' === $filter ) {
			if ( empty( $group_ids ) ) {
				$args['post__in'] = [ 0 ];
			} elseif ( ! isset( $args['post__in'] ) ) {
				$args['post__in'] = $group_ids;
			} else {
				$intersected      = array_intersect( $args['post__in'], $group_ids );
				$args['post__in'] = empty( $intersected ) ? [ 0 ] : array_values( $intersected );
			}
		} elseif ( 'non-group' === $filter ) {
			if ( ! empty( $group_ids ) ) {
				$args['post__not_in'] = isset( $args['post__not_in'] ) // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
					? array_merge( $args['post__not_in'], $group_ids )
					: $group_ids;
			}
		}

		return $args;
	}

	/**
	 * Register the publisher-configurable group label settings.
	 */
	public static function register_label_settings() {
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}
		// Group name is unused (no settings_fields() form); registers sanitize_callback via update_option().
		\register_setting(
			'newspack_group_subscription',
			'newspack_group_subscription_label_singular',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			]
		);
		\register_setting(
			'newspack_group_subscription',
			'newspack_group_subscription_label_plural',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			]
		);
	}
}
Group_Subscription_Settings::init();
