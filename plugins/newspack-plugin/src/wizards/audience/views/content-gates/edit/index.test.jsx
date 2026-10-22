/**
 * External dependencies
 */
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';
import { PREMIUM_NEWSLETTERS_WIZARD_SLUG } from '../../../../newsletters/views/premium-newsletters/consts';
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

// A newsletter gate stored before the list settings existed: it lacks both keys.
const NEWSLETTER_GATE = {
	...STORED_GATE,
	id: 9,
	content_rules: [ { slug: 'newsletters', value: [] } ],
};
const NEWSLETTER_CONFIG = { auto_signup: true };

// The newsletter gate the store and the mocked server hand back. A test that needs a gate
// with settings already chosen swaps it with storeNewsletterGate().
let storedNewsletterGate = NEWSLETTER_GATE;

const REFUSAL = 'Invalid value for the Subscription access rule.';
let refuseSaves = false;

const headerAction = label => ( select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions ?? [] ).find( action => action.label === label );
const runHeaderAction = label => act( async () => headerAction( label ).action() );
const accessRules = () => JSON.parse( screen.getByTestId( 'access-rules' ).textContent );

// Stands in for the wizard's updateGatesData, which writes the saved gates back to the store.
const updateGatesData = gates =>
	dispatch( WIZARD_STORE_NAMESPACE ).updateWizardSettings( { slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG, path: [ 'gates' ], value: gates } );

const storeNewsletterGate = gate => {
	storedNewsletterGate = gate;
	dispatch( WIZARD_STORE_NAMESPACE ).setAPIDataForWizard( {
		slug: PREMIUM_NEWSLETTERS_WIZARD_SLUG,
		data: { gates: [ gate ], config: NEWSLETTER_CONFIG },
	} );
};

const renderNewsletterEdit = async () => {
	render(
		<MemoryRouter>
			<Edit
				match={ { params: { id: String( NEWSLETTER_GATE.id ) } } }
				updateGatesData={ updateGatesData }
				slug={ PREMIUM_NEWSLETTERS_WIZARD_SLUG }
				isNewsletter
			/>
		</MemoryRouter>
	);
	await waitFor( () => expect( headerAction( 'Save' ) ).toBeDefined() );
	await waitFor( () => expect( accessRules() ).toEqual( mockStoredRules ) );
};

const savedGate = () => apiFetch.mock.calls.map( ( [ request ] ) => request ).find( request => request.method === 'POST' ).data.gate;

const renderEdit = async () => {
	render( <Edit match={ { params: { id: String( STORED_GATE.id ) } } } updateGatesData={ updateGatesData } /> );
	await waitFor( () => expect( headerAction( 'Save' ) ).toBeDefined() );
	await waitFor( () => expect( accessRules() ).toEqual( mockStoredRules ) );
};

beforeEach( () => {
	// A save test reads the request it made, not one left by an earlier test.
	apiFetch.mockClear();
	apiFetch.mockImplementation( ( { method = 'GET', path, data } ) => {
		if ( method === 'GET' ) {
			return Promise.resolve(
				path.endsWith( PREMIUM_NEWSLETTERS_WIZARD_SLUG )
					? { gates: [ storedNewsletterGate ], config: NEWSLETTER_CONFIG }
					: { gates: [ STORED_GATE ], config: {} }
			);
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
	storeNewsletterGate( NEWSLETTER_GATE );
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

describe( 'Premium newsletter list settings', () => {
	it( 'shows both settings in the Paid Access card, naming the site setting', async () => {
		await renderNewsletterEdit();

		const paidAccessCard = screen.getByRole( 'heading', { name: 'Paid Access' } ).closest( '.newspack-card--core' );
		expect( paidAccessCard ).toContainElement( screen.getByRole( 'combobox', { name: 'Auto-signup' } ) );
		expect( paidAccessCard ).toContainElement( screen.getByRole( 'checkbox', { name: 'Hide from readers' } ) );
		expect( screen.getByRole( 'option', { name: 'Use site setting (on)' } ) ).toBeInTheDocument();
	} );

	it( 'saves a gate stored without the settings as using the site setting and showing its lists', async () => {
		await renderNewsletterEdit();

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit access rules' } ) );
		await runHeaderAction( 'Save' );

		expect( savedGate() ).toEqual( expect.objectContaining( { auto_signup: 'default', hide_lists: false } ) );
	} );

	it( 'saves the auto-signup mode the editor picks', async () => {
		await renderNewsletterEdit();

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Auto-signup' } ), { target: { value: 'on' } } );
		await runHeaderAction( 'Save' );

		expect( savedGate() ).toEqual( expect.objectContaining( { auto_signup: 'on', hide_lists: false } ) );
	} );

	it( 'saves the hiding choice, and counts it as a change that can be saved', async () => {
		await renderNewsletterEdit();
		expect( headerAction( 'Save' ).disabled ).toBe( true );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Hide from readers' } ) );
		expect( headerAction( 'Save' ).disabled ).toBe( false );
		await runHeaderAction( 'Save' );

		expect( savedGate() ).toEqual( expect.objectContaining( { auto_signup: 'default', hide_lists: true } ) );
	} );

	it( 'keeps the settings a stored gate already has, and saves them unchanged', async () => {
		// A gate whose settings are not the defaults, so a load that reset them would show.
		storeNewsletterGate( { ...NEWSLETTER_GATE, auto_signup: 'on', hide_lists: true } );
		await renderNewsletterEdit();

		expect( screen.getByRole( 'option', { name: 'On' } ).selected ).toBe( true );
		expect( screen.getByRole( 'checkbox', { name: 'Hide from readers' } ) ).toBeChecked();

		await runHeaderAction( 'Rename' );
		fireEvent.change( screen.getByRole( 'textbox', { name: /premium newsletter name/i } ), { target: { value: 'Edited title' } } );
		await runHeaderAction( 'Save' );

		// Only the title changed, so the save must carry the stored settings through.
		expect( savedGate() ).toEqual( expect.objectContaining( { title: 'Edited title', auto_signup: 'on', hide_lists: true } ) );
	} );

	it( 'leaves both settings and both keys out of a content gate', async () => {
		await renderEdit();

		expect( screen.queryByRole( 'combobox', { name: 'Auto-signup' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'checkbox', { name: 'Hide from readers' } ) ).not.toBeInTheDocument();

		await runHeaderAction( 'Rename' );
		fireEvent.change( screen.getByRole( 'textbox', { name: /gate name/i } ), { target: { value: 'Edited title' } } );
		await runHeaderAction( 'Save' );

		expect( savedGate() ).not.toHaveProperty( 'auto_signup' );
		expect( savedGate() ).not.toHaveProperty( 'hide_lists' );
	} );
} );
