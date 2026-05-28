/**
 * Newspack - Dashboard, Sections
 *
 * Component for outputting sections with grid and cards
 */
import { __ } from '@wordpress/i18n';
import { lazy } from '@wordpress/element';

const settingsTabs = window.newspackSettings;

import Seo from './seo';
import Social from './social';
import Emails from './emails';
import Connections from './connections';
import Syndication from './syndication';
import AdvancedSettings from './advanced-settings';
import ThemeAndBrand from './theme-and-brand';
import Collections from './collections';
import Print from './print';
import Privacy from './privacy';

type SectionKeys = keyof typeof settingsTabs;

const sectionComponents: Partial< Record< SectionKeys | 'default', ( props: { isPartOfSetup?: boolean } ) => React.ReactNode > > = {
	connections: Connections,
	social: Social,
	emails: Emails,
	syndication: Syndication,
	seo: Seo,
	'theme-and-brand': ThemeAndBrand,
	'advanced-settings': AdvancedSettings,
	collections: Collections,
	print: Print,
	privacy: Privacy,
	default: () => <h2>🚫 { __( 'Not found' ) }</h2>,
};

/**
 * Load additional brands section if `newspack-multibranded-site` plugin is active.
 */
if ( 'additional-brands' in settingsTabs ) {
	sectionComponents[ 'additional-brands' ] = lazy( () => import( /* webpackChunkName: "newspack-wizards" */ './additional-brands' ) );
}

/**
 * Load experimental tools section if tools are registered.
 */
if ( 'experimental-tools' in settingsTabs ) {
	sectionComponents[ 'experimental-tools' ] = lazy( () => import( /* webpackChunkName: "newspack-wizards" */ './experimental-tools' ) );
}

const settingsSectionKeys = Object.keys( settingsTabs ) as SectionKeys[];

export default settingsSectionKeys.reduce( ( acc: any[], sectionPath ) => {
	acc.push( {
		label: settingsTabs[ sectionPath ].label,
		exact: '/' === ( settingsTabs[ sectionPath ].path ?? '' ),
		path: settingsTabs[ sectionPath ].path ?? `/${ sectionPath }`,
		activeTabPaths: settingsTabs[ sectionPath ].activeTabPaths ?? undefined,
		render: sectionComponents[ sectionPath ] ?? sectionComponents.default,
	} );
	return acc;
}, [] );
