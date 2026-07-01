// Spy on the network layer; the real `@wordpress/components` render the
// RadioControl so we exercise the actual radio markup. `QuickEditPanel`
// is a thin modal shell (portal + exit animation), so it's mocked down to
// a plain wrapper that exposes `isDirty` and a Save trigger — the panel's
// own status/save logic is what's under test here.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '../../components/quick-edit-panel', () => ( {
	__esModule: true,
	default: ( { children, isDirty, canSave, isBusy, onSave } ) => (
		<div>
			<div data-testid="panel-dirty">{ String( isDirty ) }</div>
			{ children }
			<button type="button" data-testid="panel-save" onClick={ onSave } disabled={ isBusy || ! canSave }>
				Save
			</button>
		</div>
	),
} ) );

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import AdsQuickEditPanel from './quick-edit-panel';

const ADVERTISERS = [ { id: 10, name: 'Acme' } ];
const PLACEMENTS = [ { id: 20, name: 'Header' } ];

const renderPanel = ( item, extra = {} ) =>
	render(
		<AdsQuickEditPanel
			item={ item }
			advertisers={ ADVERTISERS }
			placements={ PLACEMENTS }
			onClose={ jest.fn() }
			onSaved={ jest.fn() }
			{ ...extra }
		/>
	);

const makeItem = status => ( { id: 42, status, title: { raw: 'Summer sale' }, meta: {} } );

const postCall = () => apiFetch.mock.calls.find( call => call[ 0 ]?.method === 'POST' )?.[ 0 ];

describe( 'AdsQuickEditPanel status control', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		// Categories fetch (`fetchAllTerms`) and the save POST both go
		// through this mock; an empty object resolves both harmlessly.
		apiFetch.mockResolvedValue( {} );
	} );

	it( 'selects Active when the raw post_status is publish', async () => {
		renderPanel( makeItem( 'publish' ) );
		expect( await screen.findByRole( 'radio', { name: 'Active' } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: 'Inactive' } ) ).not.toBeChecked();
	} );

	it( 'selects Inactive when the raw post_status is draft', async () => {
		renderPanel( makeItem( 'draft' ) );
		expect( await screen.findByRole( 'radio', { name: 'Inactive' } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: 'Active' } ) ).not.toBeChecked();
	} );

	it( 'POSTs status: publish when toggled Inactive → Active', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'draft' ), { onSaved } );
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Active' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().path ).toBe( '/wp/v2/newspack_nl_ads_cpt/42' );
		expect( postCall().method ).toBe( 'POST' );
		expect( postCall().data.status ).toBe( 'publish' );
	} );

	it( 'POSTs status: draft when toggled Active → Inactive', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'publish' ), { onSaved } );
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Inactive' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data.status ).toBe( 'draft' );
	} );

	it( 'omits status from the payload when it is unchanged', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'publish' ), { onSaved } );
		await screen.findByRole( 'radio', { name: 'Active' } );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data ).not.toHaveProperty( 'status' );
	} );

	it( 'preserves an edge status (future) by omitting status when unchanged', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'future' ), { onSaved } );
		// `future` maps to the Active radio but must not be rewritten to publish on save.
		expect( await screen.findByRole( 'radio', { name: 'Active' } ) ).toBeChecked();
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data ).not.toHaveProperty( 'status' );
	} );

	it( 'flattens a scheduled (future) ad to draft when toggled to Inactive', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'future' ), { onSaved } );
		// Toggling a scheduled ad off is a manual override: it POSTs `draft`, discarding the scheduled post_date.
		fireEvent.click( await screen.findByRole( 'radio', { name: 'Inactive' } ) );
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data.status ).toBe( 'draft' );
	} );

	it( 'selects Inactive for a private ad and preserves it by omitting status when unchanged', async () => {
		const onSaved = jest.fn();
		renderPanel( makeItem( 'private' ), { onSaved } );
		// `private` ads are never served, so the control reflects Inactive; leaving it untouched must not rewrite the status.
		expect( await screen.findByRole( 'radio', { name: 'Inactive' } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: 'Active' } ) ).not.toBeChecked();
		fireEvent.click( screen.getByTestId( 'panel-save' ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( postCall().data ).not.toHaveProperty( 'status' );
	} );

	it( 'marks the panel dirty after a status-only change', async () => {
		renderPanel( makeItem( 'publish' ) );
		await screen.findByRole( 'radio', { name: 'Active' } );
		expect( screen.getByTestId( 'panel-dirty' ) ).toHaveTextContent( 'false' );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Inactive' } ) );
		expect( screen.getByTestId( 'panel-dirty' ) ).toHaveTextContent( 'true' );
	} );
} );
