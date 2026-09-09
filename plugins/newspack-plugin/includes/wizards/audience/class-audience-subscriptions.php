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
	use Wizards\Traits\Audience_Management_Dependency;

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
	 * WooCommerce product types offered as "available to" suggestions.
	 *
	 * Only parent products are suggested, to keep the picker to the handful of
	 * subscriptions a publisher thinks in terms of. A rule may still name an
	 * individual variation — `WC_Subscription::has_product()` matches a line
	 * item's variation ID as readily as its product ID — so a rule authored
	 * against one (via REST, or by a migration) is honoured and still resolves
	 * to its name when the editor loads it.
	 */
	const SUBSCRIPTION_PRODUCT_TYPES = [ 'subscription', 'variable-subscription' ];

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
				// Unescaped: the label is localized into a nested array, where
				// wp_localize_script() doesn't decode entities, and React escapes
				// it at render anyway. Escaping here would ship `&#8217;` to
				// locales whose translation contains an apostrophe.
				'label' => __( 'Configuration', 'newspack-plugin' ),
				'path'  => '/configuration',
				'order' => 10,
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
	 *     @type int    $order Sort position, low to high. Defaults to 10.
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
			'order' => isset( $args['order'] ) ? (int) $args['order'] : 10,
		];
	}

	/**
	 * Get the registered tabs, ordered.
	 *
	 * Sorted by the declared order rather than by registration, which depends on
	 * which hook each feature happens to register from. The first tab is where
	 * the wizard lands, so it can't be incidental.
	 *
	 * @return array[] The tabs.
	 */
	public static function get_tabs() {
		$tabs = array_values( self::$tabs );
		usort(
			$tabs,
			function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);
		return $tabs;
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

		// `per_page` bounds the number of parent products the query returns;
		// variations are appended on top, so a variable product always appears
		// with ALL of its variations rather than a truncated slice a publisher
		// couldn't finish picking from. The response size is therefore parents ×
		// their variations, bounded by the query's own limit — not a flat cap.
		//
		// Hydrating saved tokens (`include`) is the exception: it asks for named
		// IDs and needs each one back as itself, so variations aren't expanded
		// there — a saved variation resolves on its own, and expanding would spend
		// the response on rows nobody asked for.
		$expand_variations = empty( $request->get_param( 'include' ) );

		$data = [];
		foreach ( $posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$data[] = self::get_product_data( $product );

			// Only variable products carry variations. `WC_Product_Variable` is the
			// base for both `variable` and Subscriptions' `variable-subscription`,
			// so the instanceof check catches both — an `is_type( 'variable' )`
			// test would silently miss subscription variations in this very wizard.
			// A grouped product's children are standalone top-level products that
			// already appear in the results on their own, so they're left alone.
			if ( ! $expand_variations || ! $product instanceof \WC_Product_Variable ) {
				continue;
			}
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
		// Constrain the query to subscription types rather than filtering the page
		// afterwards: post-filtering applies the type test after the LIMIT, so a
		// store whose subscriptions sort late alphabetically returns an empty
		// picker — and this is the one field a rule can't be authored without.
		$posts = $this->search_products( $request, [ 'publish', 'private', 'draft' ], self::SUBSCRIPTION_PRODUCT_TYPES );

		$data = [];
		foreach ( $posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$data[] = self::get_product_data( $product );
		}

		return rest_ensure_response( $data );
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
	 * @param string[]         $product_types Restrict to these WooCommerce product types. Empty for any.
	 *
	 * @return \WP_Post[]
	 */
	private function search_products( $request, $post_statuses, $product_types = [] ) {
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

		// WooCommerce stores the product type as a `product_type` term, so the
		// filter belongs in the query — applying it to the returned page instead
		// would filter after the LIMIT and could empty the results entirely.
		//
		// Suggestions only. Hydrating saved IDs must resolve whatever the rule
		// already names: WooCommerce clears `product_type` on variations, so a
		// saved subscription variation would otherwise come back nameless and
		// render as a bare number.
		if ( ! empty( $product_types ) && empty( $include ) && taxonomy_exists( 'product_type' ) ) {
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $product_types,
				],
			];
		}

		if ( ! empty( $include ) ) {
			// Capped to match the `per_page` ceiling: the CSV is caller-supplied and
			// otherwise sets the width of the post__in clause on its own.
			$ids = array_slice( array_filter( array_map( 'absint', explode( ',', $include ) ) ), 0, 100 );
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
			// Capped as in search_products(): the CSV is caller-supplied and would
			// otherwise set the width of the term query on its own.
			$ids = array_slice( array_filter( array_map( 'absint', explode( ',', $include ) ) ), 0, 100 );
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
		$data = [
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
		];
		// array_merge, not `+`: the trait's keys have to win a collision. With `+`
		// a key added to the array above would silently shadow the prerequisite
		// state and unblock the screen.
		$data = array_merge( $data, $this->get_audience_management_script_data() );
		wp_localize_script( 'newspack-wizards', 'newspackAudienceSubscriptions', $data );
	}
}
