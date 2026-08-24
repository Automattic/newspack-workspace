/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import withWizard from './';
import { GlobalNoticeFill } from '../global-notices';

// withWizard renders the Footer whenever it is not loading, which it is not
// without required plugins.
jest.mock( '../footer', () => () => null );

describe( 'withWizard', () => {
	it( 'renders a fill from the wrapped component in the notice region', () => {
		const Wrapped = withWizard(
			forwardRef( () => (
				<GlobalNoticeFill>
					<span>Wrapped fill</span>
				</GlobalNoticeFill>
			) )
		);
		render( <Wrapped /> );
		expect( screen.getByText( 'Wrapped fill' ) ).toBeInTheDocument();
	} );
} );
