/**
 * Internal dependencies.
 */
import { DrawerContext, useDrawerContext } from './context';

/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

const Probe = () => {
	const { title } = useDrawerContext();
	return <span>{ title ? title.text : 'no title' }</span>;
};

describe( 'useDrawerContext', () => {
	it( 'returns the provided value', () => {
		render(
			<DrawerContext.Provider value={ { requestClose: () => {}, title: { id: 't1', text: 'Hello' }, setTitle: () => {} } }>
				<Probe />
			</DrawerContext.Provider>
		);
		expect( screen.getByText( 'Hello' ) ).toBeInTheDocument();
	} );

	it( 'throws outside Drawer.Root', () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		expect( () => render( <Probe /> ) ).toThrow( 'Drawer subcomponents must be rendered inside Drawer.Root.' );
		consoleError.mockRestore();
	} );
} );
