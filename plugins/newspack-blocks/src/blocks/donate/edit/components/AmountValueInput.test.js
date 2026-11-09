/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AmountValueInput from './AmountValueInput';

const AMOUNTS = {
	once: [ 9, 20, 90, 10 ],
	month: [ 7, 15, 30, 10 ],
	year: [ 84, 180, 360, 10 ],
};

// The preview shows the amounts the editor hands it, already raised to the
// block's minimum, and takes its floor from that same minimum. Raising them to
// the site-wide minimum would show a manual block's form differently than the
// front end renders it.
describe( 'AmountValueInput', () => {
	it.each( [
		[ 'a manual block with its own lower minimum', { minimumDonation: 5 }, 5, 10 ],
		[ 'a manual block following the site minimum', {}, 20, 25 ],
	] )( "shows the given amount for %s, with the block's minimum as its floor", ( label, blockMinimum, minimumDonation, amount ) => {
		const amounts = { ...AMOUNTS, month: [ 7, 15, 30, amount ] };
		render(
			<AmountValueInput
				frequencySlug="month"
				tierIndex={ 3 }
				id="amount-input"
				label="Amount"
				attributes={ { manual: true, amounts, ...blockMinimum } }
				settings={ { amounts, minimumDonation: 20 } }
				amounts={ amounts }
				minimumDonation={ minimumDonation }
				setAttributes={ jest.fn() }
				setSettings={ jest.fn() }
			/>
		);

		const input = screen.getByLabelText( 'Amount' );
		expect( input.value ).toBe( String( amount ) );
		expect( input.min ).toBe( String( minimumDonation ) );
	} );
} );
