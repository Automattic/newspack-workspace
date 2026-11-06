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
		initHelpTooltips();
	});

	function uiText(key, fallback) {
		return agentAccess.ui_text && agentAccess.ui_text[key] ? agentAccess.ui_text[key] : fallback;
	}
	function uiCount(template, values) {
		var next = 0;
		return template.replace(/%(?:(\d+)\$)?d/g, function (match, position) { return values[position ? Number(position) - 1 : next++]; });
	}
	function setLiveText(node, text) {
		if (node && node.textContent !== text) node.textContent = text;
	}

	/** Small contextual popovers: hover/focus to read, click/touch to pin, Escape to dismiss. */
	function initHelpTooltips() {
		var active = null;
		var closeTimer = null;
		function cancelClose() { if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; } }
		function scheduleClose() { cancelClose(); closeTimer = setTimeout(function () { if (active && !active.pinned && !active.wrapper.matches(':hover') && !active.wrapper.contains(document.activeElement)) close(); }, 250); }
		function close() {
			cancelClose();
			if (!active) return;
			active.panel.hidden = true;
			active.button.setAttribute('aria-expanded', 'false');
			active = null;
		}
		function position() {
			if (!active) return;
			var rect = active.button.getBoundingClientRect();
			var box = active.panel.getBoundingClientRect();
			var left = Math.max(16, Math.min(rect.left, window.innerWidth - box.width - 16));
			var top = rect.bottom + 8;
			if (top + box.height > window.innerHeight - 16) top = rect.top - box.height - 8;
			active.panel.style.left = left + 'px';
			active.panel.style.top = Math.max(16, top) + 'px';
		}
		function open(wrapper, pinned) {
			cancelClose();
			if (active && active.wrapper === wrapper) { active.pinned = active.pinned || pinned; return; }
			close();
			active = { wrapper: wrapper, button: wrapper.querySelector('.agent-access-help-toggle'), panel: wrapper.querySelector('.agent-access-help-content'), pinned: pinned };
			active.panel.hidden = false;
			active.button.setAttribute('aria-expanded', 'true');
			position();
		}
		document.addEventListener('click', function (event) {
			var button = event.target.closest('.agent-access-help-toggle');
			if (button) {
				var wrapper = button.closest('.agent-access-help');
				if (active && active.wrapper === wrapper && active.pinned) close(); else open(wrapper, true);
			} else if (active && !active.wrapper.contains(event.target)) close();
		});
		document.addEventListener('pointerover', function (event) {
			var wrapper = event.target.closest('.agent-access-help');
			if (wrapper && !wrapper.contains(event.relatedTarget) && event.pointerType !== 'touch' && window.matchMedia('(hover: hover)').matches) open(wrapper, false);
		});
		document.addEventListener('pointerout', function (event) {
			if (active && active.wrapper.contains(event.target) && !active.wrapper.contains(event.relatedTarget) && !active.pinned && !active.wrapper.contains(document.activeElement)) scheduleClose();
		});
		document.addEventListener('focusin', function (event) {
			var button = event.target.closest('.agent-access-help-toggle');
			if (button) open(button.closest('.agent-access-help'), false);
		});
		document.addEventListener('focusout', function () {
			Promise.resolve().then(function () { if (active && !active.wrapper.contains(document.activeElement)) { active.pinned = false; scheduleClose(); } });
		});
		document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && active) { close(); event.preventDefault(); } });
		window.addEventListener('resize', position);
		window.addEventListener('scroll', position, true);
	}

	/** Keep specific grants intact while read-only, full or all-custom is selected. */
	function initScopeReadOnlyToggles() {
		function sync(checklist) {
			var readOnly = checklist.querySelector('.agent-access-scope-read-only');
			var full = checklist.querySelector('.agent-access-scope-full');
			var allCustom = checklist.querySelector('.agent-access-scope-all-custom');
			var readMode = !!(readOnly && readOnly.checked);
			var fullMode = !!(full && full.checked);
			var inactive = readMode || fullMode;
			if (full) full.disabled = readMode;
			if (allCustom) allCustom.disabled = inactive;
			checklist.querySelectorAll('.agent-access-scope-type, .agent-access-scope-custom-routes').forEach(function (control) {
				var custom = !!control.closest('.agent-access-custom-options');
				control.disabled = inactive || (custom && !!(allCustom && allCustom.checked));
			});
			var list = checklist.querySelector('.agent-access-integration-list');
			var selected = list ? list.querySelectorAll('.agent-access-scope-type:checked').length : 0;
			var total = list ? list.querySelectorAll('.agent-access-scope-type').length : 0;
			if (list) list.classList.toggle('is-inactive', inactive || !!(allCustom && allCustom.checked));
			var count = checklist.querySelector('.agent-access-integration-count');
			setLiveText(count, readMode ? uiText('integrations_disabled', 'Integration writes disabled.') : (fullMode ? uiText('integrations_full', 'All detected integrations allowed by full access.') : (allCustom && allCustom.checked ? uiText('integrations_all', 'All detected integrations allowed.') : uiCount(uiText('integrations_selected', '%1$d of %2$d selected'), [selected, total]))));
			var summary = checklist.querySelector('.agent-access-scope-summary');
			var routes = checklist.querySelector('.agent-access-scope-custom-routes');
			var hasSelection = checklist.querySelector('.agent-access-scope-type:checked:not(:disabled)') || (allCustom && allCustom.checked) || (routes && routes.value.trim());
			setLiveText(summary, readMode ? uiText('read_only', 'Read only — writes disabled.') : (fullMode ? uiText('full', 'Full write access — within this user’s permissions.') : (hasSelection ? uiText('selected', 'Selected access — writes limited to the selections below.') : uiText('empty', 'No selections — writes disabled.'))));
		}
		document.querySelectorAll('.agent-access-scope-checklist').forEach(sync);
		['change', 'input'].forEach(function (name) {
			document.addEventListener(name, function (event) {
				var checklist = event.target.closest('.agent-access-scope-checklist');
				if (checklist) sync(checklist);
			});
		});
	}

	/** Collect only effective controls; disabled fieldsets must not submit grants. */
	function collectScopeFromChecklist(container) {
		var data = { scope_read_only: '', scope_full: '', scope_all_custom: '', scope_types: [], scope_custom_routes: '' };
		if (!container) return data;
		if (container.querySelector('.agent-access-scope-read-only:checked')) {
			data.scope_read_only = '1';
			return data;
		}
		if (container.querySelector('.agent-access-scope-full:checked')) {
			data.scope_full = '1';
			return data;
		}
		var allCustom = container.querySelector('.agent-access-scope-all-custom:checked');
		data.scope_all_custom = allCustom ? '1' : '';
		container.querySelectorAll('.agent-access-scope-type:checked:not(:disabled)').forEach(function (cb) {
			data.scope_types.push(cb.value);
		});
		var custom = container.querySelector('.agent-access-scope-custom-routes:not(:disabled)');
		data.scope_custom_routes = custom ? custom.value : '';
		return data;
	}

	function encodeScopeParams(data) {
		var parts = [];
		['scope_read_only', 'scope_full', 'scope_all_custom', 'scope_custom_routes'].forEach(function (key) {
			if (data[key]) parts.push(key + '=' + encodeURIComponent(data[key]));
		});
		data.scope_types.forEach(function (type) {
			parts.push('scope_types%5B%5D=' + encodeURIComponent(type));
		});
		return parts.join('&');
	}

	/** Optional field is omitted for older forms and unauthorized actors. */
	function encodeSiteManagement(container) {
		var control = container ? container.querySelector('.agent-access-site-management') : null;
		return control ? '&site_management=' + (control.checked ? '1' : '0') : '';
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
					encodeSiteManagement(adminCard) +
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
		document.querySelectorAll('.agent-access-admin-update-btn').forEach(function (btn) {
			var userId = btn.getAttribute('data-user-id');
			var section = btn.closest('.agent-access-settings-section') || btn.closest('td').closest('table').closest('div');
			var statusEl = document.querySelector('.agent-access-admin-update-status[data-user-id="' + userId + '"]');
			var revision = 0;
			if (statusEl) {
				statusEl.setAttribute('role', 'status');
				statusEl.setAttribute('aria-live', 'polite');
				statusEl.setAttribute('aria-atomic', 'true');
			}
			function status(text, state) {
				if (!statusEl) return;
				setLiveText(statusEl, text);
				statusEl.dataset.state = state;
			}
			function changed(event) {
				if (!event.target.matches('input, select, textarea')) return;
				revision++;
				status(uiText('unsaved', 'Unsaved changes'), 'dirty');
			}
			if (section) { section.addEventListener('input', changed); section.addEventListener('change', changed); }
			btn.addEventListener('click', function () {
				var checklist = section ? section.querySelector('.agent-access-admin-update-scope') : null;
				var scopeData = collectScopeFromChecklist(checklist);
				var rlEl = section ? section.querySelector('.agent-access-admin-update-rate-limit') : null;
				var cpEl = section ? section.querySelector('.agent-access-admin-update-content-policy') : null;
				var savedRevision = revision;
				btn.disabled = true;
				btn.textContent = uiText('saving', 'Saving…');
				status(uiText('saving', 'Saving…'), 'saving');
				function ready() { btn.disabled = false; btn.textContent = uiText('save_settings', 'Save settings'); }
				var xhr = new XMLHttpRequest();
				xhr.open('POST', agentAccess.ajax_url, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function () {
					ready();
					var response;
					try { response = JSON.parse(xhr.responseText); }
					catch (error) { status(uiText('unexpected_response', 'Unexpected response. Settings were not confirmed saved.'), 'error'); return; }
					if (response && response.success) {
						status(revision === savedRevision ? uiText('saved', 'Settings saved.') : uiText('unsaved_remaining', 'Unsaved changes remain.'), revision === savedRevision ? 'saved' : 'dirty');
					} else {
						status(response && typeof response.data === 'string' ? response.data : uiText('error_saving', 'Error saving settings.'), 'error');
					}
				};
				xhr.onerror = function () { ready(); status(uiText('network_error', 'Network error. Please try again.'), 'error'); };
				xhr.send(
					'action=agent_access_admin_update' +
					'&nonce=' + encodeURIComponent(agentAccess.admin_update_nonce) +
					'&user_id=' + encodeURIComponent(userId) +
					'&' + encodeScopeParams(scopeData) +
					encodeSiteManagement(section) +
					'&rate_limit=' + encodeURIComponent(rlEl ? rlEl.value : 'standard') +
					'&content_policy=' + encodeURIComponent(cpEl ? cpEl.value : 'standard')
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
