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

	it( 'reports showing the lists again', () => {
		const onChange = jest.fn();
		render( <NewsletterSettings autoSignup="default" hideLists siteAutoSignup onChange={ onChange } /> );

		const toggle = screen.getByRole( 'checkbox', { name: 'Hide from readers' } );
		expect( toggle ).toBeChecked();
		fireEvent.click( toggle );

		expect( onChange ).toHaveBeenCalledWith( { hide_lists: false } );
	} );
} );
