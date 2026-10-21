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
		{ siteAutoSignup: true, label: 'Use site setting (on)' },
		{ siteAutoSignup: false, label: 'Use site setting (off)' },
		// Before the wizard data loads, no value is named rather than a guessed one.
		{ siteAutoSignup: undefined, label: 'Use site setting' },
	] )( 'labels the default choice "$label" when the site setting is $siteAutoSignup', ( { siteAutoSignup, label } ) => {
		render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup={ siteAutoSignup } onChange={ jest.fn() } /> );

		// A name given as a string must match in full, so this also rules out any "(on)" or "(off)" suffix.
		expect( screen.getByRole( 'option', { name: label } ) ).toBeInTheDocument();
	} );

	it( 'ties the card explanation to the auto-signup select', () => {
		render( <NewsletterSettings autoSignup="default" hideLists={ false } siteAutoSignup onChange={ jest.fn() } /> );

		const group = screen.getByRole( 'group', { name: 'Auto-signup' } );

		expect( group ).toHaveAccessibleDescription(
			'Add readers to these lists when they gain access. Readers who leave a list aren’t added back when they renew.'
		);
		expect( group ).toContainElement( screen.getByRole( 'combobox', { name: 'Auto-signup' } ) );
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

	it( 'reports showing the lists again', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="default" hideLists siteAutoSignup onChange={ onChange } /> );

		const toggle = screen.getByRole( 'checkbox', { name: 'Hide from readers' } );
		expect( toggle ).toBeChecked();
		fireEvent.click( toggle );

		expect( onChange ).toHaveBeenCalledWith( { hide_lists: false } );
	} );
} );
