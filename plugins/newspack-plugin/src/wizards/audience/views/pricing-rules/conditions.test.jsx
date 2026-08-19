/**
 * The cohort-gate datetime condition: the default it arms on a new rule, and the
 * date it carries into Custom on an edit.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Conditions from './conditions';
import { tsToLocalInput } from './datetime';

jest.mock( '../../../../../packages/components/src', () => ( { AutocompleteTokenField: () => null } ) );

const LABEL = 'Subscriptions started on or after';
const VOCAB = [ { id: 'cohort_start', label: LABEL, field_type: 'datetime' } ];

describe( 'the cohort-gate datetime condition', () => {
	it( 'arms a new rule with the publish-date default', () => {
		const onChange = jest.fn();
		render( <Conditions vocab={ VOCAB } value={ {} } publishedAt={ null } isNew onChange={ onChange } path="custom" /> );

		expect( screen.getByLabelText( LABEL ) ).toHaveValue( 'publish' );
		expect( onChange ).toHaveBeenCalledTimes( 1 );
		const armed = onChange.mock.calls[ 0 ][ 0 ].cohort_start;
		expect( Math.abs( armed - Math.floor( Date.now() / 1000 ) ) ).toBeLessThan( 60 );
	} );

	it( 'seeds Custom from the date in force on an edit', () => {
		const publishedAt = 1750000000;
		const onChange = jest.fn();
		render(
			<Conditions
				vocab={ VOCAB }
				value={ { cohort_start: publishedAt } }
				publishedAt={ publishedAt }
				isNew={ false }
				onChange={ onChange }
				path="custom"
			/>
		);
		const mode = screen.getByLabelText( LABEL );
		expect( mode ).toHaveValue( 'publish' );

		fireEvent.change( mode, { target: { value: 'custom' } } );

		expect( screen.getByDisplayValue( tsToLocalInput( publishedAt ) ) ).toBeInTheDocument();
		expect( onChange ).toHaveBeenLastCalledWith( { cohort_start: publishedAt } );
	} );

	it( 'restores the date in force after a detour through Anytime', () => {
		const publishedAt = 1750000000;
		const onChange = jest.fn();
		const props = { vocab: VOCAB, publishedAt, isNew: false, onChange, path: 'custom' };
		const { rerender } = render( <Conditions { ...props } value={ { cohort_start: publishedAt } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'none' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { cohort_start: null } );
		rerender( <Conditions { ...props } value={ { cohort_start: null } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );

		expect( onChange ).toHaveBeenLastCalledWith( { cohort_start: publishedAt } );
		expect( screen.getByDisplayValue( tsToLocalInput( publishedAt ) ) ).toBeInTheDocument();
	} );

	it( 'carries the armed default into Custom on a new rule', () => {
		const onChange = jest.fn();
		const props = { vocab: VOCAB, publishedAt: null, isNew: true, onChange, path: 'custom' };
		const { rerender } = render( <Conditions { ...props } value={ {} } /> );

		// The parent now holds the timestamp the mount effect armed, so feed it back.
		const armed = onChange.mock.calls[ 0 ][ 0 ].cohort_start;
		rerender( <Conditions { ...props } value={ { cohort_start: armed } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );

		expect( onChange ).toHaveBeenLastCalledWith( { cohort_start: armed } );
		expect( screen.getByDisplayValue( tsToLocalInput( armed ) ) ).toBeInTheDocument();
	} );

	// A null gate is stored as no gate at all, which the engine reads as "everyone
	// qualifies" — so Custom must never be selectable without a date behind it.
	it( 'still carries a date into Custom on a new rule that detoured through Anytime', () => {
		const onChange = jest.fn();
		const props = { vocab: VOCAB, publishedAt: null, isNew: true, onChange, path: 'custom' };
		const { rerender } = render( <Conditions { ...props } value={ {} } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'none' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { cohort_start: null } );
		rerender( <Conditions { ...props } value={ { cohort_start: null } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );

		const stored = onChange.mock.calls[ onChange.mock.calls.length - 1 ][ 0 ].cohort_start;
		expect( stored ).not.toBeNull();
		expect( screen.getByDisplayValue( tsToLocalInput( stored ) ) ).toBeInTheDocument();
	} );

	// A datetime-local reads as empty until every segment is filled, so an in-progress
	// entry must not be mistaken for "no gate".
	it( 'keeps the stored date while a Custom entry is cleared, and restores it on blur', () => {
		const publishedAt = 1750000000;
		const onChange = jest.fn();
		const props = { vocab: VOCAB, publishedAt, isNew: false, onChange, path: 'custom' };
		render( <Conditions { ...props } value={ { cohort_start: publishedAt } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );
		const field = screen.getByDisplayValue( tsToLocalInput( publishedAt ) );
		fireEvent.change( field, { target: { value: '' } } );

		expect( onChange ).toHaveBeenLastCalledWith( { cohort_start: publishedAt } );

		fireEvent.blur( field );

		expect( onChange ).toHaveBeenLastCalledWith( { cohort_start: publishedAt } );
		expect( screen.getByDisplayValue( tsToLocalInput( publishedAt ) ) ).toBeInTheDocument();
	} );
} );
