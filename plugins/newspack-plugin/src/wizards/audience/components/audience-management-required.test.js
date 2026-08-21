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
import AudienceManagementRequired, { redirectWithoutAudienceManagement, requireAudienceManagement } from './audience-management-required';

// The real @wordpress/components cannot load in jsdom (its data-store side effects throw
// at import), so pass through only what the prerequisite state renders. ExternalLink and
// Button must survive as real anchors for the href assertions to mean anything.
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		ExternalLink: ( { children, href } ) => React.createElement( 'a', { href }, children ),
	};
} );

// The heading and description come from the real EmptyState, which reaches Grid by path
// rather than through this barrel, so Grid is not stubbed here.
jest.mock( '../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		// Button must survive as a real anchor: the href assertions below are what
		// stop a dead link shipping.
		Button: ( { children, href } ) => React.createElement( 'a', { href }, children ),
	};
} );

jest.mock( '../../../../packages/components/src/proxied-imports/router', () => {
	const React = require( 'react' );
	return {
		__esModule: true,
		default: {
			Redirect: ( { to } ) => React.createElement( 'div', { 'data-testid': 'redirect', 'data-to': to } ),
		},
	};
} );

const PREREQUISITE_HEADING = 'Set up Audience Management first';
const ACTION_LABEL = 'Set up Audience Management';
const SETUP_URL = '/wp-admin/admin.php?page=newspack-audience#/';

const setAudienceManagement = enabled => {
	window.newspackAudienceContentGates = {
		audience_management_enabled: enabled,
		audience_management_url: SETUP_URL,
	};
};

const Section = () => <div>the real section</div>;

const getConfig = () => window.newspackAudienceContentGates;

const GATES_COPY = 'Access Control needs accounts, sign-in, and account emails. Audience Management provides them.';

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

		const Guarded = requireAudienceManagement( Section, { description: GATES_COPY, getConfig } );
		render( <Guarded /> );

		expect( screen.getByText( PREREQUISITE_HEADING ) ).toBeInTheDocument();
		expect( screen.queryByText( 'the real section' ) ).not.toBeInTheDocument();
		// The action must route to the setup flow, not merely carry a label.
		expect( screen.getByText( ACTION_LABEL ) ).toHaveAttribute( 'href', SETUP_URL );
	} );

	it( 'renders the section untouched when Audience Management is on', () => {
		setAudienceManagement( '1' );

		const Guarded = requireAudienceManagement( Section, { description: GATES_COPY, getConfig } );
		render( <Guarded /> );

		expect( screen.getByText( 'the real section' ) ).toBeInTheDocument();
		expect( screen.queryByText( PREREQUISITE_HEADING ) ).not.toBeInTheDocument();
	} );

	it( 'forwards section props through', () => {
		setAudienceManagement( '1' );
		const PropSpy = ( { label } ) => <div>{ label }</div>;

		const Guarded = requireAudienceManagement( PropSpy, { description: GATES_COPY, getConfig } );
		render( <Guarded label="forwarded" /> );

		expect( screen.getByText( 'forwarded' ) ).toBeInTheDocument();
	} );

	// The copy belongs to the screen, not to this component: three surfaces now
	// depend on Audience Management and a boolean per surface does not scale.
	it( 'renders the copy the guarded screen supplied', () => {
		setAudienceManagement( '' );

		const Guarded = requireAudienceManagement( Section, { description: 'Premium newsletters need accounts.', getConfig } );
		render( <Guarded /> );

		expect( screen.getByText( 'Premium newsletters need accounts.' ) ).toBeInTheDocument();
	} );

	it( 'omits the action rather than rendering a dead link when the URL is missing', () => {
		render( <AudienceManagementRequired description={ GATES_COPY } setupUrl="" /> );

		expect( screen.getByText( PREREQUISITE_HEADING ) ).toBeInTheDocument();
		expect( screen.queryByText( ACTION_LABEL ) ).not.toBeInTheDocument();
	} );
} );

describe( 'redirectWithoutAudienceManagement (NPPD-1846)', () => {
	// Sub-routes send the publisher to the landing route instead of each rendering their
	// own prerequisite copy: the Wizard draws section.title/description above the section,
	// so rendering it here stacked a second page header claiming the feature was
	// configurable.
	it.each( [
		[ "'' (wp_localize_script false)", '' ],
		[ 'undefined (key absent)', undefined ],
	] )( 'redirects to the landing route when the flag is %s', ( _label, value ) => {
		setAudienceManagement( value );

		const Guarded = redirectWithoutAudienceManagement( Section, '/content-gates', getConfig );
		render( <Guarded /> );

		expect( screen.getByTestId( 'redirect' ) ).toHaveAttribute( 'data-to', '/content-gates' );
		expect( screen.queryByText( 'the real section' ) ).not.toBeInTheDocument();
		// The prerequisite copy belongs to the landing route only.
		expect( screen.queryByText( PREREQUISITE_HEADING ) ).not.toBeInTheDocument();
	} );

	it( 'renders the section untouched when Audience Management is on', () => {
		setAudienceManagement( '1' );

		const Guarded = redirectWithoutAudienceManagement( Section, '/content-gates', getConfig );
		render( <Guarded /> );

		expect( screen.getByText( 'the real section' ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'redirect' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'every gate-editing wizard section is guarded (NPPD-1846)', () => {
	// Read the router sources rather than rendering the wizards: the assertion is about
	// completeness of the section list, and a rendered test can only ever visit the
	// routes it thinks to visit — which is the same blind spot that let #/edit through.
	const fs = require( 'fs' );
	const path = require( 'path' );

	const ROUTERS = [
		[ 'Access Control', path.join( __dirname, '../views/content-gates/index.js' ), 7 ],
		[ 'Premium Newsletters', path.join( __dirname, '../../newsletters/views/premium-newsletters/index.js' ), 2 ],
	];

	it.each( ROUTERS )( '%s guards every section renderer', ( _name, routerPath, expectedSections ) => {
		const source = fs.readFileSync( routerPath, 'utf8' );

		// Every section is `{ path: '…', render: <Identifier> }`. Matching the identifier
		// without requiring a trailing comma matters: the previous form (`/render: [^,]+,/`)
		// saw nothing at all for a renderer written last in its object, so the whole
		// assertion could pass against an empty list.
		const renderers = [ ...source.matchAll( /render:\s*([A-Za-z0-9_$]+)\s*[,\n}]/g ) ].map( m => m[ 1 ] );
		const routes = [ ...source.matchAll( /path:\s*'/g ) ];

		// Anchored to a literal count so adding a section without wiring it here fails
		// loudly instead of shrinking the denominator.
		expect( routes ).toHaveLength( expectedSections );
		expect( renderers ).toHaveLength( expectedSections );

		// Each renderer must resolve to a module-scope binding produced by one of the two
		// guards. Wrapping at module scope is itself load-bearing: calling a guard inside
		// the component body mints a new component type per render, which remounts the
		// section and discards in-progress editor state.
		const unguarded = [ ...new Set( renderers ) ].filter( name => {
			const declaration = new RegExp( `const ${ name } = (requireAudienceManagement|redirectWithoutAudienceManagement)\\(` );
			return ! declaration.test( source );
		} );
		expect( unguarded ).toEqual( [] );

		// Exactly one route may render the prerequisite state; the rest redirect to it.
		const landing = [ ...new Set( renderers ) ].filter( name => new RegExp( `const ${ name } = requireAudienceManagement\\(` ).test( source ) );
		expect( landing ).toHaveLength( 1 );
	} );
} );
