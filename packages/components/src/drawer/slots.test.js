/**
 * Internal dependencies.
 */
import { DrawerContext } from './context';
import Header from './header';
import Title from './title';
import CloseIcon from './close-icon';
import Content from './content';
import Divider from './divider';
import Footer from './footer';
import Action from './action';

/**
 * External dependencies.
 */
import { fireEvent, render, screen } from '@testing-library/react';

const withContext = ( ui, value = {} ) =>
	render( <DrawerContext.Provider value={ { requestClose: () => {}, title: null, setTitle: () => {}, ...value } }>{ ui }</DrawerContext.Provider> );

describe( 'Drawer.Header', () => {
	it( 'renders a container with its children', () => {
		withContext( <Header className="custom">content</Header> );
		const header = screen.getByText( 'content' );
		expect( header ).toHaveClass( 'newspack-drawer__header', 'custom' );
	} );
} );

describe( 'Drawer.Title', () => {
	it( 'renders an h2 and registers its id and text', () => {
		const setTitle = jest.fn();
		withContext( <Title>Edit Styles</Title>, { setTitle } );
		const heading = screen.getByRole( 'heading', { level: 2, name: 'Edit Styles' } );
		expect( heading.id ).toBeTruthy();
		expect( setTitle ).toHaveBeenCalledWith( { id: heading.id, text: 'Edit Styles' } );
	} );

	it( 'registers null text for non-string children and clears on unmount', () => {
		const setTitle = jest.fn();
		const { unmount } = withContext(
			<Title>
				<em>Rich</em>
			</Title>,
			{ setTitle }
		);
		expect( setTitle ).toHaveBeenCalledWith( expect.objectContaining( { text: null } ) );
		unmount();
		expect( setTitle ).toHaveBeenLastCalledWith( null, expect.any( String ) );
	} );
} );

describe( 'Drawer.CloseIcon', () => {
	it( 'is named "Close" without a title and funnels clicks to requestClose', () => {
		const requestClose = jest.fn();
		withContext( <CloseIcon />, { requestClose } );
		const button = screen.getByRole( 'button', { name: 'Close' } );
		fireEvent.click( button );
		expect( requestClose ).toHaveBeenCalled();
	} );

	it( 'composes the label with a string title', () => {
		withContext( <CloseIcon />, { title: { id: 't', text: 'Edit Styles' } } );
		expect( screen.getByRole( 'button', { name: 'Close Edit Styles' } ) ).toBeInTheDocument();
	} );

	it( 'accepts a label override', () => {
		withContext( <CloseIcon label="Dismiss panel" />, { title: { id: 't', text: 'Edit Styles' } } );
		expect( screen.getByRole( 'button', { name: 'Dismiss panel' } ) ).toBeInTheDocument();
	} );
} );

describe( 'Drawer.Content', () => {
	const sectionOf = container => container.querySelector( '.newspack-drawer__content' );

	// jest-dom's toHaveStyle is unreliable for custom properties in jsdom.
	it( 'defaults to padding 6 on the 4px scale', () => {
		const { container } = render( <Content>body</Content> );
		expect( sectionOf( container ).style.getPropertyValue( '--newspack-drawer-content-padding' ) ).toBe( '24px' );
	} );

	it( 'accepts padding 0 for a flush section', () => {
		const { container } = render( <Content padding={ 0 }>flush</Content> );
		expect( sectionOf( container ).style.getPropertyValue( '--newspack-drawer-content-padding' ) ).toBe( '0px' );
	} );

	it( 'renders each section as a VStack', () => {
		const { container } = render( <Content>stacked</Content> );
		expect( sectionOf( container ) ).toHaveClass( 'newspack-drawer__content', 'components-v-stack' );
	} );

	// VStack emits its spacing as a `calc()` on the 4px base, not as pixels.
	it( 'spaces its children by 16px, and by the gap prop', () => {
		const { container, rerender } = render( <Content>stacked</Content> );
		expect( window.getComputedStyle( sectionOf( container ) ).gap ).toBe( 'calc(4px * 4)' );

		rerender( <Content gap={ 2 }>stacked</Content> );
		expect( window.getComputedStyle( sectionOf( container ) ).gap ).toBe( 'calc(4px * 2)' );
	} );

	// VStack keeps only elements, so these would otherwise vanish without warning.
	it( 'keeps plain text sitting beside an element', () => {
		render(
			<Content>
				Lead text
				<span>an element</span>
				{ 42 }
			</Content>
		);
		expect( screen.getByText( 'Lead text' ) ).toBeInTheDocument();
		expect( screen.getByText( 'an element' ) ).toBeInTheDocument();
		expect( screen.getByText( '42' ) ).toBeInTheDocument();
	} );

	it( 'keeps a run of text and interpolations on one row', () => {
		const { container } = render( <Content>Edited by { 'Ada' } just now</Content> );
		const rows = sectionOf( container ).children;
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toHaveTextContent( 'Edited by Ada just now' );
	} );

	it( 'starts a new row either side of an element', () => {
		const { container } = render(
			<Content>
				before
				<span>middle</span>
				after
			</Content>
		);
		expect( Array.from( sectionOf( container ).children ).map( row => row.textContent ) ).toEqual( [ 'before', 'middle', 'after' ] );
	} );

	it( 'emits no row for whitespace or empty text between elements', () => {
		const { container } = render(
			<Content>
				<span>one</span> { '' }
				<span>two</span>
			</Content>
		);
		expect( Array.from( sectionOf( container ).children ).map( row => row.textContent ) ).toEqual( [ 'one', 'two' ] );
	} );

	it( 'keeps a lone string', () => {
		render( <Content>on its own</Content> );
		expect( screen.getByText( 'on its own' ) ).toBeInTheDocument();
	} );
} );

