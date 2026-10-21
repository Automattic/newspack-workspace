/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import NewsletterSettings from './newsletter-settings';

describe( 'NewsletterSettings', () => {
	it( 'names the site setting as On when the site has auto-signup on', () => {
		render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'option', { name: 'Use site setting (on)' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'option', { name: 'Use site setting (off)' } ) ).not.toBeInTheDocument();
	} );

	it( 'names the site setting as Off when the site has auto-signup off', () => {
		render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup={ false } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'option', { name: 'Use site setting (off)' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'option', { name: 'Use site setting (on)' } ) ).not.toBeInTheDocument();
	} );

	it( 'reports the chosen auto-signup mode', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup onChange={ onChange } /> );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Auto-signup' } ), { target: { value: 'off' } } );

		expect( onChange ).toHaveBeenCalledWith( { auto_signup: 'off' } );
	} );

	it( 'reflects the stored auto-signup mode', () => {
		render( <NewsletterSettings autoSignup="on" hideLists={ false } siteAutoSignup={ false } onChange={ jest.fn() } /> );

		expect( screen.getByRole( 'combobox', { name: 'Auto-signup' } ) ).toHaveValue( 'on' );
	} );

	it( 'keeps the auto-signup card neutral whatever mode is chosen', () => {
		const { rerender } = render( <NewsletterSettings autoSignup="off" hideLists={ false } siteAutoSignup onChange={ jest.fn() } /> );
		const getCard = () => screen.getByRole( 'combobox', { name: 'Auto-signup' } ).closest( '.newspack-card--core' );

		// The select is always shown, so the card must not borrow the toggled-on look.
		expect( getCard() ).not.toHaveClass( 'newspack-card--core__is-active' );

		rerender( <NewsletterSettings autoSignup="on" hideLists={ false } siteAutoSignup onChange={ jest.fn() } /> );
		expect( getCard() ).not.toHaveClass( 'newspack-card--core__is-active' );
	} );

	it( 'marks the hide card active only while the lists are hidden', () => {
		const { rerender } = render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup onChange={ jest.fn() } /> );
		const getCard = () => screen.getByRole( 'checkbox', { name: 'Hide from readers' } ).closest( '.newspack-card--core' );

		expect( getCard() ).not.toHaveClass( 'newspack-card--core__is-active' );

		rerender( <NewsletterSettings autoSignup="default" hideLists siteAutoSignup onChange={ jest.fn() } /> );
		expect( getCard() ).toHaveClass( 'newspack-card--core__is-active' );
	} );

	it( 'reports hiding the lists from readers', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup onChange={ onChange } /> );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Hide from readers' } ) );

		expect( onChange ).toHaveBeenCalledWith( { hide_lists: true } );
	} );

	it( 'reports showing the lists again', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="default" hideLists siteAutoSignup onChange={ onChange } /> );

		const toggle = screen.getByRole( 'checkbox', { name: 'Hide from readers' } );
		expect( toggle ).toBeChecked();
		fireEvent.click( toggle );

		expect( onChange ).toHaveBeenCalledWith( { hide_lists: false } );
	} );
} );
