/**
 * Republish template script.
 */

/**
 * The main script for the republish template.
 */
document.addEventListener("DOMContentLoaded", () => {
	/**
	 * Handle tab switching for format selection.
	 */
	const tabButtons = document.querySelectorAll(".republish-format-tabs__button");
	const tabContents = document.querySelectorAll(".republish-content");

	tabButtons.forEach((button) => {
		button.addEventListener("click", (event) => {
			event.preventDefault();
			const targetTab = button.getAttribute("data-tab");

			tabButtons.forEach((btn) => btn.classList.remove("republish-format-tabs__button--active"));
			tabContents.forEach((content) => content.classList.remove("republish-content--active"));

			button.classList.add("republish-format-tabs__button--active");
			const targetContent = document.querySelector(`[data-tab-content="${targetTab}"]`);
			if (targetContent) {
				targetContent.classList.add("republish-content--active");
			}

			// Show/hide main copy button based on active tab
			const mainCopyButton = document.querySelector(".republish-article__copy-button--main");
			if (mainCopyButton) {
				if (targetTab === "html") {
					mainCopyButton.classList.add("show-for-html");
				} else {
					mainCopyButton.classList.remove("show-for-html");
				}
			}
		});
	});

	/**
	 * Selects the text in the active textarea when it is focused.
	 */
	const textareas = document.querySelectorAll(".republish-content__textarea");
	textareas.forEach((textarea) => {
		textarea.addEventListener("focus", (event) => {
			event.target.select();
		});
	});

	/**
	 * Copies the text in the active textarea to the clipboard when the copy button is clicked.
	 */
	document
		.querySelector(".republish-article__copy-button")
		?.addEventListener("click", (event) => {
			event.preventDefault();

			const activeTextarea = document.querySelector(".republish-content.republish-content--active.republish-content__textarea");

			if (!activeTextarea) {
				return;
			}

			ClipboardUtils.copyFromElement(activeTextarea, event.target);
		});

	/**
	 * Handle individual field copy buttons for plain text format.
	 */
	const copyFieldButtons = document.querySelectorAll(".plain-text-field__button");
	copyFieldButtons.forEach((button) => {
		button.addEventListener("click", (event) => {
			event.preventDefault();

			const targetSelector = button.getAttribute("data-target");
			ClipboardUtils.copyFromElement(targetSelector, button);
		});
	});
});
