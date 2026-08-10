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
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Wizard, withWizard } from '../../../../../packages/components/src';
import { getTab } from './tabs';
import type { SubscriptionsTab } from './types';

const HEADER_TEXT = __( 'Audience Management / Subscriptions', 'newspack-plugin' );

function AudienceSubscriptions( _props: Record< string, unknown >, ref: React.ForwardedRef< HTMLDivElement > ) {
	const tabs: SubscriptionsTab[] = window.newspackAudienceSubscriptions.tabs || [];

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
	// above it. Say so instead — the two registries are maintained independently,
	// which is exactly how a list ends up empty.
	if ( ! sections.length ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'No Subscriptions screens are available on this site.', 'newspack-plugin' ) }
			</Notice>
		);
	}

	return <Wizard headerText={ HEADER_TEXT } sections={ sections } requiredPlugins={ [ 'woocommerce' ] } ref={ ref } />;
}

export default withWizard( forwardRef( AudienceSubscriptions ) );
