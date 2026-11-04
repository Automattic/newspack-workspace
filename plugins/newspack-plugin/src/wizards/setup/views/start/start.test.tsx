import { act, fireEvent, render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import Start from './index';
import type { SetupViewProps } from '../../steps';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const STARTER_CONTENT = '/newspack/v1/wizard/newspack-setup-wizard/starter-content/';

const INSTALL_STATE = '/newspack/v1/wizard/newspack-setup-wizard/install-state';

const mockApi = (
	init: () => Promise< unknown > = () => Promise.resolve( { status: 200, post_count: 2 } ),
	saveState: () => Promise< unknown > = () => Promise.resolve( {} )
) =>
	( apiFetch as unknown as jest.Mock ).mockImplementation( ( { path }: { path: string } ) => {
		if ( path === `${ STARTER_CONTENT }init` ) {
			return init();
		}
		return path === INSTALL_STATE ? saveState() : Promise.resolve( {} );
	} );

const spoken = () =>
	`${ document.getElementById( 'a11y-speak-polite' )?.textContent } ${ document.getElementById( 'a11y-speak-assertive' )?.textContent }`;

const renderStart = ( props: Partial< SetupViewProps > = {} ) => {
	const view: SetupViewProps = {
		initialCheck: { plugins: [], is_ssl: true },
		installState: { approach: null, complete: false },
		setInstallState: jest.fn(),
		goTo: jest.fn(),
		completeSetup: jest.fn(),
		...props,
	};
	render(
		<div className="setup-canvas">
			<Start { ...view } />
		</div>
	);
	return view;
};

const installButton = () => screen.getByRole( 'button', { name: 'Install' } );

const isDisabled = ( button: HTMLElement ) => button.getAttribute( 'aria-disabled' ) === 'true' || button.hasAttribute( 'disabled' );

const chooseMove = () => fireEvent.click( screen.getByRole( 'radio', { name: 'Move an existing WordPress site' } ) );

const typeUrl = ( value: string ) =>
	fireEvent.change( screen.getByRole( 'textbox', { name: /Address of your current site/ } ), { target: { value } } );

const advance = ( ms: number ) => act( () => jest.advanceTimersByTimeAsync( ms ) );

describe( 'Get started', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		mockApi();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'shows the URL field for a move and holds Install until an address is typed', () => {
		renderStart();
		expect( screen.queryByRole( 'textbox', { name: /Address of your current site/ } ) ).toBeNull();
		expect( screen.getByRole( 'checkbox', { name: /Add sample stories/ } ) ).toBeInTheDocument();
		expect( isDisabled( installButton() ) ).toBe( false );

		chooseMove();
		expect( screen.queryByRole( 'checkbox', { name: /Add sample stories/ } ) ).toBeNull();
		expect( screen.getByRole( 'textbox', { name: /Address of your current site/ } ) ).toBeInTheDocument();
		expect( isDisabled( installButton() ) ).toBe( true );

		typeUrl( 'https://example.test' );
		expect( isDisabled( installButton() ) ).toBe( false );
	} );

	it( 'says why on the URL field, and does not install, when the address is not a site address', async () => {
		const value = 'example';
		const view = renderStart();
		chooseMove();
		typeUrl( value );
		( apiFetch as unknown as jest.Mock ).mockClear();
		fireEvent.click( installButton() );
		await advance( 0 );

		const field = screen.getByRole( 'textbox', { name: /Address of your current site/ } );
		const message = 'Enter the full address of your current site, like https://example.com.';
		expect( field ).toHaveFocus();
		expect( field ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( field ).toHaveAccessibleDescription( expect.stringContaining( message ) );
		expect( spoken() ).not.toContain( message );
		expect( screen.queryByRole( 'heading', { name: 'Installing…' } ) ).toBeNull();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( view.setInstallState ).not.toHaveBeenCalled();

		typeUrl( 'https://example.test' );
		expect( screen.queryByText( message, { ignore: '.a11y-speak-region' } ) ).toBeNull();
	} );

	it( 'moves the choice with the arrow keys and keeps one tab stop', () => {
		renderStart();
		const [ generated, imported ] = screen.getAllByRole( 'radio' );
		expect( generated ).toHaveAttribute( 'aria-checked', 'true' );
		expect( generated ).toHaveAttribute( 'tabindex', '0' );
		expect( imported ).toHaveAttribute( 'tabindex', '-1' );

		fireEvent.keyDown( generated, { key: 'ArrowDown' } );
		expect( imported ).toHaveAttribute( 'aria-checked', 'true' );
		expect( imported ).toHaveFocus();
		expect( screen.getByRole( 'textbox', { name: /Address of your current site/ } ) ).toBeInTheDocument();
	} );

	it( 'moves the choice to the first and last option with Home and End', () => {
		renderStart();
		const [ generated, imported ] = screen.getAllByRole( 'radio' );
		fireEvent.keyDown( generated, { key: 'End' } );
		expect( imported ).toHaveAttribute( 'aria-checked', 'true' );
		expect( imported ).toHaveFocus();

		fireEvent.keyDown( imported, { key: 'Home' } );
		expect( generated ).toHaveAttribute( 'aria-checked', 'true' );
		expect( generated ).toHaveFocus();

		fireEvent.keyDown( generated, { key: 'End', ctrlKey: true } );
		expect( generated ).toHaveAttribute( 'aria-checked', 'true' );
	} );

	it( 'leaves browser shortcuts such as Alt+ArrowLeft alone', () => {
		renderStart();
		const [ generated ] = screen.getAllByRole( 'radio' );
		fireEvent.keyDown( generated, { key: 'ArrowLeft', altKey: true } );
		expect( generated ).toHaveAttribute( 'aria-checked', 'true' );
		fireEvent.keyDown( generated, { key: 'ArrowRight', metaKey: true } );
		expect( generated ).toHaveAttribute( 'aria-checked', 'true' );
	} );

	it( 'names each radio by its title and describes it with its description', () => {
		renderStart();
		const radio = screen.getByRole( 'radio', { name: 'Start a new site' } );
		expect( radio ).toHaveAccessibleDescription( 'Start fresh, with no content to bring over.' );
	} );

	it( 'keeps Install disabled on a site without HTTPS, and says why politely', async () => {
		renderStart( { initialCheck: { plugins: [], is_ssl: false } } );
		expect(
			screen.getByText( 'This site does not use HTTPS. Newspack can’t be installed.', { ignore: '.a11y-speak-region' } )
		).toBeInTheDocument();
		await advance( 0 );
		expect( document.getElementById( 'a11y-speak-polite' )?.textContent ).toContain( 'This site does not use HTTPS.' );
		expect( document.getElementById( 'a11y-speak-assertive' )?.textContent ?? '' ).not.toContain( 'HTTPS' );
		expect( isDisabled( installButton() ) ).toBe( true );
		expect( installButton() ).toHaveAccessibleDescription( expect.stringContaining( 'This site does not use HTTPS.' ) );
	} );

	it( 'counts down on the Continue button after a clean install and then moves on', async () => {
		const view = renderStart();
		fireEvent.click( installButton() );
		await advance( 0 );
		expect( screen.getByRole( 'heading', { name: 'Installing…' } ) ).toHaveFocus();
		expect( isDisabled( screen.getByRole( 'button', { name: 'Continue' } ) ) ).toBe( true );
		expect( screen.getByRole( 'progressbar', { name: 'Installation progress' } ) ).toBeInTheDocument();

		await advance( 3 * 1200 );
		expect( screen.getByRole( 'heading', { name: 'Your site is ready to set up' } ) ).toBeInTheDocument();
		expect( spoken() ).toContain( 'Continuing to Site details in 5 seconds. Press Tab to stay on this step. Your site is ready to set up' );
		expect( isDisabled( screen.getByRole( 'button', { name: 'Continue (5)' } ) ) ).toBe( false );

		await advance( 1000 );
		expect( screen.getByRole( 'button', { name: 'Continue (4)' } ) ).toBeInTheDocument();
		expect( view.goTo ).not.toHaveBeenCalled();
		expect( view.setInstallState ).not.toHaveBeenCalled();

		await advance( 4000 );
		expect( view.setInstallState ).toHaveBeenCalledWith( { approach: 'generated', complete: true } );
		expect( view.goTo ).toHaveBeenCalledTimes( 1 );
		expect( view.goTo ).toHaveBeenCalledWith( 'site-details' );
	} );

	it( 'reads each checklist row whole when its status changes', async () => {
		renderStart();
		fireEvent.click( installButton() );
		await advance( 0 );
		const rows = screen.getAllByRole( 'listitem' );
		expect( rows.length ).toBeGreaterThan( 0 );
		rows.forEach( row => expect( row ).toHaveAttribute( 'aria-atomic', 'true' ) );
	} );

	it.each( [
		[ 'a key is pressed', () => fireEvent.keyDown( document.body, { key: 'Tab' } ) ],
		[ 'the setup is clicked', () => fireEvent.click( screen.getByRole( 'list' ) ) ],
		[ 'a control takes focus', () => screen.getByRole( 'button', { name: /Continue/ } ).focus() ],
	] )( 'stops the countdown when %s, leaving Continue to move on', async ( _, interact ) => {
		const view = renderStart();
		fireEvent.click( installButton() );
		await advance( 3 * 1200 );
		expect( screen.getByRole( 'button', { name: 'Continue (5)' } ) ).toBeInTheDocument();

		act( () => interact() );
		expect( screen.getByRole( 'button', { name: 'Continue' } ) ).toBeInTheDocument();
		await advance( 6000 );
		expect( view.goTo ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await advance( 0 );
		expect( view.goTo ).toHaveBeenCalledWith( 'site-details' );
	} );

	it( 'keeps counting down when focus lands on the step heading', async () => {
		const view = renderStart();
		fireEvent.click( installButton() );
		await advance( 3 * 1200 );
		act( () => screen.getByRole( 'heading', { name: 'Your site is ready to set up' } ).focus() );
		await advance( 5000 );
		expect( view.goTo ).toHaveBeenCalledWith( 'site-details' );
	} );

	it( 'holds Continue and the countdown until the finished install is saved', async () => {
		mockApi( undefined, () => Promise.reject( { message: 'Nope' } ) );
		const view = renderStart();
		fireEvent.click( installButton() );
		await advance( 3 * 1200 );

		expect( screen.getByRole( 'heading', { name: 'Your site is ready to set up' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Couldn’t save the install.', { ignore: '.a11y-speak-region' } ) ).toBeInTheDocument();
		expect( isDisabled( screen.getByRole( 'button', { name: 'Continue' } ) ) ).toBe( true );

		await advance( 6000 );
		expect( view.goTo ).not.toHaveBeenCalled();

		mockApi();
		const tryAgain = screen.getByRole( 'button', { name: 'Try Again' } );
		tryAgain.focus();
		fireEvent.click( tryAgain );
		await advance( 0 );
		expect( screen.queryByText( 'Couldn’t save the install.', { ignore: '.a11y-speak-region' } ) ).toBeNull();
		expect( isDisabled( screen.getByRole( 'button', { name: 'Continue (5)' } ) ) ).toBe( false );
		expect( screen.getByRole( 'heading', { name: 'Your site is ready to set up' } ) ).toHaveFocus();
	} );

	it( 'keeps focus on the step when saving the install fails again', async () => {
		mockApi( undefined, () => Promise.reject( { message: 'Nope' } ) );
		renderStart();
		fireEvent.click( installButton() );
		await advance( 3 * 1200 );
		const tryAgain = screen.getByRole( 'button', { name: 'Try Again' } );
		tryAgain.focus();
		fireEvent.click( tryAgain );
		await advance( 0 );
		expect( screen.getByText( 'Couldn’t save the install.', { ignore: '.a11y-speak-region' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Your site is ready to set up' } ) ).toHaveFocus();
	} );

	it( 'announces a failed new-site start once focus has settled on the heading', async () => {
		const message = 'Could not start the install.';
		mockApi( () => Promise.reject( { code: 'newspack_starter_content', message } ) );
		renderStart();
		fireEvent.click( installButton() );
		await advance( 0 );

		expect( screen.getByRole( 'heading', { name: 'Welcome to Newspack' } ) ).toHaveFocus();
		expect( screen.getByText( message, { ignore: '.a11y-speak-region' } ) ).toBeInTheDocument();
		expect( spoken() ).not.toContain( message );

		await advance( 300 );
		expect( document.getElementById( 'a11y-speak-assertive' )?.textContent ).toContain( message );
	} );

	it( 'shows an inline error on the URL field when the move cannot start', async () => {
		const message = '"https://example.test/" does not appear to be a WordPress site.';
		mockApi( () => Promise.reject( { code: 'newspack_starter_content', message } ) );
		const view = renderStart();
		chooseMove();
		typeUrl( 'https://example.test' );
		fireEvent.click( installButton() );
		await advance( 0 );

		const field = screen.getByRole( 'textbox', { name: /Address of your current site/ } );
		expect( field ).toHaveValue( 'https://example.test' );
		expect( field ).toHaveFocus();
		expect( field ).toHaveAccessibleDescription( expect.stringContaining( message ) );
		expect( screen.getByText( message, { ignore: '.a11y-speak-region' } ) ).toBeInTheDocument();
		expect( spoken() ).not.toContain( message );
		expect( view.goTo ).not.toHaveBeenCalled();
		expect( view.setInstallState ).not.toHaveBeenCalled();

		typeUrl( 'https://example.test/news' );
		expect( screen.queryByText( message, { ignore: '.a11y-speak-region' } ) ).toBeNull();
	} );
} );
