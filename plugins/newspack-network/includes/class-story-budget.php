<?php
/**
 * Story Budget Integration Class.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Node\Settings as Node_Settings;
use Newspack_Network\Content_Distribution;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Content_Distribution\Incoming_Post;

use Newspack_Story_Budget\Fields;
use Newspack_Story_Budget\Fields\Editable_Field;

/**
 * Story Budget Integration Class.
 */
class Story_Budget {
	const SITES_FIELD_SLUG = 'network_sites';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 9 );
		add_filter( 'rest_allowed_cors_headers', [ __CLASS__, 'add_cors_headers' ] );
		add_filter( 'newspack_network_content_distribution_ignored_post_meta_keys', [ __CLASS__, 'filter_ignored_post_meta_keys' ] );
		add_filter( 'newspack_network_content_distribution_always_distributed_taxonomies', [ __CLASS__, 'filter_always_distributed_taxonomies' ] );

		add_filter( 'newspack_story_budget_fields', [ __CLASS__, 'add_sites_field' ] );
		add_filter( 'newspack_story_budget_story_metadata', [ __CLASS__, 'add_story_remote_metadata' ], 10, 2 );
		add_filter( 'newspack_story_budget_fields_props', [ __CLASS__, 'add_outgoing_post_network_sites_props' ], 10, 2 );
		add_filter( 'newspack_story_budget_fields_props', [ __CLASS__, 'add_incoming_post_network_sites_props' ], 10, 2 );
		add_filter( 'newspack_story_budget_fields_props', [ __CLASS__, 'add_synced_fields_props' ], 10, 2 );
	}

	/**
	 * Get the Story Budget fields that are synced on distribution.
	 *
	 * @return array The synced fields.
	 */
	private static function get_synced_fields() {
		$synced_fields = [
			'name',   // Story name.
			'status', // Story status.
		];

		/**
		 * Filter the Story Budget fields that are synced on distribution.
		 *
		 * @param array $synced_fields Array of field slugs that are synced.
		 */
		return apply_filters( 'newspack_network_story_budget_synced_fields', $synced_fields );
	}

	/**
	 * Enqueue assets.
	 */
	public static function enqueue_assets() {
		wp_enqueue_script(
			'newspack-story-budget-network',
			plugins_url( '../dist/story-budget.js', __FILE__ ),
			[ 'wp-data-controls', 'wp-core-data', 'wp-components', 'wp-hooks' ],
			filemtime( __DIR__ . '/../dist/story-budget.js' ),
			true
		);

		wp_localize_script(
			'newspack-story-budget-network',
			'newspackStoryBudgetNetwork',
			[
				'sites' => array_map(
					function( $site ) {
						return [
							'url'  => $site['value'],
							'name' => $site['label'],
						];
					},
					self::get_sites_list()
				),
			]
		);
	}

	/**
	 * Add CORS headers to the REST API.
	 *
	 * @param array $headers The headers.
	 *
	 * @return array The headers.
	 */
	public static function add_cors_headers( $headers ) {
		$headers[] = 'X-Network-Site-Url';
		return $headers;
	}

	/**
	 * Filter the ignored post meta keys to include Story Budget fields that are
	 * not intentionally synced.
	 *
	 * @param array $ignored_keys The ignored post meta keys.
	 *
	 * @return array The ignored post meta keys.
	 */
	public static function filter_ignored_post_meta_keys( $ignored_keys ) {
		// Bail if Newspack Story Budget is not active.
		if ( ! class_exists( 'Newspack_Story_Budget\Fields' ) ) {
			return $ignored_keys;
		}

		$fields = Fields::get_all_fields();
		$ignored_fields = array_map(
			function( $field ) {
				$slug = $field->get_slug();
				if ( ! in_array( $slug, self::get_synced_fields(), true ) ) {
					return $field->get_post_meta_name();
				}
				return null;
			},
			$fields
		);
		$ignored_fields = array_filter( $ignored_fields );

		$ignored_fields[] = '_np_story_budget__modified';

		return array_merge( $ignored_keys, $ignored_fields );
	}

	/**
	 * Filter the always distributed taxonomies to include Story Budget taxonomies.
	 *
	 * @param array $always_distributed_taxonomies The always distributed taxonomies.
	 *
	 * @return array The always distributed taxonomies.
	 */
	public static function filter_always_distributed_taxonomies( $always_distributed_taxonomies ) {
		$always_distributed_taxonomies[] = 'newspack_story_status';
		return $always_distributed_taxonomies;
	}

	/**
	 * Add the sites field to Story Budget.
	 *
	 * @param array $fields The fields to add.
	 * @return array The fields to add.
	 */
	public static function add_sites_field( $fields ) {
		$sites_list = self::get_sites_list();
		if ( empty( $sites_list ) ) {
			return $fields;
		}

		$fields[] = [
			'description'        => __( 'The websites this story will be published on.', 'newspack-story-budget' ),
			'is_editable'        => false,
			'is_sortable'        => false,
			'is_multiple'        => true,
			'is_filterable'      => 'always',
			'name'               => __( 'Sites', 'newspack-story-budget' ),
			'show_in_table'      => true,
			'slug'               => self::SITES_FIELD_SLUG,
			'type'               => 'text',
			'options'            => $sites_list,
			'default_order'      => 18,
			'get_value_callback' => [ __CLASS__, 'get_sites_field_value' ],
		];

		return $fields;
	}

	/**
	 * Get the value of the sites field.
	 *
	 * If the post has been distributed, read the value from the distributed post.
	 * If the post is incoming, read the value from the stored payload.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array The value of the field.
	 */
	public static function get_sites_field_value( $post_id ) {
		// If the post has been distributed, read the value from the distributed post.
		$distributed_post = Content_Distribution::get_distributed_post( $post_id );
		if ( $distributed_post ) {
			return $distributed_post->get_distribution();
		}

		// If the post is incoming, read the value from the payload.
		if ( Content_Distribution::is_post_incoming( $post_id ) ) {
			try {
				$incoming_post = new Incoming_Post( $post_id );
				$original_site_url = $incoming_post->get_original_site_url();
				$payload = $incoming_post->get_post_payload();
				$sites = array_filter(
					$payload['sites'],
					function( $url ) {
						return $url !== get_site_url();
					}
				);
				return array_unique(
					array_merge(
						[ $original_site_url ],
						$sites
					)
				);
			} catch ( \Exception $e ) {
				return [];
			}
		}

		// Default is empty array.
		return [];
	}

	/**
	 * Get the sites for the Newspack Story Budget.
	 *
	 * @return array The sites.
	 */
	private static function get_sites_list() {
		$sites = [];

		if ( Site_Role::is_hub() ) {
			$nodes = Nodes::get_all_nodes();
			foreach ( $nodes as $node ) {
				$sites[] = [
					'label' => $node->get_name(),
					'value' => $node->get_url(),
				];
			}
		}

		if ( Site_Role::is_node() ) {
			$hub_url = Node_Settings::get_hub_url();
			$sites[] = [
				// The hub name is not stored in the node, so we'll use a pretty URL as label.
				'label' => preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $hub_url ) ),
				'value' => $hub_url,
			];
			$nodes_data = get_option( Node::HUB_NODES_SYNCED_OPTION, [] );
			foreach ( $nodes_data as $node_data ) {
				$sites[] = [
					'label' => $node_data['title'],
					'value' => $node_data['url'],
				];
			}
		}

		return $sites;
	}

	/**
	 * Add metadata to stories when accessing from a different node.
	 *
	 * @param array $metadata The story metadata.
	 * @param int   $story_id The story ID.
	 *
	 * @return array The story metadata.
	 */
	public static function add_story_remote_metadata( $metadata, $story_id ) {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $metadata;
		}

		$request_site_url = filter_input( INPUT_SERVER, 'HTTP_X_NETWORK_SITE_URL', FILTER_VALIDATE_URL );

		// Only modify the metadata if this is a remote request.
		if ( ! $request_site_url ) {
			return $metadata;
		}

		$metadata['can_pull']  = true;
		$metadata['is_pulled'] = false;

		$outgoing_post = Content_Distribution::get_distributed_post( $story_id );
		if ( ! $outgoing_post ) {
			return $metadata;
		}

		$metadata['is_pulled'] = in_array( $request_site_url, $outgoing_post->get_distribution(), true );

		return $metadata;
	}

	/**
	 * Add field props to the sites field for outgoing posts.
	 *
	 * @param array $fields_props The fields props to add.
	 * @param int   $story_id     The story ID.
	 *
	 * @return array The fields props to add.
	 */
	public static function add_outgoing_post_network_sites_props( $fields_props, $story_id ) {
		$outgoing_post = Content_Distribution::get_distributed_post( $story_id );
		if ( ! $outgoing_post ) {
			return $fields_props;
		}

		$distribution = $outgoing_post->get_distribution();

		$options = self::get_sites_list();

		foreach ( array_keys( $options ) as $option_key ) {
			if ( in_array( $options[ $option_key ]['value'], $distribution, true ) ) {
				$options[ $option_key ]['user_can_apply'] = false;
			}
		}

		if ( ! isset( $fields_props[ self::SITES_FIELD_SLUG ] ) ) {
			$fields_props[ self::SITES_FIELD_SLUG ] = [];
		}
		$fields_props[ self::SITES_FIELD_SLUG ]['options'] = $options;

		return $fields_props;
	}

	/**
	 * Add field props to the sites field for incoming posts.
	 *
	 * @param array $fields_props The fields props to add.
	 * @param int   $story_id     The story ID.
	 *
	 * @return array The fields props to add.
	 */
	public static function add_incoming_post_network_sites_props( $fields_props, $story_id ) {
		if ( ! Content_Distribution::is_post_incoming( $story_id ) ) {
			return $fields_props;
		}
		if ( ! isset( $fields_props[ self::SITES_FIELD_SLUG ] ) ) {
			$fields_props[ self::SITES_FIELD_SLUG ] = [];
		}

		// Disable editing of network sites if the post is incoming.
		$fields_props[ self::SITES_FIELD_SLUG ]['is_editable'] = false;

		return $fields_props;
	}

	/**
	 * Add fields props for synced fields.
	 *
	 * @param array $fields_props The fields props to add.
	 * @param int   $story_id     The story ID.
	 *
	 * @return array The fields props to add.
	 */
	public static function add_synced_fields_props( $fields_props, $story_id ) {
		if ( ! Content_Distribution::is_post_incoming( $story_id ) ) {
			return $fields_props;
		}

		try {
			$incoming_post = new Incoming_Post( $story_id );
		} catch ( \Exception $e ) {
			return $fields_props;
		}
		if ( ! $incoming_post->is_linked() ) {
			return $fields_props;
		}

		// Disable editing of synced fields.
		foreach ( self::get_synced_fields() as $field_slug ) {
			if ( ! isset( $fields_props[ $field_slug ] ) ) {
				$fields_props[ $field_slug ] = [];
			}
			$fields_props[ $field_slug ]['is_editable'] = false;
		}

		return $fields_props;
	}
}
