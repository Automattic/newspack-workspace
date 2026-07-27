<?php
/**
 * Audience Subscriptions Wizard
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Subscriptions Wizard.
 *
 * Hosts the subscription-commerce surfaces: subscription configuration and the
 * subscriber-commerce features (subscriber-only products, subscriber
 * discounts). Features register their own tab with ::register_tab() rather than
 * being wired in here, so a tab can ship without touching the shell.
 */
class Audience_Subscriptions extends Wizard {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-subscriptions';

	/**
	 * Parent slug.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-audience';

	/**
	 * Registered tabs, keyed by slug. Each is [ 'slug', 'label', 'path' ].
	 *
	 * @var array<string, array>
	 */
	private static $tabs = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );

		self::register_tab(
			'configuration',
			[
				'label' => esc_html__( 'Configuration', 'newspack-plugin' ),
				'path'  => '/configuration',
			]
		);
	}

	/**
	 * Register a tab on this wizard.
	 *
	 * The matching front-end component registers itself under the same slug in
	 * `src/wizards/audience/views/subscriptions/tabs`.
	 *
	 * @param string $slug  Tab slug. Must match the front-end registration.
	 * @param array  $args  {
	 *     Tab arguments.
	 *
	 *     @type string $label Tab label, translated.
	 *     @type string $path  Route path, e.g. '/subscriber-only'. Defaults to "/{$slug}".
	 * }
	 */
	public static function register_tab( $slug, $args = [] ) {
		$slug = sanitize_key( $slug );
		if ( ! $slug || empty( $args['label'] ) ) {
			return;
		}
		self::$tabs[ $slug ] = [
			'slug'  => $slug,
			'label' => $args['label'],
			'path'  => $args['path'] ?? '/' . $slug,
		];
	}

	/**
	 * Get the registered tabs.
	 *
	 * @return array[] The tabs, in registration order.
	 */
	public static function get_tabs() {
		return array_values( self::$tabs );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Subscriptions', 'newspack-plugin' );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/primary-product',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_primary_product' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'primary_product' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		$search_args = [
			'search'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'include'  => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'per_page' => [
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];

		// Shared by every subscriber-commerce tab: the pickers for targeted
		// products, product categories, and the subscriptions that unlock them.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/products-search',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'products_search' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => $search_args,
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/product-categories-search',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'product_categories_search' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => $search_args,
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/subscriptions-search',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'subscriptions_search' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => $search_args,
			]
		);
	}

	/**
	 * Update the primary product.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error The response object or error.
	 */
	public function api_update_primary_product( $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'woocommerce_not_active', __( 'WooCommerce is not active.', 'newspack-plugin' ) );
		}
		$primary_product = $request->get_param( 'primary_product' );
		if ( empty( $primary_product ) ) {
			Subscriptions_Tiers::set_primary_subscription_tier_product( null );
			return rest_ensure_response( [ 'success' => true ] );
		}

		$product = wc_get_product( $primary_product );
		if ( ! $product ) {
			return new \WP_Error( 'invalid_product', __( 'Invalid product.', 'newspack-plugin' ) );
		}
		Subscriptions_Tiers::set_primary_subscription_tier_product( $product );
		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Search store products.
	 *
	 * Variable products are returned with their variations, since a rule's
	 * preview and its exclusions operate on what is actually sold.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function products_search( $request ) {
		// Products are routinely private or held as drafts before launch, and a
		// rule binds as soon as they go live — so let publishers pick them.
		$posts = $this->search_products( $request, [ 'publish', 'private', 'draft' ] );

		$data = [];
		foreach ( $posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$data[] = self::get_product_data( $product );
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof \WC_Product ) {
					$data[] = self::get_product_data( $variation );
				}
			}
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Search subscription products — the "available to" side of a rule.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function subscriptions_search( $request ) {
		$posts = $this->search_products( $request, [ 'publish', 'private', 'draft' ] );

		$data = [];
		foreach ( $posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product instanceof \WC_Product || ! self::is_subscription_product( $product ) ) {
				continue;
			}
			$data[] = self::get_product_data( $product );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Whether a product is a subscription product.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return bool
	 */
	private static function is_subscription_product( $product ) {
		return in_array( $product->get_type(), [ 'subscription', 'variable-subscription', 'subscription_variation' ], true );
	}

	/**
	 * Build the REST representation of a product.
	 *
	 * Prices are included so a rule editor can preview what a reader would pay
	 * without a second round trip per product.
	 *
	 * @param \WC_Product $product The product (or variation).
	 *
	 * @return array
	 */
	private static function get_product_data( $product ) {
		return [
			'id'            => (int) $product->get_id(),
			'name'          => $product->get_name(),
			'parent_id'     => (int) $product->get_parent_id(),
			'type_label'    => $product->get_parent_id() ? __( 'Variation', 'newspack-plugin' ) : __( 'Product', 'newspack-plugin' ),
			'price'         => (string) $product->get_price(),
			'regular_price' => (string) $product->get_regular_price(),
			'sale_price'    => (string) $product->get_sale_price(),
			'is_on_sale'    => (bool) $product->is_on_sale(),
		];
	}

	/**
	 * Query products for the search endpoints.
	 *
	 * @param \WP_REST_Request $request       The request object.
	 * @param string[]         $post_statuses Post statuses to search.
	 *
	 * @return \WP_Post[]
	 */
	private function search_products( $request, $post_statuses ) {
		if ( ! function_exists( 'wc_get_product' ) || ! post_type_exists( 'product' ) ) {
			return [];
		}

		$args = [
			'post_type'      => 'product',
			'post_status'    => $post_statuses,
			'posts_per_page' => (int) $request->get_param( 'per_page' ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		];

		$include = $request->get_param( 'include' );
		if ( ! empty( $include ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $include ) ) );
			if ( empty( $ids ) ) {
				return [];
			}
			// Broader status filter when hydrating saved tokens, so the editor keeps
			// showing products whose status changed since the rule was saved.
			$args['post_status']    = [ 'publish', 'draft', 'pending', 'private', 'future' ];
			$args['post__in']       = $ids;
			$args['posts_per_page'] = min( count( $ids ), 100 );
			$args['orderby']        = 'post__in';
			// A saved token can be a variation, which is its own post type.
			$args['post_type'] = [ 'product', 'product_variation' ];
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			// Numeric search: treat as a product ID lookup.
			if ( is_numeric( $search ) ) {
				$args['p'] = absint( $search );
			} else {
				$args['s'] = $search;
			}
		}

		$query = new \WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Search product categories.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function product_categories_search( $request ) {
		if ( ! taxonomy_exists( Product_Targeting::PRODUCT_CATEGORY_TAXONOMY ) ) {
			return rest_ensure_response( [] );
		}

		$args = [
			'taxonomy'   => Product_Targeting::PRODUCT_CATEGORY_TAXONOMY,
			'hide_empty' => false,
			'number'     => (int) $request->get_param( 'per_page' ),
			'orderby'    => 'name',
			'order'      => 'ASC',
		];

		$include = $request->get_param( 'include' );
		if ( ! empty( $include ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $include ) ) );
			if ( empty( $ids ) ) {
				return rest_ensure_response( [] );
			}
			$args['include'] = $ids;
			$args['number']  = min( count( $ids ), 100 );
			$args['orderby'] = 'include';
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			// Numeric search: treat as a term ID lookup.
			if ( is_numeric( $search ) ) {
				$args['include'] = [ absint( $search ) ];
			} else {
				$args['search'] = $search;
			}
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response( [] );
		}

		$data = array_map(
			function ( $term ) {
				return [
					'id'         => (int) $term->term_id,
					'name'       => $term->name,
					'type_label' => __( 'Product category', 'newspack-plugin' ),
				];
			},
			$terms
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Add Subscriptions page.
	 */
	public function add_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Subscriptions', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}

		$primary_product = Subscriptions_Tiers::get_primary_subscription_tier_product();

		parent::enqueue_scripts_and_styles();
		wp_enqueue_script( 'newspack-wizards' );
		wp_localize_script(
			'newspack-wizards',
			'newspackAudienceSubscriptions',
			[
				'tabs'                     => self::get_tabs(),
				'memberships_url'          => admin_url( 'edit.php?post_type=wc_membership_plan' ),
				'memberships_active'       => Memberships::is_active(),
				'primary_product'          => $primary_product ? $primary_product->get_id() : '',
				'eligible_products'        => array_map(
					function ( $product ) {
						return [
							'id'    => $product->get_id(),
							'title' => $product->get_title(),
						];
					},
					Subscriptions_Tiers::get_tier_eligible_products()
				),
				'upgrade_subscription_url' => Subscriptions_Tiers::get_upgrade_subscription_url(),
			]
		);
	}
}
