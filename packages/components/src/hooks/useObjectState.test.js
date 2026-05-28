import React from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';

import useObjectState from './useObjectState';

const INIT_STATE = { name: 'Foo', widgets: [ 1 ], attributes: { bar: 0, baz: 1 } };
const TestComponent = () => {
	const [ state, updateState ] = useObjectState( INIT_STATE );
	return (
		<div>
			<input placeholder="state" onChange={ () => {} } value={ JSON.stringify( state ) } />
			<button onClick={ () => updateState( { widgets: [] } ) }>Remove widgets</button>
			<button onClick={ () => updateState( { widgets: [ 1 ] } ) }>Add widget</button>
			<button onClick={ () => updateState( { attributes: { bar: 2 } } ) }>Nested update</button>
			<input type="text" value={ state.name } onChange={ e => updateState( { name: e.target.value } ) } placeholder="name" />
		</div>
	);
};

describe( 'useObjectState', () => {
	const getState = () => JSON.parse( screen.getByPlaceholderText( 'state' ).getAttribute( 'value' ) );

	beforeEach( () => {
		render( <TestComponent /> );
	} );

	it( 'updates arrays', () => {
		expect( getState() ).toStrictEqual( INIT_STATE );
		act( () => screen.getByText( 'Remove widgets' ).click() );
		expect( getState() ).toMatchObject( { widgets: [] } );
		act( () => screen.getByText( 'Add widget' ).click() );
		expect( getState() ).toMatchObject( { widgets: [ 1 ] } );
	} );
	it( 'updates a simple value', () => {
		act( () =>
			fireEvent.change( screen.getByPlaceholderText( 'name' ), {
				target: { value: 'Ramon' },
			} )
		);
		expect( getState() ).toMatchObject( { name: 'Ramon' } );
	} );
	it( 'updates a nested object', () => {
		act( () => screen.getByText( 'Nested update' ).click() );
		expect( getState() ).toMatchObject( {
			attributes: { bar: 2, baz: 1 },
		} );
	} );
} );
