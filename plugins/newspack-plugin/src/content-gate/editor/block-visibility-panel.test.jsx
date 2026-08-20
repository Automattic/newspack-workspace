/**
 * The inspector panel's copy of the option pickers. The attribute-registration filter is
 * covered separately in `block-visibility.test.ts`, which mocks `@wordpress/components`
 * away; this suite renders the real controls instead.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, within } from '@testing-library/react';

const registeredFilters = {};

jest.mock( '@wordpress/hooks', () => ( {
	addFilter: ( _hook, namespace, callback ) => {
		registeredFilters[ namespace ] = callback;
	},
} ) );
// `@wordpress/components` reads `observableMap` from compose, so keep the real module
// and override only the HOC wrapper, whose display-name plumbing the panel doesn't need.
jest.mock( '@wordpress/compose', () => ( { ...jest.requireActual( '@wordpress/compose' ), createHigherOrderComponent: fn => fn } ) );
jest.mock( '@wordpress/block-editor', () => ( { InspectorControls: ( { children } ) => children } ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( [] ) ) );

/**
 * Three products sharing a display name, as sites end up with when a name is reused
 * across legacy and current product tiers.
 */
const DUPLICATE_NAME_PRODUCTS = [
	{ value: 188250, label: 'Annual' },
	{ value: 200014, label: 'Annual' },
	{ value: 205482, label: 'Annual' },
];

window.newspackBlockVisibility = {
	target_blocks: [ 'core/group' ],
	available_gates: [
		{ id: 12, title: 'Members only' },
		{ id: 34, title: 'Members only' },
	],
	available_access_rules: {
		subscription: { name: 'Active subscription', description: '', default: [], options: DUPLICATE_NAME_PRODUCTS },
	},
};

require( './block-visibility' );

const BlockEditWithPanel = registeredFilters[ 'newspack-plugin/block-visibility/inspector' ]( () => null );

const renderPanel = attributes => {
	const setAttributes = jest.fn();
	render( <BlockEditWithPanel name="core/group" attributes={ attributes } setAttributes={ setAttributes } /> );
	return setAttributes;
};

const removeToken = label => {
	const token = screen.getByText( label ).closest( '.components-form-token-field__token' );
	fireEvent.click( within( token ).getByRole( 'button' ) );
};

describe( 'block visibility panel, gate picker', () => {
	it( 'identifies same-named gates by ID, and removes only the one whose token was removed', () => {
		const setAttributes = renderPanel( { newspackAccessControlMode: 'gate', newspackAccessControlGateIds: [ 12, 34 ] } );

		expect( screen.getByText( 'Members only (#12)' ) ).toBeInTheDocument();

		removeToken( 'Members only (#12)' );

		expect( setAttributes ).toHaveBeenCalledWith( { newspackAccessControlGateIds: [ 34 ] } );
	} );

	it( 'keeps a gate no option describes, and stores it as an integer', () => {
		// The attribute is declared as integers, so the string a preserved token carries
		// has to be coerced on the way back out.
		const setAttributes = renderPanel( { newspackAccessControlMode: 'gate', newspackAccessControlGateIds: [ 12, '99' ] } );

		expect( screen.getByText( '(gate not listed) (#99)' ) ).toBeInTheDocument();

		removeToken( 'Members only (#12)' );

		expect( setAttributes ).toHaveBeenCalledWith( { newspackAccessControlGateIds: [ 99 ] } );
	} );
} );

describe( 'block visibility panel, access rule picker', () => {
	const renderSubscriptionRule = value =>
		renderPanel( {
			newspackAccessControlMode: 'custom',
			newspackAccessControlRules: { custom_access: { active: true, access_rules: [ [ { slug: 'subscription', value } ] ] } },
		} );

	it( 'removes only the product whose token was removed, not every product sharing its name', () => {
		const setAttributes = renderSubscriptionRule( [ 188250, 200014, 205482 ] );

		removeToken( 'Annual (#200014)' );

		expect( setAttributes ).toHaveBeenCalledWith(
			expect.objectContaining( {
				newspackAccessControlRules: expect.objectContaining( {
					custom_access: expect.objectContaining( { access_rules: [ [ { slug: 'subscription', value: [ 188250, 205482 ] } ] ] } ),
				} ),
			} )
		);
	} );

	it( 'cautions that a value no option describes is still granting access', () => {
		// The token reads like a stale entry, and removing it widens the gate — an empty
		// value list applies no filter at all.
		renderSubscriptionRule( [ 188250, 999999 ] );

		expect( screen.getByText( '(product not listed) (#999999)' ) ).toBeInTheDocument();
		expect( screen.getByText( /still checked when access is evaluated/ ) ).toBeInTheDocument();
	} );

	it( 'gives no caution when every value resolves', () => {
		renderSubscriptionRule( [ 188250 ] );

		expect( screen.queryByText( /still checked when access is evaluated/ ) ).not.toBeInTheDocument();
	} );
} );
