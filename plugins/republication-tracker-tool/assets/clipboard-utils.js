/**
 * Clipboard utility for copying text to clipboard
 *
 * uses Clipboard API.
 */
window.ClipboardUtils = {
	/**
	 * Copy text to clipboard
	 * @param {string} text - Text to copy
	 * @returns {Promise<boolean>} - Promise resolving to success status
	 */
	async copyText(text) {
		if (!navigator.clipboard) {
			console.warn('Clipboard API not available');
			return false;
		}

		try {
			await navigator.clipboard.writeText(text);
			return true;
		} catch (err) {
			console.error('Failed to copy text:', err);
			return false;
		}
	},

	/**
	 * Start of the handout's tracking code. The footer puts the attribution
	 * first and the tracking last, beginning with this pixel, and the footer
	 * ends the handout.
	 */
	trackingAnchor: '<img id="republication-tracker-tool-source"',

	/**
	 * Wrap the handout's tracking code in a Custom HTML block.
	 *
	 * The block editor strips scripts from pasted HTML, but keeps pasted
	 * block markup as it is. Only the tracking is wrapped, so the story
	 * still pastes as ordinary editable content.
	 *
	 * @param {string} text - Handout as shown in the modal
	 * @returns {string|null} - Wrapped handout, or null when no tracking is found
	 */
	wrapTracking(text) {
		const start = text.lastIndexOf(this.trackingAnchor);
		if (start === -1) {
			return null;
		}
		return text.slice(0, start) + '\n<!-- wp:html -->\n' + text.slice(start) + '\n<!-- /wp:html -->\n';
	},

	/**
	 * Copy a republish handout as HTML and as plain text.
	 *
	 * Editors that read HTML get the wrapped tracking. The plain text stays
	 * exactly as shown, because a classic editor's Code tab reads it, and
	 * block markup there would stop WordPress adding paragraph tags.
	 * Browsers without ClipboardItem get the plain text alone, as before.
	 *
	 * @param {string} text - Handout as shown in the modal
	 * @returns {Promise<boolean>} - Promise resolving to success status
	 */
	async copyHandout(text) {
		const html = this.wrapTracking(text);
		if (!html || typeof ClipboardItem === 'undefined' || !navigator.clipboard || !navigator.clipboard.write) {
			return this.copyText(text);
		}

		try {
			// No await before this call: Safari only allows the write
			// while the click is still being handled.
			await navigator.clipboard.write([
				new ClipboardItem({
					'text/html': new Blob([html], { type: 'text/html' }),
					'text/plain': new Blob([text], { type: 'text/plain' }),
				}),
			]);
			return true;
		} catch (err) {
			return this.copyText(text);
		}
	},

	/**
	 * Get text content from an element
	 * @param {string|Element} elementOrSelector - Element or selector
	 * @returns {string} - Text content
	 */
	getElementText(elementOrSelector) {
		let element;
		
		if (typeof elementOrSelector === 'string') {
			element = document.querySelector(elementOrSelector);
		} else {
			element = elementOrSelector;
		}

		if (!element) {
			return '';
		}

		const tagName = element.tagName.toLowerCase();
		if (tagName === 'input' || tagName === 'textarea') {
			return element.value || '';
		} else {
			return element.textContent || element.innerText || '';
		}
	},

	/**
	 * Copy content from an element
	 * @param {string|Element} elementOrSelector - Source element
	 * @param {Element} button - Button element for feedback
	 * @returns {Promise<boolean>} - Promise resolving to success status
	 */
	async copyFromElement(elementOrSelector, button) {
		const text = this.getElementText(elementOrSelector);
		if (!text) {
			return false;
		}

		const success = await this.copyText(text);
		
		if (success && button) {
			this.showButtonFeedback(button);
		}

		return success;
	},

	/**
	 * Copy a republish handout from an element
	 * @param {string|Element} elementOrSelector - Source element
	 * @param {Element} button - Button element for feedback
	 * @returns {Promise<boolean>} - Promise resolving to success status
	 */
	async copyHandoutFromElement(elementOrSelector, button) {
		const text = this.getElementText(elementOrSelector);
		if (!text) {
			return false;
		}

		const success = await this.copyHandout(text);

		if (success && button) {
			this.showButtonFeedback(button);
		}

		return success;
	},

	/**
	 * Show temporary "Copied!" feedback on button
	 * @param {Element} button - Button element
	 */
	showButtonFeedback(button) {
		const originalText = button.textContent || button.innerText;
		
		button.textContent = 'Copied!';
		setTimeout(() => {
			button.textContent = originalText;
		}, 2000);
	}
};
