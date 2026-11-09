/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import Edit from '.';
import metadata from '../block.json';

jest.mock( '@wordpress/api-fetch' );
// The block editor package can't load in this suite. The toolbar, sidebar and
// block preview aren't under test, so they render nothing.
jest.mock( '@wordpress/block-editor', () => ( {
	BlockControls: () => null,
	ColorPaletteControl: () => null,
	InspectorControls: () => null,
	useBlockProps: ( props = {} ) => props,
} ) );
jest.mock( './FrequencyBasedLayout', () => () => null );
jest.mock( './TierBasedLayout', () => () => null );

const SITE_DONATION_SETTINGS = {
	amounts: {
		once: [ 9, 20, 90, 20 ],
		month: [ 7, 15, 30, 15 ],
		year: [ 84, 180, 360, 180 ],
	},
	currencySymbol: '$',
	tiered: false,
	disabledFrequencies: { once: false, month: false, year: false },
	minimumDonation: 20,
	platform: 'wc',
};

const DEFAULT_ATTRIBUTES = Object.fromEntries(
	Object.entries( metadata.attributes )
		.filter( ( [ , definition ] ) => 'default' in definition )
		.map( ( [ name, definition ] ) => [ name, definition.default ] )
);

// A manual block's minimum is what its form enforces, and any other block's is
// unused until manual mode is turned on, so loading the editor must not change
// either one.
describe( 'Donate block editor', () => {
	beforeAll( () => {
		window.newspack_blocks_data = { can_use_name_your_price: true };
	} );

	beforeEach( () => {
		apiFetch.mockResolvedValue( { donation_data: SITE_DONATION_SETTINGS } );
	} );

	it.each( [
		[ 'a manual block with its own minimum', { manual: true, minimumDonation: 1200 } ],
		[ 'a manual block without one', { manual: true } ],
		[ 'a block without manual mode', { manual: false, minimumDonation: 5 } ],
	] )( 'leaves the minimum of %s alone on load', async ( label, attributes ) => {
		const setAttributes = jest.fn();
		const savedAttributes = { ...DEFAULT_ATTRIBUTES, amounts: SITE_DONATION_SETTINGS.amounts, ...attributes };
		render( <Edit attributes={ savedAttributes } setAttributes={ setAttributes } /> );

		await waitFor( () => expect( setAttributes ).toHaveBeenCalled() );

		setAttributes.mock.calls.forEach( ( [ update ] ) => expect( update ).not.toHaveProperty( 'minimumDonation' ) );
	} );
} );
