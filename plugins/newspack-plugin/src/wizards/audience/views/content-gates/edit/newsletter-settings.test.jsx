/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import NewsletterSettings from './newsletter-settings';

describe( 'NewsletterSettings', () => {
	it.each( [
		{ siteAutoSignup: true, label: 'Default (on)' },
		{ siteAutoSignup: false, label: 'Default (off)' },
		// Before the wizard data loads, no value is named rather than a guessed one.
		{ siteAutoSignup: undefined, label: 'Default' },
	] )( 'names the site value in the default choice: "$label"', ( { siteAutoSignup, label } ) => {
		render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup={ siteAutoSignup } onChange={ jest.fn() } /> );

		// A name given as a string must match in full, so this also rules out a stray suffix.
		expect( screen.getByRole( 'radio', { name: label } ) ).toBeChecked();
	} );

	it( 'selects the mode the gate stores', () => {
		render( <NewsletterSettings autoSignup="on" hideLists={ false } siteAutoSignup onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'radio', { name: 'On' } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: 'Default (on)' } ) ).not.toBeChecked();
	} );

	it( 'leaves hiding available before the wizard data loads', () => {
		render( <NewsletterSettings autoSignup="default" hideLists={ false } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'checkbox', { name: 'Hide from readers' } ) ).toBeEnabled();
	} );

	it( 'reports showing the lists again', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="default" hideLists siteAutoSignup onChange={ onChange } /> );

		const toggle = screen.getByRole( 'checkbox', { name: 'Hide from readers' } );
		expect( toggle ).toBeChecked();
		fireEvent.click( toggle );

		expect( onChange ).toHaveBeenCalledWith( { hide_lists: false } );
	} );

	it.each( [
		{ autoSignup: 'off', siteAutoSignup: true },
		{ autoSignup: 'default', siteAutoSignup: false },
	] )( 'disables hiding when auto-signup resolves to off ($autoSignup, site $siteAutoSignup)', ( { autoSignup, siteAutoSignup } ) => {
		render( <NewsletterSettings autoSignup={ autoSignup } hideLists={ false } siteAutoSignup={ siteAutoSignup } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'checkbox', { name: 'Hide from readers' } ) ).toBeDisabled();
	} );

	it( 'shows lists a gate already hides with auto-signup off as hidden, and lets them be shown again', () => {
		const onChange = jest.fn();
		// Reachable by turning the site setting off while a gate on Default hides its lists.
		render( <NewsletterSettings autoSignup="default" hideLists siteAutoSignup={ false } onChange={ onChange } /> );

		const toggle = screen.getByRole( 'checkbox', { name: 'Hide from readers' } );
		expect( toggle ).toBeChecked();
		expect( toggle ).toBeEnabled();
		fireEvent.click( toggle );

		expect( onChange ).toHaveBeenCalledWith( { hide_lists: false } );
	} );

	it( 'shows the lists again when auto-signup is turned off', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="on" hideLists siteAutoSignup onChange={ onChange } /> );

		fireEvent.click( screen.getByRole( 'radio', { name: 'Off' } ) );

		expect( onChange ).toHaveBeenCalledWith( { auto_signup: 'off', hide_lists: false } );
	} );

	it( 'keeps the hiding choice when auto-signup stays on', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="on" hideLists siteAutoSignup onChange={ onChange } /> );

		fireEvent.click( screen.getByRole( 'radio', { name: 'Default (on)' } ) );

		expect( onChange ).toHaveBeenCalledWith( { auto_signup: 'default' } );
	} );
} );
