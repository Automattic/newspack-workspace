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

	return <Wizard headerText={ HEADER_TEXT } sections={ sections } requiredPlugins={ [ 'woocommerce' ] } ref={ ref } />;
}

export default withWizard( forwardRef( AudienceSubscriptions ) );
