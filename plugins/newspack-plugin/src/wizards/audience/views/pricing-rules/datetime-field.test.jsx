/**
 * The schedule's date-and-time popover field.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import DateTimeField from './datetime-field';

const defaultSettings = getSettings();

const setup = ( props = {} ) => {
	const onChange = jest.fn();
	render(
		<DateTimeField
			id="starts"
			label="Starts"
			help="Times are in your local timezone."
			value="2026-08-12T09:00"
			placeholder="Active immediately"
			onChange={ onChange }
			{ ...props }
		/>
	);
	return onChange;
};

describe( 'DateTimeField', () => {
	afterEach( () => setSettings( defaultSettings ) );

	it( 'names the toggle with both the label and the resolved value', () => {
		setup();
		expect( screen.getByRole( 'button', { name: 'Starts: August 12, 2026 9:00 am' } ) ).toBeInTheDocument();
	} );

	// A site offset west of UTC would land a day earlier if this resolved against it.
	it( 'reads the value as a wall clock, not against the site timezone', () => {
		setSettings( { ...defaultSettings, timezone: { offset: -5, string: '', abbr: 'UTC-5' } } );
		setup( { value: '2026-08-12T00:00' } );
		expect( screen.getByRole( 'button', { name: 'Starts: August 12, 2026 12:00 am' } ) ).toBeInTheDocument();
	} );

	it( 'shows the placeholder when unset', () => {
		setup( { value: '' } );
		expect( screen.getByRole( 'button', { name: 'Starts: Active immediately' } ) ).toBeInTheDocument();
	} );

	it( 'opens the picker and emits a local wall-clock string', () => {
		const onChange = setup();
		fireEvent.click( screen.getByRole( 'button', { name: /^Starts:/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'August 20, 2026' } ) );
		expect( onChange ).toHaveBeenCalledWith( '2026-08-20T09:00' );
	} );

	it( 'emits an empty value from Clear and closes the popover', () => {
		const onChange = setup();
		fireEvent.click( screen.getByRole( 'button', { name: /^Starts:/ } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Clear' } ) );
		expect( onChange ).toHaveBeenCalledWith( '' );
		expect( screen.queryByRole( 'button', { name: 'Clear' } ) ).not.toBeInTheDocument();
	} );

	it( 'offers no Clear when there is nothing to clear', () => {
		setup( { value: '' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Starts: Active immediately' } ) );
		expect( screen.queryByRole( 'button', { name: 'Clear' } ) ).not.toBeInTheDocument();
	} );

	it( 'renders the label visually without pointing it at the toggle', () => {
		setup();
		expect( screen.getByText( 'Starts' ) ).toBeInTheDocument();
		expect( document.querySelector( 'label[for="starts"]' ) ).toBeNull();
	} );

	it( 'omits the label entirely when it is hidden from vision', () => {
		setup( { hideLabelFromVision: true } );
		expect( screen.queryByText( 'Starts' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /^Starts:/ } ) ).toBeInTheDocument();
	} );

	it( 'reads an escaped a in the time format as 24-hour', () => {
		setSettings( { ...defaultSettings, formats: { ...defaultSettings.formats, time: 'H:i \\a\\t' } } );
		setup( { value: '2026-08-12T13:00' } );
		expect( screen.getByRole( 'button', { name: /13:00/ } ) ).toBeInTheDocument();
	} );

	it( 'points the toggle at its own help text', () => {
		setup();
		expect( screen.getByRole( 'button', { name: /^Starts:/ } ) ).toHaveAttribute( 'aria-describedby', 'starts__help' );
	} );

	it( 'points the toggle at a shared description outside the control', () => {
		setup( { help: undefined, describedBy: 'schedule__help' } );
		expect( screen.getByRole( 'button', { name: /^Starts:/ } ) ).toHaveAttribute( 'aria-describedby', 'schedule__help' );
	} );

	it( 'leaves the toggle undescribed when there is no description', () => {
		setup( { help: undefined } );
		expect( screen.getByRole( 'button', { name: /^Starts:/ } ) ).not.toHaveAttribute( 'aria-describedby' );
	} );
} );
