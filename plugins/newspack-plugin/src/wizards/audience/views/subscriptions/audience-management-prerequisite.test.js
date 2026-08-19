/**
 * The Subscriptions screen stands down as a whole without Audience Management.
 *
 * Enforcement for both subscriber-commerce features is gated on Audience
 * Management, so a publisher configuring either one here would be authoring
 * rules that do nothing. Blocking tab by tab was the alternative and is worse:
 * Configuration alone still works, but a screen with one live tab and two
 * prerequisite notices reads as broken rather than as a dependency.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AudienceSubscriptions from './index';

const PREREQUISITE_HEADING = 'Set up Audience Management first';

// The Wizard mock below renders whatever sections it is handed, so these tests pin
// which sections reach it, not how routing resolves. Route enumeration is not
// needed here the way it is for the gate editors: the screen replaces the whole
// section list rather than guarding each renderer, and the one remaining section
// is registered non-exact at '/', so no sub-route can slip past it.

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		__experimentalVStack: ( { children } ) => React.createElement( 'div', null, children ),
		ExternalLink: ( { children, href } ) => React.createElement( 'a', { href }, children ),
	};
} );

// Wizard is replaced by a renderer for the sections it was handed: what matters
// here is which sections reach it, not how it draws them.
jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Button: ( { children, href } ) => React.createElement( 'a', { href }, children ),
		Grid: ( { children } ) => React.createElement( 'div', null, children ),
		Notice: ( { children } ) => React.createElement( 'div', null, children ),
		SectionHeader: ( { title, description } ) =>
			React.createElement( 'div', null, React.createElement( 'h2', null, title ), React.createElement( 'p', null, description ) ),
		Wizard: ( { sections } ) =>
			React.createElement(
				'div',
				null,
				sections.map( section => React.createElement( 'div', { key: section.path }, React.createElement( section.render ) ) )
			),
		withWizard: component => component,
	};
} );

jest.mock(
	'../../../wizards-tab',
	() =>
		( { children } ) =>
			children
);

// One registered tab, so a screen that did NOT stand down would render it and
// the assertions below would fail for the right reason.
jest.mock( './tabs', () => ( {
	getTab: () => ( {
		render: () => require( 'react' ).createElement( 'div', null, 'Configuration tab' ),
	} ),
} ) );

const setUpScreen = audienceManagementEnabled => {
	window.newspackAudienceSubscriptions = {
		tabs: [ { slug: 'configuration', label: 'Configuration', path: '/' } ],
		audience_management_enabled: audienceManagementEnabled,
		audience_management_url: 'https://example.test/wp-admin/admin.php?page=newspack-audience',
	};
};

describe( 'Subscriptions screen without Audience Management', () => {
	it( 'replaces every tab with the prerequisite state', () => {
		setUpScreen( '' );

		render( <AudienceSubscriptions /> );

		expect( screen.getByText( PREREQUISITE_HEADING ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Configuration tab' ) ).not.toBeInTheDocument();
	} );

	it( 'points the publisher at the setup flow', () => {
		setUpScreen( '' );

		render( <AudienceSubscriptions /> );

		expect( screen.getByText( 'Set up Audience Management' ) ).toHaveAttribute(
			'href',
			'https://example.test/wp-admin/admin.php?page=newspack-audience'
		);
	} );

	it( 'renders the registered tabs once Audience Management is on', () => {
		setUpScreen( '1' );

		render( <AudienceSubscriptions /> );

		expect( screen.getByText( 'Configuration tab' ) ).toBeInTheDocument();
		expect( screen.queryByText( PREREQUISITE_HEADING ) ).not.toBeInTheDocument();
	} );
} );
