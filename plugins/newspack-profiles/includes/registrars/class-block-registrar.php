<?php
/**
 * Block registrar for the Newspack Profiles plugin.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\Registrars;

use NewspackProfiles\Configs\Pattern_Config;
use NewspackProfiles\Profile_Collections;
use NewspackProfiles\Query_Manager;
use NewspackProfiles\Traits\Singleton;

use const NewspackProfiles\BLOCKS_DIR;
use const NewspackProfiles\BUILD_DIR;

/**
 * Block_Registrar class to handle Gutenberg block registration.
 */
class Block_Registrar {

	use Singleton;

	/**
	 * Constructor for the Block_Registrar class.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ), 10 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_filter( 'block_bindings_supported_attributes_core/social-link', array( $this, 'add_extra_block_bindings_attributes_for_core_social_link' ) );
		add_filter( 'block_bindings_supported_attributes_newspack-profiles/conditional-style', array( $this, 'add_extra_block_bindings_attributes_for_conditional_style' ) );
		add_action( 'remote_data_blocks_query_input_variables', array( $this, 'handle_query_input_variables' ), 10, 2 );
	}

	/**
	 * Register Gutenberg blocks.
	 */
	public function register_blocks(): void {
		if ( ! function_exists( 'register_remote_data_block' ) ) {
			return;
		}

		$profile_collections_config = Profile_Collections::get_instance();

		foreach ( $profile_collections_config->get_all() as $config ) {
			$query_builder = Query_Manager::get_query_builder( $config );

			if ( ! $query_builder || ! $query_builder->has_valid_data_source() ) {
				continue;
			}

			register_remote_data_block(
				array(
					'name'              => $config['slug'],
					'title'             => $config['name'],
					'icon'              => 'newspack-profiles-block-icon',
					'render_query'      => array(
						'query' => $query_builder->get_item_query(),
					),
					'selection_queries' => array(
						array(
							'display_name' => esc_html__( 'Profile List', 'newspack-profiles' ),
							'query'        => $query_builder->get_list_query(),
							'type'         => 'list',
						),
					),
					'patterns'          => $this->get_patterns( $config, 'single' ),
					'overrides'         => array(
						array(
							'name'         => 'slug_override',
							'display_name' => __( 'Dynamically replace slug from URL', 'newspack-profiles' ),
							/* translators: %s is the profile slug */
							'help_text'    => sprintf( __( 'For use on the /%s/<slug> page', 'newspack-profiles' ), $config['slug'] ),
						),
					),
				)
			);

			register_remote_data_block(
				array(
					'title'        => esc_html__( 'List: ', 'newspack-profiles' ) . $config['name'],
					'name'         => 'list-' . $config['slug'],
					'icon'         => 'newspack-profiles-block-icon',
					'render_query' => array(
						'query' => $query_builder->get_list_query(),
					),
					'patterns'     => $this->get_patterns( $config, 'list' ),
				)
			);
		}

		// Register design blocks.
		register_block_type(
			BLOCKS_DIR . 'conditional-style',
		);
	}

	/**
	 * Get block patterns based on the profile configuration.
	 *
	 * @param array           $config Profile configuration.
	 * @param 'single'|'list' $type The type of pattern to retrieve.
	 *
	 * @return array Array of block patterns.
	 */
	private function get_patterns( array $config, string $type ): array {
		$patterns = Pattern_Config::get_all( $config['mappings'] );

		$type_specific_patterns = array_filter(
			$patterns,
			function ( $pattern ) use ( $type ) {
				return $pattern['type'] === $type;
			}
		);

		if ( empty( $type_specific_patterns ) ) {
			return array();
		}

		return array_values(
			array_map(
				function ( $pattern ) use ( $config, $type ) {
					return array(
						'title' => sprintf( '%s/%s/%s', $pattern['name'], $config['slug'], $type ),
						'html'  => $pattern['content'],
					);
				},
				$type_specific_patterns
			)
		);
	}

	/**
	 * Handle query input variables for remote data blocks.
	 *
	 * @param array $input_variables Existing input variables.
	 * @param array $enabled_overrides Enabled overrides.
	 *
	 * @return array Modified input variables.
	 */
	public function handle_query_input_variables( array $input_variables, array $enabled_overrides ): array {
		if ( true === in_array( 'slug_override', $enabled_overrides, true ) ) {
			$slug = get_query_var( 'np_slug' );

			if ( ! empty( $slug ) ) {
				$input_variables['slug'] = strtolower( $slug );
			}
		}

		return $input_variables;
	}

	/**
	 * Enqueue block editor assets.
	 */
	public function enqueue_block_editor_assets() {
		$assets_file = include BUILD_DIR . 'block-editor.asset.php';

		wp_enqueue_script(
			'newspack-profiles-block-editor-script',
			plugins_url( 'dist/block-editor.js', NEWSPACK_PROFILES_PLUGIN_FILE ),
			$assets_file['dependencies'],
			$assets_file['version'],
			true
		);
	}

	/**
	 * Add extra supported attributes for core/social-link block bindings.
	 *
	 * @param array $attributes Existing supported attributes.
	 *
	 * @return array Modified supported attributes.
	 */
	public function add_extra_block_bindings_attributes_for_core_social_link( array $attributes ): array {
		$attributes[] = 'url';

		return $attributes;
	}

	/**
	 * Add extra supported attributes for newspack-profiles/conditional-style block bindings.
	 *
	 * @param array $attributes Existing supported attributes.
	 *
	 * @return array Modified supported attributes.
	 */
	public function add_extra_block_bindings_attributes_for_conditional_style( array $attributes ): array {
		$attributes[] = 'fieldName';

		return $attributes;
	}
}
