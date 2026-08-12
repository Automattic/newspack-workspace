/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';
import { useReducedMotion } from '@wordpress/compose';
import { useLayoutEffect, useRef } from '@wordpress/element';

// useMediaQuery caches one subscriber per query for the life of the module, so a
// later matchMedia mock never reaches the hook.
jest.mock( '@wordpress/compose', () => ( {
	...jest.requireActual( '@wordpress/compose' ),
	useReducedMotion: jest.fn( () => false ),
} ) );

afterEach( () => {
	useReducedMotion.mockReturnValue( false );
} );

/**
 * External dependencies
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import { flushSync } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import Drawer from '.';
import useConfirmDialog from '../hooks/use-confirm-dialog';

const noop = () => {};

// Pass `contents` as an array, not a fragment: Children.toArray flattens arrays only.
const drawerTree = ( { rootProps = {}, header = true, contents, footer = null } = {} ) => (
	<Drawer.Root isOpen onRequestClose={ noop } { ...rootProps }>
		{ header && (
			<Drawer.Header>
				<Drawer.Title>Drawer title</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
		) }
		{ contents || <Drawer.Content>Body</Drawer.Content> }
		{ footer }
	</Drawer.Root>
);

const renderDrawer = options => render( drawerTree( options ) );

const panel = () => document.querySelector( '.newspack-drawer' );

const overlay = () => document.querySelector( '.newspack-drawer__overlay' );

const closeButton = () => screen.getByRole( 'button', { name: 'Close Drawer title' } );

describe( 'Drawer rendering', () => {
	it( 'renders nothing when closed', () => {
		renderDrawer( { rootProps: { isOpen: false } } );
		expect( panel() ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Body' ) ).not.toBeInTheDocument();
	} );

	it( 'renders its children when open', () => {
		renderDrawer();
		expect( screen.getByText( 'Body' ) ).toBeInTheDocument();
	} );

	it( 'applies the medium size by default', () => {
		renderDrawer();
		expect( panel() ).toHaveClass( 'newspack-drawer', 'newspack-drawer--size-medium' );
	} );

	it( 'applies the requested size', () => {
		renderDrawer( { rootProps: { size: 'large' } } );
		expect( panel() ).toHaveClass( 'newspack-drawer--size-large' );
	} );

	it( 'falls back to medium and warns on a size it does not know', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		renderDrawer( { rootProps: { size: 'xlarge' } } );

		expect( panel() ).toHaveClass( 'newspack-drawer--size-medium' );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'unknown size' ) );
		warn.mockRestore();
	} );

	it( 'takes a ref, a style and a description', () => {
		const ref = { current: null };
		render(
			<>
				<p id="drawer-description">What this panel is for</p>
				{ drawerTree( { rootProps: { ref, style: { zIndex: 5 }, describedBy: 'drawer-description' } } ) }
			</>
		);

		expect( ref.current ).toBe( overlay() );
		expect( panel() ).toHaveStyle( { zIndex: 5 } );
		expect( panel() ).toHaveAttribute( 'aria-describedby', 'drawer-description' );
	} );

	const twoTitles = ( { second = true, first = 'Drawer title' } = {} ) => (
		<Drawer.Root isOpen onRequestClose={ noop }>
			<Drawer.Header>
				<Drawer.Title>{ first }</Drawer.Title>
				{ second && <Drawer.Title>Second title</Drawer.Title> }
			</Drawer.Header>
			<Drawer.Content>Body</Drawer.Content>
		</Drawer.Root>
	);

	const nameOf = heading => screen.getByRole( 'heading', { name: heading } ).id;

	it( 'keeps its name when a second title unmounts', () => {
		const { rerender } = render( twoTitles() );
		rerender( twoTitles( { second: false } ) );

		expect( panel() ).toHaveAttribute( 'aria-labelledby', nameOf( 'Drawer title' ) );
	} );

	it( 'does not promote an earlier title when its text changes', () => {
		const { rerender } = render( twoTitles() );
		expect( panel() ).toHaveAttribute( 'aria-labelledby', nameOf( 'Second title' ) );

		rerender( twoTitles( { first: 'Renamed' } ) );
		expect( panel() ).toHaveAttribute( 'aria-labelledby', nameOf( 'Second title' ) );
	} );
} );

describe( 'Drawer popovers', () => {
	it( 'gives its children a popover slot', () => {
		renderDrawer();
		expect( document.querySelector( '.popover-slot' ) ).toBeInTheDocument();
	} );
} );

describe( 'Drawer accessible name', () => {
	it( 'names the panel by the title Drawer.Title registers', () => {
		renderDrawer();
		const heading = screen.getByRole( 'heading', { name: 'Drawer title' } );
		expect( panel() ).toHaveAttribute( 'aria-labelledby', heading.id );
		expect( screen.getByRole( 'dialog', { name: 'Drawer title' } ) ).toBeInTheDocument();
	} );

	// RTL's render() flushes passive effects inside act() and would hide the
	// difference, so this drives React itself.
	it( 'names the panel in the first painted frame', () => {
		const wasActEnvironment = global.IS_REACT_ACT_ENVIRONMENT;
		global.IS_REACT_ACT_ENVIRONMENT = false;
		const container = document.body.appendChild( document.createElement( 'div' ) );
		const root = createRoot( container );

		try {
			flushSync( () => root.render( drawerTree() ) );
			expect( panel() ).toHaveAttribute( 'aria-labelledby', document.querySelector( '.newspack-drawer__title' ).id );
			expect( document.querySelector( '.newspack-drawer__dismiss' ) ).toHaveAttribute( 'aria-label', 'Close Drawer title' );
		} finally {
			flushSync( () => root.unmount() );
			container.remove();
			global.IS_REACT_ACT_ENVIRONMENT = wasActEnvironment;
		}
	} );

	it( 'names the panel by contentLabel when there is no title', () => {
		renderDrawer( { header: false, rootProps: { contentLabel: 'Settings panel' } } );
		expect( panel() ).toHaveAttribute( 'aria-label', 'Settings panel' );
	} );

	it( 'warns when it has neither a title nor a contentLabel', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		renderDrawer( { header: false } );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'contentLabel' ) );
		warn.mockRestore();
	} );

	it( 'does not warn when a Drawer.Title is rendered', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		renderDrawer();
		expect( warn ).not.toHaveBeenCalled();
		warn.mockRestore();
	} );

	it( 'names its own confirmation, whose heading never shows', () => {
		renderDrawer( { rootProps: { isDirty: true } } );
		fireEvent.click( closeButton() );

		const confirmation = screen.getByRole( 'dialog', { name: 'Unsaved changes' } );
		expect( confirmation ).not.toBe( panel() );
		expect( confirmation ).toHaveClass( 'newspack-modal--hide-title' );
	} );
} );

const bodyOf = node => node.parentElement;

describe( 'Drawer sections', () => {
	it( 'groups consecutive content sections into one scroll container', () => {
		renderDrawer( {
			contents: [ <Drawer.Content key="one">One</Drawer.Content>, <Drawer.Content key="two">Two</Drawer.Content> ],
			footer: <Drawer.Footer>Actions</Drawer.Footer>,
		} );

		const one = screen.getByText( 'One' );
		expect( one ).toHaveClass( 'newspack-drawer__content' );
		expect( bodyOf( one ) ).toHaveClass( 'newspack-drawer__body' );
		expect( bodyOf( screen.getByText( 'Two' ) ) ).toBe( bodyOf( one ) );
		expect( bodyOf( one ) ).not.toContainElement( document.querySelector( '.newspack-drawer__header' ) );
		expect( bodyOf( one ) ).not.toContainElement( screen.getByText( 'Actions' ) );
	} );

	// `.newspack-drawer__body:has(+ .newspack-drawer__footer)` drops the last
	// section's bottom padding, so only the body the footer abuts may reach it.
	it( 'leaves the footer immediately after the last scroll container', () => {
		renderDrawer( {
			contents: [
				<Drawer.Content key="one">One</Drawer.Content>,
				<div key="split">Split</div>,
				<Drawer.Content key="two">Two</Drawer.Content>,
			],
			footer: <Drawer.Footer>Actions</Drawer.Footer>,
		} );

		const bodies = document.querySelectorAll( '.newspack-drawer__body' );
		const footer = document.querySelector( '.newspack-drawer__footer' );
		expect( bodies ).toHaveLength( 2 );
		expect( footer.previousElementSibling ).toBe( bodies[ 1 ] );
		expect( bodies[ 0 ].nextElementSibling ).not.toBe( footer );
	} );

	// jsdom has no layout, so the overflow the body measures is stubbed on the node.
	describe( 'keyboard access to the scroll container', () => {
		let observers;

		const setOverflow = ( body, scrollHeight ) => {
			Object.defineProperty( body, 'scrollHeight', { configurable: true, value: scrollHeight } );
			Object.defineProperty( body, 'clientHeight', { configurable: true, value: 100 } );
		};

		let mutationObservers;

		beforeEach( () => {
			observers = [];
			mutationObservers = [];
			global.ResizeObserver = class {
				constructor( callback ) {
					this.callback = callback;
					this.boxes = [];
					observers.push( this );
					this.disconnect = jest.fn();
				}
				observe( target, options ) {
					this.boxes.push( options?.box );
				}
			};
			global.MutationObserver = class {
				constructor( callback ) {
					this.callback = callback;
					mutationObservers.push( this );
					this.disconnect = jest.fn();
				}
				observe() {}
			};
		} );

		// jsdom supplies MutationObserver, so restore it rather than deleting it.
		const nativeMutationObserver = global.MutationObserver;

		afterEach( () => {
			delete global.ResizeObserver;
			global.MutationObserver = nativeMutationObserver;
		} );

		it( 'takes no tab stop while the content fits', () => {
			renderDrawer( { contents: [ <Drawer.Content key="one">One</Drawer.Content> ] } );
			const body = bodyOf( screen.getByText( 'One' ) );
			expect( body ).not.toHaveAttribute( 'tabindex' );
			expect( body ).not.toHaveAttribute( 'aria-label' );
			expect( body ).not.toHaveAttribute( 'role' );
		} );

		it( 'becomes focusable and named once it overflows, and reverts when it fits again', () => {
			renderDrawer( { contents: [ <Drawer.Content key="one">One</Drawer.Content> ] } );
			const body = bodyOf( screen.getByText( 'One' ) );

			setOverflow( body, 400 );
			act( () => observers.forEach( observer => observer.callback() ) );
			expect( body ).toHaveAttribute( 'tabindex', '0' );
			expect( body ).toHaveAttribute( 'aria-label', 'Scrollable section' );
			expect( body ).toHaveAttribute( 'role', 'group' );

			setOverflow( body, 50 );
			act( () => observers.forEach( observer => observer.callback() ) );
			expect( body ).not.toHaveAttribute( 'tabindex' );
			expect( body ).not.toHaveAttribute( 'aria-label' );
			expect( body ).not.toHaveAttribute( 'role' );
		} );

		// A section's padding is an inline custom property, so it grows the border box
		// without touching the content box a default observer would watch.
		it( 'observes its sections by border box', () => {
			renderDrawer( { contents: [ <Drawer.Content key="one">One</Drawer.Content> ] } );
			expect( observers.some( observer => observer.boxes.includes( 'border-box' ) ) ).toBe( true );
		} );

		// Setup disconnects once itself to re-observe, so count the extra teardown call.
		it( 'disconnects its observer when the drawer goes away', () => {
			const { unmount } = renderDrawer( { contents: [ <Drawer.Content key="one">One</Drawer.Content> ] } );
			expect( observers.length ).toBeGreaterThan( 0 );
			const before = observers.map( observer => observer.disconnect.mock.calls.length );

			unmount();
			observers.forEach( ( observer, index ) => {
				expect( observer.disconnect.mock.calls.length ).toBeGreaterThan( before[ index ] );
			} );
			// The MutationObserver holds a registration on the now-detached body.
			expect( mutationObservers.length ).toBeGreaterThan( 0 );
			mutationObservers.forEach( observer => expect( observer.disconnect ).toHaveBeenCalled() );
		} );
	} );

	// The `> * { flex: none }` rule that stops sections compressing depends on this.
	it( 'lays the scroll container out as a flush VStack', () => {
		renderDrawer( { contents: [ <Drawer.Content key="one">One</Drawer.Content> ] } );

		const body = bodyOf( screen.getByText( 'One' ) );
		expect( body ).toHaveClass( 'components-v-stack' );
		expect( window.getComputedStyle( body ).gap ).toBe( '0' );
		expect( window.getComputedStyle( body ).justifyContent ).toBe( 'flex-start' );
	} );

	it( 'starts a new container after a non-content child', () => {
		renderDrawer( {
			contents: [
				<Drawer.Content key="one">One</Drawer.Content>,
				<div key="notice">Notice</div>,
				<Drawer.Content key="two">Two</Drawer.Content>,
			],
		} );
		expect( bodyOf( screen.getByText( 'Two' ) ) ).not.toBe( bodyOf( screen.getByText( 'One' ) ) );
	} );

	it( 'keeps a divider inside the container it divides', () => {
		renderDrawer( {
			contents: [
				<Drawer.Content key="one">One</Drawer.Content>,
				<Drawer.Divider key="rule" />,
				<Drawer.Content key="two">Two</Drawer.Content>,
			],
		} );

		const body = bodyOf( screen.getByText( 'One' ) );
		expect( bodyOf( screen.getByText( 'Two' ) ) ).toBe( body );
		expect( body ).toContainElement( document.querySelector( '.newspack-drawer__divider' ) );
	} );

	it( 'keeps the same scroll container when a sibling above it toggles', () => {
		const tree = withNotice => (
			<Drawer.Root isOpen contentLabel="Settings panel" onRequestClose={ noop }>
				{ withNotice && <div>Notice</div> }
				<Drawer.Content>Body</Drawer.Content>
			</Drawer.Root>
		);

		const { rerender } = render( tree( false ) );
		const body = bodyOf( screen.getByText( 'Body' ) );

		rerender( tree( true ) );
		expect( bodyOf( screen.getByText( 'Body' ) ) ).toBe( body );
	} );
} );

// The dialog's X reuses the cancel label, so a role query for "Cancel" is
// ambiguous. Query the footer buttons by visible text.
const dialogButton = name => screen.getByText( name, { selector: 'button' } );

describe( 'Drawer closing', () => {
	it( 'closes on a close icon click when it is not dirty', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		fireEvent.click( closeButton() );
		expect( onRequestClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'closes on Escape when it is not dirty', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		fireEvent.keyDown( screen.getByText( 'Body' ), { key: 'Escape' } );
		expect( onRequestClose ).toHaveBeenCalled();
	} );

	it( 'closes on an Escape reported by code alone', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		fireEvent.keyDown( screen.getByText( 'Body' ), { code: 'Escape' } );
		expect( onRequestClose ).toHaveBeenCalled();
	} );

	it( 'ignores an Escape that ends an IME composition', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		fireEvent.keyDown( screen.getByText( 'Body' ), { key: 'Escape', isComposing: true } );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	it( 'ignores an Escape a child has already handled', () => {
		const onRequestClose = jest.fn();
		renderDrawer( {
			rootProps: { onRequestClose },
			contents: (
				<Drawer.Content>
					<button onKeyDown={ event => event.preventDefault() }>child</button>
				</Drawer.Content>
			),
		} );
		fireEvent.keyDown( screen.getByRole( 'button', { name: 'child' } ), { key: 'Escape' } );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	it( 'confirms before closing when it is dirty', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { isDirty: true, onRequestClose } } );

		fireEvent.click( closeButton() );
		expect( onRequestClose ).not.toHaveBeenCalled();

		fireEvent.click( dialogButton( 'Discard Changes' ) );
		expect( onRequestClose ).toHaveBeenCalled();
	} );

	it( 'confirms an Escape when it is dirty', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { isDirty: true, onRequestClose } } );
		fireEvent.keyDown( screen.getByText( 'Body' ), { key: 'Escape' } );
		expect( onRequestClose ).not.toHaveBeenCalled();
		fireEvent.click( dialogButton( 'Discard Changes' ) );
		expect( onRequestClose ).toHaveBeenCalled();
	} );

	it( 'stays open when a delegated confirmation opens', () => {
		const onRequestClose = jest.fn();
		const Delegating = () => {
			const { confirmDialog, requestConfirm } = useConfirmDialog( {
				message: 'Discard?',
				confirmButtonText: 'Discard Changes',
			} );
			return (
				<>
					{ drawerTree( { rootProps: { isDirty: true, requestConfirm, onRequestClose } } ) }
					{ confirmDialog }
				</>
			);
		};
		render( <Delegating /> );

		fireEvent.click( closeButton() );
		expect( screen.getByText( 'Discard Changes', { selector: 'button' } ) ).toBeInTheDocument();
		expect( onRequestClose ).not.toHaveBeenCalled();
		expect( panel() ).toBeInTheDocument();

		fireEvent.click( screen.getByText( 'Discard Changes', { selector: 'button' } ) );
		expect( onRequestClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reads the current isDirty when a modal above it dismisses', () => {
		const onRequestClose = jest.fn();
		const above = ( { sibling, isDirty } ) => (
			<>
				{ sibling && (
					<Modal title="Above" onRequestClose={ noop }>
						Above
					</Modal>
				) }
				{ drawerTree( { rootProps: { isDirty, onRequestClose } } ) }
			</>
		);
		const { rerender } = render( above( { sibling: false, isDirty: false } ) );

		rerender( above( { sibling: true, isDirty: true } ) );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	it( 'warns when a delegated confirmation closes without prompting', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const onRequestClose = jest.fn();
		const requestConfirm = callback => callback();
		renderDrawer( { rootProps: { isDirty: true, requestConfirm, onRequestClose } } );

		fireEvent.click( closeButton() );
		expect( onRequestClose ).toHaveBeenCalled();
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'without prompting' ) );
		warn.mockRestore();
	} );

	it( 'does not warn when a delegated confirmation prompts', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		renderDrawer( { rootProps: { isDirty: true, requestConfirm: jest.fn(), onRequestClose: noop } } );

		fireEvent.click( closeButton() );
		expect( warn ).not.toHaveBeenCalled();
		warn.mockRestore();
	} );

	it( 'leaves a real delegated confirmation to its owner', () => {
		const error = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		const Delegating = ( { open } ) => {
			const { confirmDialog, requestConfirm } = useConfirmDialog( {
				message: 'Discard?',
				confirmButtonText: 'Discard Changes',
			} );
			return (
				<>
					{ drawerTree( { rootProps: { isOpen: open, isDirty: true, requestConfirm, onRequestClose: noop } } ) }
					{ confirmDialog }
				</>
			);
		};
		const { rerender } = render( <Delegating open /> );

		fireEvent.click( closeButton() );
		expect( screen.getByText( 'Discard Changes', { selector: 'button' } ) ).toBeInTheDocument();

		rerender( <Delegating open={ false } /> );
		expect( screen.getByText( 'Discard Changes', { selector: 'button' } ) ).toBeInTheDocument();
		expect( error ).not.toHaveBeenCalled();
		error.mockRestore();
	} );

	const withSibling = ( rootProps, sibling ) => (
		<>
			{ drawerTree( { rootProps } ) }
			{ sibling && (
				<Modal title="Elsewhere" onRequestClose={ noop }>
					Elsewhere
				</Modal>
			) }
		</>
	);

	it( 'closes without confirming when another modal replaces it', () => {
		const onRequestClose = jest.fn();
		const rootProps = { onRequestClose };
		const { rerender } = render( withSibling( rootProps, false ) );

		rerender( withSibling( rootProps, true ) );
		expect( onRequestClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stays open behind another modal when it is dirty', () => {
		const onRequestClose = jest.fn();
		const rootProps = { isDirty: true, onRequestClose };
		const { rerender } = render( withSibling( rootProps, false ) );

		rerender( withSibling( rootProps, true ) );
		expect( onRequestClose ).not.toHaveBeenCalled();
		expect( panel() ).toBeInTheDocument();
		expect( screen.queryByText( 'Discard Changes', { selector: 'button' } ) ).not.toBeInTheDocument();
	} );

	it( 'stays open when the confirmation is cancelled', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { isDirty: true, onRequestClose } } );

		fireEvent.click( closeButton() );
		fireEvent.click( dialogButton( 'Cancel' ) );
		expect( onRequestClose ).not.toHaveBeenCalled();
		expect( screen.getByText( 'Body' ) ).toBeInTheDocument();
	} );

	it( 'delegates to requestConfirm when one is supplied', () => {
		const onRequestClose = jest.fn();
		const requestConfirm = jest.fn();
		renderDrawer( { rootProps: { isDirty: true, requestConfirm, onRequestClose } } );

		fireEvent.click( closeButton() );
		expect( requestConfirm ).toHaveBeenCalledTimes( 1 );
		expect( screen.queryByText( 'Discard Changes', { selector: 'button' } ) ).not.toBeInTheDocument();

		expect( onRequestClose ).not.toHaveBeenCalled();
		requestConfirm.mock.calls[ 0 ][ 0 ]();
		expect( onRequestClose ).toHaveBeenCalledTimes( 1 );
	} );

	// jsdom has no PointerEvent, and a plain Event drops the `button` the handler
	// checks. A MouseEvent carries one.
	const pointerEvent = ( type, button = 0 ) => new MouseEvent( type, { bubbles: true, cancelable: true, button } );

	const pointerDownEvent = button => pointerEvent( 'pointerdown', button );

	const pointerDown = ( node, button ) => fireEvent( node, pointerDownEvent( button ) );

	const pointerUp = ( node, button ) => fireEvent( node, pointerEvent( 'pointerup', button ) );

	const press = ( node, button ) => {
		pointerDown( node, button );
		pointerUp( node, button );
	};

	it( 'closes on an overlay press', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		press( overlay() );
		expect( onRequestClose ).toHaveBeenCalled();
	} );

	it( 'does not close on a press inside the panel', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		press( screen.getByText( 'Body' ) );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	it( 'closes on an overlay press that spans a re-render', () => {
		const onRequestClose = jest.fn();
		const tree = () => drawerTree( { rootProps: { onRequestClose: () => onRequestClose() } } );
		const { rerender } = render( tree() );

		pointerDown( overlay() );
		rerender( tree() );
		pointerUp( overlay() );
		expect( onRequestClose ).toHaveBeenCalled();
	} );

	// Not the commit-versus-paint timing the ref mirror guards: act() drains passive
	// effects, so this passes either way.
	it( 'confirms when the drawer turns dirty mid-gesture', () => {
		const onRequestClose = jest.fn();
		const tree = isDirty => drawerTree( { rootProps: { onRequestClose, isDirty } } );
		const { rerender } = render( tree( false ) );

		pointerDown( overlay() );
		rerender( tree( true ) );
		pointerUp( overlay() );

		expect( onRequestClose ).not.toHaveBeenCalled();
		expect( dialogButton( 'Discard Changes' ) ).toBeInTheDocument();
	} );

	it( 'does not close when the press ends inside the panel', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		pointerDown( overlay() );
		pointerUp( screen.getByText( 'Body' ) );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	it( 'does not close on a release that lands outside the overlay', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );

		pointerDown( overlay() );
		pointerUp( document.body );
		expect( onRequestClose ).not.toHaveBeenCalled();

		pointerUp( overlay() );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	// jsdom implements neither capture method, hence the stubs.
	it( 'releases the implicit pointer capture on an overlay press', () => {
		renderDrawer();
		const scrim = overlay();
		scrim.hasPointerCapture = jest.fn( () => true );
		scrim.releasePointerCapture = jest.fn();

		const event = pointerDownEvent();
		event.pointerId = 7;
		fireEvent( scrim, event );
		expect( scrim.releasePointerCapture ).toHaveBeenCalledWith( 7 );
	} );

	it( 'does not close when the press is cancelled', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		pointerDown( overlay() );
		fireEvent( overlay(), pointerEvent( 'pointercancel' ) );
		pointerUp( overlay() );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	it( 'does not close on a non-primary overlay press', () => {
		const onRequestClose = jest.fn();
		renderDrawer( { rootProps: { onRequestClose } } );
		press( overlay(), 2 );
		expect( onRequestClose ).not.toHaveBeenCalled();
	} );

	it( 'prevents the default on an overlay press', () => {
		renderDrawer();
		const event = pointerDownEvent();
		fireEvent( overlay(), event );
		expect( event.defaultPrevented ).toBe( true );
	} );

	describe( 'during the exit animation', () => {
		const closing = rootProps => {
			const { rerender } = render( drawerTree( { rootProps } ) );
			rerender( drawerTree( { rootProps: { ...rootProps, isOpen: false } } ) );
			expect( panel() ).toBeInTheDocument();
		};

		it( 'ignores a further Escape', () => {
			const onRequestClose = jest.fn();
			closing( { onRequestClose } );
			fireEvent.keyDown( screen.getByText( 'Body' ), { key: 'Escape' } );
			expect( onRequestClose ).not.toHaveBeenCalled();
		} );

		it( 'ignores a further overlay press', () => {
			const onRequestClose = jest.fn();
			closing( { onRequestClose } );
			press( overlay() );
			expect( onRequestClose ).not.toHaveBeenCalled();
		} );

		it( 'marks the frame inert', () => {
			const { rerender } = render( drawerTree() );
			expect( panel() ).not.toHaveAttribute( 'inert' );

			rerender( drawerTree( { rootProps: { isOpen: false } } ) );
			expect( panel() ).toHaveAttribute( 'inert' );
		} );

		it( 'does not reopen the confirmation of a dirty drawer', () => {
			const onRequestClose = jest.fn();
			closing( { isDirty: true, onRequestClose } );

			fireEvent.keyDown( screen.getByText( 'Body' ), { key: 'Escape' } );
			expect( screen.queryByText( 'Discard Changes', { selector: 'button' } ) ).not.toBeInTheDocument();
			expect( onRequestClose ).not.toHaveBeenCalled();
		} );
	} );
} );

// jsdom emulates none of inert's behaviour; these observe the explicit return.
describe( 'Drawer focus return', () => {
	// Plain DOM: RTL's cleanup would fight a React-owned node removed by hand below.
	let opener;
	let elsewhere;

	beforeEach( () => {
		opener = document.body.appendChild( document.createElement( 'button' ) );
		elsewhere = document.body.appendChild( document.createElement( 'button' ) );
		opener.focus();
	} );

	afterEach( () => {
		opener.remove();
		elsewhere.remove();
	} );

	const closed = { rootProps: { isOpen: false } };

	it( 'returns focus to the opener as the exit begins', () => {
		const { rerender } = render( drawerTree( closed ) );

		rerender( drawerTree() );
		closeButton().focus();
		expect( panel() ).toContainElement( document.activeElement );

		rerender( drawerTree( closed ) );
		expect( panel() ).toBeInTheDocument();
		expect( document.activeElement ).toBe( opener );
	} );

	it( 'leaves focus on the opener once the panel unmounts', () => {
		const { rerender } = render( drawerTree( closed ) );

		rerender( drawerTree() );
		rerender( drawerTree( closed ) );
		fireEvent.animationEnd( document.querySelector( '.components-modal__frame' ) );
		expect( panel() ).not.toBeInTheDocument();
		expect( document.activeElement ).toBe( opener );
	} );

	it( 'closes without complaint when the opener has gone', () => {
		const { rerender } = render( drawerTree( closed ) );

		rerender( drawerTree() );
		opener.remove();

		expect( () => rerender( drawerTree( closed ) ) ).not.toThrow();
		expect( panel() ).toBeInTheDocument();
	} );

	it( 'leaves focus alone when it has moved outside the panel', () => {
		const { rerender } = render( drawerTree( closed ) );

		rerender( drawerTree() );
		elsewhere.focus();

		rerender( drawerTree( closed ) );
		expect( document.activeElement ).toBe( elsewhere );
	} );

	const editor = ( editing, child ) => (
		<Drawer.Root isOpen={ !! editing } contentLabel="Editor" onRequestClose={ noop }>
			<Drawer.Content>{ editing && child }</Drawer.Content>
		</Drawer.Root>
	);

	it( 'returns focus to the opener when the focused child unmounts with the close', () => {
		const { rerender } = render( editor( false, <input aria-label="Name" /> ) );

		rerender( editor( true, <input aria-label="Name" /> ) );
		screen.getByLabelText( 'Name' ).focus();
		expect( panel() ).toContainElement( document.activeElement );

		rerender( editor( false, <input aria-label="Name" /> ) );
		expect( screen.queryByLabelText( 'Name' ) ).not.toBeInTheDocument();
		expect( document.activeElement ).toBe( opener );
	} );

	it( 'restores focus to the panel when it reopens mid-exit', () => {
		const { rerender } = render( drawerTree( closed ) );

		rerender( drawerTree() );
		rerender( drawerTree( closed ) );
		expect( document.activeElement ).toBe( opener );

		rerender( drawerTree() );
		expect( panel() ).not.toHaveAttribute( 'inert' );
		expect( panel() ).toContainElement( document.activeElement );
		expect( document.activeElement ).not.toBe( opener );
	} );

	it( 'leaves focus on content that claims it when it reopens mid-exit', () => {
		const SelfFocusing = () => {
			const ref = useRef( null );
			useLayoutEffect( () => ref.current?.focus(), [] );
			return <input aria-label="Name" ref={ ref } />;
		};
		const { rerender } = render( editor( false, <SelfFocusing /> ) );

		rerender( editor( true, <SelfFocusing /> ) );
		rerender( editor( false, <SelfFocusing /> ) );
		expect( document.activeElement ).toBe( opener );

		rerender( editor( true, <SelfFocusing /> ) );
		expect( document.activeElement ).toBe( screen.getByLabelText( 'Name' ) );
	} );
} );

describe( 'Drawer closed from the parent while its confirmation is open', () => {
	let opener;

	beforeEach( () => {
		opener = document.body.appendChild( document.createElement( 'button' ) );
		opener.focus();
	} );

	afterEach( () => {
		opener.remove();
	} );

	const dirty = { isDirty: true, onRequestClose: noop };

	const confirmingThenClosed = () => {
		const { rerender } = render( drawerTree( { rootProps: dirty } ) );
		fireEvent.click( closeButton() );
		expect( dialogButton( 'Discard Changes' ) ).toBeInTheDocument();

		rerender( drawerTree( { rootProps: { ...dirty, isOpen: false } } ) );
		return rerender;
	};

	const confirmation = () => screen.queryByText( 'Discard Changes', { selector: 'button' } );

	it( 'dismisses the confirmation', () => {
		confirmingThenClosed();
		expect( confirmation() ).not.toBeInTheDocument();
	} );

	it( 'returns focus to the opener', () => {
		confirmingThenClosed();
		expect( document.activeElement ).toBe( opener );
	} );

	it( 'leaves focus on the opener once the panel unmounts', () => {
		confirmingThenClosed();
		fireEvent.animationEnd( document.querySelector( '.components-modal__frame' ) );
		expect( panel() ).not.toBeInTheDocument();
		expect( document.activeElement ).toBe( opener );
	} );

	it( 'does not restore the confirmation when it opens again', () => {
		const rerender = confirmingThenClosed();
		fireEvent.animationEnd( document.querySelector( '.components-modal__frame' ) );

		rerender( drawerTree( { rootProps: dirty } ) );
		expect( confirmation() ).not.toBeInTheDocument();
	} );

	it( 'returns focus to the opener under prefers-reduced-motion', () => {
		useReducedMotion.mockReturnValue( true );
		confirmingThenClosed();
		expect( panel() ).not.toBeInTheDocument();
		expect( confirmation() ).not.toBeInTheDocument();
		expect( document.activeElement ).toBe( opener );
	} );
} );

describe( 'Drawer confirmation inside a router', () => {
	const renderRouted = rootProps => render( <MemoryRouter>{ drawerTree( { rootProps } ) }</MemoryRouter> );

	it( 'confirms a close', () => {
		const onRequestClose = jest.fn();
		renderRouted( { isDirty: true, onRequestClose } );

		fireEvent.click( closeButton() );
		fireEvent.click( dialogButton( 'Discard Changes' ) );
		expect( onRequestClose ).toHaveBeenCalled();
	} );

	it( 'cancels a close, and can still confirm afterwards', () => {
		const onRequestClose = jest.fn();
		renderRouted( { isDirty: true, onRequestClose } );

		fireEvent.click( closeButton() );
		fireEvent.click( dialogButton( 'Cancel' ) );
		expect( onRequestClose ).not.toHaveBeenCalled();

		fireEvent.click( closeButton() );
		fireEvent.click( dialogButton( 'Discard Changes' ) );
		expect( onRequestClose ).toHaveBeenCalled();
	} );
} );

// jsdom has no AnimationEvent, so `fireEvent.animationEnd` cannot carry a name.
const animationEnd = ( node, animationName ) => {
	const event = new Event( 'animationend', { bubbles: true } );
	event.animationName = animationName;
	fireEvent( node, event );
};

describe( 'Drawer exit animation', () => {
	afterEach( () => {
		jest.useRealTimers();
	} );

	const closed = { rootProps: { isOpen: false } };

	it( 'marks the frame as exiting, then removes it', () => {
		jest.useFakeTimers();
		const { rerender } = render( drawerTree() );

		rerender( drawerTree( closed ) );
		expect( panel() ).toHaveClass( 'components-modal__frame', 'is-exiting' );

		act( () => {
			jest.runAllTimers();
		} );
		expect( panel() ).not.toBeInTheDocument();
	} );

	it( 'keeps the same frame across a close', () => {
		const { rerender } = render( drawerTree() );
		const opened = panel();

		rerender( drawerTree( closed ) );
		expect( panel() ).toBe( opened );
	} );

	it( 'marks the overlay as exiting', () => {
		jest.useFakeTimers();
		const { rerender } = render( drawerTree() );

		rerender( drawerTree( closed ) );
		expect( overlay() ).toHaveClass( 'is-exiting' );

		act( () => {
			jest.runAllTimers();
		} );
		expect( overlay() ).not.toBeInTheDocument();
	} );

	it( 'ignores an animationend from the overlay fade', () => {
		const { rerender } = render( drawerTree() );

		rerender( drawerTree( closed ) );
		animationEnd( overlay(), 'newspack-drawer-overlay-fade-out' );
		expect( panel() ).toBeInTheDocument();

		animationEnd( document.querySelector( '.components-modal__frame' ), 'newspack-drawer-slide-out' );
		expect( panel() ).not.toBeInTheDocument();
	} );

	it( 'unmounts the panel when its frame finishes animating out', () => {
		const { rerender } = render( drawerTree() );

		rerender( drawerTree( closed ) );
		fireEvent.animationEnd( document.querySelector( '.components-modal__frame' ) );
		expect( panel() ).not.toBeInTheDocument();
	} );

	it( 'ignores an animationend from a nested animation', () => {
		const { rerender } = render( drawerTree() );

		rerender( drawerTree( closed ) );
		animationEnd( screen.getByText( 'Body' ), 'components-popover__appear' );
		expect( panel() ).toBeInTheDocument();

		animationEnd( panel(), 'newspack-drawer-slide-out' );
		expect( panel() ).not.toBeInTheDocument();
	} );

	it( 'removes the panel at once under prefers-reduced-motion', () => {
		useReducedMotion.mockReturnValue( true );
		const { rerender } = render( drawerTree() );

		rerender( drawerTree( closed ) );
		expect( panel() ).not.toBeInTheDocument();
	} );
} );
