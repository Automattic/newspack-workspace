/**
 * Internal dependencies
 */
import { openNewslettersSignupModal, refreshNewslettersSignupModal } from './newsletters-modal';

import { domReady } from '../utils';

import './newsletters-form.js';

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( readerActivation => {
	domReady( function () {
		/** Expose the openNewslettersSignupModal function to the RAS scope */
		readerActivation._openNewslettersSignupModal = openNewslettersSignupModal;
		readerActivation.refreshNewslettersSignupModal = refreshNewslettersSignupModal;
	} );
} );
