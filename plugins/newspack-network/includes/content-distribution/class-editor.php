<?php
/**
 * Newspack Network Content Distribution Editor.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Utils\Network;
use WP_Post;

/**
 * Editor Class.
 */
class Editor {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
		add_filter( 'manage_posts_columns', [ __CLASS__, 'add_distribution_column' ], 10, 2 );
		add_filter( 'manage_pages_columns', [ __CLASS__, 'add_distribution_column' ], 10, 2 );
		add_action( 'manage_posts_custom_column', [ __CLASS__, 'render_distribution_column' ], 10, 2 );
		add_action( 'manage_pages_custom_column', [ __CLASS__, 'render_distribution_column' ], 10, 2 );
		add_action( 'admin_footer', [ __CLASS__, 'add_posts_column_styles' ] );
	}

	/**
	 * Register the meta available in the editor.
	 */
	public static function register_meta() {
		$post_types = Content_Distribution_Class::get_distributed_post_types();
		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				Outgoing_Post::DISTRIBUTED_POST_META,
				[
					'single'        => true,
					'type'          => 'array',
					'show_in_rest'  => [
						'schema' => [
							'context' => [ 'edit' ],
							'type'    => 'array',
							'default' => [],
							'items'   => [
								'type' => 'string',
							],
						],
					],
					'auth_callback' => function () {
						return current_user_can( Admin::CAPABILITY );
					},
				]
			);
		}
	}

	/**
	 * Action callback.
	 *
	 * @return void
	 */
	public static function enqueue_block_editor_assets(): void {
		$screen = get_current_screen();
		if (
			! current_user_can( Admin::CAPABILITY )
			|| ! in_array( $screen->post_type, Content_Distribution_Class::get_distributed_post_types(), true )
		) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( Content_Distribution_Class::is_post_incoming( $post ) ) {
			self::enqueue_block_editor_assets_for_incoming_post( $post );
		} else {
			self::enqueue_block_editor_assets_for_outgoing_post( $post );
		}
	}

	/**
	 * Enqueue block editor assets.
	 *
	 * @param WP_Post $post The post being edited.
	 *
	 * @return void
	 */
	private static function enqueue_block_editor_assets_for_incoming_post( WP_Post $post ): void {
		$incoming = new Incoming_Post( $post->ID );

		wp_enqueue_script(
			'newspack-network-incoming-post',
			plugins_url( '../../dist/incoming-post.js', __FILE__ ),
			[],
			filemtime( NEWSPACK_NETWORK_PLUGIN_DIR . 'dist/incoming-post.js' ),
			true
		);
		wp_register_style(
			'newspack-network-incoming-post',
			plugins_url( '../../dist/incoming-post.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_NETWORK_PLUGIN_DIR . 'dist/incoming-post.css' ),
		);
		wp_style_add_data( 'newspack-network-incoming-post', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-network-incoming-post' );

		wp_localize_script(
			'newspack-network-incoming-post',
			'newspack_network_incoming_post',
			[
				'originalSiteUrl'     => $incoming->get_original_site_url(),
				'originalPostEditUrl' => $incoming->get_original_post_edit_url(),
				'unlinked'            => ! $incoming->is_linked(),
				'postTypeLabel'       => get_post_type_labels( get_post_type_object( $post->post_type ) )->singular_name,
			]
		);
	}

	/**
	 * Enqueue block editor assets.
	 *
	 * @param WP_Post $post The post being edited.
	 *
	 * @return void
	 */
	private static function enqueue_block_editor_assets_for_outgoing_post( WP_Post $post ): void {
		wp_enqueue_script(
			'newspack-network-outgoing-post',
			plugins_url( '../../dist/outgoing-post.js', __FILE__ ),
			[],
			filemtime( NEWSPACK_NETWORK_PLUGIN_DIR . 'dist/outgoing-post.js' ),
			true
		);
		wp_register_style(
			'newspack-network-outgoing-post',
			plugins_url( '../../dist/outgoing-post.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_NETWORK_PLUGIN_DIR . 'dist/outgoing-post.css' ),
		);
		wp_style_add_data( 'newspack-network-outgoing-post', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-network-outgoing-post' );

		wp_localize_script(
			'newspack-network-outgoing-post',
			'newspack_network_outgoing_post',
			[
				'default_status'   => Admin::get_default_distribution_status(),
				'network_sites'    => Network::get_networked_urls(),
				'distributed_meta' => Outgoing_Post::DISTRIBUTED_POST_META,
				'post_type_label'  => get_post_type_labels( get_post_type_object( $post->post_type ) )->singular_name,
			]
		);
	}

	/**
	 * Add distribution column to the posts list.
	 *
	 * @param array  $columns   Columns.
	 * @param string $post_type Post type.
	 *
	 * @return array
	 */
	public static function add_distribution_column( $columns, $post_type = '' ) {
		if ( ! $post_type ) {
			$post_type = get_current_screen()->post_type;
		}
		if ( ! in_array( $post_type, Content_Distribution_Class::get_distributed_post_types(), true ) ) {
			return $columns;
		}
		$columns['content_distribution'] = sprintf(
			'<span title="%1$s">%2$s <span class="screen-reader-text">%1$s</span></span>',
			esc_attr__( 'Content Distribution', 'newspack-network' ),
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" style="margin: -2px 0 -6px;"><path d="M11.2 13h1.5v7h-1.5v-7ZM7.4 5.6l.5-.5L6.8 4l-.5.5c-3.1 3.1-3.1 8.2 0 11.3l.5.5 1.1-1.1-.5-.5c-2.5-2.5-2.5-6.7 0-9.2Zm2.7 1.6L9 6.1l-.5.5c-2 2-2 5.1 0 7.1l.5.5 1.1-1.1-.5-.5c-1.4-1.4-1.4-3.6 0-4.9l.5-.5Zm1.9 2c-.6 0-1 .4-1 1s.4 1 1 1 1-.4 1-1-.4-1-1-1ZM17.1 4 16 5.1l.5.5c2.5 2.5 2.5 6.7 0 9.2l-.5.5 1.1 1.1.5-.5c3.1-3.1 3.1-8.2 0-11.3l-.5-.5Zm-1.6 2.7-.5-.5-1.1 1.1.5.5c1.4 1.4 1.4 3.6 0 4.9l-.5.5 1.1 1.1.5-.5c2-2 2-5.1 0-7.1Z" fill="#406ebc"></path></svg>'
		);
		return $columns;
	}

	/**
	 * Render the distribution column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 *
	 * @return void
	 */
	public static function render_distribution_column( $column, $post_id ) {
		if ( 'content_distribution' !== $column ) {
			return;
		}

		$is_incoming = Content_Distribution_Class::is_post_incoming( $post_id );
		$is_outgoing = Content_Distribution_Class::is_post_distributed( $post_id );

		if ( ! $is_incoming && ! $is_outgoing ) {
			return;
		}

		$original_url      = '';
		$original_site_url = '';

		if ( $is_incoming ) {
			try {
				$incoming_post     = new Incoming_Post( $post_id );
				$linked            = $incoming_post->is_linked();
				$original_url      = $incoming_post->get_original_post_url();
				$original_site_url = $incoming_post->get_original_site_url();
			} catch ( \Exception $e ) {
				$linked = false;
			}
			printf(
				$original_url ?
					'<a href="%1$s" title="%2$s %3$s" rel="external" target="_blank">%4$s<span class="screen-reader-text">%2$s %3$s</span></a>' :
					'<span title="%2$s">%4$s<span class="screen-reader-text">%2$s</span></span>',
				esc_url( $original_url ),
				$linked ?
					sprintf(
						// translators: %s is the original site URL.
						esc_html__( 'Originally posted and linked to %s.', 'newspack-network' ),
						esc_url( $original_site_url )
					) :
					sprintf(
						// translators: %s is the original site URL.
						esc_html__( 'Originally posted in %s and currently unlinked.', 'newspack-network' ),
						esc_url( $original_site_url )
					),
				$original_url ? esc_html__( 'Click to visit the original post.', 'newspack-network' ) : '',
				$linked ?
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M10 17.389H8.444A5.194 5.194 0 1 1 8.444 7H10v1.5H8.444a3.694 3.694 0 0 0 0 7.389H10v1.5ZM14 7h1.556a5.194 5.194 0 0 1 0 10.39H14v-1.5h1.556a3.694 3.694 0 0 0 0-7.39H14V7Zm-4.5 6h5v-1.5h-5V13Z"></path></svg>' :
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M17.031 4.703 15.576 4l-1.56 3H14v.03l-2.324 4.47H9.5V13h1.396l-1.502 2.889h-.95a3.694 3.694 0 0 1 0-7.389H10V7H8.444a5.194 5.194 0 1 0 0 10.389h.17L7.5 19.53l1.416.719L15.049 8.5h.507a3.694 3.694 0 0 1 0 7.39H14v1.5h1.556a5.194 5.194 0 0 0 .273-10.383l1.202-2.304Z"></path></svg>'
			);
		} else {
			$outgoing_post      = new Outgoing_Post( $post_id );
			$distribution_count = count( $outgoing_post->get_distribution() );
			printf(
				'<span title="%2$s">%1$s<span class="screen-reader-text">%2$s</span></span>',
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M17.3 10.1C17.3 7.60001 15.2 5.70001 12.5 5.70001C10.3 5.70001 8.4 7.10001 7.9 9.00001H7.7C5.7 9.00001 4 10.7 4 12.8C4 14.9 5.7 16.6 7.7 16.6H9.5V15.2H7.7C6.5 15.2 5.5 14.1 5.5 12.9C5.5 11.7 6.5 10.5 7.7 10.5H9L9.3 9.40001C9.7 8.10001 11 7.20001 12.5 7.20001C14.3 7.20001 15.8 8.50001 15.8 10.1V11.4L17.1 11.6C17.9 11.7 18.5 12.5 18.5 13.4C18.5 14.4 17.7 15.2 16.8 15.2H14.5V16.6H16.7C18.5 16.6 19.9 15.1 19.9 13.3C20 11.7 18.8 10.4 17.3 10.1Z M14.1245 14.2426L15.1852 13.182L12.0032 10L8.82007 13.1831L9.88072 14.2438L11.25 12.8745V18H12.75V12.8681L14.1245 14.2426Z"></path></svg>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				sprintf(
					esc_html(
						// translators: %s is the number of network sites the post has been distributed to.
						_n(
							'This post has been distributed to %d network site.',
							'This post has been distributed to %d network sites.',
							$distribution_count,
							'newspack-network'
						)
					),
					esc_attr( number_format_i18n( $distribution_count ) )
				)
			);
		}
	}

	/**
	 * Add posts columns styles.
	 */
	public static function add_posts_column_styles() {
		$screen = get_current_screen();
		if ( ! in_array( $screen->post_type, Content_Distribution_Class::get_distributed_post_types(), true ) ) {
			return;
		}
		?>
		<style>
			.wp-list-table th#content_distribution,
			.wp-list-table .column-content_distribution {
				width: 32px;
				text-align: center;
			}
			.wp-list-table .column-content_distribution a {
				display: inline-block;
			}
			.wp-list-table .column-content_distribution a svg {
				fill: currentcolor;
			}
		</style>
		<?php
	}
}
