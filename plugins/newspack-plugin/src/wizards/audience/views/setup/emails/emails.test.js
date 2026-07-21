// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';

jest.mock( './emails.scss', () => ( {} ) );

// Use mock-prefixed names so Jest's hoisted jest.mock can close over them.
const mockWizardApiFetch = jest.fn();
const mockResetError = jest.fn();
let mockErrorMessage = null;

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: ( ...args ) => mockWizardApiFetch( ...args ),
		isFetching: false,
		errorMessage: mockErrorMessage,
		resetError: ( ...args ) => mockResetError( ...args ),
	} ),
} ) );

jest.mock( '@wordpress/icons', () => ( {
	Icon: ( { icon } ) => <span data-testid="icon">{ icon }</span>,
	envelope: 'envelope',
} ) );

jest.mock( '@wordpress/dataviews', () => ( {
	filterSortAndPaginate: data => ( {
		data,
		paginationInfo: { totalItems: data.length, totalPages: 1 },
	} ),
} ) );

// Use mock-prefixed names so Jest's hoisted jest.mock can close over them.
let mockCapturedActions = [];
let mockCapturedView = null;
let mockCapturedOnChangeView = null;
let mockCapturedData = [];

jest.mock( '../../../../../../packages/components/src', () => {
	function renderField( field, item ) {
		if ( field.render ) {
			return field.render( { item } );
		}
		if ( field.getValue ) {
			return field.getValue( { item } );
		}
		return null;
	}
	return {
		Badge: ( { text } ) => <span>{ text }</span>,
		DataViews: ( { data, fields, actions, view, onChangeView } ) => {
			mockCapturedActions = actions || [];
			mockCapturedView = view;
			mockCapturedOnChangeView = onChangeView;
			mockCapturedData = data;
			return (
				<table data-testid="dataviews">
					<tbody>
						{ data.map( ( item, i ) => (
							<tr key={ i }>
								{ fields.map( field => (
									<td key={ field.id }>{ renderField( field, item ) }</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			);
		},
		Card: ( { children } ) => <div data-testid="card">{ children }</div>,
		Notice: ( { noticeText } ) => <div data-testid="notice">{ noticeText }</div>,
		utils: {
			confirmAction: jest.fn( () => true ),
		},
	};
} );

jest.mock(
	'../../../../wizards-plugin-card',
	() =>
		function MockPluginCard( props ) {
			return <div data-testid="plugin-card">{ props.slug }</div>;
		}
);

// Mock EmailPreview so we don't run its IntersectionObserver +
// apiFetch in every emails test. The stub exposes the postId prop
// via a data-attribute so the preview-field tests can assert which
// id reached the component for each row.
jest.mock( './email-preview', () => ( {
	__esModule: true,
	default: function MockEmailPreview( { postId } ) {
		return <div data-testid="email-preview-stub" data-post-id={ String( postId ) } />;
	},
} ) );

// Stub the Settings modal — it pulls in @wordpress/data's useDispatch
// against the wizards store, which isn't registered in this test env.
// The grid tests don't exercise modal behavior; the modal has its own
// test file. Render nothing here so emails.tsx mounts cleanly.
jest.mock( './settings-modal', () => ( {
	__esModule: true,
	default: function MockSettingsModal() {
		return null;
	},
} ) );

// Fixtures span both chips and both sources so the chip-filter and
// type-routing tests have meaningful data on either side of the toggle.
const mockEmails = [
	{
		label: 'Payment receipt',
		post_id: 1,
		edit_link: '/edit/1',
		status: 'publish',
		type: 'receipt',
		category: 'reader-revenue',
		trigger_description: 'Sent after a successful payment.',
		registry_slug: 'receipt',
		recipient: 'reader',
		source: 'newspack',
		chip: 'reader-revenue',
	},
	{
		label: 'Cancellation confirmation',
		post_id: 2,
		edit_link: '/edit/2',
		status: 'publish',
		type: 'cancellation',
		category: 'reader-revenue',
		trigger_description: 'Sent when a reader cancels their subscription.',
		registry_slug: 'cancellation',
		recipient: 'reader',
		source: 'newspack',
		chip: 'reader-revenue',
	},
	{
		label: 'Reader verification',
		post_id: 3,
		edit_link: '/edit/3',
		status: 'publish',
		type: 'reader-activation-verification',
		category: 'reader-activation',
		trigger_description: 'Sent when a reader needs to verify their email address.',
		registry_slug: 'reader-activation-verification',
		recipient: 'reader',
		source: 'newspack',
		chip: 'auth-account',
	},
	{
		label: 'Account deletion',
		post_id: 4,
		edit_link: '/edit/4',
		status: 'draft',
		type: 'reader-activation-delete-account',
		category: 'reader-activation',
		trigger_description: 'Sent when a reader requests to delete their account.',
		registry_slug: 'reader-activation-delete-account',
		recipient: 'reader',
		source: 'newspack',
		chip: 'auth-account',
	},
	{
		label: 'Welcome email',
		post_id: 5,
		edit_link: '/edit/5',
		status: 'draft',
		type: 'welcome',
		category: 'reader-revenue',
		trigger_description: 'Sent to new supporters after their first payment.',
		registry_slug: 'welcome',
		recipient: 'reader',
		source: 'newspack',
		chip: 'reader-revenue',
	},
	// WC-source, reader-revenue chip, admin recipient, currently enabled —
	// exercises the deactivate→toggleWcEmail route (string post_id) AND
	// the BLOCK-template preview path (preview_id is an integer post ID).
	{
		label: 'New order',
		post_id: 'wc:new_order',
		preview_id: 999,
		edit_link: '/wp-admin/admin.php?page=wc-settings&tab=email&section=wc_email_new_order',
		status: 'publish',
		type: 'new_order',
		category: 'woocommerce',
		trigger_description: 'Sent to the admin when a new order is placed.',
		registry_slug: 'new_order',
		recipient: 'admin',
		source: 'woocommerce',
		chip: 'reader-revenue',
	},
	// WC-source, auth-account chip, currently disabled — exercises the
	// activate→toggleWcEmail route (string post_id) AND the CLASSIC
	// preview path (preview_id is a wc:{id} string, no block template).
	{
		label: 'New account',
		post_id: 'wc:customer_new_account',
		preview_id: 'wc:customer_new_account',
		edit_link: '/wp-admin/admin.php?page=wc-settings&tab=email&section=wc_email_customer_new_account',
		status: 'draft',
		type: 'customer_new_account',
		category: 'woocommerce',
		trigger_description: 'Sent when a customer creates a new account.',
		registry_slug: 'customer_new_account',
		recipient: 'reader',
		source: 'woocommerce',
		chip: 'auth-account',
	},
];

describe( 'Emails', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockErrorMessage = null;
		mockCapturedActions = [];
		mockCapturedView = null;
		mockCapturedOnChangeView = null;
		mockCapturedData = [];
		window.newspackAudience = {
			emails: {
				dependencies: {
					newspackNewsletters: true,
				},
				postType: 'newspack_rr_email',
				// Default to the Newspack platform so the full chip set (and
				// reader-revenue default) applies. The non-Newspack case has
				// its own test below.
				isNewspackPlatform: true,
			},
		};
		mockWizardApiFetch.mockImplementation( ( opts, callbacks ) => {
			if ( opts.path === '/newspack/v1/wizard/newspack-settings/emails' ) {
				callbacks?.onSuccess?.( {
					newspack_emails: mockEmails,
					post_type: 'newspack_rr_email',
				} );
			}
			return Promise.resolve();
		} );
	} );

	it( 'renders reader-revenue emails by default', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		// Default chip is reader-revenue — these rows are visible.
		await waitFor( () => {
			expect( screen.getByText( 'Payment receipt' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Cancellation confirmation' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Welcome email' ) ).toBeInTheDocument();
			expect( screen.getByText( 'New order' ) ).toBeInTheDocument();
		} );

		// Auth-account rows are filtered out by default.
		expect( screen.queryByText( 'Reader verification' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Account deletion' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'New account' ) ).not.toBeInTheDocument();
	} );

	it( 'renders Recipient column with Reader/Admin labels', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			// Default chip = reader-revenue: 3 Reader (receipt, cancellation, welcome) + 1 Admin (new_order).
			const readerCells = screen.getAllByText( 'Reader' );
			expect( readerCells.length ).toBeGreaterThanOrEqual( 3 );
			const adminCells = screen.getAllByText( 'Admin' );
			expect( adminCells.length ).toBeGreaterThanOrEqual( 1 );
		} );
	} );

	it( 'renders status as Enabled / Disabled', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			const enabledCells = screen.getAllByText( 'Enabled' );
			expect( enabledCells.length ).toBeGreaterThanOrEqual( 3 );
			const disabledCells = screen.getAllByText( 'Disabled' );
			expect( disabledCells.length ).toBeGreaterThanOrEqual( 1 );
		} );
	} );

	it( 'deactivate action calls wizardApiFetch with draft status', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const deactivate = mockCapturedActions.find( a => a.id === 'deactivate' );
		deactivate.callback( [ mockEmails[ 0 ] ] );

		// updateStatus applies the new status optimistically in local
		// state before the fetch fires, then passes `onError` only —
		// rollback on failure, no onSuccess. (Verifying onError exists
		// instead of onSuccess catches a regression that drops the
		// rollback path.)
		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wp/v2/newspack_rr_email/1',
				method: 'POST',
				data: { status: 'draft' },
			} ),
			// updateStatus is optimistic — it patches the row in place and
			// only wires onError (to roll back), never onSuccess.
			expect.objectContaining( {
				onError: expect.any( Function ),
			} )
		);
	} );

	it( 'displays error notice when hook reports an error', async () => {
		mockErrorMessage = 'Something went wrong';

		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'notice' ) ).toBeInTheDocument();
			expect( screen.getByTestId( 'notice' ) ).toHaveTextContent( 'Something went wrong' );
		} );
	} );

	it( 'activate action calls wizardApiFetch with publish status', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const activate = mockCapturedActions.find( a => a.id === 'activate' );
		// mockEmails[4] (Welcome email) is newspack + reader-revenue + draft —
		// actually eligible for activate (category !== 'reader-activation').
		// Verifies the callback wiring on an item that would pass `isEligible`.
		expect( activate.isEligible( mockEmails[ 4 ] ) ).toBe( true );
		activate.callback( [ mockEmails[ 4 ] ] );

		// updateStatus applies the new status optimistically — onError
		// is the only callback (rollback on failure). See the matching
		// deactivate test above for the same shape.
		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wp/v2/newspack_rr_email/5',
				method: 'POST',
				data: { status: 'publish' },
			} ),
			// updateStatus is optimistic — it patches the row in place and
			// only wires onError (to roll back), never onSuccess.
			expect.objectContaining( {
				onError: expect.any( Function ),
			} )
		);
	} );

	it( 'deactivate/activate are not eligible for reader-activation emails', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const deactivate = mockCapturedActions.find( a => a.id === 'deactivate' );
		const activate = mockCapturedActions.find( a => a.id === 'activate' );

		// Reader-activation emails cannot be toggled.
		expect( deactivate.isEligible( mockEmails[ 2 ] ) ).toBe( false );
		// Newspack reader-revenue email can be deactivated.
		expect( deactivate.isEligible( mockEmails[ 0 ] ) ).toBe( true );
		// Draft reader-activation email cannot be activated.
		expect( activate.isEligible( mockEmails[ 3 ] ) ).toBe( false );
	} );

	it( 'reset action calls wizardApiFetch with DELETE after confirmation', async () => {
		const { utils } = require( '../../../../../../packages/components/src' );
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const reset = mockCapturedActions.find( a => a.id === 'reset' );
		reset.callback( [ mockEmails[ 0 ] ] );

		expect( utils.confirmAction ).toHaveBeenCalled();

		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/newspack/v1/wizard/newspack-settings/emails/1',
				method: 'DELETE',
			} ),
			expect.objectContaining( {
				onSuccess: expect.any( Function ),
			} )
		);
	} );

	it( 'reset is eligible for newspack-source rows', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const reset = mockCapturedActions.find( a => a.id === 'reset' );
		// Reset's `isEligible` is gated on `item.source === 'newspack'`
		// only — the legacy registry_slug check was dropped in the
		// refactor (the registry_slug field is derived from the unified
		// config, and a Newspack-source row that lacks one is in any
		// case a Newspack-emails-system error, not a UI-input case to
		// guard against). The sister test below covers the WC-source
		// inverse.
		expect( reset.isEligible( mockEmails[ 0 ] ) ).toBe( true );
	} );

	// Slice 2a — WC surfacing tests below.

	it( 'reset is NOT eligible for a woocommerce-source row', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const reset = mockCapturedActions.find( a => a.id === 'reset' );
		// WC-source row — source guard rejects, even with registry_slug present.
		expect( reset.isEligible( mockEmails[ 5 ] ) ).toBe( false );
		// And again with the auth-account WC row.
		expect( reset.isEligible( mockEmails[ 6 ] ) ).toBe( false );
	} );

	it( 'deactivate routes string post_id to toggleWcEmail', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const deactivate = mockCapturedActions.find( a => a.id === 'deactivate' );
		// WC row is publish + category !== reader-activation → eligible.
		expect( deactivate.isEligible( mockEmails[ 5 ] ) ).toBe( true );
		deactivate.callback( [ mockEmails[ 5 ] ] );

		// Type-based routing: string post_id 'wc:new_order' goes to the
		// toggle endpoint, not to wp/v2 post status update.
		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/newspack/v1/wizard/newspack-settings/emails/new_order/toggle',
				method: 'POST',
				data: { enabled: false },
			} ),
			expect.objectContaining( {
				onSuccess: expect.any( Function ),
			} )
		);
		// And it did NOT fall through to the wp/v2 update path.
		expect( mockWizardApiFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				path: expect.stringContaining( '/wp/v2/' ),
			} ),
			expect.anything()
		);
	} );

	it( 'activate routes string post_id to toggleWcEmail', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const activate = mockCapturedActions.find( a => a.id === 'activate' );
		// WC draft row → eligible for activate.
		expect( activate.isEligible( mockEmails[ 6 ] ) ).toBe( true );
		activate.callback( [ mockEmails[ 6 ] ] );

		expect( mockWizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/newspack/v1/wizard/newspack-settings/emails/customer_new_account/toggle',
				method: 'POST',
				data: { enabled: true },
			} ),
			expect.objectContaining( {
				onSuccess: expect.any( Function ),
			} )
		);
	} );

	it( 'toggleWcEmail onSuccess replaces local state with the authoritative server response', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		// Deactivate the enabled WC row (New order). The toggle mock resolves
		// without invoking callbacks, so we drive onSuccess by hand below.
		const deactivate = mockCapturedActions.find( a => a.id === 'deactivate' );
		act( () => {
			deactivate.callback( [ mockEmails[ 5 ] ] );
		} );

		const toggleCall = mockWizardApiFetch.mock.calls.find( ( [ opts ] ) => opts.path?.includes( '/toggle' ) );
		expect( toggleCall ).toBeDefined();
		const { onSuccess } = toggleCall[ 1 ];

		// Server returns an authoritative payload that differs from anything
		// the client could predict (a sibling row's label changed). onSuccess
		// must replace local state with it wholesale.
		const serverEmails = mockEmails.map( email =>
			email.post_id === 1 ? { ...email, label: 'Payment receipt (server-authoritative)' } : email
		);
		act( () => {
			onSuccess( { newspack_emails: serverEmails, post_type: 'newspack_rr_email' } );
		} );

		await waitFor( () => {
			expect( screen.getByText( 'Payment receipt (server-authoritative)' ) ).toBeInTheDocument();
		} );
	} );

	it( 'toggleWcEmail onError rolls back the optimistic status change', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		// New order starts enabled (publish).
		const before = mockCapturedData.find( item => item.post_id === 'wc:new_order' );
		expect( before.status ).toBe( 'publish' );

		const deactivate = mockCapturedActions.find( a => a.id === 'deactivate' );
		act( () => {
			deactivate.callback( [ mockEmails[ 5 ] ] );
		} );

		// Optimistic update flipped it to draft before the request settled.
		await waitFor( () => {
			expect( mockCapturedData.find( item => item.post_id === 'wc:new_order' ).status ).toBe( 'draft' );
		} );

		// Drive the failure path — onError restores the pre-toggle snapshot.
		const toggleCall = mockWizardApiFetch.mock.calls.find( ( [ opts ] ) => opts.path?.includes( '/toggle' ) );
		const { onError } = toggleCall[ 1 ];
		act( () => {
			onError();
		} );

		await waitFor( () => {
			expect( mockCapturedData.find( item => item.post_id === 'wc:new_order' ).status ).toBe( 'publish' );
		} );
	} );

	it( 'chip filter shows only rows matching activeChip', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		// Default chip = reader-revenue. Auth-account rows are filtered out.
		await waitFor( () => {
			expect( screen.getByText( 'Payment receipt' ) ).toBeInTheDocument();
		} );
		expect( screen.queryByText( 'Reader verification' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'New account' ) ).not.toBeInTheDocument();

		// Switch chip — auth-account rows now visible, reader-revenue rows hidden.
		fireEvent.click( screen.getByRole( 'button', { name: 'Authentication & account' } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Reader verification' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Account deletion' ) ).toBeInTheDocument();
			expect( screen.getByText( 'New account' ) ).toBeInTheDocument();
		} );
		expect( screen.queryByText( 'Payment receipt' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Cancellation confirmation' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'New order' ) ).not.toBeInTheDocument();

		// The DataViews input is also chip-filtered before filterSortAndPaginate.
		expect( mockCapturedData.every( item => item.chip === 'auth-account' ) ).toBe( true );
	} );

	it( 'chip switch resets search and page', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		// Simulate DataViews search/page change. Wrapped in act() because
		// onChangeView triggers a React state update outside an event handler.
		act( () => {
			mockCapturedOnChangeView( {
				...mockCapturedView,
				search: 'receipt',
				page: 3,
			} );
		} );
		await waitFor( () => {
			expect( mockCapturedView.search ).toBe( 'receipt' );
			expect( mockCapturedView.page ).toBe( 3 );
		} );

		// Click the other chip — selectChip() resets search and page.
		fireEvent.click( screen.getByRole( 'button', { name: 'Authentication & account' } ) );

		await waitFor( () => {
			expect( mockCapturedView.search ).toBe( '' );
			expect( mockCapturedView.page ).toBe( 1 );
		} );
	} );

	it( 'search bypasses chip filter — operates across all chips', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		// Default (no search): chip filter active, reader-revenue only.
		expect( mockCapturedData.every( item => item.chip === 'reader-revenue' ) ).toBe( true );
		expect( mockCapturedData.length ).toBe( 4 );

		// Activate search via the DataViews onChangeView prop.
		act( () => {
			mockCapturedOnChangeView( {
				...mockCapturedView,
				search: 'anything',
				page: 1,
			} );
		} );

		// Full dataset now flows into filterSortAndPaginate — both chips
		// represented, no chip pre-filter applied.
		await waitFor( () => {
			expect( mockCapturedData.length ).toBe( mockEmails.length );
		} );
		const chipsRepresented = new Set( mockCapturedData.map( item => item.chip ) );
		expect( chipsRepresented ).toEqual( new Set( [ 'reader-revenue', 'auth-account' ] ) );
	} );

	it( 'chip bar shows both chips unpressed during active search', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const rrChip = screen.getByRole( 'button', { name: 'Reader revenue' } );
		const aaChip = screen.getByRole( 'button', {
			name: 'Authentication & account',
		} );

		// Default: Reader revenue chip is pressed.
		expect( rrChip.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( aaChip.getAttribute( 'aria-pressed' ) ).toBe( 'false' );

		// Search active — both chips deactivate visually (activeChip is
		// still set in state, but the visual matches what's filtering).
		act( () => {
			mockCapturedOnChangeView( {
				...mockCapturedView,
				search: 'foo',
				page: 1,
			} );
		} );

		await waitFor( () => {
			expect( rrChip.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
			expect( aaChip.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		} );
	} );

	it( 'clearing search restores active chip pressed state', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		// Set search.
		act( () => {
			mockCapturedOnChangeView( {
				...mockCapturedView,
				search: 'foo',
				page: 1,
			} );
		} );
		await waitFor( () => {
			expect( screen.getByRole( 'button', { name: 'Reader revenue' } ).getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		} );

		// Clear search — activeChip (still 'reader-revenue') re-engages.
		act( () => {
			mockCapturedOnChangeView( {
				...mockCapturedView,
				search: '',
				page: 1,
			} );
		} );
		await waitFor( () => {
			expect( screen.getByRole( 'button', { name: 'Reader revenue' } ).getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		} );
	} );

	// Slice 2b.5 — DataViews preview field. The render uses a smart
	// fallback: `item.preview_id ?? item.post_id` (with a typeof guard
	// rejecting wc:strings as a fallback target). Newspack rows have no
	// preview_id, so they fall back to their integer post_id. WC rows
	// emit preview_id directly — integer for block-template emails,
	// `wc:{id}` string for classic-template emails.

	it( 'preview field renders an anchor with aria-label for each row', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		// Default chip = reader-revenue: receipt, cancellation, welcome,
		// new order. Each row's preview field renders an anchor with an
		// aria-label of the form "Edit {label}".
		expect( screen.getByRole( 'link', { name: 'Edit Payment receipt' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Edit Cancellation confirmation' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Edit Welcome email' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Edit New order' } ) ).toBeInTheDocument();
	} );

	it( 'preview field passes the right id to EmailPreview for each render path', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		const previews = screen.getAllByTestId( 'email-preview-stub' );
		const ids = previews.map( el => el.getAttribute( 'data-post-id' ) );

		// Default chip = reader-revenue. Expected ids on this chip:
		// - Newspack rows fall back to integer post_id: receipt=1,
		//   cancellation=2, welcome=5
		// - WC block-template row uses preview_id (integer): new_order=999
		expect( ids ).toEqual( expect.arrayContaining( [ '1', '2', '5', '999' ] ) );

		// Switch to auth-account chip so the WC classic row surfaces.
		fireEvent.click( screen.getByRole( 'button', { name: 'Authentication & account' } ) );

		await waitFor( () => {
			const aaPreviews = screen.getAllByTestId( 'email-preview-stub' );
			const aaIds = aaPreviews.map( el => el.getAttribute( 'data-post-id' ) );
			// Newspack RA fallback: verification=3, delete-account=4.
			// WC classic row uses preview_id (string): customer_new_account.
			expect( aaIds ).toEqual( expect.arrayContaining( [ '3', '4', 'wc:customer_new_account' ] ) );
		} );
	} );

	it( 'renders the Emails heading as visually hidden (screen-reader only)', async () => {
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		// The tab surface renders no visible title; the section heading is
		// present for assistive tech but visually hidden via screen-reader-text.
		const heading = screen.getByRole( 'heading', { level: 1, name: 'Emails' } );
		expect( heading ).toHaveClass( 'screen-reader-text' );
	} );

	it( 'hides the chip bar entirely on a non-Newspack platform', async () => {
		// NPPD-1538: on RevEngine/Other the server returns only auth/account
		// emails, so there's a single group — the chip bar is hidden rather
		// than showing a lone, always-pressed (non-functional) chip. Settings
		// stays available; the list renders unfiltered.
		window.newspackAudience.emails.isNewspackPlatform = false;
		const Emails = require( './emails' ).default;
		render( <Emails /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'dataviews' ) ).toBeInTheDocument();
		} );

		expect( screen.queryByRole( 'button', { name: 'Reader revenue' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Authentication & account' } ) ).not.toBeInTheDocument();
		// Settings button remains.
		expect( screen.getByRole( 'button', { name: 'Settings' } ) ).toBeInTheDocument();
	} );
} );
