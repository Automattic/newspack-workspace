<?php
/**
 * Wizard Traits - Audience Management Dependency
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Traits;

use Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Trait Audience_Management_Dependency
 *
 * Audience Management is a hard prerequisite for the wizards that depend on it:
 * the two that surface the content gate editor (Audience Access Control and
 * Premium Newsletters), and Subscriptions, whose subscriber-commerce features
 * are enforced only while it is on
 * ({@see \Newspack\Subscriber_Commerce::is_enforcement_active()}).
 * Everything a gate hands the reader off to is gated on it: reader
 * registration, magic link login, account emails, session hydration and My
 * Account. A gate enforced without Audience Management locks readers out and
 * then gives them no way in.
 *
 * Gating therefore stands down rather than half-works: reader-facing enforcement
 * asks {@see \Newspack\Content_Gate::is_gating_active()}, so with Audience
 * Management off gates stay configured and restrict nothing. This trait is the
 * other half of that — it keeps the publisher from authoring new gating that
 * would do nothing, and points them at the setting that makes it work.
 *
 * Shared by every such surface so the dependency cannot be enforced on one and
 * forgotten on the next.
 */
trait Audience_Management_Dependency {
	/**
	 * The wizard's capability check, supplied by the consuming Wizard subclass.
	 *
	 * Declared abstract so a consumer that is not a Wizard fails at class
	 * declaration rather than inside a REST permission callback at request time.
	 *
	 * @param \WP_REST_Request $request API request object.
	 *
	 * @return bool|\WP_Error
	 */
	abstract public function api_permissions_check( $request );

	/**
	 * Whether Audience Management is enabled on this site.
	 *
	 * This is the Reader Activation enabled toggle, not a setup-completeness
	 * checklist — the toggle is what the reader-side registration, login and
	 * account surfaces themselves branch on.
	 *
	 * Cast because the underlying value passes through the
	 * `newspack_reader_activation_enabled` filter and so is not guaranteed to be
	 * a real bool. That matters: the un-cast value is localized to the browser,
	 * where an integer 0 would arrive as the string '0' — truthy in JavaScript —
	 * leaving the screen unblocked while this same check still refuses creation.
	 *
	 * @return bool
	 */
	public function has_audience_management(): bool {
		return (bool) Reader_Activation::is_enabled();
	}

	/**
	 * Audience Management prerequisite data for the wizard's localized config.
	 *
	 * The screen renders its blocked state entirely from these two keys. The URL
	 * is where the admin is sent to satisfy the prerequisite, so it points at the
	 * Audience Management setup flow rather than this screen.
	 *
	 * @return array{audience_management_enabled: bool, audience_management_url: string}
	 */
	public function get_audience_management_script_data(): array {
		return [
			'audience_management_enabled' => $this->has_audience_management(),
			'audience_management_url'     => \admin_url( 'admin.php?page=newspack-audience#/' ),
		];
	}

	/**
	 * Permission callback for routes that create new gating.
	 *
	 * Layered on top of the wizard's capability check: the screen already
	 * refuses to offer gate creation without Audience Management, and this stops
	 * a stale browser tab from POSTing a gate into existence behind it.
	 *
	 * Deliberately scoped to creation. Reads, updates, priority changes and
	 * deletes stay open because nothing is at stake in leaving them open: gates go
	 * inert while Audience Management is off
	 * ({@see \Newspack\Content_Gate::is_gating_active()}), so a gate reached by
	 * those routes is restricting nothing at the time.
	 *
	 * Note that with Audience Management off there is currently no screen those
	 * routes back: the gate list is replaced by the prerequisite state, every
	 * sub-route redirects to it, and the CPT is registered `show_in_menu => false`.
	 * Gates are frozen and out of reach until Audience Management is switched back
	 * on, at which point they all resume together. Leaving the routes open is what
	 * keeps a read-only list possible later without another permissions change.
	 *
	 * This guard is a nudge rather than a safety property, and is worth keeping as
	 * one: a gate authored now would do nothing until Audience Management is set
	 * up, and finding that out at save time is worse than being told up front.
	 *
	 * Wizard-scoped, not entity-scoped: `np_content_gate` is registered with
	 * `show_ui` and `show_in_rest`, so an administrator can still create the bare
	 * CPT outside these wizards. That is adequate for the stale-tab threat this
	 * guards against, and such a gate stays inert like any other until Audience
	 * Management is switched back on.
	 *
	 * @param \WP_REST_Request $request API request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function api_permissions_check_audience_management( $request ) {
		$permission = $this->api_permissions_check( $request );
		// Mirror core's own reading of a permission callback: WP_Error and any
		// falsy value are refusals and pass straight through, while any truthy
		// value means the capability check passed. Comparing against `true`
		// alone would hand a truthy non-true verdict back to core as "allowed"
		// and skip the check below — the wrong direction for a guard to fail.
		if ( \is_wp_error( $permission ) || ! $permission ) {
			return $permission;
		}
		if ( ! $this->has_audience_management() ) {
			return new \WP_Error(
				'newspack_audience_management_required',
				__( 'Audience Management must be set up before creating gated content.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}
}
