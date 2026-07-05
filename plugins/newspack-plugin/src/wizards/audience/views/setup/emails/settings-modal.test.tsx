// @jest-environment jsdom

/**
 * NPPD-1566 — settings-modal coverage. Tests render the modal with
 * controllable mocks for useWizardApiFetch (fetch behavior),
 * useDispatch (addNotice spy), and useConfirmDialog (dirty-discard
 * flow). Test #1 also renders the parent emails.tsx to verify the
 * Settings button on the chip bar opens the modal — that's why this
 * file mirrors several of emails.test.js's parent-level mocks.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

jest.mock( './emails.scss', () => ( {} ) );

// Use mock-prefixed names so Jest's hoisted jest.mock can close over them.
const mockWizardApiFetch = jest.fn();
const mockResetError = jest.fn();
const mockAddNotice = jest.fn();
const mockRequestConfirm = jest.fn();
// Mutable hook state so individual tests can drive the fetching /
// error surfaces the modal reads. Reset in beforeEach.
let mockIsFetching = false;
let mockErrorMessage = null;

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( ...args ) => mockWizardApiFetch( ...args ),
		isFetching: mockIsFetching,
		errorMessage: mockErrorMessage,
		resetError: ( ...args ) => mockResetError( ...args ),
	} ),
} ) );

// useDispatch is the modal's only @wordpress/data hook. Other consumers
// in the render tree don't use the data store at the moment; if that
// changes, add corresponding mock entries here.
jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		addNotice: ( ...args ) => mockAddNotice( ...args ),
	} ),
} ) );

// The modal imports WIZARD_STORE_NAMESPACE from this barrel. Loading
// the real module would also evaluate `createReduxStore` against the
// mocked @wordpress/data (which doesn't expose it), so stub just the
// constant — that's all the modal reads.
jest.mock( '../../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

// Mocks below mirror emails.test.js's parent-level dependencies so
// test #1 can render the grid and click the Settings button.

jest.mock( '@wordpress/icons', () => ( {
	Icon: ( { icon } ) => <span data-testid="icon">{ icon }</span>,
	envelope: 'envelope',
} ) );

// Stub @wordpress/components — settings-modal.tsx imports TextControl
// and the experimental HStack/VStack. emails.tsx imports Button + HStack
// from here too. Real-module load fails in this jsdom env (the barrel
// pulls in components that need broader setup), so provide minimal
// passthrough stubs. TextControl mirrors the real component's contract:
// label + help + value + onChange( value ) signature, with the input
// rendered as the label's accessible target so getByLabelText resolves.
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	// help text intentionally rendered as a sibling of the label, not
	// a child — keeping it inside the label would fold into the input's
	// accessible name and break getByLabelText('Sender Name'). The real
	// TextControl uses aria-describedby for the same separation.
	// `...rest` forwards passthrough props (e.g. `aria-invalid`) onto the
	// input, mirroring the real TextControl's behavior, so tests can
	// assert the accessible invalid state.
	const TextControl = ( { label, help, value, onChange, type, required, ...rest } ) =>
		React.createElement(
			'div',
			null,
			React.createElement(
				'label',
				null,
				label,
				React.createElement( 'input', {
					type: type || 'text',
					value: value === undefined ? '' : value,
					onChange: e => onChange( e.target.value ),
					required: required || undefined,
					...rest,
				} )
			),
			help ? React.createElement( 'span', null, help ) : null
		);
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	// Discard `loading` rather than spreading it to the DOM <button> —
	// React warns on unrecognized non-boolean attributes. The real
	// Newspack Button accepts the prop and translates it to a spinner;
	// for tests we only need the click behavior.
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	const Button = ( { children, onClick, disabled, loading, ...rest } ) => React.createElement( 'button', { onClick, disabled, ...rest }, children );
	return {
		TextControl,
		Button,
		__experimentalHStack: Passthrough,
		__experimentalVStack: Passthrough,
	};
} );

jest.mock( '@wordpress/dataviews', () => ( {
	filterSortAndPaginate: data => ( {
		data,
		paginationInfo: { totalItems: data.length, totalPages: 1 },
	} ),
} ) );

jest.mock(
	'../../../../wizards-plugin-card',
	() =>
		function MockPluginCard() {
			return <div data-testid="plugin-card" />;
		}
);

jest.mock( './email-preview', () => ( {
	__esModule: true,
	default: function MockEmailPreview() {
		return <div data-testid="email-preview-stub" />;
	},
} ) );

// Single mock for the packages/components/src barrel covers both the
// grid (Badge, DataViews, Notice, utils) and the modal (Button, Modal,
// useConfirmDialog). useConfirmDialog is captured via the top-level
// spy so tests can assert when it was called and whether the callback
// fired.
jest.mock( '../../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		Badge: ( { text } ) => <span>{ text }</span>,
		DataViews: ( { data } ) => <div data-testid="dataviews">{ data.length }</div>,
		Notice: ( { noticeText } ) => <div data-testid="notice">{ noticeText }</div>,
		// Discard `loading` and `variant` rather than spreading them to
		// the DOM button — React warns on unrecognized non-boolean
		// attributes. Same treatment as the @wordpress/components Button
		// mock above.
		// eslint-disable-next-line @typescript-eslint/no-unused-vars
		Button: function MockButton( { children, onClick, disabled, loading, variant, ...rest } ) {
			return (
				<button onClick={ onClick } disabled={ disabled } { ...rest }>
					{ children }
				</button>
			);
		},
		Modal: function MockModal( { children, title, onRequestClose } ) {
			return (
				<div role="dialog" aria-label={ title }>
					<button aria-label="Close" onClick={ onRequestClose }>
						X
					</button>
					{ children }
				</div>
			);
		},
		useConfirmDialog: function MockUseConfirmDialog( opts ) {
			return {
				requestConfirm: function MockRequestConfirm( callback ) {
					mockRequestConfirm( { when: opts.when, callback } );
					// Match the real hook's contract: when=false skips
					// the dialog and fires the callback synchronously.
					if ( opts.when === false ) {
						callback();
					}
				},
				confirmDialog: React.createElement( 'div', { 'data-testid': 'mock-confirm-dialog' } ),
			};
		},
		utils: { confirmAction: jest.fn( () => true ) },
	};
} );

const SAMPLE_INITIAL = {
	sender_name: 'My Site',
	sender_email_address: 'hello@example.com',
	contact_email_address: 'support@example.com',
	defaults: {
		sender_name: 'My Default Site Title',
		sender_email_address: 'no-reply@example.com',
		contact_email_address: 'admin@example.com',
	},
};

// Default implementation: POST echoes the submitted data and preserves
// the defaults block (mirrors the server returning the full
// {values + defaults} shape on save). GET resolves with the provided
// initial settings. Returns a settled promise so production-side
// `.catch()` chaining (the unhandled-rejection guard) works in tests.
const setUpFetchMock = ( initial = SAMPLE_INITIAL ) => {
	mockWizardApiFetch.mockImplementation( ( args, handlers ) => {
		if ( args.method === 'POST' ) {
			if ( handlers && handlers.onSuccess ) {
				handlers.onSuccess( { ...args.data, defaults: initial.defaults } );
			}
		} else if ( handlers && handlers.onSuccess ) {
			handlers.onSuccess( initial );
		}
		return Promise.resolve();
	} );
};

// GET succeeds (populates + loads the form) but POST rejects: fires the
// modal's `onError` and returns a rejected promise, exercising the
// modal-level `.catch()` unhandled-rejection guard. Used to assert the
// modal stays open on save failure.
const setUpFetchMockPostFails = ( initial = SAMPLE_INITIAL ) => {
	mockWizardApiFetch.mockImplementation( ( args, handlers ) => {
		if ( args.method === 'POST' ) {
			if ( handlers && handlers.onError ) {
				handlers.onError();
			}
			return Promise.reject( new Error( 'save failed' ) );
		}
		if ( handlers && handlers.onSuccess ) {
			handlers.onSuccess( initial );
		}
		return Promise.resolve();
	} );
};

// GET rejects: fires `onError` and returns a rejected promise so the
// form never reaches the loaded state. Exercises the GET-rejection
// `.catch()` guard and the `! loaded` Save gate.
const setUpFetchMockGetFails = () => {
	mockWizardApiFetch.mockImplementation( ( args, handlers ) => {
		if ( handlers && handlers.onError ) {
			handlers.onError();
		}
		return Promise.reject( new Error( 'load failed' ) );
	} );
};

describe( 'SettingsModal', () => {
	beforeEach( () => {
		mockWizardApiFetch.mockReset();
		mockResetError.mockReset();
		mockAddNotice.mockReset();
		mockRequestConfirm.mockReset();
		mockIsFetching = false;
		mockErrorMessage = null;
		// Window globals emails.tsx reads at mount. Empty newspack_emails
		// is still truthy, so the grid's mount-time fetch is skipped —
		// only the modal will fire a fetch when opened.
		window.newspackAudience = {
			emails: {
				dependencies: { newspackNewsletters: true },
				initial: { newspack_emails: [], post_type: 'newspack_em' },
				postType: 'newspack_em',
			},
		};
	} );

	it( 'opens the modal when the Settings button on the chip bar is clicked', async () => {
		setUpFetchMock();
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		// Modal not yet rendered.
		expect( screen.queryByRole( 'dialog', { name: 'Settings' } ) ).not.toBeInTheDocument();

		// Click the Settings button on the chip bar.
		fireEvent.click( screen.getByRole( 'button', { name: 'Settings' } ) );

		await waitFor( () => {
			expect( screen.getByRole( 'dialog', { name: 'Settings' } ) ).toBeInTheDocument();
		} );
	} );

	it( 'saves: POSTs payload, dispatches success notice, closes modal', async () => {
		setUpFetchMock();
		const closeModal = jest.fn();
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ closeModal } /> );

		// Wait for the GET fetch to resolve and fields to populate.
		await waitFor( () => {
			expect( screen.getByLabelText( 'Sender Name' ).value ).toBe( 'My Site' );
		} );

		// Edit a field to make the modal dirty (enables Save).
		fireEvent.change( screen.getByLabelText( 'Sender Name' ), { target: { value: 'New Name' } } );

		// Click Save.
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => {
			expect( closeModal ).toHaveBeenCalled();
		} );

		// POST issued against the correct endpoint with the correct payload.
		const postCall = mockWizardApiFetch.mock.calls.find( ( [ args ] ) => args.method === 'POST' );
		expect( postCall ).toBeDefined();
		expect( postCall[ 0 ].path ).toBe( '/newspack/v1/wizard/newspack-settings/emails/settings' );
		expect( postCall[ 0 ].data ).toMatchObject( { sender_name: 'New Name' } );

		// Success notice dispatched against the wizards store.
		expect( mockAddNotice ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'success',
				message: 'Saved.',
			} )
		);
	} );

	it( 'cancel: prompts for confirmation when fields are dirty', async () => {
		setUpFetchMock();
		const closeModal = jest.fn();
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ closeModal } /> );

		await waitFor( () => {
			expect( screen.getByLabelText( 'Sender Name' ).value ).toBe( 'My Site' );
		} );

		// Dirty the form.
		fireEvent.change( screen.getByLabelText( 'Sender Name' ), { target: { value: 'Different' } } );

		// Click Cancel.
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

		// requestConfirm called with when=true (dirty). Callback NOT
		// invoked — closeModal stays unfired pending user confirmation.
		expect( mockRequestConfirm ).toHaveBeenCalledTimes( 1 );
		expect( mockRequestConfirm.mock.calls[ 0 ][ 0 ].when ).toBe( true );
		expect( closeModal ).not.toHaveBeenCalled();
	} );

	it( 'cancel: closes directly when fields are clean (no prompt)', async () => {
		setUpFetchMock();
		const closeModal = jest.fn();
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ closeModal } /> );

		await waitFor( () => {
			expect( screen.getByLabelText( 'Sender Name' ).value ).toBe( 'My Site' );
		} );

		// No edits — directly click Cancel.
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

		// requestConfirm called with when=false (clean). Callback fires
		// immediately → closeModal invoked.
		expect( mockRequestConfirm ).toHaveBeenCalledTimes( 1 );
		expect( mockRequestConfirm.mock.calls[ 0 ][ 0 ].when ).toBe( false );
		expect( closeModal ).toHaveBeenCalled();
	} );

	it( 'renders the inline error Notice when the hook surfaces an errorMessage', async () => {
		setUpFetchMock();
		mockErrorMessage = 'Could not save transactional email settings.';
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ jest.fn() } /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'notice' ) ).toHaveTextContent( 'Could not save transactional email settings.' );
		} );
	} );

	it( 'save failure: fires onError, keeps the modal open, dispatches no success notice', async () => {
		setUpFetchMockPostFails();
		const closeModal = jest.fn();
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ closeModal } /> );

		await waitFor( () => {
			expect( screen.getByLabelText( 'Sender Name' ).value ).toBe( 'My Site' );
		} );

		// Dirty + valid → Save enabled.
		fireEvent.change( screen.getByLabelText( 'Sender Name' ), { target: { value: 'New Name' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		// POST was issued and rejected (swallowed by the modal-level
		// .catch — no unhandled rejection).
		await waitFor( () => {
			expect( mockWizardApiFetch.mock.calls.some( ( [ args ] ) => args.method === 'POST' ) ).toBe( true );
		} );

		// Modal stays open: closeModal never fires, no success notice.
		expect( closeModal ).not.toHaveBeenCalled();
		expect( mockAddNotice ).not.toHaveBeenCalled();
	} );

	it( 'disables Save while a request is in flight', async () => {
		setUpFetchMock();
		mockIsFetching = true;
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ jest.fn() } /> );

		// Form loads from the GET, then dirty + valid — the only thing
		// keeping Save disabled is the in-flight request.
		await waitFor( () => {
			expect( screen.getByLabelText( 'Sender Name' ).value ).toBe( 'My Site' );
		} );
		fireEvent.change( screen.getByLabelText( 'Sender Name' ), { target: { value: 'New Name' } } );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
	} );

	it( 'gates Save and flags the field on a malformed email', async () => {
		setUpFetchMock();
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ jest.fn() } /> );

		await waitFor( () => {
			expect( screen.getByLabelText( 'Sender Email Address' ).value ).toBe( 'hello@example.com' );
		} );

		// Type a malformed (non-empty) email.
		fireEvent.change( screen.getByLabelText( 'Sender Email Address' ), { target: { value: 'not-an-email' } } );

		// Save is gated, the field is marked invalid, and field-level
		// help explains the requirement.
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		expect( screen.getByLabelText( 'Sender Email Address' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( screen.getByText( 'Enter a valid email address, or leave blank to use the default.' ) ).toBeInTheDocument();
	} );

	it( 'GET failure: leaves Save disabled even when the user dirties a field (not-loaded guard)', async () => {
		setUpFetchMockGetFails();
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ jest.fn() } /> );

		// GET rejected → form never loaded. The fields render empty.
		await waitFor( () => {
			expect( mockWizardApiFetch ).toHaveBeenCalled();
		} );

		// Even after a valid, dirtying edit, the `! loaded` guard keeps
		// Save disabled so a failed GET can't be overwritten with the
		// empty-form values.
		fireEvent.change( screen.getByLabelText( 'Sender Name' ), { target: { value: 'Typed After Failure' } } );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
	} );

	// NPPD-1566: site titles / admin emails can carry encoded HTML
	// entities (e.g. an ampersand as `&amp;`). Both the saved values and
	// the derived-default placeholders must render decoded.
	it( 'decodes HTML entities in saved values and default placeholders', async () => {
		setUpFetchMock( {
			sender_name: 'Tom &amp; Jerry News',
			sender_email_address: 'hello@example.com',
			contact_email_address: 'support@example.com',
			defaults: {
				sender_name: 'Smith &amp; Co.',
				sender_email_address: 'no-reply@example.com',
				contact_email_address: 'admin@example.com',
			},
		} );
		const SettingsModal = require( './settings-modal' ).default;
		render( <SettingsModal showModal={ true } closeModal={ jest.fn() } /> );

		await waitFor( () => {
			// Saved value rendered decoded in the input.
			expect( screen.getByLabelText( 'Sender Name' ).value ).toBe( 'Tom & Jerry News' );
		} );
		// Derived default rendered decoded in the placeholder.
		expect( screen.getByLabelText( 'Sender Name' ) ).toHaveAttribute( 'placeholder', 'Smith & Co.' );
	} );
} );
