/**
 * Agent Access Admin JavaScript
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initCreateButton();
		initCopyButtons();
		initRevokeButton();
		initAdminCreateButtons();
		initAdminRevokeButtons();
	});

	/**
	 * Create connection via AJAX.
	 */
	function initCreateButton() {
		var btn = document.getElementById('agent-access-create-btn');
		if (!btn) return;

		btn.addEventListener('click', function () {
			btn.disabled = true;
			btn.textContent = agentAccess.creating_text;

			var xhr = new XMLHttpRequest();
			xhr.open('POST', agentAccess.ajax_url, true);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

			xhr.onload = function () {
				var response;
				try {
					response = JSON.parse(xhr.responseText);
				} catch (e) {
					alert('Unexpected error. Please reload the page.');
					btn.disabled = false;
					btn.textContent = 'Connect Agent';
					return;
				}

				if (response.success) {
					renderCreatedState(response.data);
				} else {
					alert(response.data || 'An error occurred.');
					btn.disabled = false;
					btn.textContent = 'Connect Agent';
				}
			};

			xhr.onerror = function () {
				alert('Network error. Please try again.');
				btn.disabled = false;
				btn.textContent = 'Connect Agent';
			};

			var scopeEl = document.getElementById('agent-access-scope');
			var scope   = scopeEl ? scopeEl.value : 'posts_media';
			xhr.send(
				'action=agent_access_create' +
				'&nonce=' + encodeURIComponent(agentAccess.create_nonce) +
				'&scope=' + encodeURIComponent(scope)
			);
		});
	}

	/**
	 * Replace the card with the created state (password + JSON).
	 */
	function renderCreatedState(info) {
		var card = document.getElementById('agent-access-card');
		if (!card) return;

		var json = JSON.stringify(info, null, 4);
		var prompt = 'Save these WordPress Application Password credentials and use them to connect to my site via the WordPress REST API:\n' + json;

		card.innerHTML =
			'<p><span class="agent-access-success-icon">&#10003;</span> <strong>Connection Created!</strong></p>' +
			'<div class="agent-access-warning-box">' +
				'<strong>Important:</strong> This password will only be shown once. Copy the message below and send it to your AI agent.' +
			'</div>' +
			'<div class="agent-access-json-block">' +
				'<pre class="agent-access-json" id="agent-access-json">' + escapeHtml(prompt) + '</pre>' +
				'<button type="button" class="button agent-access-copy-btn" data-target="agent-access-json">' + agentAccess.copy_text + '</button>' +
			'</div>' +
			'<p class="agent-access-next-step">Paste this into your Agent Access chat (Telegram, WhatsApp, etc.) and your agent will handle the rest.</p>';

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
		var buttons = document.querySelectorAll('.agent-access-copy-btn');

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
		btn.textContent = agentAccess.copied_text;
		btn.classList.add('agent-access-copy-btn--copied');

		setTimeout(function () {
			btn.textContent = original;
			btn.classList.remove('agent-access-copy-btn--copied');
		}, 2000);
	}

	/**
	 * Admin: generate Agent Access credentials on behalf of another user.
	 */
	function initAdminCreateButtons() {
		var buttons = document.querySelectorAll('.agent-access-admin-create-btn');
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var userId      = btn.getAttribute('data-user-id');
				var displayName = btn.getAttribute('data-display-name') || 'this user';

				btn.disabled    = true;
				btn.textContent = agentAccess.creating_text;

				var xhr = new XMLHttpRequest();
				xhr.open('POST', agentAccess.ajax_url, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

				xhr.onload = function () {
					var response;
					try {
						response = JSON.parse(xhr.responseText);
					} catch (e) {
						alert('Unexpected error. Please reload the page.');
						btn.disabled    = false;
						btn.textContent = 'Connect agent for ' + displayName;
						return;
					}

					if (response.success) {
						renderAdminCreatedState(userId, displayName, response.data);
					} else {
						alert(response.data || 'An error occurred.');
						btn.disabled    = false;
						btn.textContent = 'Connect agent for ' + displayName;
					}
				};

				xhr.onerror = function () {
					alert('Network error. Please try again.');
					btn.disabled    = false;
					btn.textContent = 'Connect agent for ' + displayName;
				};

				var scopeEl = document.querySelector('#agent-access-admin-card select[name="scope"]');
				var scope   = scopeEl ? scopeEl.value : 'posts_media';
				xhr.send(
					'action=agent_access_admin_create' +
					'&nonce=' + encodeURIComponent(agentAccess.admin_create_nonce) +
					'&user_id=' + encodeURIComponent(userId) +
					'&scope=' + encodeURIComponent(scope)
				);
			});
		});
	}

	/**
	 * Replace the admin card with the created-state credential display.
	 */
	function renderAdminCreatedState(userId, displayName, info) {
		var card = document.getElementById('agent-access-admin-card');
		if (!card) return;

		var json   = JSON.stringify(info, null, 4);
		var prompt = 'Save these WordPress Application Password credentials and use them to connect to this site via the WordPress REST API:\n' + json;
		var uniqueId = 'agent-access-admin-json-' + userId;

		card.innerHTML =
			'<p><span class="agent-access-success-icon">&#10003;</span> <strong>Agent Access credentials generated for ' + escapeHtml(displayName) + '!</strong></p>' +
			'<div class="agent-access-warning-box">' +
				'<strong>Important:</strong> This password will only be shown once. Copy and share these credentials with ' + escapeHtml(displayName) + ' or their agent.' +
			'</div>' +
			'<div class="agent-access-json-block">' +
				'<pre class="agent-access-json" id="' + uniqueId + '">' + escapeHtml(prompt) + '</pre>' +
				'<button type="button" class="button agent-access-copy-btn" data-target="' + uniqueId + '">' + agentAccess.copy_text + '</button>' +
			'</div>';

		initCopyButtons();
	}

	/**
	 * Admin: revoke another user's agent connection.
	 */
	function initAdminRevokeButtons() {
		var buttons = document.querySelectorAll('.agent-access-admin-revoke-btn');
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!confirm(agentAccess.confirm_admin_msg)) return;

				var userId   = btn.getAttribute('data-user-id');
				btn.disabled = true;
				btn.textContent = agentAccess.revoking_text;

				var xhr = new XMLHttpRequest();
				xhr.open('POST', agentAccess.ajax_url, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

				xhr.onload = function () {
					var response;
					try {
						response = JSON.parse(xhr.responseText);
					} catch (e) {
						alert('Unexpected error. Please reload the page.');
						btn.disabled    = false;
						btn.textContent = 'Revoke Connection';
						return;
					}

					if (response.success) {
						window.location.reload();
					} else {
						alert(response.data || 'An error occurred.');
						btn.disabled    = false;
						btn.textContent = 'Revoke Connection';
					}
				};

				xhr.onerror = function () {
					alert('Network error. Please try again.');
					btn.disabled    = false;
					btn.textContent = 'Revoke Connection';
				};

				xhr.send(
					'action=agent_access_admin_revoke' +
					'&nonce=' + encodeURIComponent(agentAccess.admin_revoke_nonce) +
					'&user_id=' + encodeURIComponent(userId)
				);
			});
		});
	}

	/**
	 * Revoke button with confirmation and AJAX.
	 */
	function initRevokeButton() {
		var btn = document.getElementById('agent-access-revoke-btn');
		if (!btn) return;

		btn.addEventListener('click', function () {
			if (!confirm(agentAccess.confirm_msg)) return;

			btn.disabled = true;
			btn.textContent = agentAccess.revoking_text;

			var xhr = new XMLHttpRequest();
			xhr.open('POST', agentAccess.ajax_url, true);
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

			xhr.send('action=agent_access_revoke&nonce=' + encodeURIComponent(agentAccess.revoke_nonce));
		});
	}
})();
