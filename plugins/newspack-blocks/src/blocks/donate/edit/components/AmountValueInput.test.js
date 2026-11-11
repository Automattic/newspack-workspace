/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AmountValueInput from './AmountValueInput';

const SITE_MINIMUM_DONATION = 20;

const AMOUNTS = {
	once: [ 9, 20, 90, 10 ],
	month: [ 7, 15, 30, 10 ],
	year: [ 84, 180, 360, 10 ],
};

// The preview shows the amounts the editor hands it, already raised to the
// block's minimum, and takes its floor from the minimum it is given. Raising
// them to the site-wide minimum would show a manual block's form differently
// than the front end renders it.
describe( 'AmountValueInput', () => {
	it.each( [
		// An amount below the site minimum: raising it would show the site minimum.
		[ 'a block minimum below the site minimum', { minimumDonation: 5 }, 5, 10 ],
		// No block minimum: reading the floor from the block would leave it empty.
		[ 'a floor the block does not store', {}, SITE_MINIMUM_DONATION, 25 ],
	] )( 'shows the given amount and floor for %s', ( label, blockMinimum, minimumDonation, amount ) => {
		const amounts = { ...AMOUNTS, month: [ 7, 15, 30, amount ] };
		render(
			<AmountValueInput
				frequencySlug="month"
				tierIndex={ 3 }
				id="amount-input"
				label="Amount"
				attributes={ { manual: true, amounts, ...blockMinimum } }
				settings={ { amounts, minimumDonation: SITE_MINIMUM_DONATION } }
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
