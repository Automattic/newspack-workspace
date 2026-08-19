/**
 * External dependencies.
 */
import { act, render, screen } from '@testing-library/react';
import { MemoryRouter, useHistory } from 'react-router-dom';

/**
 * WordPress dependencies.
 */
import { createRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Button from './';

const HistoryGrabber = ( { historyRef } ) => {
	historyRef.current = useHistory();
	return null;
};

const clickButton = async () => {
	await act( async () => {
		screen.getByRole( 'button', { name: 'Save' } ).click();
	} );
};

describe( 'Button href with onClick', () => {
	it( 'awaits the onClick, then pushes the route', async () => {
		const historyRef = { current: null };
		const onClick = jest.fn().mockResolvedValue( undefined );
		render(
			<MemoryRouter>
				<HistoryGrabber historyRef={ historyRef } />
				<Button href="#/next" onClick={ onClick }>
					Save
				</Button>
			</MemoryRouter>
		);

		await clickButton();
		expect( onClick ).toHaveBeenCalled();
		// The leading '#' is stripped so the router sees a route, not a fragment.
		expect( historyRef.current.location.pathname ).toBe( '/next' );
	} );
} );

// `useHistory` returns undefined outside a wizard, leaving no route table for the
// href to name.
describe( 'Button href with onClick outside a router', () => {
	// jsdom's real location logs "Not implemented: navigation" rather than
	// navigating, so swap in a plain object to assert against and put the
	// original back afterwards.
	const withStubbedLocation = async fn => {
		const original = window.location;
		delete window.location;
		window.location = { href: '' };
		try {
			return await fn();
		} finally {
			window.location = original;
		}
	};

	it( 'awaits the onClick, then navigates to the href', async () => {
		const onClick = jest.fn().mockResolvedValue( undefined );
		await withStubbedLocation( async () => {
			render(
				<Button href="/wp-admin/admin.php?page=next" onClick={ onClick }>
					Save
				</Button>
			);

			await clickButton();
			expect( onClick ).toHaveBeenCalled();
			expect( window.location.href ).toBe( '/wp-admin/admin.php?page=next' );
		} );
	} );

	it( 'hands the click event to a combined href and onClick handler', async () => {
		const onClick = jest.fn();
		await withStubbedLocation( async () => {
			render(
				<Button href="/wp-admin/admin.php?page=next" onClick={ onClick }>
					Save
				</Button>
			);

			await clickButton();
			expect( onClick ).toHaveBeenCalledWith( expect.objectContaining( { type: 'click' } ) );
		} );
	} );

	it( 'runs an onClick with no href without touching navigation', async () => {
		const onClick = jest.fn();
		await withStubbedLocation( async () => {
			render( <Button onClick={ onClick }>Save</Button> );

			await clickButton();
			expect( onClick ).toHaveBeenCalled();
			expect( window.location.href ).toBe( '' );
		} );
	} );

	it( 'drops a javascript: href and still runs the onClick', async () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const onClick = jest.fn().mockResolvedValue( undefined );
		await withStubbedLocation( async () => {
			// eslint-disable-next-line no-script-url
			render(
				<Button href="javascript:alert(1)" onClick={ onClick }>
					Save
				</Button>
			);

			await clickButton();
			expect( onClick ).toHaveBeenCalled();
			expect( window.location.href ).toBe( '' );
		} );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'javascript:' ) );
		warn.mockRestore();
	} );
} );

describe( 'Button with a javascript: href alone', () => {
	const link = () => screen.getByText( 'Save' ).closest( 'a' );

	it( 'renders no link', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		render( <Button href="JavaScript:alert(1)">Save</Button> );

		expect( link() ).toBeNull();
		warn.mockRestore();
	} );

	it( 'sees through interleaved control characters', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		render( <Button href={ 'java\nscript:alert(1)' }>Save</Button> );

		expect( link() ).toBeNull();
		warn.mockRestore();
	} );

	it( 'leaves an ordinary href alone', () => {
		render( <Button href="/wp-admin/admin.php?page=next">Save</Button> );
		expect( link() ).toHaveAttribute( 'href', '/wp-admin/admin.php?page=next' );
	} );
} );

// `loading` is this component's own prop name; the core Button expresses the same
// state as `isBusy`. Passing `loading` straight through lands it on the DOM node,
// which React rejects with an unknown-prop warning.
describe( 'Button loading prop', () => {
	const button = () => screen.getByRole( 'button', { name: 'Save' } );

	it( 'renders the core busy state without putting `loading` on the DOM node', () => {
		const error = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		render( <Button loading>Save</Button> );

		expect( button() ).toHaveClass( 'is-busy' );
		expect( button() ).not.toHaveAttribute( 'loading' );
		expect( error ).not.toHaveBeenCalled();
		error.mockRestore();
	} );

	it( 'leaves the button idle without the prop', () => {
		render( <Button>Save</Button> );

		expect( button() ).not.toHaveClass( 'is-busy' );
	} );
} );

// A ref on a plain function component is dropped with a warning, so the wizard
// screens that measure or focus a button need it forwarded to the DOM node.
describe( 'Button ref', () => {
	it( 'forwards the ref to the rendered button', () => {
		const error = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		const ref = createRef();
		render( <Button ref={ ref }>Save</Button> );

		expect( ref.current ).toBe( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( error ).not.toHaveBeenCalled();
		error.mockRestore();
	} );
} );
