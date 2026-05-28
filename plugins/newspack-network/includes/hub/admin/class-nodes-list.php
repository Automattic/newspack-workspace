<?php
/**
 * Newspack Hub Nodes List Table
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Admin;

use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Node;

/**
 * The Nodes List Table
 */
class Nodes_List {

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'manage_' . Nodes::POST_TYPE_SLUG . '_posts_columns', [ __CLASS__, 'posts_columns' ] );
		add_action( 'manage_' . Nodes::POST_TYPE_SLUG . '_posts_custom_column', [ __CLASS__, 'posts_columns_values' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_enqueue_scripts' ] );
	}

	/**
	 * Cache for site info responses.
	 *
	 * @var array
	 */
	private static $node_site_info_cache = [];

	/**
	 * Cache for Hub site info.
	 *
	 * @var array
	 */
	private static $hub_site_info = false;

	/**
	 * Modify columns on post type table
	 *
	 * @param array $columns Registered columns.
	 * @return array
	 */
	public static function posts_columns( $columns ) {
		unset( $columns['date'] );
		unset( $columns['stats'] );
		if ( \Newspack_Network\Admin::use_experimental_auditing_features() ) {
			$sync_users_count = \Newspack_Network\Utils\Users::get_synchronized_users_count();
			$sync_users_info = sprintf(
				' <span class="dashicons dashicons-info-outline" title="%s"></span>',
				sprintf(
					/* translators: list of user roles which will entail synchronization */
					esc_attr__( 'Users with the following roles: %1$s (%2$d on the Hub)', 'newspack-network' ),
					implode( ', ', \Newspack_Network\Utils\Users::get_synced_user_roles() ),
					$sync_users_count
				)
			);
			/* translators: %d is the synchronizable users count. */
			$columns['sync_users'] = sprintf( __( 'Synchronizable Users (%d)', 'newspack-network' ), $sync_users_count ) . $sync_users_info;
			if ( isset( $_GET['_newspack_user_discrepancies'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$columns['user_discrepancies'] = __( 'Discrepancies in Sync. Users', 'newspack-network' );
			}

			$not_sync_users_info = sprintf(
				' <span class="dashicons dashicons-info-outline" title="%s"></span>',
				sprintf(
					/* translators: list of user roles which will entail synchronization */
					esc_attr__( 'Users with roles different than the following roles: %1$s (%2$d on the Hub)', 'newspack-network' ),
					implode( ', ', \Newspack_Network\Utils\Users::get_synced_user_roles() ),
					\Newspack_Network\Utils\Users::get_not_synchronized_users_count()
				)
			);
			$columns['not_sync_users'] = __( 'Non-synchronizable Users', 'newspack-network' ) . $not_sync_users_info;
		}
		$columns['links'] = __( 'Links', 'newspack-network' );
		return $columns;
	}

	/**
	 * Add content to the custom column
	 *
	 * @param string $column The current column.
	 * @param int    $post_id The current post ID.
	 * @return void
	 */
	public static function posts_columns_values( $column, $post_id ) {
		$node = new Node( $post_id );
		if ( 'links' === $column ) {
			$links        = array_map(
				function ( $bookmark ) {
					return sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $bookmark['url'] ), esc_html( $bookmark['label'] ) );
				},
				$node->get_bookmarks()
			);
			$allowed_tags = [
				'a' => [
					'href'   => [],
					'target' => [],
				],
			];
			?>
				<p>
					<?php echo wp_kses( implode( ' | ', $links ), $allowed_tags ); ?>
				</p>
			<?php
		}
		if ( ! isset( self::$node_site_info_cache[ $post_id ] ) ) {
			self::$node_site_info_cache[ $post_id ] = $node->get_site_info();
		}
		$node_site_info = self::$node_site_info_cache[ $post_id ];

		if ( isset( $_GET['_newspack_user_discrepancies'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! self::$hub_site_info ) {
				self::$hub_site_info = [
					'sync_user_emails' => \Newspack_Network\Utils\Users::get_synchronized_users_emails(),
				];
			}

			// Display user discrepancies.
			$node_users_emails = $node_site_info->sync_users_emails ?? [];
			// Users who are on the Hub but not on the Node.
			$not_on_node = array_diff( self::$hub_site_info['sync_user_emails'], $node_users_emails );
			// Users who are not on the Node but are on the Hub.
			$not_on_hub = array_diff( $node_users_emails, self::$hub_site_info['sync_user_emails'] );
			if ( 'user_discrepancies' === $column ) {
				?>
					<span>
						<?php
							echo esc_html(
								/* translators: %d - users on the Hub only, %d on the Node only */
								sprintf( __( '%1$d on the Hub only, %2$d on the Node only', 'newspack-network' ), count( $not_on_node ), count( $not_on_hub ) )
							);
						?>
					</span>
				<?php
			}
		}

		if ( 'sync_users' === $column ) {
			$users_link = add_query_arg(
				[
					'role__in' => implode( ',', \Newspack_Network\Utils\Users::get_synced_user_roles() ),
				],
				trailingslashit( $node->get_url() ) . 'wp-admin/users.php'
			);
			?>
				<a href="<?php echo esc_url( $users_link ); ?>"><?php echo esc_html( $node_site_info->sync_users_count ?? 0 ); ?></a>
			<?php
		}
		if ( 'not_sync_users' === $column ) {
			$users_link = add_query_arg(
				[
					'role__not_in' => implode( ',', \Newspack_Network\Utils\Users::get_synced_user_roles() ),
				],
				trailingslashit( $node->get_url() ) . 'wp-admin/users.php'
			);
			?>
				<a href="<?php echo esc_url( $users_link ); ?>"><?php echo esc_html( $node_site_info->not_sync_users_count ?? 0 ); ?></a>
			<?php
		}
	}

	/**
	 * Enqueues the admin styles.
	 *
	 * @return void
	 */
	public static function admin_enqueue_scripts() {
		$page_slug = 'edit-' . Nodes::POST_TYPE_SLUG;
		if ( get_current_screen()->id !== $page_slug ) {
			return;
		}

		wp_enqueue_style(
			'newspack-network-nodes-list',
			plugins_url( 'css/nodes-list.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_NETWORK_PLUGIN_DIR . '/includes/hub/admin/css/nodes-list.css' )
		);
	}
}
