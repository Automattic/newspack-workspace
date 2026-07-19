/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';
import { HashRouter } from 'react-router-dom';

/**
 * Internal dependencies.
 */
import Page from './';

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
