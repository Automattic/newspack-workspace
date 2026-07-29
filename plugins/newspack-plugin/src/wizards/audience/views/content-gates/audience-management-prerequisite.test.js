/**
 * NPPD-1846 — Audience Management is a hard prerequisite for content gating.
 *
 * Two things are pinned here, because they fail independently:
 *
 * 1. `requireAudienceManagement` replaces a section with the prerequisite state, and
 *    reads the prerequisite in the wire format `wp_localize_script()` actually produces
 *    ('1' / ''), not as a JS boolean.
 * 2. EVERY section of both gate-editing wizards is wrapped. That is the load-bearing
 *    assertion: the guard used to live inside the gate-list view, which left
 *    `#/edit/new/all` reachable by bookmark or browser history with a working Save
 *    button. A new section added without the wrapper reintroduces exactly that hole,
 *    and nothing else in the suite would notice.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AudienceManagementRequired, { requireAudienceManagement } from './audience-management-required';

// The real @wordpress/components cannot load in jsdom (its data-store side effects throw
// at import), so pass through only what the prerequisite state renders. ExternalLink and
// Button must survive as real anchors for the href assertions to mean anything.
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		__experimentalVStack: ( { children } ) => React.createElement( 'div', null, children ),
		ExternalLink: ( { children, href } ) => React.createElement( 'a', { href }, children ),
	};
} );

jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Grid: ( { children } ) => React.createElement( 'div', null, children ),
		Button: ( { children, href } ) => React.createElement( 'a', { href }, children ),
		SectionHeader: ( { title, description } ) =>
			React.createElement( 'div', null, React.createElement( 'h2', null, title ), React.createElement( 'p', null, description ) ),
	};
} );

const PREREQUISITE_HEADING = 'Set up Audience Management first';
const SETUP_URL = '/wp-admin/admin.php?page=newspack-audience#/';

const setAudienceManagement = enabled => {
	window.newspackAudienceContentGates = {
		audience_management_enabled: enabled,
		audience_management_url: SETUP_URL,
	};
};

const Section = () => <div>the real section</div>;

describe( 'requireAudienceManagement (NPPD-1846)', () => {
	// '' is what wp_localize_script() sends for PHP false, and the absent key covers a
	// site whose localized config predates this feature. Pinning the string rather than
	// `false` is deliberate: a check written against the boolean would pass a
	// boolean-based test and still leave the screen unblocked in production.
	it.each( [
		[ "'' (wp_localize_script false)", '' ],
		[ 'undefined (key absent)', undefined ],
	] )( 'replaces the section with the prerequisite state when the flag is %s', ( _label, value ) => {
		setAudienceManagement( value );

		const Guarded = requireAudienceManagement( Section );
		render( <Guarded /> );

		expect( screen.getByText( PREREQUISITE_HEADING ) ).toBeInTheDocument();
		expect( screen.queryByText( 'the real section' ) ).not.toBeInTheDocument();
		// The button must route to the setup flow, not merely carry a label.
		expect( screen.getByText( 'Set up Audience Management' ) ).toHaveAttribute( 'href', SETUP_URL );
	} );

	it( 'renders the section untouched when Audience Management is on', () => {
		setAudienceManagement( '1' );

		const Guarded = requireAudienceManagement( Section );
		render( <Guarded /> );

		expect( screen.getByText( 'the real section' ) ).toBeInTheDocument();
		expect( screen.queryByText( PREREQUISITE_HEADING ) ).not.toBeInTheDocument();
	} );

	it( 'forwards section props through', () => {
		setAudienceManagement( '1' );
		const PropSpy = ( { label } ) => <div>{ label }</div>;

		const Guarded = requireAudienceManagement( PropSpy );
		render( <Guarded label="forwarded" /> );

		expect( screen.getByText( 'forwarded' ) ).toBeInTheDocument();
	} );

	it( 'uses newsletter-specific copy for the Premium Newsletters surface', () => {
		setAudienceManagement( '' );

		const Guarded = requireAudienceManagement( Section, { isNewsletter: true } );
		render( <Guarded /> );

		expect( screen.getByText( /Premium newsletters need reader accounts/ ) ).toBeInTheDocument();
	} );

	it( 'uses Access Control copy by default', () => {
		setAudienceManagement( '' );

		render( <AudienceManagementRequired /> );

		expect( screen.getByText( /Access Control needs reader accounts/ ) ).toBeInTheDocument();
	} );

	it( 'omits the primary button rather than rendering a dead link when the URL is missing', () => {
		window.newspackAudienceContentGates = { audience_management_enabled: '' };

		render( <AudienceManagementRequired /> );

		expect( screen.getByText( PREREQUISITE_HEADING ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Set up Audience Management' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'every gate-editing wizard section is guarded (NPPD-1846)', () => {
	// Read the router sources rather than rendering the wizards: the assertion is about
	// completeness of the section list, and a rendered test can only ever visit the
	// routes it thinks to visit — which is the same blind spot that let #/edit through.
	const fs = require( 'fs' );
	const path = require( 'path' );

	it.each( [
		[ 'Access Control', path.join( __dirname, 'index.js' ) ],
		[ 'Premium Newsletters', path.join( __dirname, '../../../newsletters/views/premium-newsletters/index.js' ) ],
	] )( '%s wraps every section renderer', ( _name, routerPath ) => {
		const source = fs.readFileSync( routerPath, 'utf8' );
		const renderers = source.match( /render: [^,]+,/g ) || [];

		expect( renderers.length ).toBeGreaterThan( 0 );
		const unguarded = renderers.filter( line => ! line.includes( 'requireAudienceManagement(' ) );
		expect( unguarded ).toEqual( [] );
	} );
} );
