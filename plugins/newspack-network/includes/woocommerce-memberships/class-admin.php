<?php
/**
 * Newspack Network Admin customizations for woocommerce memberships.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Memberships;

/**
 * Handles admin tweaks for woocommerce memberships.
 *
 * Adds a metabox to the membership plan edit screen to allow the user to add a network id metadata to the plans
 */
class Admin {

	/**
	 * The membership plan custom post type (defined in the woocommerce-memberships plugin).
	 *
	 * @var string
	 */
	const MEMBERSHIP_PLANS_CPT = 'wc_membership_plan';

	/**
	 * The network id meta key.
	 *
	 * @var string
	 */
	const NETWORK_ID_META_KEY = '_newspack_network_id';

	/**
	 * The key of the metadata that flags a user membership that is managed in another site in the network.
	 *
	 * @var string
	 */
	const NETWORK_MANAGED_META_KEY = '_managed_by_newspack_network';

	/**
	 * The key of the metadata that holds the ID of the user membership in the origin site
	 *
	 * @var string
	 */
	const REMOTE_ID_META_KEY = '_remote_id';

	/**
	 * The key of the metadata that holds the URL of the origin site
	 *
	 * @var string
	 */
	const SITE_URL_META_KEY = '_remote_site_url';

	/**
	 * The special param to filter the WC Memberships table.
	 *
	 * @var string
	 */
	const MEMBERSHIPS_TABLE_EMAILS_QUERY_PARAM = '_newspack_emails_query';

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 100 ); // Memberships plugin does something there and we need a higher priority.
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ) );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'get_edit_post_link' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'post_row_actions' ), 99, 2 ); // After the Memberships plugin.
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 20, 4 );
		add_filter( 'wc_memberships_rest_api_membership_plan_data', [ __CLASS__, 'add_data_to_membership_plan_response' ], 2, 3 );
		add_filter( 'woocommerce_rest_prepare_wc_user_membership', [ __CLASS__, 'add_data_to_wc_user_membership_response' ], 2, 3 );
		add_filter( 'request', [ __CLASS__, 'request_query' ] );
		add_action( 'pre_user_query', [ __CLASS__, 'pre_user_query' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
	}

	/**
	 * Get active members' emails.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan The membership plan.
	 */
	public static function get_active_members_emails( $plan ) {
		$active_memberships = $plan->get_memberships( [ 'post_status' => 'wcm-active' ] );
		return array_map(
			function ( $membership ) {
				$user = get_user_by( 'id', $membership->get_user_id() );
				if ( $user ) {
					return strtolower( $user->user_email );
				} else {
					return '';
				}
			},
			$active_memberships
		);
	}

	/**
	 * Filter membership plans to add user count.
	 *
	 * @param array                           $data associative array of membership plan data.
	 * @param \WC_Memberships_Membership_Plan $plan the membership plan.
	 * @param null|\WP_REST_Request           $request The request object.
	 */
	public static function add_data_to_membership_plan_response( $data, $plan, $request ) {
		if ( $request && isset( $request->get_headers()['x_np_network_signature'] ) ) {
			$data['active_memberships_count'] = $plan->get_memberships_count( 'active' );
			$network_pass_id = get_post_meta( $plan->id, self::NETWORK_ID_META_KEY, true );
			if ( $network_pass_id && $request->get_param( 'include_active_members_emails' ) ) {
				$data['active_subscriptions_count'] = self::get_plan_related_active_subscriptions( $plan );
				$data['active_members_emails'] = array_values( array_unique( self::get_active_members_emails( $plan ) ) );
			} else {
				$data['active_subscriptions_count'] = __( 'Only displayed for plans with a Network ID.', 'newspack-network' );
			}
		}
		return $data;
	}

	/**
	 * Get the active subscriptions related to a membership plan.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan The membership plan.
	 */
	public static function get_plan_related_active_subscriptions( $plan ) {
		$product_ids = $plan->get_product_ids();
		$subscriptions = wcs_get_subscriptions_for_product( $product_ids, 'ids', [ 'subscription_status' => 'active' ] );
		return count( $subscriptions );
	}

	/**
	 * Filter user membership data from REST API.
	 *
	 * @param \WP_REST_Response $response the response object.
	 * @param null|\WP_Post     $user the user membership post object.
	 * @param \WP_REST_Request  $request the request object.
	 */
	public static function add_data_to_wc_user_membership_response( $response, $user, $request ) {
		if ( $request && isset( $request->get_headers()['x_np_network_signature'] ) ) {
			// Add network plan ID to the response.
			$plan = wc_memberships_get_membership_plan( $response->data['plan_id'] );
			if ( $plan !== false ) {
				$response->data['plan_network_id'] = get_post_meta( $plan->id, self::NETWORK_ID_META_KEY, true );
			}
		}
		return $response;
	}

	/**
	 * Adds a meta box to the membership plan edit screen.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'newspack-network-memberships-meta-box',
			__( 'Newspack Network', 'newspack-network' ),
			array( __CLASS__, 'render_meta_box' ),
			self::MEMBERSHIP_PLANS_CPT,
			'advanced'
		);
	}

	/**
	 * Renders the meta box.
	 *
	 * @param \WP_Post $post The post object.
	 */
	public static function render_meta_box( $post ) {
		$network_id = get_post_meta( $post->ID, self::NETWORK_ID_META_KEY, true );

		wp_nonce_field( 'newspack_network_save_membership_plan', 'newspack_network_save_membership_plan_nonce' );
		?>
		<label for="newspack-network-id"><?php esc_html_e( 'Network ID', 'newspack-network' ); ?></label>
		<input type="text" id="newspack-network-id" name="newspack_network_id" value="<?php echo esc_attr( $network_id ); ?>" />
		<p><?php esc_html_e( 'If a Network ID is set, the user memberships will be propagated on the network. Users will be granted the membership with the matching Network ID in all other sites in the network.', 'newspack-network' ); ?></p>
		<?php
	}

	/**
	 * Saves the meta box.
	 *
	 * @param int $post_id The post ID.
	 */
	public static function save_meta_box( $post_id ) {

		$post = get_post( $post_id );

		$post_type = $post->post_type;

		if ( self::MEMBERSHIP_PLANS_CPT !== $post_type ) {
			return;
		}

		if ( ! isset( $_POST['newspack_network_save_membership_plan_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( $_POST['newspack_network_save_membership_plan_nonce'] ), 'newspack_network_save_membership_plan' )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
			return;
		}

		$network_id = sanitize_text_field( wp_unslash( $_POST['newspack_network_id'] ?? '' ) );

		$network_id = self::unique_network_id( $network_id, $post_id );

		update_post_meta( $post_id, self::NETWORK_ID_META_KEY, $network_id );

		/**
		 * Triggers an action when a membership plan is saved.
		 *
		 * @param int    $post_id The post ID of the membership plan.
		 */
		do_action( 'newspack_network_save_membership_plan', $post_id );
	}

	/**
	 * Given a network id, makes it unique among all the membership plans.
	 *
	 * @param string $network_id The network id to make unique.
	 * @param int    $post_id The post ID that is being saved.
	 * @return string The unique network id.
	 */
	private static function unique_network_id( $network_id, $post_id ) {
		global $wpdb;
		$network_id = sanitize_text_field( $network_id );
		$query = $wpdb->prepare(
			"SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s AND post_id != %d",
			self::NETWORK_ID_META_KEY,
			$post_id
		);

		$ids = $wpdb->get_col( $query ); // phpcs:ignore

		$count               = 2;
		$original_network_id = $network_id;

		while ( in_array( $network_id, $ids, true ) ) {
			$network_id = $original_network_id . '-' . $count;
			$count++;
		}

		return $network_id;
	}

	/**
	 * Filters the edit post link for user memberships managed in another site of the network.
	 *
	 * @param string $link The edit post link.
	 * @param int    $post_id The post ID.
	 * @return string
	 */
	public static function get_edit_post_link( $link, $post_id ) {
		$managed_by_newspack_network = get_post_meta( $post_id, self::NETWORK_MANAGED_META_KEY, true );
		if ( $managed_by_newspack_network ) {
			$remote_url = get_post_meta( $post_id, self::SITE_URL_META_KEY, true );
			$remote_id  = get_post_meta( $post_id, self::REMOTE_ID_META_KEY, true );
			$link       = sprintf( '%s/wp-admin/post.php?post=%d&action=edit', $remote_url, $remote_id );
		}
		return $link;
	}

	/**
	 * Filters the row actions for user memberships managed in another site of the network.
	 *
	 * @param string[] $actions An array of row action links. Defaults are
	 *                          'Edit', 'Quick Edit', 'Restore', 'Trash',
	 *                          'Delete Permanently', 'Preview', and 'View'.
	 * @param WP_Post  $post    The post object.
	 * @return array
	 */
	public static function post_row_actions( $actions, $post ) {
		$managed_by_newspack_network = get_post_meta( $post->ID, self::NETWORK_MANAGED_META_KEY, true );
		$remote_url                  = get_post_meta( $post->ID, self::SITE_URL_META_KEY, true );
		if ( ! $managed_by_newspack_network ) {
			return $actions;
		}
		$link     = self::get_edit_post_link( '', $post->ID );
		$link_tag = sprintf( '<a href="%s" target="_blank">%s</a>', $link, $remote_url );
		return [
			'none' => sprintf(
				// translators: %s is the site URL with a link to edit the post.
				__( 'Managed in %s', 'newspack-network' ),
				$link_tag
			),
		];
	}

	/**
	 * Blocks any user from editing a user membership that is managed in another site of the network.
	 *
	 * @param array  $caps The array of required primitive capabilities or roles for the requested capability.
	 * @param string $cap The requested capability.
	 * @param int    $user_id The user ID we are checking permissions for.
	 * @param array  $args The context args passed to the check.
	 * @return array
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( 'edit_post' === $cap ) {
			$post_id                     = $args[0];
			$managed_by_newspack_network = get_post_meta( $post_id, self::NETWORK_MANAGED_META_KEY, true );
			if ( $managed_by_newspack_network ) {
				$caps = [ 'do_not_allow' ];
			}
		}
		return $caps;
	}

	/**
	 * Get table email query param.
	 */
	private static function get_table_emails_query_param() {
		$emails_param_value = isset( $_GET[ self::MEMBERSHIPS_TABLE_EMAILS_QUERY_PARAM ] ) ? sanitize_text_field( $_GET[ self::MEMBERSHIPS_TABLE_EMAILS_QUERY_PARAM ] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $emails_param_value ) {
			return explode( ',', $emails_param_value );
		}
		return false;
	}

	/**
	 * Handles custom filters for the user memberships screen.
	 *
	 * @param array $vars query vars for \WP_Query.
	 * @return array modified query vars.
	 */
	public static function request_query( $vars ) {
		global $typenow;
		if ( 'wc_user_membership' === $typenow ) {
			if ( self::get_table_emails_query_param() ) {
				$users = get_users(
					[
						'fields'         => 'ID',
						'search'         => 'emails',
						'search_columns' => [ 'user_email' ],
					]
				);
				$vars['author__in'] = $users;
			}
		}
		return $vars;
	}

	/**
	 * Handles custom filters for the user query.
	 *
	 * @param \WP_User_Query $user_query The user query.
	 */
	public static function pre_user_query( $user_query ) {
		$emails_from_query = self::get_table_emails_query_param();
		if ( $emails_from_query ) {
			$emails = array_map(
				function( $email ) {
					return "'$email'";
				},
				$emails_from_query
			);
			$user_query->query_where = preg_replace(
				"/user_email LIKE 'emails'/",
				'user_email LIKE ' . implode( ' OR user_email LIKE ', $emails ),
				$user_query->query_where
			);
		}
	}

	/**
	 * Admin notice if viewing memberships table with emails filter.
	 */
	public static function admin_notices() {
		$emails_from_query = self::get_table_emails_query_param();
		if ( $emails_from_query ) {
			?>
				<div class="notice notice-info">
					<p>
						<?php
						/* translators: %s is the list of emails */
						printf( esc_html__( 'Filtering memberships by emails (%d email addresses in the query).', 'newspack-network' ), count( $emails_from_query ) );
						?>
					</p>
				</div>
				<style>.subsubsub,.search-box,.tablenav.top{display: none;}</style>
			<?php
		}
	}
}
