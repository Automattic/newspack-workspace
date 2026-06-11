/**
 * Newspack - Dashboard
 *
 * WP Admin Newspack Dashboard page.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { createRoot, lazy, Suspense } from '@wordpress/element';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import * as Components from '../../packages/components/src';

/**
 * Internal dependencies
 */
import '../shared/js/public-path';

const pageParam = new URLSearchParams( window.location.search ).get( 'page' ) ?? '';
const rootElement = document.getElementById( pageParam );

const components: Record< string, any > = {
	/**
	 * `page` param with `newspack-*`.
	 */
	'newspack-dashboard': {
		label: __( 'Dashboard', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "newspack-wizards" */ './newspack/views/dashboard' ) ),
	},
	'newspack-settings': {
		label: __( 'Settings', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "newspack-wizards" */ './newspack/views/settings' ) ),
	},
	'newspack-insights': {
		label: __( 'Insights', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "insights-wizard" */ './insights' ) ),
	},
	'newspack-audience': {
		label: __( 'Audience', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "audience-wizards" */ './audience/views/setup' ) ),
	},
	'newspack-audience-campaigns': {
		label: __( 'Audience Campaigns', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "audience-wizards" */ './audience/views/campaigns' ) ),
	},
	'newspack-audience-access-control': {
		label: __( 'Access control', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "audience-wizards" */ './audience/views/content-gates' ) ),
	},
	'newspack-audience-donations': {
		label: __( 'Audience Donations', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "audience-wizards" */ './audience/views/donations' ) ),
	},
	'newspack-audience-subscriptions': {
		label: __( 'Audience Subscriptions', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "audience-wizards" */ './audience/views/subscriptions' ) ),
	},
	'newspack-premium-newsletters': {
		label: __( 'Premium newsletters', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "newsletters-wizards" */ './newsletters/views/premium-newsletters' ) ),
	},
} as const;

// Conditionally add the Audience Integrations page if the feature is enabled.
if ( window.newspackAudienceIntegrations?.integrations_settings_enabled ) {
	components[ 'newspack-audience-integrations' ] = {
		label: __( 'Audience Integrations', 'newspack-plugin' ),
		component: lazy( () => import( /* webpackChunkName: "audience-wizards" */ './audience/views/integrations' ) ),
	};
}

const AdminPageLoader = ( { label }: { label: string } ) => {
	return (
		<div className="newspack-wizard__loader">
			<VStack alignment="center" spacing={ 2 }>
				<Components.Waiting noMargin />
				<strong>
					{
						/* translators: %s is the label of the page */
						sprintf( __( '%s loading…', 'newspack-plugin' ), label )
					}
				</strong>
			</VStack>
		</div>
	);
};

const AdminPages = () => {
	const PageComponent = components[ pageParam ].component;
	return (
		<Suspense fallback={ <AdminPageLoader label={ components[ pageParam ].label } /> }>
			<PageComponent />
		</Suspense>
	);
};

if ( rootElement && pageParam in components ) {
	createRoot( rootElement ).render( <AdminPages /> );
} else {
	// eslint-disable-next-line no-console
	console.error( `${ pageParam } not found!` );
}
