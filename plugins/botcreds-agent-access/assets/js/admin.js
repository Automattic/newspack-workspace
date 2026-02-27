/**
 * ClawPress Admin JavaScript
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initCreateButton();
		initCopyButtons();
		initRevokeButton();
	});

	/**
	 * Create connection via AJAX.
	 */
	function initCreateButton() {
		var btn = document.getElementById('clawpress-create-btn');
		if (!btn) return;

		btn.addEventListener('click', function () {
			btn.disabled = true;
			btn.textContent = clawpress.creating_text;

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
					btn.textContent = 'Connect OpenClaw';
					return;
				}

				if (response.success) {
					renderCreatedState(response.data);
				} else {
					alert(response.data || 'An error occurred.');
					btn.disabled = false;
					btn.textContent = 'Connect OpenClaw';
				}
			};

			xhr.onerror = function () {
				alert('Network error. Please try again.');
				btn.disabled = false;
				btn.textContent = 'Connect OpenClaw';
			};

			xhr.send('action=clawpress_create&nonce=' + encodeURIComponent(clawpress.create_nonce));
		});
	}

	/**
	 * Replace the card with the created state (password + JSON).
	 */
	function renderCreatedState(info) {
		var card = document.getElementById('clawpress-card');
		if (!card) return;

		var json = JSON.stringify(info, null, 4);
		var prompt = 'Save these WordPress Application Password credentials and use them to connect to my site via the WordPress REST API:\n' + json;

		card.innerHTML =
			'<p><span class="clawpress-success-icon">&#10003;</span> <strong>Connection Created!</strong></p>' +
			'<div class="clawpress-warning-box">' +
				'<strong>Important:</strong> This password will only be shown once. Copy the message below and send it to OpenClaw.' +
			'</div>' +
			'<div class="clawpress-json-block">' +
				'<pre class="clawpress-json" id="clawpress-json">' + escapeHtml(prompt) + '</pre>' +
				'<button type="button" class="button clawpress-copy-btn" data-target="clawpress-json">' + clawpress.copy_text + '</button>' +
			'</div>' +
			'<p class="clawpress-next-step">Paste this into your OpenClaw chat (Telegram, WhatsApp, etc.) and your agent will handle the rest.</p>';

		// Re-bind copy button
		initCopyButtons();
	}

	/**
	 * Escape HTML entities.
	 */
	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	/**
	 * Copy-to-clipboard buttons.
	 */
	function initCopyButtons() {
		var buttons = document.querySelectorAll('.clawpress-copy-btn');

		buttons.forEach(function (btn) {
			// Remove old listeners by cloning
			var newBtn = btn.cloneNode(true);
			btn.parentNode.replaceChild(newBtn, btn);

			newBtn.addEventListener('click', function () {
				var targetId = newBtn.getAttribute('data-target');
				var target = document.getElementById(targetId);

				if (!target) return;

				var text = target.textContent;

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () {
						showCopied(newBtn);
					});
				} else {
					var textarea = document.createElement('textarea');
					textarea.value = text;
					textarea.style.position = 'fixed';
					textarea.style.opacity = '0';
					document.body.appendChild(textarea);
					textarea.select();
					document.execCommand('copy');
					document.body.removeChild(textarea);
					showCopied(newBtn);
				}
			});
		});
	}

	/**
	 * Show "Copied!" feedback on a button.
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
		if (!btn) return;

		btn.addEventListener('click', function () {
			if (!confirm(clawpress.confirm_msg)) return;

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
					btn.textContent = 'Revoke Connection';
					return;
				}

				if (response.success) {
					window.location.reload();
				} else {
					alert(response.data || 'An error occurred.');
					btn.disabled = false;
					btn.textContent = 'Revoke Connection';
				}
			};

			xhr.onerror = function () {
				alert('Network error. Please try again.');
				btn.disabled = false;
				btn.textContent = 'Revoke Connection';
			};

			xhr.send('action=clawpress_revoke&nonce=' + encodeURIComponent(clawpress.revoke_nonce));
		});
	}
})();
