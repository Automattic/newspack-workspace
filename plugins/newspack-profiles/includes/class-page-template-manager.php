<?php
/**
 * Page template manager for profiles.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\Configs\Pattern_Config;
use NewspackProfiles\Traits\Singleton;

/**
 * Page_Template_Manager class to manage page templates for profiles.
 */
class Page_Template_Manager {

	use Singleton;

	/**
	 * Custom post type slug for profile templates.
	 */
	const POST_TYPE = 'np_profile_template';

	/**
	 * Initialize the template manager.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the custom post type for profile templates.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Newspack Profile Templates', 'newspack-profiles' ),
					'singular_name' => __( 'Newspack Profile Template', 'newspack-profiles' ),
				),
				'public'              => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => true,
				'show_in_menu'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'hierarchical'        => true,
				'publicly_queryable'  => true,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'menu_icon'           => 'dashicons-id',
				'rewrite'             => array( 'slug' => 'profiles' ),
				'query_var'           => false,
				'capabilities'        => array(
					'create_posts'           => 'npp_do_not_allow',
					'delete_posts'           => 'npp_do_not_allow',
					'delete_published_posts' => 'npp_do_not_allow',
				),
				'supports'            => array( 'title', 'editor', 'revisions', 'author', 'custom-fields' ),
			)
		);
	}

	/**
	 * Create pages for a profile.
	 *
	 * @param array $config Configuration for the profile.
	 *
	 * @return array|null Array containing IDs of created pages or null on failure.
	 */
	public function create( array $config ): ?array {
		$list_page_id = $this->create_list_page( $config );

		if ( empty( $list_page_id ) ) {
			return null;
		}

		$single_page_id = $this->create_single_page( $config, $list_page_id );

		if ( empty( $single_page_id ) ) {
			return null;
		}

		return array(
			'single' => $single_page_id,
			'list'   => $list_page_id,
		);
	}

	/**
	 * Update pages associated with a profile.
	 *
	 * @param array           $config Configuration for the profile.
	 * @param 'single'|'list' $type The type of page to update.
	 */
	public function update( array $config, string $type ): void {
		if ( is_integer( $config['pages'][ $type ] ) ) {
			wp_update_post(
				array(
					'ID'           => $config['pages'][ $type ],
					'post_content' => $this->get_page_template( $config, $type ),
				)
			);
		}
	}

	/**
	 * Delete pages associated with a profile.
	 *
	 * @param array $pages Array containing IDs of pages to delete.
	 */
	public function delete( array $pages ): void {
		if ( is_integer( $pages['single'] ) ) {
			wp_delete_post( $pages['single'] );
		}

		if ( is_integer( $pages['list'] ) ) {
			wp_delete_post( $pages['list'] );
		}
	}

	/**
	 * Update the status of pages associated with a profile.
	 *
	 * @param array  $config Configuration for the profile.
	 * @param string $status The new status to set.
	 */
	public function update_status( array $config, string $status ): void {
		if ( is_integer( $config['pages']['single'] ) ) {
			wp_update_post(
				array(
					'ID'          => $config['pages']['single'],
					'post_status' => $status,
				)
			);
		}

		if ( is_integer( $config['pages']['list'] ) ) {
			wp_update_post(
				array(
					'ID'          => $config['pages']['list'],
					'post_status' => $status,
				)
			);
		}
	}

	/**
	 * Create a single profile page.
	 *
	 * @param array $config Configuration for the profile.
	 * @param int   $list_page_id The ID of the list page.
	 *
	 * @return int The ID of the created page.
	 */
	private function create_single_page( array $config, $list_page_id ): int {
		return wp_insert_post(
			array(
				'post_title'   => $config['name'] . __( ' (Individual)', 'newspack-profiles' ),
				'post_content' => $this->get_page_template( $config, 'single' ),
				'post_status'  => 'draft',
				'post_type'    => self::POST_TYPE,
				'post_parent'  => $list_page_id,
			)
		);
	}

	/**
	 * Create a list profile page.
	 *
	 * @param array $config Configuration for the profile.
	 *
	 * @return int The ID of the created page.
	 */
	private function create_list_page( array $config ): int {
		return wp_insert_post(
			array(
				'post_name'    => $config['slug'],
				'post_title'   => $config['name'],
				'post_content' => $this->get_page_template( $config, 'list' ),
				'post_status'  => 'draft',
				'post_type'    => self::POST_TYPE,
			)
		);
	}

	/**
	 * Get the page template content.
	 *
	 * @param array           $config Configuration for the profile.
	 * @param 'single'|'list' $type The type of page template.
	 *
	 * @return string The page template content.
	 */
	private function get_page_template( array $config, string $type ): string {
		$template_name = 'single' === $type ? 'single.html' : 'list.html';

		$template = file_get_contents( TEMPLATES_DIR . '/' . $template_name );

		if ( ! $template ) {
			return '';
		}

		$pattern = Pattern_Config::get( $config['pattern'][ $type ], $config['mappings'] );

		if ( empty( $pattern['content'] ) ) {
			return '';
		}

		$template = str_replace( '{{pattern}}', $pattern['content'], $template );
		$template = str_replace( '{{slug}}', $config['slug'], $template );

		if ( 'single' === $type ) {
			$sample_data = Data_Sources::get_sample_data( $config );

			if ( ! empty( $sample_data['slug'] ) ) {
				$template = str_replace( '{{profile_slug}}', $sample_data['slug'], $template );
			} else {
				$template = str_replace( '{{profile_slug}}', '', $template );
			}
		}

		return $template;
	}
}