describe( 'Drawer.Divider', () => {
	it( 'renders a separator, merging className', () => {
		const { container } = render( <Divider className="custom" /> );
		const divider = container.querySelector( 'hr' );
		expect( divider ).toHaveClass( 'newspack-drawer__divider', 'custom' );
		expect( screen.getByRole( 'separator' ) ).toBe( divider );
	} );
} );

describe( 'Drawer.Footer', () => {
	it( 'renders a container with its children', () => {
		render( <Footer>actions</Footer> );
		expect( screen.getByText( 'actions' ) ).toHaveClass( 'newspack-drawer__footer' );
	} );

	it( 'renders its actions as direct children', () => {
		const { container } = render(
			<Footer>
				<Action>One</Action>
				<Action>Two</Action>
				<Action>Three</Action>
			</Footer>
		);
		const footer = container.querySelector( '.newspack-drawer__footer' );
		expect( Array.from( footer.children ) ).toEqual( [
			screen.getByRole( 'button', { name: 'One' } ),
			screen.getByRole( 'button', { name: 'Two' } ),
			screen.getByRole( 'button', { name: 'Three' } ),
		] );
	} );
} );

describe( 'Drawer.Action', () => {
	it( 'renders a button with aria-label from ariaLabel', () => {
		render( <Action ariaLabel="Save styles">Save</Action> );
		expect( screen.getByRole( 'button', { name: 'Save styles' } ) ).toBeInTheDocument();
	} );

	it( 'warns when ariaLabel does not contain the visible label', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		render( <Action ariaLabel="Apply changes">Save</Action> );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'Label in Name' ) );
		warn.mockRestore();
	} );

	it( 'does not warn when ariaLabel contains the visible label', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		render( <Action ariaLabel="Save styles">Save</Action> );
		expect( warn ).not.toHaveBeenCalled();
		warn.mockRestore();
	} );

	it( 'hands the click event to onClick', () => {
		const onClick = jest.fn();
		render( <Action onClick={ onClick }>Save</Action> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( onClick ).toHaveBeenCalledWith( expect.objectContaining( { type: 'click' } ) );
	} );

	it( 'forwards the button props', () => {
		render(
			<Action variant="primary" isBusy isDestructive disabled>
				Save
			</Action>
		);
		const button = screen.getByRole( 'button', { name: 'Save' } );
		expect( button ).toHaveClass( 'is-primary', 'is-busy', 'is-destructive' );
		expect( button ).toBeDisabled();
	} );

	it( 'forwards onClick', () => {
		const onClick = jest.fn();
		render( <Action onClick={ onClick }>Save</Action> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( onClick ).toHaveBeenCalledTimes( 1 );
	} );

	// Button drops the href as soon as an onClick is present.
	it( 'renders an anchor for an href-only action', () => {
		render( <Action href="/somewhere">Go</Action> );
		const link = screen.getByRole( 'link', { name: 'Go' } );
		expect( link ).toHaveAttribute( 'href', '/somewhere' );
	} );

	// The types forbid this, but the package is consumed from plain JS.
	it( 'stays a link and warns when href is combined with closes', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const requestClose = jest.fn();
		const onClick = jest.fn();
		withContext(
			<Action href="/somewhere" closes onClick={ onClick }>
				Go
			</Action>,
			{ requestClose }
		);

		const link = screen.getByRole( 'link', { name: 'Go' } );
		expect( link ).toHaveAttribute( 'href', '/somewhere' );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'cannot also take' ) );

		fireEvent.click( link );
		expect( onClick ).not.toHaveBeenCalled();
		expect( requestClose ).not.toHaveBeenCalled();
		warn.mockRestore();
	} );

	// An empty string still renders an anchor, so truthiness would re-arm the funnel.
	it( 'treats an empty href as a link rather than re-arming the close funnel', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const requestClose = jest.fn();
		const onClick = jest.fn();
		withContext(
			<Action href="" closes onClick={ onClick }>
				Go
			</Action>,
			{ requestClose }
		);

		const anchor = screen.getByText( 'Go' ).closest( 'a' );
		expect( anchor ).toHaveAttribute( 'href', '' );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'cannot also take' ) );

		fireEvent.click( anchor );
		expect( onClick ).not.toHaveBeenCalled();
		expect( requestClose ).not.toHaveBeenCalled();
		warn.mockRestore();
	} );

	// No link, so the handlers must stay attached rather than leaving a dead anchor.
	it( 'runs the handlers when a nullable href resolves to nothing', () => {
		const requestClose = jest.fn();
		const onClick = jest.fn();
		withContext(
			<Action href={ null } closes onClick={ onClick }>
				Go
			</Action>,
			{ requestClose }
		);

		const button = screen.getByRole( 'button', { name: 'Go' } );
		fireEvent.click( button );
		expect( onClick ).toHaveBeenCalledTimes( 1 );
		expect( requestClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'requests the drawer close after onClick when `closes` is set', () => {
		const requestClose = jest.fn();
		const onClick = jest.fn( () => expect( requestClose ).not.toHaveBeenCalled() );
		withContext(
			<Action closes onClick={ onClick }>
				Cancel
			</Action>,
			{ requestClose }
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( onClick ).toHaveBeenCalledTimes( 1 );
		expect( requestClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not request the close without `closes`', () => {
		const requestClose = jest.fn();
		withContext( <Action>Save</Action>, { requestClose } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( requestClose ).not.toHaveBeenCalled();
	} );
} );
