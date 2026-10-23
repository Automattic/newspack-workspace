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
 * The sticky header stacks above the page content. A popover opened from the
 * content renders into a slot of its own, stacked under the header, so it
 * scrolls under the header instead of over it. A popover opened from the header
 * keeps the default slot, where it stays above the header it belongs to.
 */
describe( 'Page popovers', () => {
	const renderWithPopovers = () =>
		renderInRouter(
			<SlotFillProvider>
				<Page breadcrumbItems={ [ { label: 'Subscribers' } ] } actions={ <Popover>Header popover</Popover> }>
					<Popover>Content popover</Popover>
				</Page>
			</SlotFillProvider>
		);

	const contentSlot = () => document.querySelector( '.newspack-page__content-popovers' );
	const contentSlotHolding = text => screen.getByText( text ).closest( '.newspack-page__content-popovers' );

	it( 'renders popovers opened from the content into the content slot', () => {
		renderWithPopovers();

		expect( contentSlotHolding( 'Content popover' ) ).not.toBeNull();
	} );

	it( 'keeps popovers opened from the header out of the content slot', () => {
		renderWithPopovers();

		expect( contentSlotHolding( 'Header popover' ) ).toBeNull();
	} );

	// Inside the page, an ancestor that clips (the wizard root does) bounds the
	// popovers' placement, so a short page squeezes them and flips them upward.
	it( 'renders the content slot at the top level of the document', () => {
		renderWithPopovers();

		expect( contentSlot().parentElement ).toBe( document.body );
	} );

	// A header-only page has no content to open popovers from, and often no
	// SlotFillProvider either, where registering a slot only logs a warning.
	it( 'renders no content slot when the page has no content', () => {
		renderInRouter( <Page breadcrumbItems={ [ { label: 'Newsletters' } ] } /> );

		expect( contentSlot() ).toBeNull();
	} );
} );
