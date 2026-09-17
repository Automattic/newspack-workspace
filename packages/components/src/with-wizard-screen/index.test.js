/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';
import { HashRouter } from 'react-router-dom';

/**
 * Internal dependencies.
 */
import withWizardScreen from './';

global.newspack_aux_data = { is_debug_mode: false };

describe( 'withWizardScreen', () => {
	it( 'falls back to a single headerText breadcrumb when no breadcrumbItems are provided', () => {
		const WrappedComponent = () => <div>Body content</div>;
		const ScreenWithHeader = withWizardScreen( WrappedComponent );

		render(
			<HashRouter>
				<ScreenWithHeader headerText="Brands" />
			</HashRouter>
		);

		expect( screen.getByRole( 'heading', { level: 1 } ) ).toHaveTextContent( 'Brands' );
	} );

	it( 'renders without a Router when no tabbed navigation is provided', () => {
		const WrappedComponent = () => <div>Body content</div>;
		const ScreenWithHeader = withWizardScreen( WrappedComponent );

		render( <ScreenWithHeader headerText="Brands" /> );

		expect( screen.getByRole( 'heading', { level: 1 } ) ).toHaveTextContent( 'Brands' );
	} );
} );
