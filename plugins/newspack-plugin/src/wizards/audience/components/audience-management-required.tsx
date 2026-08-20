/**
 * Audience Management prerequisite state, shown in place of a screen that depends
 * on it when it is not set up.
 *
 * Shared by every such screen — the two gate editors (Access Control, Premium
 * Newsletters) and Subscriptions, whose dependency runs through
 * `Subscriber_Commerce::is_enforcement_active()` rather than through gating. The
 * copy, the setup destination and the config bag to read are all the caller's,
 * so adding a fourth consumer needs no change here.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import { people } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button } from '../../../../packages/components/src';
import EmptyState from '../../../../packages/components/src/empty-state';
import Router from '../../../../packages/components/src/proxied-imports/router';

const { Redirect } = Router;

// Access Control is where the prerequisite is documented for every surface, so it
// is the default destination rather than a per-screen choice.
const DEFAULT_LEARN_MORE_URL = 'https://help.newspack.com/access-control/';

type AudienceManagementConfig = {
	audience_management_enabled?: string;
	audience_management_url?: string;
};

/**
 * Whether Audience Management is enabled, read from a wizard's localized config.
 *
 * Every screen that depends on Audience Management localizes the two keys the
 * `Audience_Management_Dependency` PHP trait supplies, so the bag it lives in is
 * the caller's to name.
 *
 * The config is required rather than defaulted. A default would fire on an
 * explicitly passed `undefined` too, so a screen whose own bag failed to
 * localize would silently read a different screen's answer and unblock itself —
 * a fail-open hidden inside a guard.
 *
 * `wp_localize_script()` stringifies the PHP boolean, so the value arrives as
 * '1' when on and '' when off - which is why this is a truthiness check and not
 * a comparison against `true`.
 */
export const hasAudienceManagement = ( config: AudienceManagementConfig | undefined ) => Boolean( config?.audience_management_enabled );

const AudienceManagementRequired = ( {
	description,
	setupUrl = '',
	learnMoreUrl = DEFAULT_LEARN_MORE_URL,
}: {
	description: string;
	setupUrl?: string;
	learnMoreUrl?: string;
} ) => {
	return (
		<EmptyState.Root>
			<EmptyState.Header icon={ people } title={ __( 'Set up Audience Management first', 'newspack-plugin' ) } description={ description } />
			<EmptyState.Actions orientation="column" gap="lg">
				{ /* Rendered only with a real destination: a primary CTA pointing at href=""
				     reloads this same screen, which is worse than offering no button. */ }
				{ setupUrl && (
					<Button variant="primary" href={ setupUrl }>
						{ __( 'Set up Audience Management', 'newspack-plugin' ) }
					</Button>
				) }
				<ExternalLink href={ learnMoreUrl }>{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
			</EmptyState.Actions>
		</EmptyState.Root>
	);
};

/**
 * Wrap a wizard section so it is replaced by the prerequisite state when Audience
 * Management is off.
 *
 * Reserved for a screen's landing section - the one route that is allowed to render
 * the prerequisite state. Every other section redirects to it via
 * `redirectWithoutAudienceManagement()` rather than rendering its own copy, because
 * the Wizard draws `section.title` and `section.description` above the section
 * component: on `#/settings/countdown-banner` that produced the settings page header,
 * implying the feature was configurable, stacked directly on top of this one.
 *
 * Safe to short-circuit the whole section: the Wizard resets header data on every
 * route change, so no stale header action survives into the blocked state.
 *
 * Call at module scope, never inside a component body. Each call mints a new
 * component type, and the Wizard renders sections as `<SectionComponent />` - a type
 * that changes identity between renders remounts the section subtree and discards
 * in-progress editor state.
 */
export const requireAudienceManagement = < P extends object >(
	Section: React.ComponentType< P >,
	{ description, getConfig }: { description: string; getConfig: () => AudienceManagementConfig | undefined }
) => {
	const Guarded = ( props: P ) => {
		// Read at render, not at module scope: these wrappers are built while the
		// bundle evaluates, before `wp_localize_script()` has necessarily run.
		const config = getConfig();
		return hasAudienceManagement( config ) ? (
			<Section { ...props } />
		) : (
			<AudienceManagementRequired description={ description } setupUrl={ config?.audience_management_url || '' } />
		);
	};
	// Named so the guarded sections are distinguishable in React DevTools
	// rather than all reading as `Anonymous`.
	Guarded.displayName = `RequireAudienceManagement(${ Section.displayName || Section.name || 'Section' })`;
	return Guarded;
};

/**
 * Wrap a wizard section so it redirects to the screen's landing route when Audience
 * Management is off.
 *
 * These routes stay reachable by bookmark and browser history, and the gate editor
 * among them offers a working Save, so they cannot simply render. Sending them to the
 * one route that explains the prerequisite keeps the explanation in a single place.
 *
 * Same module-scope requirement as `requireAudienceManagement()`.
 */
export const redirectWithoutAudienceManagement = < P extends object >(
	Section: React.ComponentType< P >,
	redirectTo: string,
	getConfig: () => AudienceManagementConfig | undefined
) => {
	const Guarded = ( props: P ) => ( hasAudienceManagement( getConfig() ) ? <Section { ...props } /> : <Redirect to={ redirectTo } /> );
	Guarded.displayName = `RedirectWithoutAudienceManagement(${ Section.displayName || Section.name || 'Section' })`;
	return Guarded;
};

export default AudienceManagementRequired;
