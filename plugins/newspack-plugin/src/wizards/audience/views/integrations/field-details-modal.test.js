import { render, fireEvent, screen } from '@testing-library/react';
import FieldDetailsModal from './field-details-modal';
import { buildFieldRows } from './outbound-fields';

const DEFS = [
	{
		id: 'v1:last_payment_amount',
		version: 'v1',
		raw_key: 'last_payment_amount',
		name: 'Last Payment Amount',
		section: 'Payment',
		available: true,
		dynamic_suffix: false,
		description: 'Amount of the most recent payment, net of refunds',
		example: '20',

		status: 'existing',
		supersedes: null,
		superseded_by: [],
		in_conflict_group: true,
	},
	{
		id: 'v2:Last_Payment_Amount',
		version: 'v2',
		raw_key: 'Last_Payment_Amount',
		name: 'Last Payment Amount',
		section: 'Payment',
		available: true,
		dynamic_suffix: false,
		description: 'Gross amount of the most recent order',
		example: '20.00',

		status: 'updated',
		supersedes: null,
		superseded_by: [],
		in_conflict_group: true,
	},
];

describe( 'FieldDetailsModal', () => {
	it( 'renders comparison cards for a conflict row and picks a version', () => {
		const row = buildFieldRows( DEFS, [ 'v1:last_payment_amount' ], 'v1' )[ 0 ];
		const onPickVersion = jest.fn();
		render( <FieldDetailsModal row={ row } onPickVersion={ onPickVersion } onClose={ () => {} } /> );
		expect( screen.getByText( 'Amount of the most recent payment, net of refunds' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Gross amount of the most recent order' ) ).toBeInTheDocument();
		// `Notice` calls `@wordpress/a11y`'s `speak()` on mount, which echoes its
		// text into an off-screen aria-live region alongside the real content —
		// exclude that region so the query resolves to the one visible notice.
		expect( screen.getByText( /segments and automations/i, { ignore: '.a11y-speak-region' } ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: /use v2/i } ) );
		expect( onPickVersion ).toHaveBeenCalledWith( 'v2' );
	} );

	it( 'renders a single card without a picker for non-conflict rows', () => {
		const single = [ { ...DEFS[ 0 ], in_conflict_group: false, id: 'v1:total_paid', name: 'Total Paid', raw_key: 'total_paid' } ];
		const row = buildFieldRows( single, [], 'v1' )[ 0 ];
		render( <FieldDetailsModal row={ row } onPickVersion={ () => {} } onClose={ () => {} } /> );
		expect( screen.queryByRole( 'button', { name: /use v/i } ) ).not.toBeInTheDocument();
	} );
} );
