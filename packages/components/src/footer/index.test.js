/**
 * External dependencies.
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import Footer from './';

// Footer renders outside the wizard's HashRouter, so the confirm dialog's
// useHistory() has no Router above it. That is the case worth pinning.
describe( 'Footer', () => {
	const RESET_URL = 'https://example.test/wp-admin/admin.php?page=newspack-dashboard&newspack_reset=reset';
	let assigned;

	beforeEach( () => {
		assigned = [];
		delete window.location;
		window.location = {
			get href() {
				return 'https://example.test/wp-admin/admin.php?page=newspack-dashboard';
			},
			set href( value ) {
				assigned.push( value );
			},
		};
		window.newspack_urls = {
			support: 'https://help.newspack.com/',
			reset_url: RESET_URL,
			plugin_version: { label: 'Newspack 1.0.0' },
		};
	} );

	it( 'does not reset on the first click', async () => {
		render( <Footer /> );
		fireEvent.click( screen.getByRole( 'link', { name: 'Reset Newspack' } ) );
		expect( assigned ).toEqual( [] );
	} );

	it( 'asks before resetting', async () => {
		render( <Footer /> );
		fireEvent.click( screen.getByRole( 'link', { name: 'Reset Newspack' } ) );
		expect( await screen.findByText( /deletes every Newspack setting/ ) ).toBeInTheDocument();
	} );

	it( 'leaves the site alone when the reset is cancelled', async () => {
		render( <Footer /> );
		fireEvent.click( screen.getByRole( 'link', { name: 'Reset Newspack' } ) );
		// The modal's close icon also carries aria-label="Cancel"; this is the text button.
		const cancel = ( await screen.findAllByRole( 'button', { name: 'Cancel' } ) ).find( button => 'Cancel' === button.textContent );
		fireEvent.click( cancel );
		expect( assigned ).toEqual( [] );
	} );

	it( 'resets once confirmed', async () => {
		render( <Footer /> );
		fireEvent.click( screen.getByRole( 'link', { name: 'Reset Newspack' } ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Reset Newspack' } ) );
		expect( assigned ).toEqual( [ RESET_URL ] );
	} );

	it( 'does not guard the non-destructive links', async () => {
		window.newspack_urls.setup_wizard = 'https://example.test/wp-admin/admin.php?page=newspack-setup-wizard';
		render( <Footer /> );
		const link = screen.getByRole( 'link', { name: 'Setup Wizard' } );
		expect( link ).toHaveAttribute( 'href', window.newspack_urls.setup_wizard );
		fireEvent.click( link );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );
} );
