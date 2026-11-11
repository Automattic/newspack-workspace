/**
 * End Test modal: recommends the version to keep, describes each option with
 * its rate once, and ends the test with the chosen version.
 */

import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import EndTestModal from './end-test-modal';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Pinned so the en-US expectations below hold on a host in any locale.
beforeEach( () => {
	document.documentElement.lang = 'en-US';
} );

const test = {
	test_id: 'donate',
	name: 'Donate banner',
	variants: {
		a: { id: 1, title: 'Donate banner: one-time ask', status: 'publish' },
		b: { id: 2, title: 'Donate banner: monthly ask', status: 'publish' },
	},
};

const stats = ( { label = 'reliable', young = false } = {} ) => ( {
	source: { state: 'ok' },
	variants: { a: { rate: 1.9, lift: null }, b: { rate: 2.69, lift: 41.6 } },
	comparisons: { b: { confidence: 99.1, label } },
	flags: { young_test: young },
} );

const option = name => screen.getByRole( 'radio', { name } );
const descriptionOf = radio => document.getElementById( radio.getAttribute( 'aria-describedby' ) ).textContent;

describe( 'EndTestModal', () => {
	beforeEach( () => jest.clearAllMocks() );

	it( 'recommends and preselects a reliable winner, focusing it, with each rate once', () => {
		render( <EndTestModal test={ test } stats={ stats() } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		const keepB = option( 'Keep B (Recommended)' );
		expect( keepB ).toBeChecked();
		expect( keepB ).toHaveFocus();
		expect( descriptionOf( keepB ) ).toBe( 'Donate banner: monthly ask. The reliable winner: 2.69% converted, against 1.90% for A.' );
		expect( descriptionOf( option( 'Keep A' ) ) ).toBe( 'Donate banner: one-time ask. 1.90% converted.' );
		expect( screen.getByText( /^Choose which version of “Donate banner” to keep\./ ) ).toBeInTheDocument();
	} );

	it( 'recommends A, with the reason, when there is no reliable winner yet', () => {
		render( <EndTestModal test={ test } stats={ stats( { young: true } ) } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		const keepA = option( 'Keep A (Recommended)' );
		expect( keepA ).toBeChecked();
		expect( descriptionOf( keepA ) ).toBe(
			"Donate banner: one-time ask. 1.90% converted. There's no reliable winner yet, so keeping the original is the safer choice."
		);
		expect( descriptionOf( option( 'Keep B' ) ) ).toBe( 'Donate banner: monthly ask. 2.69% converted.' );
	} );

	it( 'describes each version by title alone when there are no results', () => {
		render( <EndTestModal test={ test } stats={ null } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		expect( descriptionOf( option( 'Keep A (Recommended)' ) ) ).toBe( 'Donate banner: one-time ask' );
		expect( descriptionOf( option( 'Keep B' ) ) ).toBe( 'Donate banner: monthly ask' );
	} );

	it( 'moves the selection to the recommendation when results arrive', () => {
		const { rerender } = render( <EndTestModal test={ test } stats={ null } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		expect( option( 'Keep A (Recommended)' ) ).toBeChecked();
		rerender( <EndTestModal test={ test } stats={ stats() } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		expect( option( 'Keep B (Recommended)' ) ).toBeChecked();
	} );

	it( 'keeps the version the user picked when results arrive', () => {
		const { rerender } = render( <EndTestModal test={ test } stats={ null } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		fireEvent.click( option( 'Keep B' ) );
		rerender( <EndTestModal test={ test } stats={ stats( { young: true } ) } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		expect( option( 'Keep B' ) ).toBeChecked();
	} );

	it( 'ends the test with the version chosen', async () => {
		const ended = { ...test, status: 'ended', kept: 'a' };
		apiFetch.mockResolvedValue( ended );
		const onEnded = jest.fn();
		render( <EndTestModal test={ test } stats={ stats() } onEnded={ onEnded } onClose={ jest.fn() } /> );
		fireEvent.click( option( 'Keep A' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'End Test' } ) );
		await waitFor( () => expect( onEnded ).toHaveBeenCalledWith( ended ) );
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/donate/end', method: 'POST', data: { keep: 'a' } } );
	} );

	it( 'promises nothing is published when the test is not running', () => {
		const draft = { ...test, variants: { a: { ...test.variants.a, status: 'publish' }, b: { ...test.variants.b, status: 'draft' } } };
		render( <EndTestModal test={ draft } stats={ null } onEnded={ jest.fn() } onClose={ jest.fn() } /> );
		expect(
			screen.getByText(
				'“Donate banner” isn’t running. Choose which version to keep. It stays as it is, and the other version moves to draft.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( /stays published/ ) ).not.toBeInTheDocument();
		expect( option( 'Keep A (Recommended)' ) ).toBeChecked();
	} );

	it( 'ends a test with one version without asking which to keep', async () => {
		apiFetch.mockResolvedValueOnce( { test_id: 'donate', status: 'ended' } );
		const onEnded = jest.fn();
		const onlyB = { ...test, variants: { b: { ...test.variants.b, status: 'draft' } } };
		render( <EndTestModal test={ onlyB } stats={ null } onEnded={ onEnded } onClose={ jest.fn() } /> );
		expect( screen.getByText( 'Version B stays as it is and leaves “Donate banner”. The test moves to Ended.' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'radio' ) ).not.toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'End Test' } ) );
		await waitFor( () => expect( onEnded ).toHaveBeenCalled() );
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/donate/end', method: 'POST', data: { keep: 'b' } } );
	} );

	it( 'shows the error and stays open when ending fails', async () => {
		apiFetch.mockRejectedValue( { message: 'This test has already ended.' } );
		const onEnded = jest.fn();
		render( <EndTestModal test={ test } stats={ stats() } onEnded={ onEnded } onClose={ jest.fn() } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'End Test' } ) );
		// In the dialog: the page's live region repeats the text.
		expect( await within( screen.getByRole( 'dialog' ) ).findByText( 'This test has already ended.' ) ).toBeInTheDocument();
		expect( onEnded ).not.toHaveBeenCalled();
		expect( screen.getByRole( 'button', { name: 'End Test' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
	} );
} );
