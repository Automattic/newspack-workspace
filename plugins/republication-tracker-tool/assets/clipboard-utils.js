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
	 * Start of the handout's tracking code: the pixel from
	 * create_tracking_pixel_markup(), which tests/test-tracking-pixel-markup.php
	 * pins to this exact opening. The footer puts the attribution first and the
	 * tracking last, so everything from the last match onward counts as tracking.
	 */
	trackingAnchor: '<img id="republication-tracker-tool-source"',

	/**
	 * Wrap the handout's tracking code in a Custom HTML block.
	 *
	 * The block editor strips scripts from pasted HTML, but keeps pasted
	 * block markup as it is. Any block markup in a paste sends the whole paste
	 * through the block parser, though, so the story lands in one Classic block
	 * that Convert to blocks turns into ordinary blocks. A pixel with nothing
	 * after it survives an ordinary paste, so it isn't worth that cost.
	 *
	 * @param {string} text - Handout as shown in the Republish textarea
	 * @returns {string|null} - Wrapped handout, or null when there is no pixel or nothing after it
	 */
	wrapTracking(text) {
		const start = text.lastIndexOf(this.trackingAnchor);
		if (start === -1) {
			return null;
		}
		const pixelEnd = text.indexOf('>', start);
		if (pixelEnd === -1 || !text.slice(pixelEnd + 1).trim()) {
			return null;
		}
		return text.slice(0, start) + '\n<!-- wp:html -->\n' + text.slice(start) + '\n<!-- /wp:html -->\n';
	},

	/**
	 * Copy a republish handout as HTML and as plain text.
	 *
	 * Editors that read HTML get the wrapped tracking: the block editor keeps
	 * it, visual editors outside WordPress may drop the scripts, and a classic
	 * editor's Visual tab loses the story's paragraphs. Safari strips comments
	 * and scripts from the HTML on its way to the clipboard, so from Safari
	 * those editors get the pixel alone. The plain text stays exactly as
	 * shown, because a classic editor's Code tab reads it, and block markup
	 * there would stop WordPress adding paragraph tags.
	 * The plain text goes alone when the handout has no tracking beyond the
	 * pixel, or when the browser lacks ClipboardItem or refuses the HTML copy.
	 *
	 * @param {string} text - Handout as shown in the Republish textarea
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
			console.warn('Failed to copy HTML, copying plain text instead:', err);
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
