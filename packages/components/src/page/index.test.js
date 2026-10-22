/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';
import { HashRouter } from 'react-router-dom';

/**
 * WordPress dependencies.
 */
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
