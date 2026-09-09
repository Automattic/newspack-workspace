/**
 * The cohort-gate datetime condition: the default it arms on a new rule, and the
 * date it carries into Custom on an edit.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { getSettings, gmdateI18n } from '@wordpress/date';

/**
 * Internal dependencies
 */
import Conditions from './conditions';
import { tsToLocalInput } from './datetime';

jest.mock( '../../../../../packages/components/src', () => ( { AutocompleteTokenField: () => null } ) );

const LABEL = 'Subscriptions started on or after';
const VOCAB = [ { id: 'cohort_start', label: LABEL, field_type: 'datetime' } ];

const lastValue = ( onChange, prev = {} ) => {
	const arg = onChange.mock.calls[ onChange.mock.calls.length - 1 ][ 0 ];
	return 'function' === typeof arg ? arg( prev ) : arg;
};

// Derived rather than hard-coded, which would depend on the suite's timezone.
const customToggle = ts => {
	const { formats } = getSettings();
	const display = gmdateI18n( `${ formats.date } ${ formats.time }`, `${ tsToLocalInput( ts ) }Z` );
	return `${ LABEL }: ${ display }`;
};

const ANY_CUSTOM_TOGGLE = new RegExp( `^${ LABEL }: .` );

describe( 'the cohort-gate datetime condition', () => {
	it( 'arms a new rule with the publish-date default', () => {
		const onChange = jest.fn();
		render( <Conditions vocab={ VOCAB } value={ {} } publishedAt={ null } isNew onChange={ onChange } path="custom" /> );

		expect( screen.getByLabelText( LABEL ) ).toHaveValue( 'publish' );
		expect( onChange ).toHaveBeenCalledTimes( 1 );
		const armed = onChange.mock.calls[ 0 ][ 0 ]( {} ).cohort_start;
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

		expect( screen.getByRole( 'button', { name: customToggle( publishedAt ) } ) ).toBeInTheDocument();
		expect( lastValue( onChange ) ).toEqual( { cohort_start: publishedAt } );
	} );

	it( 'restores the date in force after a detour through Anytime', () => {
		const publishedAt = 1750000000;
		const onChange = jest.fn();
		const props = { vocab: VOCAB, publishedAt, isNew: false, onChange, path: 'custom' };
		const { rerender } = render( <Conditions { ...props } value={ { cohort_start: publishedAt } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'none' } } );
		expect( lastValue( onChange ) ).toEqual( { cohort_start: null } );
		rerender( <Conditions { ...props } value={ { cohort_start: null } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );

		expect( lastValue( onChange ) ).toEqual( { cohort_start: publishedAt } );
		expect( screen.getByRole( 'button', { name: customToggle( publishedAt ) } ) ).toBeInTheDocument();
	} );

	it( 'carries the armed default into Custom on a new rule', () => {
		const onChange = jest.fn();
		const props = { vocab: VOCAB, publishedAt: null, isNew: true, onChange, path: 'custom' };
		const { rerender } = render( <Conditions { ...props } value={ {} } /> );

		// The parent now holds the timestamp the mount effect armed, so feed it back.
		const armed = onChange.mock.calls[ 0 ][ 0 ]( {} ).cohort_start;
		rerender( <Conditions { ...props } value={ { cohort_start: armed } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );

		expect( lastValue( onChange ) ).toEqual( { cohort_start: armed } );
		expect( screen.getByRole( 'button', { name: customToggle( armed ) } ) ).toBeInTheDocument();
	} );

	it( 'drops the selector to Anytime when the date is cleared', () => {
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

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );
		fireEvent.click( screen.getByRole( 'button', { name: customToggle( publishedAt ) } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Clear' } ) );

		expect( lastValue( onChange ) ).toEqual( { cohort_start: null } );
		expect( screen.getByLabelText( LABEL ) ).toHaveValue( 'none' );
		expect( screen.queryByRole( 'button', { name: ANY_CUSTOM_TOGGLE } ) ).not.toBeInTheDocument();
		// Clearing tears down the toggle the popover would have returned focus to.
		expect( screen.getByLabelText( LABEL ) ).toHaveFocus();
	} );

	it( 'seeds a date when Custom is chosen on a rule with no gate', () => {
		const onChange = jest.fn();
		render(
			<Conditions vocab={ VOCAB } value={ { cohort_start: null } } publishedAt={ null } isNew={ false } onChange={ onChange } path="custom" />
		);

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );

		const stored = lastValue( onChange ).cohort_start;
		expect( stored ).not.toBeNull();
		expect( Math.abs( stored - Math.floor( Date.now() / 1000 ) ) ).toBeLessThan( 60 );
		expect( screen.getByRole( 'button', { name: ANY_CUSTOM_TOGGLE } ) ).toBeInTheDocument();
	} );

	it( 'sets the custom date through the shared picker', () => {
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

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );
		fireEvent.click( screen.getByRole( 'button', { name: customToggle( publishedAt ) } ) );

		expect( screen.getByRole( 'button', { name: 'Clear' } ) ).toBeInTheDocument();
	} );

	// The engine reads an absent gate as "everyone qualifies", so Custom must never be
	// selectable without a date behind it — including on a new rule that detoured through
	// Anytime, where neither the remembered nor the stored value survives.
	it( 'still carries a date into Custom on a new rule that detoured through Anytime', () => {
		const onChange = jest.fn();
		const props = { vocab: VOCAB, publishedAt: null, isNew: true, onChange, path: 'custom' };
		const { rerender } = render( <Conditions { ...props } value={ {} } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'none' } } );
		expect( lastValue( onChange ) ).toEqual( { cohort_start: null } );
		rerender( <Conditions { ...props } value={ { cohort_start: null } } /> );

		fireEvent.change( screen.getByLabelText( LABEL ), { target: { value: 'custom' } } );

		const stored = lastValue( onChange ).cohort_start;
		expect( stored ).not.toBeNull();
		expect( screen.getByRole( 'button', { name: customToggle( stored ) } ) ).toBeInTheDocument();
	} );
} );
