/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SplitSection from '.';

const renderSection = ( headerProps = {}, headerChildren = null ) =>
	render(
		<SplitSection.Root data-testid="root">
			<SplitSection.Header title="Group Names" description="Choose how a group bought at checkout is named." { ...headerProps }>
				{ headerChildren }
			</SplitSection.Header>
			<SplitSection.Content data-testid="content">
				<input aria-label="Name" />
			</SplitSection.Content>
		</SplitSection.Root>
	);

describe( 'SplitSection', () => {
	it( 'names its parts for React DevTools', () => {
		expect( SplitSection.Root.displayName ).toBe( 'SplitSection.Root' );
		expect( SplitSection.Header.displayName ).toBe( 'SplitSection.Header' );
		expect( SplitSection.Content.displayName ).toBe( 'SplitSection.Content' );
	} );

	it( 'renders a two-column grid with no outer margin', () => {
		renderSection();
		const root = screen.getByTestId( 'root' );
		expect( root ).toHaveClass(
			'newspack-split-section',
			'newspack-grid',
			'newspack-grid__columns-2',
			'newspack-grid__gutter-32',
			'newspack-grid--no-margin'
		);
	} );

	it( 'puts the header first and the fields second', () => {
		renderSection();
		const root = screen.getByTestId( 'root' );
		expect( root.children ).toHaveLength( 2 );
		expect( root.children[ 0 ] ).toHaveClass( 'newspack-split-section__header' );
		expect( root.children[ 1 ] ).toBe( screen.getByTestId( 'content' ) );
		expect( screen.getByTestId( 'content' ) ).toContainElement( screen.getByRole( 'textbox', { name: 'Name' } ) );
	} );

	it( 'renders the title as an h2 by default, with the description', () => {
		renderSection();
		expect( screen.getByRole( 'heading', { level: 2, name: 'Group Names' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Choose how a group bought at checkout is named.' ) ).toBeInTheDocument();
	} );

	it( 'takes another heading level', () => {
		renderSection( { heading: 3 } );
		expect( screen.getByRole( 'heading', { level: 3, name: 'Group Names' } ) ).toBeInTheDocument();
	} );

	it( 'drops the section header margins', () => {
		renderSection();
		const heading = screen.getByRole( 'heading', { name: 'Group Names' } );
		expect( heading.closest( '.newspack-section-header' ) ).toHaveClass( 'newspack-section-header--no-margin' );
	} );

	it( 'places the id on the section header', () => {
		renderSection( { id: 'group-names' } );
		expect( document.getElementById( 'group-names' ) ).toContainElement( screen.getByRole( 'heading', { name: 'Group Names' } ) );
	} );

	it( 'shows header children below the title and description', () => {
		renderSection( {}, <button type="button">Add Label</button> );
		const header = screen.getByRole( 'heading', { name: 'Group Names' } ).closest( '.newspack-split-section__header' );
		const button = screen.getByRole( 'button', { name: 'Add Label' } );
		expect( header ).toContainElement( button );
		expect( header.lastElementChild ).toBe( button );
	} );

	it( 'merges class names onto each part', () => {
		render(
			<SplitSection.Root className="extra-root" data-testid="root">
				<SplitSection.Header title="Title" className="extra-header" />
				<SplitSection.Content className="extra-content" data-testid="content" />
			</SplitSection.Root>
		);
		expect( screen.getByTestId( 'root' ) ).toHaveClass( 'newspack-split-section', 'extra-root' );
		expect( screen.getByTestId( 'content' ) ).toHaveClass( 'newspack-split-section__content', 'extra-content' );
		expect( screen.getByRole( 'heading', { name: 'Title' } ).closest( '.newspack-split-section__header' ) ).toHaveClass( 'extra-header' );
	} );
} );
