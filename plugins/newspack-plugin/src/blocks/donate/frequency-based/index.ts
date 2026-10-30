/**
 * Internal dependencies
 */
import './style.scss';

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
function domReady( callback: () => void ): void {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if (
		document.readyState === 'complete' || // DOMContentLoaded + Images/Styles/etc loaded, so we call directly.
		document.readyState === 'interactive' // DOMContentLoaded fires at this point, so we call directly.
	) {
		return void callback();
	}
	// DOMContentLoaded has not fired yet, delay callback until then.
	document.addEventListener( 'DOMContentLoaded', callback );
}

const handleOtherValue = ( container: HTMLElement ) => {
	const frequencies = container.querySelectorAll( '.tiers' );
	if ( ! frequencies?.length ) {
		return;
	}
	const frequencyInputs = container.querySelectorAll( 'input[name="donation_frequency"]' );
	const submitButton = container.querySelector( 'button[type="submit"]' );
	frequencies.forEach( frequency => {
		const tiers = frequency.querySelectorAll( 'input[type="radio"]' );
		const input = < HTMLInputElement >frequency.querySelector( '.money-input input' );
		if ( ! tiers?.length || ! input ) {
			return;
		}
		const originalValue = input.getAttribute( 'value' );
		const reset = () => {
			input.value = originalValue || '';
		};
		const toggleSubmit = () => {
			const checkedTier = < HTMLInputElement >frequency.querySelector( 'input[type="radio"]:checked' );
			if ( ! checkedTier ) {
				return;
			}
			if ( checkedTier.value === 'other' && ! input.value ) {
				submitButton?.setAttribute( 'disabled', 'disabled' );
			} else {
				submitButton?.removeAttribute( 'disabled' );
			}
		};
		input.addEventListener( 'keyup', toggleSubmit );
		toggleSubmit();
		tiers.forEach( tierInput => {
			tierInput.addEventListener( 'change', reset );
			tierInput.addEventListener( 'change', toggleSubmit );
		} );
		frequencyInputs.forEach( frequencyInput => {
			frequencyInput.addEventListener( 'change', reset );
			frequencyInput.addEventListener( 'change', toggleSubmit );
		} );
	} );
};

const addAccessibleTabs = ( container: HTMLElement ) => {
	// Get the block's tabs, panels, and radio buttons associated with donation frequency.
	const tabList = container.querySelectorAll( 'div[role="tablist"] [role="tab"]' ) as NodeListOf< HTMLElement >;
	const panels = container.querySelectorAll( 'div[role="tabpanel"]' ) as NodeListOf< HTMLElement >;
	const radioButtons = container.querySelectorAll( 'input[type="radio"][name="donation_frequency"]' ) as NodeListOf< HTMLInputElement >;
	// Figure out which radio button is currently selected.
	const checkedRadioId = Array.from( radioButtons ).find( ( radio: HTMLInputElement ) => radio.checked )?.id || null;

	// Set the tab associated to the radio button to selected.
	if ( checkedRadioId ) {
		const selectedTabId = `tab-${ checkedRadioId }`;
		document.getElementById( selectedTabId )?.setAttribute( 'aria-selected', 'true' );
	}

	// Add a click event listener to each tab.
	tabList.forEach( ( tab: HTMLElement ) => {
		tab.addEventListener( 'click', function () {
			selectTab( tab, tabList, radioButtons, panels );
		} );
	} );
};

const selectTab = (
	tab: HTMLElement,
	tabList: NodeListOf< HTMLElement >,
	radioButtons: NodeListOf< HTMLInputElement >,
	panels: NodeListOf< HTMLElement >
) => {
	// Loop through tabs and set them as selected or not selected:
	tabList.forEach( ( thisTab: HTMLElement ) => {
		if ( tab === thisTab ) {
			thisTab.setAttribute( 'aria-selected', 'true' );
			thisTab.classList.add( 'wpbnbd__button--active' );
		} else {
			thisTab.setAttribute( 'aria-selected', 'false' );
			thisTab.classList.remove( 'wpbnbd__button--active' );
		}
	} );

	// Update the underlying radio button.
	const tabId = tab.id || '';
	const frequencyId = tabId.replace( 'tab-', '' );
	radioButtons.forEach( ( radio: HTMLInputElement ) => {
		radio.checked = frequencyId === radio.id;
	} );

	// Loop through the panels and set tabindex 0 on the selected panel; remove it from others.
	panels.forEach( ( panel: HTMLElement ) => {
		if ( tab.getAttribute( 'aria-controls' ) === panel.id ) {
			panel.setAttribute( 'tabindex', '0' );
		} else {
			panel.removeAttribute( 'tabindex' );
		}
	} );
};

export const processFrequencyBasedElements = ( parentEl = document ) => {
	const elements = parentEl.querySelectorAll( '.wpbnbd--frequency-based' ) as NodeListOf< HTMLElement >;
	elements.forEach( container => {
		handleOtherValue( container );
		addAccessibleTabs( container );
	} );
};

if ( typeof window !== 'undefined' ) {
	domReady( () => processFrequencyBasedElements() );
}
