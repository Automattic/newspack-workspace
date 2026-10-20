/**
 * External dependencies
 */
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// The real hook, so a request's isFetching and error transitions run as they do in the browser.
// The editor leaves a refused request's promise unhandled, which Jest would report as a failure.
jest.mock( '../../../../hooks/use-wizard-api-fetch', () => {
	const actual = jest.requireActual( '../../../../hooks/use-wizard-api-fetch' );
	return {
		useWizardApiFetch: slug => {
			const hook = actual.useWizardApiFetch( slug );
			const wizardApiFetch = ( ...args ) => {
				const request = hook.wizardApiFetch( ...args );
				request?.catch?.( () => {} );
				return request;
			};
			return { ...hook, wizardApiFetch };
		},
	};
} );

// The editor's modules read this global as they load.
window.newspackAudienceContentGates = {
	presave_checks_enabled: false,
	default_gate_status: 'draft',
	available_access_rules: {},
	edit_gate_layout_url: 'https://example.test/wp-admin/post.php',
};
const Edit = require( './index' ).default;

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockStoredRules = [ [ { slug: 'subscription', value: [ 50 ] } ] ];
const mockEditedRules = [ [ { slug: 'subscription', value: [ 51 ] } ] ];

jest.mock( './content-rules', () => () => null );
jest.mock( './registration', () => () => null );
jest.mock( './custom-access', () => ( { customAccess, onChange } ) => (
	<>
		<output data-testid="access-rules">{ JSON.stringify( customAccess.access_rules ) }</output>
		<button onClick={ () => onChange( { ...customAccess, access_rules: mockEditedRules } ) }>Edit access rules</button>
	</>
) );

const STORED_GATE = {
	id: 7,
	title: 'Stored title',
	priority: 0,
	status: 'publish',
	content_rules: [ { slug: 'post_types', value: [ 'post' ] } ],
	content_rules_match: 'all',
	registration: { active: false, metering: { enabled: false, count: 1, period: 'month' }, access_rules: [] },
	custom_access: { active: true, metering: { enabled: false, count: 1, period: 'month' }, access_rules: mockStoredRules },
};

const REFUSAL = 'Invalid value for the Subscription access rule.';
let refuseSaves = false;

const headerAction = label => ( select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions ?? [] ).find( action => action.label === label );
const runHeaderAction = label => act( async () => headerAction( label ).action() );
const accessRules = () => JSON.parse( screen.getByTestId( 'access-rules' ).textContent );

// Stands in for the wizard's updateGatesData, which writes the saved gates back to the store.
const updateGatesData = gates =>
	dispatch( WIZARD_STORE_NAMESPACE ).updateWizardSettings( { slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG, path: [ 'gates' ], value: gates } );

const renderEdit = async () => {
	render( <Edit match={ { params: { id: String( STORED_GATE.id ) } } } updateGatesData={ updateGatesData } /> );
	await waitFor( () => expect( headerAction( 'Save' ) ).toBeDefined() );
	await waitFor( () => expect( accessRules() ).toEqual( mockStoredRules ) );
};

beforeEach( () => {
	apiFetch.mockImplementation( ( { method = 'GET', data } ) => {
		if ( method === 'GET' ) {
			return Promise.resolve( { gates: [ STORED_GATE ], config: {} } );
		}
		if ( refuseSaves ) {
			return Promise.reject( {
				code: 'rest_invalid_param',
				message: 'Invalid parameter(s): gate',
				data: { status: 400, params: { gate: REFUSAL } },
			} );
		}
		return Promise.resolve( data.gate );
	} );
	refuseSaves = false;
	dispatch( WIZARD_STORE_NAMESPACE ).setAPIDataForWizard( {
		slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
		data: { gates: [ STORED_GATE ], config: {} },
	} );
} );

describe( 'Content gate editor', () => {
	it( 'keeps the edits when the server refuses a save', async () => {
		refuseSaves = true;
		await renderEdit();

		await runHeaderAction( 'Rename' );
		fireEvent.change( screen.getByRole( 'textbox', { name: /gate name/i } ), { target: { value: 'Edited title' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Edit access rules' } ) );

		await runHeaderAction( 'Save' );
		await act( async () => {} );

		expect( apiFetch ).toHaveBeenCalledWith( expect.objectContaining( { method: 'POST' } ) );
		expect( accessRules() ).toEqual( mockEditedRules );
		expect( screen.getByRole( 'textbox', { name: /gate name/i } ) ).toHaveValue( 'Edited title' );
	} );

	it( 'picks up the stored gate after a save succeeds', async () => {
		await renderEdit();

		// A status change saves the stored gate as it is, so the editor has to copy the response back.
		await runHeaderAction( 'Set to inactive' );
		await act( async () => {} );

		expect( headerAction( 'Set to active' ) ).toBeDefined();
	} );
} );
