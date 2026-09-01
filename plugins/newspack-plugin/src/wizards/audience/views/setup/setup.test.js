/**
 * Tests for the Audience Management setup screen.
 */

/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Setup from './setup';

// The screen fetches the ESP on mount; Newspack Newsletters may not be installed,
// so the component already tolerates a rejection — resolve empty to keep it quiet.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn( () => Promise.resolve( { settings: {} } ) ),
} ) );

const baseProps = {
	config: {
		enabled: true,
		use_custom_lists: true,
		newsletter_lists: [],
		newsletter_list_initial_size: 3,
	},
	prerequisites: {
		esp: { active: true, plugins: { 'newspack-newsletters': true } },
	},
	requiredPlugins: {},
	espSyncErrors: [],
	fetchConfig: () => {},
	updateConfig: () => {},
	saveConfig: () => {},
	getSharedProps: () => ( {} ),
	inFlight: false,
};

// Awaited so the on-mount ESP fetch settles inside act().
const renderSetup = async initialSize => {
	await act( async () => {
		render( <Setup { ...baseProps } config={ { ...baseProps.config, newsletter_list_initial_size: initialSize } } /> );
	} );
};

describe( 'Audience setup: initial list size', () => {
	beforeEach( () => {
		global.newspack_aux_data = { is_debug_mode: false };
		global.newspackAudience = {
			available_newsletter_lists: [],
			integrations_settings_enabled: false,
			can_use_salesforce: false,
		};
	} );

	const slider = () => screen.getByRole( 'slider', { name: 'Initial list size' } );

	it( 'shows the configured size when the value is a number', async () => {
		await renderSetup( 3 );
		expect( slider() ).toHaveValue( '3' );
	} );

	// Options read from the database come back as numeric strings (NPPM-3073).
	// RangeControl discards any value that is not `typeof 'number'` and silently
	// falls back to its initial position, so the publisher sees 2 whatever is saved.
	it( 'shows the configured size when the value is a numeric string', async () => {
		await renderSetup( '3' );
		expect( slider() ).toHaveValue( '3' );
	} );

	// With nothing saved yet, the control should fall back to its own
	// initialPosition rather than to a value hardcoded at the call site.
	it( 'falls back to the default size when no value is set', async () => {
		await renderSetup( undefined );
		expect( slider() ).toHaveValue( '2' );
	} );
} );
