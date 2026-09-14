/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CollapsibleGroup from './index';

const renderItems = ( count, props = {} ) =>
	render(
		<CollapsibleGroup { ...props }>
			{ Array.from( { length: count }, ( _, i ) => (
				<CollapsibleGroup.Item key={ i } title={ `Panel ${ i }` }>
					content
				</CollapsibleGroup.Item>
			) ) }
		</CollapsibleGroup>
	);

const dividers = container => container.querySelectorAll( '.newspack-divider' );
const items = container => container.querySelectorAll( '.newspack-collapsible-group__item' );

describe( 'CollapsibleGroup dividers', () => {
	it( 'renders no divider for a single item', () => {
		const { container } = renderItems( 1 );
		expect( items( container ) ).toHaveLength( 1 );
		expect( dividers( container ) ).toHaveLength( 0 );
	} );

	it( 'renders a divider between items but not after the last', () => {
		const { container } = renderItems( 3 );
		expect( items( container ) ).toHaveLength( 3 );
		expect( dividers( container ) ).toHaveLength( 2 );
		expect( container.querySelector( '.newspack-collapsible-group' ).lastElementChild ).not.toHaveClass( 'newspack-divider' );
	} );

	it( 'counts only rendered items when placing dividers', () => {
		const { container } = render(
			<CollapsibleGroup>
				<CollapsibleGroup.Item title="Panel 0">content</CollapsibleGroup.Item>
				<CollapsibleGroup.Item title="Panel 1">content</CollapsibleGroup.Item>
				{ false }
				{ 'trailing text' }
			</CollapsibleGroup>
		);

		expect( items( container ) ).toHaveLength( 2 );
		expect( dividers( container ) ).toHaveLength( 1 );
		expect( container.querySelector( '.newspack-collapsible-group' ).lastElementChild ).not.toHaveClass( 'newspack-divider' );
	} );

	it( 'renders tertiary dividers', () => {
		const { container } = renderItems( 2 );
		expect( dividers( container )[ 0 ] ).toHaveClass( 'newspack-divider--variant-tertiary' );
	} );
} );

describe( 'CollapsibleGroup titleLevel', () => {
	it( 'renders item titles as h2 by default', () => {
		renderItems( 2 );
		expect( screen.getAllByRole( 'heading', { level: 2 } ) ).toHaveLength( 2 );
	} );

	it( 'renders every item title at the level given', () => {
		renderItems( 2, { titleLevel: 3 } );
		expect( screen.getAllByRole( 'heading', { level: 3 } ) ).toHaveLength( 2 );
		expect( screen.queryByRole( 'heading', { level: 2 } ) ).not.toBeInTheDocument();
	} );

	it( 'clamps a level above the heading range', () => {
		renderItems( 2, { titleLevel: 7 } );
		expect( screen.getAllByRole( 'heading', { level: 6 } ) ).toHaveLength( 2 );
	} );

	it( 'clamps a level below the heading range', () => {
		renderItems( 1, { titleLevel: 0 } );
		expect( screen.getByRole( 'heading', { level: 1 } ) ).toBeInTheDocument();
	} );

	it( 'inherits the level in a nested group', () => {
		render(
			<CollapsibleGroup titleLevel={ 4 }>
				<CollapsibleGroup.Item title="Outer" defaultOpen>
					<CollapsibleGroup>
						<CollapsibleGroup.Item title="Inner">content</CollapsibleGroup.Item>
					</CollapsibleGroup>
				</CollapsibleGroup.Item>
			</CollapsibleGroup>
		);
		expect( screen.getByRole( 'heading', { level: 4, name: 'Inner' } ) ).toBeInTheDocument();
	} );

	it( 'inherits the level in a nested group when the title is hidden', () => {
		render(
			<CollapsibleGroup titleLevel={ 4 } hideSingleTitle>
				<CollapsibleGroup.Item title="Outer">
					<CollapsibleGroup>
						<CollapsibleGroup.Item title="Inner">content</CollapsibleGroup.Item>
					</CollapsibleGroup>
				</CollapsibleGroup.Item>
			</CollapsibleGroup>
		);
		expect( screen.getByRole( 'heading', { level: 4, name: 'Inner' } ) ).toBeInTheDocument();
	} );
} );

describe( 'CollapsibleGroup hideSingleTitle', () => {
	it( 'keeps the title on a lone item by default', () => {
		renderItems( 1 );
		expect( screen.getByRole( 'button', { name: 'Panel 0' } ) ).toBeInTheDocument();
	} );

	it( 'drops the title and opens a lone item when set', () => {
		renderItems( 1, { hideSingleTitle: true } );
		expect( screen.queryByRole( 'button', { name: 'Panel 0' } ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'content' ) ).toBeVisible();
	} );

	it( 'leaves titles alone when there is more than one item', () => {
		renderItems( 2, { hideSingleTitle: true } );
		expect( screen.getByRole( 'button', { name: 'Panel 0' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Panel 1' } ) ).toBeInTheDocument();
	} );
} );

describe( 'CollapsibleGroup item state', () => {
	it( 'expands only the item marked defaultOpen', () => {
		render(
			<CollapsibleGroup>
				<CollapsibleGroup.Item title="Open" defaultOpen>
					open content
				</CollapsibleGroup.Item>
				<CollapsibleGroup.Item title="Closed">closed content</CollapsibleGroup.Item>
			</CollapsibleGroup>
		);
		expect( screen.getByRole( 'button', { name: 'Open' } ) ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( screen.getByRole( 'button', { name: 'Closed' } ) ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'leaves a collapsed panel reachable by find-in-page', () => {
		const { container } = renderItems( 1 );
		expect( container.querySelector( '.newspack-collapsible-group__panel' ) ).toHaveAttribute( 'hidden', 'until-found' );
		expect( screen.getByText( 'content' ) ).toBeInTheDocument();
	} );

	it( 'opens an item when its trigger is clicked', () => {
		const { container } = renderItems( 1 );
		const trigger = screen.getByRole( 'button', { name: 'Panel 0' } );

		fireEvent.click( trigger );

		expect( trigger ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( container.querySelector( '.newspack-collapsible-group__panel' ) ).not.toHaveAttribute( 'hidden' );
	} );

	it( 'closes an open item when its trigger is clicked', () => {
		const { container } = renderItems( 1 );
		const trigger = screen.getByRole( 'button', { name: 'Panel 0' } );

		fireEvent.click( trigger );
		fireEvent.click( trigger );

		expect( trigger ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( container.querySelector( '.newspack-collapsible-group__panel' ) ).toHaveAttribute( 'hidden', 'until-found' );
	} );
} );
