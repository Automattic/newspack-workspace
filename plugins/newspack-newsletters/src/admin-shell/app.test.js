import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import App from './app';

const NoopScreen = () => <div data-testid="noop-screen">noop</div>;

describe( 'admin-shell App chrome', () => {
	let originalGlobal;
	let originalWasDefined;

	beforeEach( () => {
		originalWasDefined = Object.prototype.hasOwnProperty.call( window, 'newspackNewslettersAdmin' );
		originalGlobal = window.newspackNewslettersAdmin;
	} );

	afterEach( () => {
		if ( originalWasDefined ) {
			window.newspackNewslettersAdmin = originalGlobal;
		} else {
			delete window.newspackNewslettersAdmin;
		}
	} );

	it( 'mounts the provided screen component', () => {
		render( <App label="Layouts" Screen={ NoopScreen } /> );
		expect( screen.getByTestId( 'noop-screen' ) ).toBeInTheDocument();
	} );

	it( 'renders the chrome inside a main landmark region', () => {
		render( <App label="Settings" Screen={ NoopScreen } /> );
		expect( screen.getByRole( 'main' ) ).toBeInTheDocument();
	} );

	it( 'renders an h1 with the page label', () => {
		render( <App label="Newsletters" Screen={ NoopScreen } /> );
		expect( screen.getByRole( 'heading', { level: 1, name: 'Newsletters' } ) ).toBeInTheDocument();
	} );

	it( 'hides the h1 visually in bundled mode (newspack-plugin owns the breadcrumb)', () => {
		window.newspackNewslettersAdmin = { bundledMode: true };
		const { container } = render( <App label="Newsletters" Screen={ NoopScreen } /> );
		expect( container.querySelector( 'h1.screen-reader-text' ) ).not.toBeNull();
	} );

	it( 'shows the h1 as a visible page title in standalone mode', () => {
		window.newspackNewslettersAdmin = { bundledMode: false };
		const { container } = render( <App label="Settings" Screen={ NoopScreen } /> );
		expect( container.querySelector( 'h1.newspack-newsletters-admin__title' ) ).not.toBeNull();
	} );
} );
