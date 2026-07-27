/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { EnableModal, getMissingRequiredFields } from './enable-modal';

// EnableModal + SettingsField read six components from the components
// barrel, but the barrel's index evaluates all ~50 components (Wizard,
// PluginInstaller, integration icons, …) — seconds of module execution
// per suite that made these tests fragile under CPU load. Re-export just
// the submodules this render tree uses, as their REAL implementations,
// so modal semantics, label association and disabled state stay fully
// exercised. If the components under test start using another barrel
// export, add it here (the failure is a loud "X is not a component").
jest.mock( '../../../../../packages/components/src', () => ( {
	Button: jest.requireActual( '../../../../../packages/components/src/button' ).default,
	Modal: jest.requireActual( '../../../../../packages/components/src/modal' ).default,
	Notice: jest.requireActual( '../../../../../packages/components/src/notice' ).default,
	Grid: jest.requireActual( '../../../../../packages/components/src/grid' ).default,
	SelectControl: jest.requireActual( '../../../../../packages/components/src/select-control' ).default,
	TextControl: jest.requireActual( '../../../../../packages/components/src/text-control' ).default,
} ) );

const audienceField = {
	key: 'mailchimp_audience_id',
	type: 'select',
	label: 'Mailchimp Audience',
	description: 'Choose an audience.',
	required: true,
	value: '',
	options: [
		{ label: 'None', value: '' },
		{ label: 'Weekly', value: 'abc123' },
	],
};

const integration = {
	id: 'esp',
	name: 'Newsletter ESP',
	settings: [ audienceField, { key: 'sync_esp_delete', type: 'checkbox', value: true } ],
};

describe( 'getMissingRequiredFields', () => {
	it( 'returns only required fields with empty values', () => {
		expect( getMissingRequiredFields( integration ) ).toEqual( [ audienceField ] );
		expect( getMissingRequiredFields( { settings: [ { ...audienceField, value: 'abc123' } ] } ) ).toEqual( [] );
		expect( getMissingRequiredFields( undefined ) ).toEqual( [] );
	} );

	it( 'ignores required fields of managed types the modal cannot collect', () => {
		expect(
			getMissingRequiredFields( {
				settings: [ { key: 'token', type: 'oauth', required: true, value: '' }, audienceField ],
			} )
		).toEqual( [ audienceField ] );
	} );
} );

describe( 'EnableModal', () => {
	it( 'renders a titled modal collecting the missing required fields', () => {
		render( <EnableModal integration={ integration } onClose={ jest.fn() } onEnable={ jest.fn() } onGoToSettings={ jest.fn() } /> );
		expect( screen.getByText( 'Enable Newsletter ESP' ) ).toBeTruthy();
		expect( screen.getByLabelText( /Mailchimp Audience/ ) ).toBeTruthy();
	} );

	it( 'disables Enable until every required field has a value, then submits the values', async () => {
		const onEnable = jest.fn( () => Promise.resolve() );
		render( <EnableModal integration={ integration } onClose={ jest.fn() } onEnable={ onEnable } onGoToSettings={ jest.fn() } /> );
		const enableButton = screen.getByRole( 'button', { name: 'Enable' } );
		expect( enableButton.disabled ).toBe( true );
		fireEvent.change( screen.getByLabelText( /Mailchimp Audience/ ), { target: { value: 'abc123' } } );
		expect( enableButton.disabled ).toBe( false );
		fireEvent.click( enableButton );
		await waitFor( () => expect( onEnable ).toHaveBeenCalledWith( { mailchimp_audience_id: 'abc123' } ) );
	} );

	it( 'stays open with re-enabled buttons and an error notice when enabling fails', async () => {
		const onEnable = jest.fn( () => Promise.reject( new Error( 'nope' ) ) );
		render( <EnableModal integration={ integration } onClose={ jest.fn() } onEnable={ onEnable } onGoToSettings={ jest.fn() } /> );
		fireEvent.change( screen.getByLabelText( /Mailchimp Audience/ ), { target: { value: 'abc123' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Enable' } ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Enable' } ).disabled ).toBe( false ) );
		expect( screen.getByText( 'Something went wrong. Please try again.' ) ).toBeTruthy();
	} );

	it( 'offers the settings view when a required select has no selectable options', () => {
		const onGoToSettings = jest.fn();
		render(
			<EnableModal
				integration={ { ...integration, settings: [ { ...audienceField, options: [ { label: 'None', value: '' } ] } ] } }
				onClose={ jest.fn() }
				onEnable={ jest.fn() }
				onGoToSettings={ onGoToSettings }
			/>
		);
		expect( screen.queryByRole( 'button', { name: 'Enable' } ) ).toBeNull();
		fireEvent.click( screen.getByRole( 'button', { name: 'Open settings' } ) );
		expect( onGoToSettings ).toHaveBeenCalled();
	} );
} );
