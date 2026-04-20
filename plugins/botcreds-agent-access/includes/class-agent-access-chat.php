<?php
/**
 * Agent Access Chat — wp-admin chat UI and REST API endpoints.
 *
 * @package Agent_Access
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Chat {

	/** @var string Chat table name (without prefix). */
	const TABLE = 'agent_access_chat';

	/**
	 * Boot the chat subsystem.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Admin menu
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function register_menu() {
		add_submenu_page(
			'botcreds-agent-access',
			__( 'Chat', 'botcreds-agent-access' ),
			__( 'Chat', 'botcreds-agent-access' ),
			'read',
			'agent-access-chat',
			array( __CLASS__, 'render_page' )
		);
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Chat page renderer
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function render_page() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Check if chat table exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			echo '<div class="wrap"><h1>Agent Access Chat</h1>';
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Chat table not found. Please deactivate and reactivate the plugin.', 'botcreds-agent-access' );
			echo '</p></div></div>';
			return;
		}

		// Get channels
		$cache_key = 'botcreds_chat_channels';
		$channels  = wp_cache_get( $cache_key );
		if ( false === $channels ) {
			$channels = $wpdb->get_col(
				$wpdb->prepare( 'SELECT DISTINCT channel FROM %i ORDER BY channel ASC', $table ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			);
			wp_cache_set( $cache_key, $channels, '', 30 );
		}
		if ( empty( $channels ) ) {
			$channels = array( 'general' );
		}

		$current_user = wp_get_current_user();
		$display_name = $current_user->display_name ?: $current_user->user_login;

		?>
		<div class="wrap" id="aa-chat-wrap">
			<style>
				#aa-chat-wrap { display: flex; gap: 0; min-height: 70vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.aa-sidebar { width: 240px; flex-shrink: 0; background: #1d2327; color: #f0f0f1; display: flex; flex-direction: column; border-radius: 6px 0 0 6px; overflow-y: auto; }
				.aa-sidebar h2 { font-size: 14px; color: #a7aaad; padding: 16px 16px 8px; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; }
				.aa-sidebar .aa-channel-list { list-style: none; margin: 0; padding: 0; flex: 1; }
				.aa-sidebar .aa-channel-list li { padding: 8px 16px; cursor: pointer; color: #c3c4c7; transition: background 0.15s; }
				.aa-sidebar .aa-channel-list li:hover { background: #2c3338; }
				.aa-sidebar .aa-channel-list li.active { background: #2271b1; color: #fff; }
				.aa-sidebar .aa-channel-list li::before { content: '#'; margin-right: 6px; opacity: 0.6; }

				/* Security notice in sidebar */
				.aa-security-notice { padding: 12px 16px; background: #2c3338; border-top: 1px solid #3c4349; margin-top: auto; font-size: 11px; line-height: 1.5; color: #a7aaad; }
				.aa-security-notice summary { cursor: pointer; color: #c3c4c7; font-size: 12px; font-weight: 600; }
				.aa-security-notice summary:hover { color: #f0f0f1; }
				.aa-security-notice ul { margin: 8px 0 0; padding-left: 14px; }
				.aa-security-notice li { margin-bottom: 4px; }
				.aa-security-notice .aa-lock-icon { margin-right: 4px; }

				.aa-main { flex: 1; display: flex; flex-direction: column; background: #fff; border: 1px solid #c3c4c7; border-left: 0; border-radius: 0 6px 6px 0; }
				.aa-header { padding: 12px 16px; border-bottom: 1px solid #e0e0e0; font-weight: 600; font-size: 15px; background: #f6f7f7; }
				.aa-header::before { content: '#'; margin-right: 4px; opacity: 0.5; }
				.aa-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; min-height: 300px; }
				.aa-msg { padding: 6px 0; }
				.aa-msg-sender { font-weight: 600; margin-right: 8px; }
				.aa-msg-sender.bot { color: #2271b1; }
				.aa-msg-sender.bot::after { content: ' 🤖'; font-size: 12px; }
				.aa-msg-time { color: #999; font-size: 11px; }
				.aa-msg-body { margin-top: 2px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
				.aa-compose { display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid #e0e0e0; background: #f6f7f7; }
				.aa-compose input { flex: 1; padding: 8px 12px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px; }
				.aa-compose button { padding: 8px 20px; background: #2271b1; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
				.aa-compose button:hover { background: #135e96; }
				.aa-compose button:disabled { opacity: 0.5; cursor: not-allowed; }
				.aa-typing { padding: 4px 16px; color: #999; font-size: 12px; font-style: italic; min-height: 20px; }
			</style>

			<!-- Sidebar with channels + security notice -->
			<div class="aa-sidebar">
				<h2><?php esc_html_e( 'Channels', 'botcreds-agent-access' ); ?></h2>
				<ul class="aa-channel-list" id="aa-channels">
					<?php foreach ( $channels as $i => $ch ) : ?>
						<li data-channel="<?php echo esc_attr( $ch ); ?>"
							class="<?php echo 0 === $i ? 'active' : ''; ?>">
							<?php echo esc_html( $ch ); ?>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="aa-security-notice">
					<details>
						<summary><span class="aa-lock-icon">🔒</span> Security &amp; Scope</summary>
						<ul>
							<li><strong>Auth:</strong> WP Application Passwords over HTTPS</li>
							<li><strong>Visibility:</strong> Site admins + connected AI agents</li>
							<li><strong>Storage:</strong> WordPress DB, plaintext at rest</li>
							<li><strong>AI link:</strong> Polled every ~30s by agent bridge</li>
							<li><strong>Isolation:</strong> Messages here stay here — not shared with agent's other channels unless forwarded</li>
							<li><strong>No E2E encryption</strong></li>
						</ul>
					</details>
				</div>
			</div>

			<!-- Main chat area -->
			<div class="aa-main">
				<div class="aa-header" id="aa-channel-name"><?php echo esc_html( $channels[0] ?? 'general' ); ?></div>
				<div class="aa-messages" id="aa-messages"></div>
				<div class="aa-typing" id="aa-typing"></div>
				<div class="aa-compose">
					<input type="text" id="aa-input" placeholder="<?php esc_attr_e( 'Type a message...', 'botcreds-agent-access' ); ?>" autocomplete="off" />
					<button id="aa-send"><?php esc_html_e( 'Send', 'botcreds-agent-access' ); ?></button>
				</div>
			</div>

			<script>
			(function() {
				const restUrl   = '<?php echo esc_js( rest_url( 'agent-access/v1/chat' ) ); ?>';
				const nonce     = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
				const sender    = '<?php echo esc_js( $display_name ); ?>';
				const channels  = document.querySelectorAll('#aa-channels li');
				const msgBox    = document.getElementById('aa-messages');
				const input     = document.getElementById('aa-input');
				const sendBtn   = document.getElementById('aa-send');
				const chanLabel = document.getElementById('aa-channel-name');
				let activeChannel = '<?php echo esc_js( $channels[0] ?? 'general' ); ?>';
				let lastId = 0;
				let polling = null;

				function headers() {
					return { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
				}

				function escHtml(s) {
					const d = document.createElement('div');
					d.textContent = s;
					return d.innerHTML;
				}

				function renderMsg(m) {
					const isBotClass = (m.sender_type === 'bot' || m.sender_type === 'agent') ? ' bot' : '';
					const time = m.timestamp ? new Date(m.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
					return `<div class="aa-msg">
						<span class="aa-msg-sender${isBotClass}">${escHtml(m.sender)}</span>
						<span class="aa-msg-time">${escHtml(time)}</span>
						<div class="aa-msg-body">${escHtml(m.message)}</div>
					</div>`;
				}

				async function loadMessages(channel) {
					try {
						const r = await fetch(restUrl + '/messages?channel=' + encodeURIComponent(channel) + '&limit=50', { headers: headers() });
						const msgs = await r.json();
						const list = Array.isArray(msgs) ? msgs : (msgs.messages || []);
						msgBox.innerHTML = list.map(renderMsg).join('');
						msgBox.scrollTop = msgBox.scrollHeight;
						if (list.length) lastId = Math.max(...list.map(m => parseInt(m.id) || 0));
					} catch (e) {
						console.error('Load error:', e);
					}
				}

				async function pollNew() {
					try {
						const r = await fetch(restUrl + '/messages?channel=' + encodeURIComponent(activeChannel) + '&since_id=' + lastId + '&limit=20', { headers: headers() });
						const msgs = await r.json();
						const list = Array.isArray(msgs) ? msgs : (msgs.messages || []);
						for (const m of list) {
							const mid = parseInt(m.id) || 0;
							if (mid > lastId) {
								lastId = mid;
								msgBox.insertAdjacentHTML('beforeend', renderMsg(m));
							}
						}
						if (list.length) msgBox.scrollTop = msgBox.scrollHeight;
					} catch (e) { /* silent */ }
				}

				async function sendMessage() {
					const text = input.value.trim();
					if (!text) return;
					input.value = '';
					sendBtn.disabled = true;

					try {
						await fetch(restUrl + '/send', {
							method: 'POST',
							headers: headers(),
							body: JSON.stringify({
								channel: activeChannel,
								sender: sender,
								sender_type: 'human',
								message: text
							})
						});
						await pollNew();
					} catch (e) {
						console.error('Send error:', e);
					} finally {
						sendBtn.disabled = false;
						input.focus();
					}
				}

				function switchChannel(ch) {
					activeChannel = ch;
					chanLabel.textContent = ch;
					channels.forEach(el => el.classList.toggle('active', el.dataset.channel === ch));
					lastId = 0;
					msgBox.innerHTML = '';
					loadMessages(ch);
				}

				channels.forEach(el => el.addEventListener('click', () => switchChannel(el.dataset.channel)));
				sendBtn.addEventListener('click', sendMessage);
				input.addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });

				// Initial load + poll
				loadMessages(activeChannel);
				polling = setInterval(pollNew, 5000);
			})();
			</script>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Assets
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function enqueue_assets( $hook ) {
		if ( 'agent-access_page_agent-access-chat' !== $hook ) {
			return;
		}
		// Inline styles/scripts are in render_page() for simplicity
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * REST API routes
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function register_routes() {
		register_rest_route( 'agent-access/v1', '/chat/channels', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_channels' ),
			'permission_callback' => function() {
				return current_user_can( 'read' ) || self::is_app_password_request();
			},
		) );

		register_rest_route( 'agent-access/v1', '/chat/messages', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_messages' ),
			'permission_callback' => function() {
				return current_user_can( 'read' ) || self::is_app_password_request();
			},
		) );

		register_rest_route( 'agent-access/v1', '/chat/send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'api_send' ),
			'permission_callback' => function() {
				return current_user_can( 'read' ) || self::is_app_password_request();
			},
		) );

		register_rest_route( 'agent-access/v1', '/chat/poll', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'api_poll' ),
			'permission_callback' => function() {
				return current_user_can( 'read' ) || self::is_app_password_request();
			},
		) );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * API handlers
	 * ──────────────────────────────────────────────────────────────────────── */

	public static function api_channels( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$cache_key = 'botcreds_chat_api_channels';
		$rows      = wp_cache_get( $cache_key );
		if ( false === $rows ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT channel, COUNT(*) as msg_count, COUNT(DISTINCT sender) as member_count, MAX(timestamp) as last_message_at FROM %i GROUP BY channel ORDER BY channel',
					$table
				)
			);
			wp_cache_set( $cache_key, $rows, '', 30 );
		}

		$channels = array();
		foreach ( $rows as $r ) {
			$channels[] = array(
				'name'            => $r->channel,
				'description'     => '',
				'private'         => false,
				'member_count'    => (int) $r->member_count,
				'last_message_at' => $r->last_message_at,
			);
		}

		return new WP_REST_Response( array( 'channels' => $channels ), 200 );
	}

	public static function api_messages( $request ) {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$channel = sanitize_text_field( $request->get_param( 'channel' ) ?: 'general' );
		$limit   = absint( $request->get_param( 'limit' ) ?: 50 );
		$since   = absint( $request->get_param( 'since_id' ) ?: 0 );

		if ( $limit > 200 ) $limit = 200;

		$cache_key = 'botcreds_msgs_' . md5( $channel . '_' . $since . '_' . $limit );
		$rows      = wp_cache_get( $cache_key );
		if ( false === $rows ) {
			if ( $since > 0 ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'SELECT id, channel, sender, sender_type, message, timestamp FROM %i WHERE channel = %s AND id > %d ORDER BY id ASC LIMIT %d',
						$table, $channel, $since, $limit
					)
				);
			} else {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						'SELECT id, channel, sender, sender_type, message, timestamp FROM %i WHERE channel = %s ORDER BY id ASC LIMIT %d',
						$table, $channel, $limit
					)
				);
			}
			wp_cache_set( $cache_key, $rows, '', 5 );
		}

		return new WP_REST_Response( $rows ?: array(), 200 );
	}

	public static function api_send( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$channel     = sanitize_text_field( $request->get_param( 'channel' ) ?: 'general' );
		$sender      = sanitize_text_field( $request->get_param( 'sender' ) ?: 'anonymous' );
		$sender_type = sanitize_text_field( $request->get_param( 'sender_type' ) ?: 'human' );
		$message     = sanitize_textarea_field( $request->get_param( 'message' ) ?: '' );

		if ( empty( $message ) ) {
			return new WP_Error( 'empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'channel'     => $channel,
				'sender'      => $sender,
				'sender_type' => $sender_type,
				'message'     => $message,
				'timestamp'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'insert_failed', 'Failed to save message.', array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'id'          => $wpdb->insert_id,
				'channel'     => $channel,
				'sender'      => $sender,
				'sender_type' => $sender_type,
				'message'     => $message,
				'timestamp'   => current_time( 'mysql', true ),
			),
			201
		);
	}

	public static function api_poll( $request ) {
		$channel = sanitize_text_field( $request->get_param( 'channel' ) ?: 'general' );
		$since   = absint( $request->get_param( 'since_id' ) ?: 0 );
		$timeout = min( absint( $request->get_param( 'timeout' ) ?: 25 ), 30 );

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$end = time() + $timeout;
		while ( time() < $end ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT id, channel, sender, sender_type, message, timestamp FROM %i WHERE channel = %s AND id > %d ORDER BY id ASC LIMIT 50',
					$table, $channel, $since
				)
			);
			if ( ! empty( $rows ) ) {
				return new WP_REST_Response( $rows, 200 );
			}
			usleep( 500000 ); // 0.5s
		}

		return new WP_REST_Response( array(), 200 );
	}

	/* ──────────────────────────────────────────────────────────────────────────
	 * Helpers
	 * ──────────────────────────────────────────────────────────────────────── */

	private static function is_app_password_request() {
		return ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] );
	}

	/**
	 * Create the chat table on plugin activation.
	 */
	public static function install() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel VARCHAR(100) NOT NULL DEFAULT 'general',
			sender VARCHAR(200) NOT NULL,
			sender_type VARCHAR(50) NOT NULL DEFAULT 'human',
			message LONGTEXT NOT NULL,
			timestamp DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY channel_ts (channel, timestamp),
			KEY channel_id (channel, id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

