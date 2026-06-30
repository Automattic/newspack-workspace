<?php
/**
 * Audience Pricing Rules Wizard.
 *
 * Page shell for the DataViews-based pricing-rules manager. The rule CRUD REST
 * is owned by the standalone woocommerce-dynamic-pricing plugin
 * (wc-dynamic-pricing/v1/rules); this wizard only registers the admin page and
 * mounts the React app, which consumes that REST via @wordpress/api-fetch.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Pricing Rules Wizard.
 */
class Audience_Pricing_Rules extends Wizard {
	/**
	 * Admin page slug. Must match the React page map key in src/wizards/index.tsx.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-pricing-rules';

	/**
	 * Parent slug.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-audience';

	/**
	 * Expose the slug for tests/consumers.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Pricing Rules', 'newspack-plugin' );
	}

	/**
	 * Add the Pricing Rules page under Audience.
	 */
	public function add_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Pricing Rules', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Enqueue scripts and styles. Currency + vocab come from the rules REST, so
	 * the only page config is whether the engine plugin is active.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}
		parent::enqueue_scripts_and_styles();
		wp_enqueue_script( 'newspack-wizards' );
		wp_localize_script(
			'newspack-wizards',
			'newspackAudiencePricingRules',
			[
				'rules_rest_path' => 'wc-dynamic-pricing/v1/rules',
				'engine_active'   => class_exists( 'Automattic\\WooCommerce\\DynamicPricing\\Dynamic_Pricing' ),
			]
		);
	}
}
