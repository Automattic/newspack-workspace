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
		initAdminUpdateButtons();
		initScopeReadOnlyToggles();
	});

	/**
	 * When "Read only" is toggled on, disable and uncheck all other scope checkboxes.
	 * When toggled off, re-enable them.
	 */
	function initScopeReadOnlyToggles() {
		// Delegate so it also works for dynamically injected checklists.
		document.addEventListener('change', function (e) {
			if (!e.target.classList.contains('agent-access-scope-read-only')) return;
			var checklist = e.target.closest('.agent-access-scope-checklist');
			if (!checklist) return;
			var typeCheckboxes = checklist.querySelectorAll('.agent-access-scope-type');
			var isReadOnly = e.target.checked;
			typeCheckboxes.forEach(function (cb) {
				cb.disabled = isReadOnly;
				if (isReadOnly) {
					cb.checked = false;
				}
			});
		});
	}

	/**
	 * Collect scope data from a checklist container.
	 * Returns { scope_read_only: '1'|'', scope_types: string[] }
	 */
	function collectScopeFromChecklist(container) {
		var readOnlyEl = container ? container.querySelector('.agent-access-scope-read-only') : null;
		if (readOnlyEl && readOnlyEl.checked) {
			return { scope_read_only: '1', scope_types: [] };
		}
		var typeCheckboxes = container ? container.querySelectorAll('.agent-access-scope-type:checked') : [];
		var types = [];
		typeCheckboxes.forEach(function (cb) {
			types.push(cb.value);
		});
		return { scope_read_only: '', scope_types: types };
	}

	/**
	 * Encode scope data as a query-string fragment.
	 */
	function encodeScopeParams(scopeData) {
		var parts = [];
		if (scopeData.scope_read_only) {
			parts.push('scope_read_only=' + encodeURIComponent(scopeData.scope_read_only));
		}
		if (scopeData.scope_types && scopeData.scope_types.length > 0) {
			scopeData.scope_types.forEach(function (t) {
				parts.push('scope_types%5B%5D=' + encodeURIComponent(t));
			});
		}
		return parts.join('&');
	}

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

			var card       = document.getElementById('agent-access-card');
			var checklist  = card ? card.querySelector('.agent-access-scope-checklist') : null;
			var scopeData  = collectScopeFromChecklist(checklist);
			var rlEl       = document.getElementById('agent-access-rate-limit');
			var rl         = rlEl ? rlEl.value : 'standard';
			var cpEl       = document.getElementById('agent-access-content-policy');
			var cp         = cpEl ? cpEl.value : 'standard';
			xhr.send(
				'action=agent_access_create' +
				'&nonce=' + encodeURIComponent(agentAccess.create_nonce) +
				'&' + encodeScopeParams(scopeData) +
				'&rate_limit=' + encodeURIComponent(rl) +
				'&content_policy=' + encodeURIComponent(cp)
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

				var adminCard  = document.getElementById('agent-access-admin-card');
				var checklist  = adminCard ? adminCard.querySelector('.agent-access-scope-checklist') : null;
				var scopeData  = collectScopeFromChecklist(checklist);
				var rlEl       = document.querySelector('#agent-access-admin-card select[name="rate_limit"]');
				var rl         = rlEl ? rlEl.value : 'standard';
				var cpEl       = document.querySelector('#agent-access-admin-card select[name="content_policy"]');
				var cp         = cpEl ? cpEl.value : 'standard';
				xhr.send(
					'action=agent_access_admin_create' +
					'&nonce=' + encodeURIComponent(agentAccess.admin_create_nonce) +
					'&user_id=' + encodeURIComponent(userId) +
					'&' + encodeScopeParams(scopeData) +
					'&rate_limit=' + encodeURIComponent(rl) +
					'&content_policy=' + encodeURIComponent(cp)
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
	 * Admin: save scope/policy/rate-limit for an already-connected agent.
	 */
	function initAdminUpdateButtons() {
		var buttons = document.querySelectorAll('.agent-access-admin-update-btn');
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var userId  = btn.getAttribute('data-user-id');
				var section = btn.closest('.agent-access-settings-section') || btn.closest('td').closest('table').closest('div');

				// The update scope is rendered as a .agent-access-admin-update-scope div (checklist).
				var checklistEl = section ? section.querySelector('.agent-access-admin-update-scope') : null;
				var scopeData   = collectScopeFromChecklist(checklistEl);
				var rlEl        = section ? section.querySelector('.agent-access-admin-update-rate-limit') : null;
				var cpEl        = section ? section.querySelector('.agent-access-admin-update-content-policy') : null;

				var rl     = rlEl     ? rlEl.value     : 'standard';
				var policy = cpEl     ? cpEl.value     : 'standard';

				var statusEl = document.querySelector('.agent-access-admin-update-status[data-user-id="' + userId + '"]');

				btn.disabled    = true;
				btn.textContent = 'Saving…';
				if (statusEl) {
					statusEl.style.display = 'none';
					statusEl.textContent   = '';
				}

				var xhr = new XMLHttpRequest();
				xhr.open('POST', agentAccess.ajax_url, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

				xhr.onload = function () {
					var response;
					try {
						response = JSON.parse(xhr.responseText);
					} catch (e) {
						if (statusEl) {
							statusEl.textContent   = 'Unexpected error.';
							statusEl.style.color   = '#b32d2e';
							statusEl.style.display = 'inline';
						}
						btn.disabled    = false;
						btn.textContent = 'Save settings';
						return;
					}

					btn.disabled    = false;
					btn.textContent = 'Save settings';

					if (response.success) {
						if (statusEl) {
							statusEl.textContent   = '\u2713 Saved';
							statusEl.style.color   = '#1a7a1a';
							statusEl.style.display = 'inline';
							setTimeout(function () {
								statusEl.style.display = 'none';
							}, 3000);
						}
					} else {
						if (statusEl) {
							statusEl.textContent   = response.data || 'Error saving settings.';
							statusEl.style.color   = '#b32d2e';
							statusEl.style.display = 'inline';
						} else {
							alert(response.data || 'Error saving settings.');
						}
					}
				};

				xhr.onerror = function () {
					btn.disabled    = false;
					btn.textContent = 'Save settings';
					if (statusEl) {
						statusEl.textContent   = 'Network error.';
						statusEl.style.color   = '#b32d2e';
						statusEl.style.display = 'inline';
					} else {
						alert('Network error. Please try again.');
					}
				};

				xhr.send(
					'action=agent_access_admin_update' +
					'&nonce=' + encodeURIComponent(agentAccess.admin_update_nonce) +
					'&user_id=' + encodeURIComponent(userId) +
					'&' + encodeScopeParams(scopeData) +
					'&rate_limit=' + encodeURIComponent(rl) +
					'&content_policy=' + encodeURIComponent(policy)
				);
			});
		});
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

	// ── Approval queue: approve / reject buttons ───────────────────────
	document.querySelectorAll('.agent-access-approve-btn, .agent-access-reject-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var isApprove = btn.classList.contains('agent-access-approve-btn');
			var action    = isApprove ? 'agent_access_approve' : 'agent_access_reject';
			var label     = isApprove ? 'Approving…' : 'Rejecting…';
			var userId    = btn.getAttribute('data-user-id');
			var uuid      = btn.getAttribute('data-uuid');
			var nonce     = btn.getAttribute('data-nonce');

			btn.disabled    = true;
			btn.textContent = label;

			var xhr = new XMLHttpRequest();
			xhr.open('POST', agentAccess.ajax_url);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

			xhr.onload = function () {
				var response;
				try {
					response = JSON.parse(xhr.responseText);
				} catch (e) {
					alert('Unexpected error. Please reload the page.');
					btn.disabled    = false;
					btn.textContent = isApprove ? 'Approve' : 'Reject';
					return;
				}
				if (response.success) {
					window.location.reload();
				} else {
					alert(response.data || 'An error occurred.');
					btn.disabled    = false;
					btn.textContent = isApprove ? 'Approve' : 'Reject';
				}
			};

			xhr.onerror = function () {
				alert('Network error. Please try again.');
				btn.disabled    = false;
				btn.textContent = isApprove ? 'Approve' : 'Reject';
			};

			xhr.send(
				'action=' + action +
				'&user_id=' + encodeURIComponent(userId) +
				'&uuid=' + encodeURIComponent(uuid) +
				'&nonce=' + encodeURIComponent(nonce)
			);
		});
	});

	// ── Rollback: restore buttons ─────────────────────────────
	document.querySelectorAll('.agent-access-restore-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var snapshotId = btn.getAttribute('data-snapshot-id');
			var nonce      = btn.getAttribute('data-nonce');
			var postTitle  = btn.getAttribute('data-post-title');

			if ( ! confirm( 'Restore "' + postTitle + '" to its state before the agent edit? This cannot be undone.' ) ) {
				return;
			}

			btn.disabled    = true;
			btn.textContent = 'Restoring…';

			var xhr = new XMLHttpRequest();
			xhr.open('POST', agentAccess.ajax_url);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

			xhr.onload = function () {
				var response;
				try {
					response = JSON.parse(xhr.responseText);
				} catch (e) {
					alert('Unexpected error. Please reload the page.');
					btn.disabled    = false;
					btn.textContent = '\u21a9 Restore';
					return;
				}
				if (response.success) {
					btn.textContent = '\u2713 Restored';
					btn.style.color = '#4CAF50';
					if (response.data && response.data.edit_url) {
						var link = document.createElement('a');
						link.href      = response.data.edit_url;
						link.textContent = ' View post';
						link.target    = '_blank';
						btn.parentNode.appendChild(link);
					}
				} else {
					alert(response.data || 'An error occurred.');
					btn.disabled    = false;
					btn.textContent = '\u21a9 Restore';
				}
			};

			xhr.onerror = function () {
				alert('Network error. Please try again.');
				btn.disabled    = false;
				btn.textContent = '\u21a9 Restore';
			};

			xhr.send(
				'action=agent_access_restore' +
				'&snapshot_id=' + encodeURIComponent(snapshotId) +
				'&nonce=' + encodeURIComponent(nonce)
			);
		});
	});
})();
