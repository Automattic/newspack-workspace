import React from 'react';
import { render } from '@testing-library/react';

import ProgressBar from './';

describe( 'ProgressBar', () => {
	it( 'should render a progress indicator', () => {
		const { getByTestId } = render( <ProgressBar completed="1" total="2" /> );
		const indicator = getByTestId( 'progress-bar-indicator' );
		expect( indicator ).toHaveAttribute( 'style', 'width: 50%;' );
	} );

	it( 'should render a label', () => {
		const label = 'test label';
		const { getByText } = render( <ProgressBar completed="1" total="2" label={ label } /> );
		expect( getByText( label ) ).toBeInTheDocument();
	} );

	it( 'should render progress as a fraction', () => {
		const { getByText } = render( <ProgressBar completed="1" total="2" displayFraction /> );
		expect( getByText( '1/2' ) ).toBeInTheDocument();
	} );

	it( 'should render with both label and fraction', () => {
		const label = 'test label';
		const { getByText } = render( <ProgressBar completed="1" total="2" label={ label } displayFraction /> );
		expect( getByText( label ) ).toBeInTheDocument();
		expect( getByText( '1/2' ) ).toBeInTheDocument();
	} );

	describe( 'should calculate progress correctly', () => {
		[
			{
				props: { completed: 1, total: 2 },
				expectedWidth: 50,
			},
			{
				props: { completed: 2, total: 3 },
				expectedWidth: 67,
			},
			{
				props: { completed: 6, total: 3 },
				expectedWidth: 100,
			},
		].forEach( ( { props, expectedWidth } ) => {
			it( `with expected  progress of ${ expectedWidth }`, () => {
				const { getByTestId } = render( <ProgressBar { ...props } key={ expectedWidth } /> );
				expect( getByTestId( 'progress-bar-indicator' ) ).toHaveAttribute( 'style', `width: ${ expectedWidth }%;` );
			} );
		} );
	} );

	it( 'should handle non-numeric values in ProgressBar element', () => {
		const { getByText, getByTestId } = render( <ProgressBar completed="cats" total="dogs" displayFraction /> );
		expect( getByText( '0/0' ) ).toBeInTheDocument();
		expect( getByTestId( 'progress-bar-indicator' ) ).toHaveAttribute( 'style', 'width: 100%;' );
	} );

	it( 'should handle non-logical values in ProgressBar element', () => {
		const { getByText, getByTestId } = render( <ProgressBar completed="3" total="-1" displayFraction /> );
		expect( getByText( '0/0' ) ).toBeInTheDocument();
		expect( getByTestId( 'progress-bar-indicator' ) ).toHaveAttribute( 'style', 'width: 100%;' );
	} );
} );
