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
