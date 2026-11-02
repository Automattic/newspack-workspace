/**
 * The Content Gates and Institutions tabs each save part of the same advanced
 * settings, and the endpoint writes only the keys it receives. Each modal posts
 * only the settings it shows, so neither can write a stale copy of the other's.
 */

/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

window.newspackAudienceContentGates = {
	feed_restriction_modes: [
		{ value: 'truncate', label: 'Teaser only' },
		{ value: 'exclude', label: 'Remove' },
	],
};

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockWizardApiFetch = jest.fn( () => Promise.resolve() );
const mockWizardData = {
	gates: [],
	config: {
		advanced_settings: {
			restrict_feeds: true,
			feed_restriction_mode: 'truncate',
			newsletter_link_bypass_enabled: false,
			institutional_access_text: 'Saved on the Institutions tab',
			comment_restriction_gate_id: 0,
			comment_restriction_message: '',
			comment_restriction_purchase_url: '',
		},
	},
};

jest.mock( '../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( ...args ) => mockWizardApiFetch( ...args ),
		isFetching: false,
		resetError: jest.fn(),
	} ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { addNotice: jest.fn(), resetNotices: jest.fn(), updateWizardSettings: jest.fn() } ),
} ) );

// The real component packages cannot load in this jsdom env (their data-store side
// effects throw at import), and the payload under test does not depend on them.
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	const ToggleGroupControl = ( { children, onChange } ) =>
		React.createElement(
			'div',
			null,
			React.Children.map( children, option =>
				React.createElement( 'button', { type: 'button', onClick: () => onChange( option.props.value ) }, option.props.label )
			)
		);
	const TextControl = ( { label, value, onChange } ) =>
		React.createElement( 'input', { 'aria-label': label, value, onChange: event => onChange( event.target.value ) } );
	return {
		Notice: () => null,
		SelectControl: () => null,
		TextControl,
		ToggleControl: () => null,
		__experimentalToggleGroupControl: ToggleGroupControl,
		__experimentalToggleGroupControlOption: () => null,
	};
} );

jest.mock( '@wordpress/ui', () => {
	const React = require( 'react' );
	return { Stack: ( { children } ) => React.createElement( 'div', null, children ) };
} );

jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Button: ( { children, onClick, disabled } ) => React.createElement( 'button', { type: 'button', onClick, disabled }, children ),
		Modal: ( { children } ) => React.createElement( 'div', null, children ),
	};
} );

jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => mockWizardData,
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

/**
 * Internal dependencies
 */
const AdvancedSettings = require( './advanced-settings' ).default;
const InstitutionsAdvancedSettings = require( './institutions/advanced-settings' ).default;

const postedSettings = () => mockWizardApiFetch.mock.calls[ 0 ][ 0 ].data.advanced_settings;

describe( 'Advanced Settings payloads', () => {
	beforeEach( () => {
		mockWizardApiFetch.mockClear();
	} );

	it( 'leaves the institutional link text out of what Content Gates saves', () => {
		render( <AdvancedSettings showModal closeModal={ () => {} } /> );

		fireEvent.click( screen.getByText( 'Remove' ) );
		fireEvent.click( screen.getByText( 'Save' ) );

		expect( postedSettings() ).toEqual( expect.objectContaining( { feed_restriction_mode: 'exclude' } ) );
		expect( postedSettings() ).not.toHaveProperty( 'institutional_access_text' );
	} );

	it( 'saves only the institutional link text from Institutions', () => {
		render( <InstitutionsAdvancedSettings closeModal={ () => {} } /> );

		fireEvent.change( screen.getByLabelText( 'Institutional access link text' ), { target: { value: 'Check your access' } } );
		fireEvent.click( screen.getByText( 'Save' ) );

		expect( postedSettings() ).toEqual( { institutional_access_text: 'Check your access' } );
	} );
} );
