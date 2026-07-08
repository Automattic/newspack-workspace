/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ConfirmModal from './ConfirmModal';

describe( 'ConfirmModal', () => {
	it( 'renders the warning message and both buttons', () => {
		render( <ConfirmModal onContinue={ jest.fn() } onCancel={ jest.fn() } /> );
		expect( screen.getByText( 'The data for these settings may take a while to load. Continue?' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Continue' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toBeInTheDocument();
	} );

	it( 'calls onContinue when Continue is clicked', () => {
		const onContinue = jest.fn();
		render( <ConfirmModal onContinue={ onContinue } onCancel={ jest.fn() } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		expect( onContinue ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'calls onCancel when Cancel is clicked', () => {
		const onCancel = jest.fn();
		render( <ConfirmModal onContinue={ jest.fn() } onCancel={ onCancel } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
	} );
} );
