<?php
/**
 * Audience Pricing Rules Wizard, backed by subscriber discounts.
 *
 * Deliberately shares the admin slug, menu label and routes of
 * Audience_Pricing_Rules, which fronts the standalone dynamic-pricing engine.
 * Wizards registers exactly one of the two — the engine's page wherever its
 * plugin is active, this one otherwise — so a publisher only ever sees a single
 * Pricing Rules screen, and installing the engine later keeps their URL,
 * bookmarks and documentation working. The stored rules are ported at that
 * point by `wp newspack migrate-discounts`.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing Rules page served by subscriber discounts.
 */
class Audience_Subscriber_Discounts extends Wizard {
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
	 * Enqueue scripts and styles, telling the page map which manager owns this
	 * screen. Rules and currency come from the REST payload.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}
		parent::enqueue_scripts_and_styles();
		wp_enqueue_script( 'newspack-wizards' );
		wp_localize_script(
			'newspack-wizards',
			'newspackPricingRules',
			[ 'engine' => false ]
		);
	}
}
