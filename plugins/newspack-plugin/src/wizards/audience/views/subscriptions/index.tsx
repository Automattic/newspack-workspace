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

function AudienceSubscriptions( _props: Record< string, unknown >, ref: React.ForwardedRef< HTMLDivElement > ) {
	const tabs: SubscriptionsTab[] = window.newspackAudienceSubscriptions.tabs || [];

	// The whole screen stands down without Audience Management, rather than a tab
	// at a time. Subscriber-only products and subscriber discounts are enforced
	// only while it is on ({@see Newspack\Subscriber_Commerce::is_enforcement_active()}),
	// and configuring either one here would do nothing. The Configuration tab
	// would still work on its own, but this screen is one feature to a publisher,
	// and a half-blocked one reads as broken rather than as a prerequisite.
	const audienceManagement = window.newspackAudienceSubscriptions;
	const prerequisiteSection = {
		label: __( 'Subscriptions', 'newspack-plugin' ),
		path: '/',
		breadcrumbs: [ { label: __( 'Audience Management', 'newspack-plugin' ) }, { label: __( 'Subscriptions', 'newspack-plugin' ) } ],
		render: () => (
			<AudienceManagementRequired
				description={ __(
					'Subscriptions needs accounts, sign-in, and account emails. Audience Management provides them.',
					'newspack-plugin'
				) }
				setupUrl={ audienceManagement?.audience_management_url || '' }
			/>
		),
	};

	const sections = tabs
		.map( tab => {
			const registered = getTab( tab.slug );
			// A tab PHP registered with no front end would render an empty screen;
			// leaving it out is the honest failure.
			if ( ! registered ) {
				return null;
			}
			return {
				label: tab.label,
				path: tab.path,
				breadcrumbs: [
					{ label: __( 'Audience Management', 'newspack-plugin' ) },
					{ label: __( 'Subscriptions', 'newspack-plugin' ) },
					{ label: registered.breadcrumbLabel || tab.label },
				],
				render: registered.render,
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
	const displayedSections = ! hasAudienceManagement( audienceManagement )
		? [ prerequisiteSection ]
		: sections.length
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
