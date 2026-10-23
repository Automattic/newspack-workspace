/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';
import { HashRouter } from 'react-router-dom';

/**
 * WordPress dependencies.
 */
import { Popover, SlotFillProvider } from '@wordpress/components';
import { useViewportMatch } from '@wordpress/compose';

/**
 * Internal dependencies.
 */
import Page from './';

jest.mock( '@wordpress/compose', () => ( {
	...jest.requireActual( '@wordpress/compose' ),
	useViewportMatch: jest.fn( () => true ),
} ) );

afterEach( () => {
	useViewportMatch.mockReturnValue( true );
} );

const renderInRouter = ui => render( <HashRouter>{ ui }</HashRouter> );

describe( 'Page', () => {
	it( 'renders the breadcrumb current item as h1 and the content', () => {
		renderInRouter(
			<Page breadcrumbItems={ [ { label: 'Audience', url: '/' }, { label: 'Access control' } ] }>
				<div>Body content</div>
			</Page>
		);
		expect( screen.getByRole( 'heading', { level: 1 } ) ).toHaveTextContent( 'Access control' );
		expect( screen.getByText( 'Body content' ) ).toBeInTheDocument();
	} );

	it( 'renders actions and subtitle', () => {
		renderInRouter(
			<Page breadcrumbItems={ [ { label: 'Audience' } ] } subTitle="Manage readers" actions={ <button>Add new</button> }>
				<div />
			</Page>
		);
		expect( screen.getByRole( 'button', { name: 'Add new' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Manage readers' ) ).toBeInTheDocument();
	} );

	it( 'names the page region after the current breadcrumb item', () => {
		renderInRouter(
			<Page breadcrumbItems={ [ { label: 'Audience', url: '/' }, { label: 'Access control' } ] }>
				<div />
			</Page>
		);
		expect( screen.getByRole( 'region', { name: 'Access control' } ) ).toBeInTheDocument();
	} );
} );

describe( 'Page subtitle', () => {
	it( 'stays readable by screen readers but is visually hidden below 782px', () => {
		useViewportMatch.mockReturnValue( false );
		renderInRouter(
			<Page breadcrumbItems={ [ { label: 'Integrations' } ] } subTitle="Manage how Newspack syncs reader data.">
				<div />
			</Page>
		);
		expect( screen.getByText( 'Manage how Newspack syncs reader data.' ) ).toHaveClass( 'screen-reader-text' );
	} );

	it( 'shows from 782px', () => {
		useViewportMatch.mockReturnValue( true );
		renderInRouter(
			<Page breadcrumbItems={ [ { label: 'Integrations' } ] } subTitle="Manage how Newspack syncs reader data.">
				<div />
			</Page>
		);
		expect( screen.getByText( 'Manage how Newspack syncs reader data.' ) ).not.toHaveClass( 'screen-reader-text' );
	} );
} );

/**
 * The sticky header stacks above the page content, so a popover opened from the
 * content has to render inside the page to scroll under the header instead of
 * over it. A popover opened from the header still renders at the document level,
 * where it stays above the header it belongs to.
 */
describe( 'Page popovers', () => {
	const renderWithPopovers = () =>
		render(
			<SlotFillProvider>
				<HashRouter>
					<Page breadcrumbItems={ [ { label: 'Subscribers' } ] } actions={ <Popover>Header popover</Popover> }>
						<Popover>Content popover</Popover>
					</Page>
				</HashRouter>
			</SlotFillProvider>
		);

	const contentSlotHolding = text => screen.getByText( text ).closest( '.newspack-page__content-popovers' );

	it( 'renders popovers opened from the content inside the page', () => {
		renderWithPopovers();

		expect( contentSlotHolding( 'Content popover' ) ).not.toBeNull();
	} );

	it( 'keeps popovers opened from the header out of the content slot', () => {
		renderWithPopovers();

		expect( contentSlotHolding( 'Header popover' ) ).toBeNull();
	} );
} );
