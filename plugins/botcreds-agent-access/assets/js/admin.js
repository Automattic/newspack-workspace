/**
 * ClawPress Admin JavaScript
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initCopyButtons();
		initRevokeButton();
	});

	/**
	 * Copy-to-clipboard buttons.
	 */
	function initCopyButtons() {
		var buttons = document.querySelectorAll('.clawpress-copy-btn');

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var targetId = btn.getAttribute('data-target');
				var target = document.getElementById(targetId);

				if (!target) {
					return;
				}

				var text = target.textContent;

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () {
						showCopied(btn);
					});
				} else {
					// Fallback for older browsers
					var textarea = document.createElement('textarea');
					textarea.value = text;
					textarea.style.position = 'fixed';
					textarea.style.opacity = '0';
					document.body.appendChild(textarea);
					textarea.select();
					document.execCommand('copy');
					document.body.removeChild(textarea);
					showCopied(btn);
				}
			});
		});
	}

	/**
	 * Show "Copied!" feedback on a button.
	 *
	 * @param {HTMLElement} btn
	 */
	function showCopied(btn) {
		var original = btn.textContent;
		btn.textContent = clawpress.copied_text;
		btn.classList.add('clawpress-copy-btn--copied');

		setTimeout(function () {
			btn.textContent = original;
			btn.classList.remove('clawpress-copy-btn--copied');
		}, 2000);
	}

	/**
	 * Revoke button with confirmation and AJAX.
	 */
	function initRevokeButton() {
		var btn = document.getElementById('clawpress-revoke-btn');

		if (!btn) {
			return;
		}

		btn.addEventListener('click', function () {
			if (!confirm(clawpress.confirm_msg)) {
				return;
			}

			btn.disabled = true;
			btn.textContent = clawpress.revoking_text;

			var xhr = new XMLHttpRequest();
			xhr.open('POST', clawpress.ajax_url, true);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

			xhr.onload = function () {
				var response;
				try {
					response = JSON.parse(xhr.responseText);
				} catch (e) {
					alert('Unexpected error. Please reload the page.');
					btn.disabled = false;
					btn.textContent = clawpress.copy_text;
					return;
				}

				if (response.success) {
					window.location.reload();
				} else {
					alert(response.data || 'An error occurred.');
					btn.disabled = false;
					btn.textContent = clawpress.copy_text;
				}
			};

			xhr.onerror = function () {
				alert('Network error. Please try again.');
				btn.disabled = false;
				btn.textContent = clawpress.copy_text;
			};

			xhr.send('action=clawpress_revoke&nonce=' + encodeURIComponent(clawpress.revoke_nonce));
		});
	}
})();
