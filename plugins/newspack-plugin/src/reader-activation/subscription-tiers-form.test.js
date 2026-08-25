import init from './subscription-tiers-form';

/**
 * Build a tiers form the way Subscriptions_Tiers::render_form() does.
 *
 * @param {Object}  options                Form options.
 * @param {boolean} options.flatIsCurrent  Whether the flat tier wears the "current" badge.
 * @param {boolean} options.flatIsSelected Whether the flat tier starts out checked.
 * @param {string}  options.seatsValue     Initial seats value.
 * @param {string}  options.originalValue  Seats the reader already pays for, if any.
 * @param {number}  options.seatsFloor     Seats already in use, which the field cannot go below.
 */
function renderForm( { flatIsCurrent = false, flatIsSelected = true, seatsValue = '3', originalValue = '', seatsFloor = 0 } = {} ) {
	const hidden = flatIsSelected ? ' hidden' : '';
	const disabled = flatIsSelected ? ' disabled' : '';
	document.body.innerHTML = `
		<form class="newspack__subscription-tiers__form">
			<label class="newspack-ui__input-card${ flatIsCurrent ? ' current' : '' }">
				<input type="radio" name="product_id" value="311"${ flatIsSelected ? ' checked' : '' }>
			</label>
			<label class="newspack-ui__input-card${ flatIsCurrent ? '' : ' current' }">
				<input type="radio" name="product_id" value="312"${ flatIsSelected ? '' : ' checked' } data-per-seat="1" data-seats-min="3" data-seats-max="6">
			</label>
			<p class="newspack__subscription-tiers__seats" data-seats-floor="${ seatsFloor }"${ hidden }>
				<label for="newspack-group-seats-item-554">Number of team seats</label>
				<input type="number" name="quantity" id="newspack-group-seats-item-554" step="1" min="3" value="${ seatsValue }" data-original-value="${ originalValue }"${ disabled }>
			</p>
			<button type="submit">Change Subscription</button>
			<button type="button" class="newspack-ui__modal__cancel">Cancel</button>
		</form>
	`;
	init();
	return {
		seats: document.querySelector( '.newspack__subscription-tiers__seats input[name="quantity"]' ),
		field: document.querySelector( '.newspack__subscription-tiers__seats' ),
		flat: document.querySelector( 'input[value="311"]' ),
		perSeat: document.querySelector( 'input[value="312"]' ),
		submit: document.querySelector( 'button[type="submit"]' ),
	};
}

/**
 * Check one tier's radio and fire the event the form listens for.
 *
 * @param {HTMLInputElement} radio The radio to check.
 */
function selectTier( radio ) {
	document.querySelectorAll( 'input[name="product_id"]' ).forEach( input => ( input.checked = input === radio ) );
	radio.dispatchEvent( new Event( 'change' ) );
}

describe( 'subscription tiers form seats field', () => {
	it( 'hides and disables the seats field while a flat tier is selected', () => {
		const { field, seats } = renderForm();

		// A disabled input is not submitted, so the flat tier carries no seat count.
		expect( field.hidden ).toBe( true );
		expect( seats.disabled ).toBe( true );
	} );

	it( 'shows the field and applies the tier bounds when a per-seat tier is picked', () => {
		const { field, seats, perSeat } = renderForm();

		selectTier( perSeat );

		expect( field.hidden ).toBe( false );
		expect( seats.disabled ).toBe( false );
		expect( seats.getAttribute( 'min' ) ).toBe( '3' );
		expect( seats.getAttribute( 'max' ) ).toBe( '6' );
	} );

	it( 'hides the field again when the reader goes back to a flat tier', () => {
		const { field, seats, flat, perSeat } = renderForm();

		selectTier( perSeat );
		selectTier( flat );

		expect( field.hidden ).toBe( true );
		expect( seats.disabled ).toBe( true );
	} );

	it( 'clamps a seat count the newly chosen tier cannot sell', () => {
		const { seats, perSeat } = renderForm( { seatsValue: '9' } );

		selectTier( perSeat );

		expect( seats.value ).toBe( '6' );
	} );

	it( 'drops the max attribute for a tier with no ceiling', () => {
		const { seats, perSeat } = renderForm();
		perSeat.dataset.seatsMax = '';

		selectTier( perSeat );

		expect( seats.hasAttribute( 'max' ) ).toBe( false );
	} );

	it( 'leaves the reader mid-edit alone: typing is not clamped', () => {
		const { seats } = renderForm( { flatIsSelected: false } );

		seats.value = '1';
		seats.dispatchEvent( new Event( 'input' ) );

		expect( seats.value ).toBe( '1' );
	} );

	it( 'treats a changed seat count on the current tier as a real change', () => {
		const { seats, submit } = renderForm( {
			flatIsSelected: false,
			seatsValue: '4',
			originalValue: '4',
		} );

		expect( submit.disabled ).toBe( true );

		seats.value = '5';
		seats.dispatchEvent( new Event( 'input' ) );

		expect( submit.disabled ).toBe( false );
	} );

	it( 'keeps submit disabled when neither the tier nor the seats changed', () => {
		const { seats, submit } = renderForm( {
			flatIsSelected: false,
			seatsValue: '4',
			originalValue: '4',
		} );

		seats.dispatchEvent( new Event( 'input' ) );

		expect( submit.disabled ).toBe( true );
	} );

	it( 'will not let the field go below the seats already in use', () => {
		// The per-seat tier sells from 3; six people are already seated.
		const { seats, flat, perSeat } = renderForm( { flatIsSelected: false, seatsValue: '7', seatsFloor: 6 } );

		expect( seats.min ).toBe( '6' );

		// The floor belongs to the group, so a round trip through another tier and
		// back leaves it in place.
		selectTier( flat );
		selectTier( perSeat );

		expect( seats.min ).toBe( '6' );
		expect( seats.value ).toBe( '6' );
	} );
} );
