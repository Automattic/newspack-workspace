/**
 * The Audience Management / Subscriptions wizard.
 *
 * A shell: it renders whichever tabs PHP registered, in registration order,
 * looking each one up in the front-end tab registry. It has no knowledge of any
 * particular feature, so a tab ships without changing anything here.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Notice, Wizard, withWizard } from '../../../../../packages/components/src';
import WizardsTab from '../../../wizards-tab';
import AudienceManagementRequired, { hasAudienceManagement } from '../../components/audience-management-required';
import { getTab } from './tabs';
import type { SubscriptionsTab } from './types';

const HEADER_TEXT = __( 'Audience Management / Subscriptions', 'newspack-plugin' );

// Built at module scope: `Wizard` renders `section.render` as a component type, so
// rebuilding it per render would remount the subtree and drop focus.
const PREREQUISITE_SECTION = {
	label: __( 'Subscriptions', 'newspack-plugin' ),
	// `Wizard` registers section routes non-exact, so a bookmarked tab route lands
	// here rather than on an empty Switch. That is what makes the stand-down
	// deep-link safe without a per-route redirect.
	path: '/',
	breadcrumbs: [ { label: __( 'Audience Management', 'newspack-plugin' ) }, { label: __( 'Subscriptions', 'newspack-plugin' ) } ],
	render: () => (
		<AudienceManagementRequired
			description={ __(
				'The Subscriptions screen needs accounts, sign-in, and account emails. Audience Management provides them.',
				'newspack-plugin'
			) }
			setupUrl={ window.newspackAudienceSubscriptions?.audience_management_url || '' }
		/>
	),
};

function AudienceSubscriptions( _props: Record< string, unknown >, ref: React.ForwardedRef< HTMLDivElement > ) {
	const config = window.newspackAudienceSubscriptions;

	// The whole screen stands down without Audience Management, rather than a tab
	// at a time. Subscriber-only products and subscriber discounts are enforced
	// only while it is on ({@see Newspack\Subscriber_Commerce::is_enforcement_active()}),
	// so configuring either one would do nothing.
	//
	// The Configuration tab is the accepted cost of that: its primary-tier setting
	// still drives the front-end upgrade modal, which does not depend on Audience
	// Management, so blocking the screen puts that setting out of reach too. One
	// live tab beside two prerequisite notices reads as broken rather than as a
	// dependency, so the screen is treated as one feature.
	//
	// Returning early rather than adding an arm to the ternary below keeps the tab
	// lookup off the blocked path, and leaves that ternary's comment with the
	// fallback it actually describes.
	if ( ! hasAudienceManagement( config ) ) {
		return <Wizard headerText={ HEADER_TEXT } sections={ [ PREREQUISITE_SECTION ] } requiredPlugins={ [ 'woocommerce' ] } ref={ ref } />;
	}

	const tabs: SubscriptionsTab[] = config.tabs || [];

	const sections = tabs
		.map( tab => {
			const registered = getTab( tab.slug );
			// A tab PHP registered with no front end would render an empty screen;
			// leaving it out is the honest failure.
			if ( ! registered ) {
				return null;
			}
			const breadcrumbs: { label: string }[] = [
				{ label: __( 'Audience Management', 'newspack-plugin' ) },
				{ label: __( 'Subscriptions', 'newspack-plugin' ) },
			];
			// A tab that authors its leaf at render time keeps ancestors only here,
			// following the one-place convention in appendSectionName's docblock.
			if ( ! registered.rendersLeafCrumb ) {
				breadcrumbs.push( { label: registered.breadcrumbLabel || tab.label } );
			}
			return {
				label: tab.label,
				path: tab.path,
				breadcrumbs,
				render: registered.render,
				fullWidth: registered.fullWidth,
			};
		} )
		.filter( Boolean );

	// Dropping one unregistered tab is a graceful degrade; ending up with none is
	// not. Wizard redirects to `sections[ 0 ].path` unconditionally, so an empty
	// list throws and takes the whole admin screen down with no error boundary
	// above it. The two registries are maintained independently, which is exactly
	// how a list ends up empty — so fall back to a single section carrying a
	// notice. Routed through Wizard rather than returned on its own, it keeps the
	// header, breadcrumbs and admin chrome, and the forwarded ref stays attached.
	const displayedSections = sections.length
		? sections
		: [
				{
					label: __( 'Subscriptions', 'newspack-plugin' ),
					path: '/',
					breadcrumbs: [ { label: __( 'Audience Management', 'newspack-plugin' ) }, { label: __( 'Subscriptions', 'newspack-plugin' ) } ],
					render: () => (
						<WizardsTab title={ __( 'Subscriptions', 'newspack-plugin' ) }>
							<Notice isWarning>{ __( 'No Subscriptions screens are available on this site.', 'newspack-plugin' ) }</Notice>
						</WizardsTab>
					),
				},
		  ];

	return <Wizard headerText={ HEADER_TEXT } sections={ displayedSections } requiredPlugins={ [ 'woocommerce' ] } ref={ ref } />;
}

export default withWizard( forwardRef( AudienceSubscriptions ) );
