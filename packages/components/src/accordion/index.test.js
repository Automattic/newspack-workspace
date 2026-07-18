/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Accordion, { AccordionPanel } from './index';

const renderPanels = ( count, props = {} ) =>
	render(
		<Accordion { ...props }>
			{ Array.from( { length: count }, ( _, i ) => (
				<AccordionPanel key={ i } title={ `Panel ${ i }` }>
					content
				</AccordionPanel>
			) ) }
		</Accordion>
	);

const dividers = container => container.querySelectorAll( '.newspack-divider' );

describe( 'Accordion dividers', () => {
	it( 'renders no divider for a single panel', () => {
		const { container } = renderPanels( 1 );
		expect( container.querySelectorAll( '.components-panel__body' ) ).toHaveLength( 1 );
		expect( dividers( container ) ).toHaveLength( 0 );
	} );

	it( 'renders a divider between panels but not after the last', () => {
		const { container } = renderPanels( 3 );
		expect( container.querySelectorAll( '.components-panel__body' ) ).toHaveLength( 3 );
		expect( dividers( container ) ).toHaveLength( 2 );
		expect( container.querySelector( '.newspack-accordion' ).lastElementChild ).not.toHaveClass( 'newspack-divider' );
	} );

	it( 'renders secondary dividers', () => {
		const { container } = renderPanels( 2 );
		expect( dividers( container )[ 0 ] ).toHaveClass( 'newspack-divider--variant-secondary' );
	} );
} );

describe( 'Accordion hideSingleTitle', () => {
	it( 'keeps the title on a lone panel by default', () => {
		renderPanels( 1 );
		expect( screen.getByRole( 'button', { name: 'Panel 0' } ) ).toBeInTheDocument();
	} );

	it( 'drops the title and opens a lone panel when set', () => {
		const { container } = renderPanels( 1, { hideSingleTitle: true } );
		expect( screen.queryByRole( 'button', { name: 'Panel 0' } ) ).not.toBeInTheDocument();
		expect( container.querySelector( '.components-panel__body' ) ).toHaveClass( 'is-opened' );
		expect( screen.getByText( 'content' ) ).toBeInTheDocument();
	} );

	it( 'leaves titles alone when there is more than one panel', () => {
		renderPanels( 2, { hideSingleTitle: true } );
		expect( screen.getByRole( 'button', { name: 'Panel 0' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Panel 1' } ) ).toBeInTheDocument();
	} );
} );
