/**
 * Steps inside one modal, such as a request that turns into its result. On wide
 * screens the panel's clip-path shrinks around the next step while the steps
 * cross-fade; the transitions live in the modal styles.
 */

import { getInitialFocus } from './focus';

/**
 * A modal's steps.
 *
 * @param {HTMLElement} modal The modal element.
 * @return {HTMLElement[]} Its steps, in order.
 */
const getSteps = modal => [ ...modal.querySelectorAll( '.newspack-ui__modal__step' ) ];

/**
 * Point the dialog's name and description at a step's heading and content, so a
 * screen reader announces the step it moves to.
 *
 * @param {HTMLElement} modal    The modal element.
 * @param {HTMLElement} step     The step.
 * @param {boolean}     describe Whether to describe the dialog with the step's content.
 */
const labelWithStep = ( modal, step, describe ) => {
	const prefix = `${ modal.id || 'newspack-ui-modal' }-${ step.dataset.step }`;
	const header = step.querySelector( '.newspack-ui__modal__header' );
	const heading = header?.hidden
		? step.querySelector( '.newspack-ui__modal__content :is(h1, h2, h3, h4, h5, h6)' ) || header.querySelector( 'h2' )
		: header?.querySelector( 'h2' );
	if ( heading ) {
		heading.id = heading.id || `${ prefix }-title`;
		modal.setAttribute( 'aria-labelledby', heading.id );
	} else {
		modal.removeAttribute( 'aria-labelledby' );
	}
	const content = step.querySelector( '.newspack-ui__modal__content' );
	if ( describe && content ) {
		content.id = content.id || `${ prefix }-content`;
		modal.setAttribute( 'aria-describedby', content.id );
	} else {
		modal.removeAttribute( 'aria-describedby' );
	}
};

/**
 * Make one step the only one a reader can reach.
 *
 * @param {HTMLElement[]} steps  The modal's steps.
 * @param {HTMLElement}   active The step to show.
 */
const setActiveStep = ( steps, active ) => {
	steps.forEach( step => step.toggleAttribute( 'inert', step !== active ) );
};

/**
 * Show a step of a modal, moving focus into it.
 *
 * @param {HTMLElement} modal The modal element.
 * @param {string}      name  The step's name.
 * @return {boolean} Whether the modal has that step.
 */
export const showStep = ( modal, name ) => {
	const steps = getSteps( modal );
	const target = steps.find( step => step.dataset.step === name );
	if ( ! target ) {
		return false;
	}
	const panel = modal.querySelector( '.newspack-ui__modal__steps' );
	if ( panel ) {
		// The clip insets the full-size panel down to the next step, centered.
		panel.style.setProperty( '--newspack-ui-modal-inset-x', `${ Math.max( 0, ( panel.offsetWidth - target.offsetWidth ) / 2 ) }px` );
		panel.style.setProperty( '--newspack-ui-modal-inset-y', `${ Math.max( 0, ( panel.offsetHeight - target.offsetHeight ) / 2 ) }px` );
	}
	setActiveStep( steps, target );
	labelWithStep( modal, target, true );
	modal.dataset.step = name;
	getInitialFocus( target ).focus();
	return true;
};

/**
 * Return a modal to its first step without animating, so it opens where it starts.
 *
 * @param {HTMLElement} modal The modal element.
 */
export const resetSteps = modal => {
	const steps = getSteps( modal );
	if ( ! steps.length || modal.dataset.step === steps[ 0 ].dataset.step ) {
		return;
	}
	modal.classList.add( 'is-resetting-steps' );
	setActiveStep( steps, steps[ 0 ] );
	labelWithStep( modal, steps[ 0 ], false );
	modal.dataset.step = steps[ 0 ].dataset.step;
	const panel = modal.querySelector( '.newspack-ui__modal__steps' );
	panel?.style.removeProperty( '--newspack-ui-modal-inset-x' );
	panel?.style.removeProperty( '--newspack-ui-modal-inset-y' );
	// Apply the reset before transitions come back.
	void modal.offsetWidth;
	modal.classList.remove( 'is-resetting-steps' );
};
