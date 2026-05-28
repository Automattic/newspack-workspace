<?php
/**
 * Customisable emails.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Reader-revenue related emails.
 */
class Emails {
	const POST_TYPE              = 'newspack_rr_email'; // "Reader Revenue" emails, for legacy reasons.
	const EMAIL_CONFIG_NAME_META = 'newspack_email_type'; // "type" for legacy reasons.

	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_api_endpoints' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
		add_filter( 'newspack_newsletters_email_editor_cpts', [ __CLASS__, 'register_email_cpt_with_email_editor' ] );
		add_filter( 'newspack_newsletters_allowed_editor_actions', [ __CLASS__, 'register_scripts_enqueue_with_email_editor' ] );
		add_action( 'update_option_theme_mods_' . ( wp_get_theme()->parent() ? get_stylesheet() : get_template() ), [ __CLASS__, 'maybe_update_email_templates' ], 10, 2 );
		add_action( 'admin_head', [ __CLASS__, 'inject_dynamic_email_template_styles' ] );
	}

	/**
	 * Register the custom post type as edited-as-email.
	 *
	 * @param array $email_editor_cpts Post type which should be edited as emails.
	 * @codeCoverageIgnore
	 */
	public static function register_email_cpt_with_email_editor( $email_editor_cpts ) {
		$email_editor_cpts[] = self::POST_TYPE;
		return $email_editor_cpts;
	}

	/**
	 * Register the editor scripts as allowed when editing email.
	 *
	 * @param array $allowed_actions Actions allowed when enqueuing assets for the block editor.
	 * @codeCoverageIgnore
	 */
	public static function register_scripts_enqueue_with_email_editor( $allowed_actions ) {
		$allowed_actions[] = __CLASS__ . '::enqueue_block_editor_assets';
		return $allowed_actions;
	}

	/**
	 * Register the custom post type for emails.
	 *
	 * @codeCoverageIgnore
	 */
	public static function register_cpt() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$labels = [
			'name'                     => _x( 'Newspack Emails', 'post type general name', 'newspack-plugin' ),
			'singular_name'            => _x( 'Newspack Email', 'post type singular name', 'newspack-plugin' ),
			'menu_name'                => _x( 'Newspack Emails', 'admin menu', 'newspack-plugin' ),
			'name_admin_bar'           => _x( 'Newspack Email', 'add new on admin bar', 'newspack-plugin' ),
			'add_new'                  => _x( 'Add New', 'popup', 'newspack-plugin' ),
			'add_new_item'             => __( 'Add New Newspack Email', 'newspack-plugin' ),
			'new_item'                 => __( 'New Newspack Email', 'newspack-plugin' ),
			'edit_item'                => __( 'Edit Newspack Email', 'newspack-plugin' ),
			'view_item'                => __( 'View Newspack Email', 'newspack-plugin' ),
			'all_items'                => __( 'All Newspack Emails', 'newspack-plugin' ),
			'search_items'             => __( 'Search Newspack Emails', 'newspack-plugin' ),
			'parent_item_colon'        => __( 'Parent Newspack Emails:', 'newspack-plugin' ),
			'not_found'                => __( 'No Newspack Emails found.', 'newspack-plugin' ),
			'not_found_in_trash'       => __( 'No Newspack Emails found in Trash.', 'newspack-plugin' ),
			'items_list'               => __( 'Newspack Emails list', 'newspack-plugin' ),
			'item_published'           => __( 'Newspack Email published', 'newspack-plugin' ),
			'item_published_privately' => __( 'Newspack Email published privately', 'newspack-plugin' ),
			'item_reverted_to_draft'   => __( 'Newspack Email reverted to draft', 'newspack-plugin' ),
			'item_scheduled'           => __( 'Newspack Email scheduled', 'newspack-plugin' ),
			'item_updated'             => __( 'Newspack Email updated', 'newspack-plugin' ),
		];

		\register_post_type(
			self::POST_TYPE,
			[
				'public'       => false,
				'labels'       => $labels,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => [ 'editor', 'title', 'custom-fields' ],
				'taxonomies'   => [],
			]
		);
	}

	/**
	 * Load up common JS/CSS for newsletter editor.
	 *
	 * @codeCoverageIgnore
	 */
	public static function enqueue_block_editor_assets() {
		if ( get_post_type() !== self::POST_TYPE ) {
			return;
		}
		Newspack::load_common_assets();
		$handle = 'revenue-email-editor';
		\wp_register_script(
			$handle,
			Newspack::plugin_url() . '/dist/other-scripts/emails.js',
			[],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
		\wp_localize_script(
			$handle,
			'newspack_emails',
			[
				'current_user_email'     => wp_get_current_user()->user_email,
				'configs'                => self::get_email_configs(),
				'email_config_name_meta' => self::EMAIL_CONFIG_NAME_META,
				'from_name'              => self::get_from_name(),
				'from_email'             => self::get_from_email(),
				'reply_to_email'         => self::get_reply_to_email(),
			]
		);
		wp_enqueue_script( $handle );

		\wp_register_style(
			$handle,
			Newspack::plugin_url() . '/dist/other-scripts/emails.css',
			[],
			NEWSPACK_PLUGIN_VERSION
		);
		\wp_style_add_data( $handle, 'rtl', 'replace' );
		\wp_enqueue_style( $handle );
	}

	/**
	 * Register custom fields.
	 *
	 * @codeCoverageIgnore
	 */
	public static function register_meta() {
		\register_meta(
			'post',
			self::EMAIL_CONFIG_NAME_META,
			[
				'object_subtype' => self::POST_TYPE,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);
	}

	/**
	 * Get email payload by config name.
	 *
	 * @param string $config_name Name of email config.
	 * @param array  $placeholders Placeholders to replace in email.
	 */
	public static function get_email_payload( $config_name, $placeholders = [] ) {
		$email_config   = self::get_email_config_by_type( $config_name );
		$html           = $email_config['html_payload'];
		$reply_to_email = $email_config['reply_to_email'];
		$site_address   = '';

		if ( class_exists( 'WC' ) ) {
			$base_address  = WC()->countries->get_base_address();
			$base_city     = WC()->countries->get_base_city();
			$base_postcode = WC()->countries->get_base_postcode();
		} else {
			$base_address  = get_option( 'woocommerce_store_address', '' );
			$base_city     = get_option( 'woocommerce_store_city', '' );
			$base_postcode = get_option( 'woocommerce_store_postcode', '' );
		}

		if ( $base_address ) {
			if ( ! $base_city && ! $base_postcode ) {
				$site_address = $base_address;
			} else {
				$site_address = sprintf(
					// translators: formatted store address where 1 is street address, 2 is city, and 3 is postcode.
					__( '%1$s, %2$s %3$s', 'newspack-plugin' ),
					$base_address,
					$base_city,
					$base_postcode
				);
			}
		}

		if ( $site_address ) {
			$site_contact = sprintf(
				/* Translators: 1: site title 2: site base address. */
				__( '%1$s — %2$s', 'newspack-plugin' ),
				'<strong>' . get_bloginfo( 'name' ) . '</strong>',
				$site_address
			);
		} else {
			$site_contact = get_bloginfo( 'name' );
		}

		$placeholders = array_merge(
			[
				[
					'template' => '*CONTACT_EMAIL*',
					'value'    => sprintf( '<a href="mailto:%s">%s</a>', $reply_to_email, $reply_to_email ),
				],
				[
					'template' => '*SITE_ADDRESS*',
					'value'    => $site_address,
				],
				[
					'template' => '*SITE_CONTACT*',
					'value'    => $site_contact,
				],
				[
					'template' => '*SITE_LOGO*',
					'value'    => esc_url( wp_get_attachment_url( get_theme_mod( 'custom_logo' ) ) ),
				],
				[
					'template' => '*SITE_TITLE*',
					'value'    => get_bloginfo( 'name' ),
				],
				[
					'template' => '*SITE_URL*',
					'value'    => get_bloginfo( 'wpurl' ),
				],
			],
			$placeholders
		);
		foreach ( $placeholders as $value ) {
			$html = str_replace(
				$value['template'],
				$value['value'],
				$html
			);
		}
		return $html;
	}

	/**
	 * Send an HTML email.
	 *
	 * @param string $config_name Email config name.
	 * @param string $to Recipient's email addesss.
	 * @param array  $placeholders Dynamic content substitutions.
	 */
	public static function send_email( $config_name, $to, $placeholders = [] ) {
		if ( ! self::supports_emails() ) {
			return false;
		}

		// Migrate to RAS-ACC email templates if migration option is not set AND there have been no manual updates to the templates.
		if ( get_option( 'newspack_email_templates_migrated', '' ) !== 'v1' ) {
			$migrated  = true;
			$templates = get_posts(
				[
					'post_type'      => self::POST_TYPE,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				]
			);

			foreach ( $templates as $template ) {
				$publish_date       = get_the_date( 'Y-m-d H:i:s', $template->ID );
				$last_modified_date = get_the_modified_date( 'Y-m-d H:i:s', $template->ID );

				// Template has not been modified, so trash the post so we can trigger a template update.
				if ( $publish_date === $last_modified_date ) {
					if ( ! wp_trash_post( $template->ID ) ) {
						// Flag the migration as failed so we can trigger another attempt later.
						$migrated = false;
					}
				}
			}

			if ( $migrated ) {
				update_option( 'newspack_email_templates_migrated', 'v1' );
			}
		}

		$switched_locale = \switch_to_locale( \get_user_locale( \wp_get_current_user() ) );

		if ( 'string' === gettype( $config_name ) ) {
			$email_config = self::get_email_config_by_type( $config_name );
		} elseif ( 'integer' === gettype( $config_name ) ) {
			$email_config = self::serialize_email( null, $config_name );
			$config_name  = \get_post_meta( $config_name, self::EMAIL_CONFIG_NAME_META, true );
		} else {
			return false;
		}
		if ( ! $to || ! $email_config || 'publish' !== $email_config['status'] ) {
			return false;
		}

		$email_content_type = function() {
			return 'text/html';
		};

		$headers = [
			sprintf( 'From: %s <%s>', $email_config['from_name'], $email_config['from_email'] ),
		];
		if ( $email_config['from_email'] !== $email_config['reply_to_email'] ) {
			$headers[] = sprintf( 'Reply-To: %s <%s>', $email_config['from_name'], $email_config['reply_to_email'] );
		}

		add_filter( 'wp_mail_content_type', $email_content_type );
		$email_send_result = wp_mail( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$to,
			$email_config['subject'],
			self::get_email_payload( $config_name, $placeholders ),
			$headers
		);
		remove_filter( 'wp_mail_content_type', $email_content_type );

		if ( $switched_locale ) {
			\restore_previous_locale();
		}

		Logger::log( 'Sending "' . $config_name . '" email to: ' . $to );

		return $email_send_result;
	}

	/**
	 * Load a template of an email.
	 *
	 * @param string $type Email type.
	 */
	private static function load_email_template( $type ) {
		$configs = self::get_email_configs();

		if ( ! isset( $configs[ $type ], $configs[ $type ]['template'] ) ) {
			return false;
		}
		return require $configs[ $type ]['template'];
	}

	/**
	 * Does this instance support emails?
	 */
	public static function supports_emails() {
		if ( ! class_exists( 'Newspack_Newsletters' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Can email of a particular type be sent?
	 *
	 * @param string $type Type of the email.
	 */
	public static function can_send_email( $type ) {
		if ( ! self::supports_emails() ) {
			return false;
		}
		$email_config = self::get_email_config_by_type( $type );
		if ( ! $email_config || 'publish' !== $email_config['status'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Get all email configs.
	 */
	private static function get_email_configs() {
		return apply_filters( 'newspack_email_configs', [] );
	}

	/**
	 * Serialize an email config.
	 *
	 * @param string $type Type of the email.
	 * @param int    $post_id Email post id.
	 *
	 * @return array|false The serialized email config or false if not available or supported.
	 */
	private static function serialize_email( $type = null, $post_id = 0 ) {
		if ( ! self::supports_emails() ) {
			return false;
		}

		if ( null !== $type ) {
			$configs = self::get_email_configs();
			if ( ! isset( $configs[ $type ] ) ) {
				return false;
			}
			$email_config = $configs[ $type ];
		} else {
			$email_config = [
				'label'       => '',
				'description' => '',
				'category'    => '',
			];
		}
		$html_payload = get_post_meta( $post_id, \Newspack_Newsletters::EMAIL_HTML_META, true );
		if ( ! $html_payload || empty( $html_payload ) ) {
			return false;
		}
		$edit_link = '';
		$post_link = get_edit_post_link( $post_id, '' );
		if ( $post_link ) {
			// Make the edit link relative.
			$edit_link = str_replace( site_url(), '', $post_link );
		}
		$serialized_email = [
			'type'           => $type,
			'category'       => $email_config['category'],
			'label'          => $email_config['label'],
			'description'    => $email_config['description'],
			'post_id'        => $post_id,
			'edit_link'      => $edit_link,
			'subject'        => get_the_title( $post_id ),
			'from_name'      => isset( $email_config['from_name'] ) ? $email_config['from_name'] : self::get_from_name(),
			'from_email'     => isset( $email_config['from_email'] ) ? $email_config['from_email'] : self::get_from_email(),
			'reply_to_email' => isset( $email_config['reply_to_email'] ) ? $email_config['reply_to_email'] : self::get_reply_to_email(),
			'status'         => get_post_status( $post_id ),
			'html_payload'   => $html_payload,
		];

		return $serialized_email;
	}

	/**
	 * Get the from email address used to send all transactional emails.
	 * We avoid use of the `wp_mail_from` hook because we only want to
	 * set the email address for Newspack emails, not all emails sent via wp_mail.
	 *
	 * @return string Email address used as the sender for Newspack emails.
	 */
	public static function get_from_email() {
		// Get the site domain and get rid of www.
		$sitename   = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$from_email = 'no-reply@';

		if ( null !== $sitename ) {
			if ( 'www.' === substr( $sitename, 0, 4 ) ) {
				$sitename = substr( $sitename, 4 );
			}
			$from_email .= $sitename;
		}
		if ( Reader_Activation::is_enabled() ) {
			$from_email = get_option( Reader_Activation::OPTIONS_PREFIX . 'sender_email_address', $from_email );
		}
		return apply_filters( 'newspack_from_email', $from_email );
	}

	/**
	 * Get the "reply-to" email address used to send all transactional emails.
	 *
	 * @return string
	 */
	public static function get_reply_to_email() {
		$reply_to_email = get_bloginfo( 'admin_email' );
		if ( Reader_Activation::is_enabled() ) {
			$reply_to_email = get_option( Reader_Activation::OPTIONS_PREFIX . 'contact_email_address', $reply_to_email );
		}
		return apply_filters( 'newspack_reply_to_email', $reply_to_email );
	}

	/**
	 * Get the from from name used to send all transactional emails.
	 * We avoid use of the `wp_mail_from_name` hook because we only want
	 * to set the name for Newspack emails, not all emails sent via wp_mail.
	 *
	 * @return string Name used as the sender for Newspack emails.
	 */
	public static function get_from_name() {
		$from_name = get_bloginfo( 'name' );
		if ( Reader_Activation::is_enabled() ) {
			$from_name = get_option( Reader_Activation::OPTIONS_PREFIX . 'sender_name', $from_name );
		}
		return apply_filters( 'newspack_from_name', $from_name );
	}

	/**
	 * Get the email for a specific type.
	 * If the email does not exist, it will be created based on default template.
	 *
	 * @param string $type Type of the email.
	 *
	 * @return array|false The serialized email config or false if not available or supported.
	 */
	public static function get_email_config_by_type( $type ) {
		$emails_query = new \WP_Query(
			[
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'meta_key'       => self::EMAIL_CONFIG_NAME_META,
				'meta_value'     => $type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		if ( $emails_query->post ) {
			return self::serialize_email( $type, $emails_query->post->ID );
		} elseif ( ! function_exists( 'is_user_logged_in' ) ) {
			/** Only attempt to create the email post if wp-includes/pluggable.php is loaded. */
			return false;
		} else {
			// Make sure newsletters color palette is updated with latest theme colors.
			if ( self::supports_emails() && method_exists( '\Newspack_Newsletters', 'update_color_palette' ) ) {
				$theme_colors = newspack_get_theme_colors();
				\Newspack_Newsletters::update_color_palette(
					[
						'primary'             => $theme_colors['primary_color'],
						'primary-text'        => $theme_colors['primary_text_color'],
						'primary-variation'   => $theme_colors['primary_variation'],
						'secondary'           => $theme_colors['secondary_color'],
						'secondary-text'      => $theme_colors['secondary_text_color'],
						'secondary-variation' => $theme_colors['secondary_variation'],
					]
				);
			}

			$email_post_data = self::load_email_template( $type );
			if ( ! $email_post_data ) {
				Logger::error( 'Error: could not retrieve template for type: ' . $type );
				return false;
			}
			$email_post_data['post_status'] = 'publish';
			$email_post_data['post_type']   = self::POST_TYPE;
			$email_post_data['meta_input']  = [
				self::EMAIL_CONFIG_NAME_META           => $type,
				\Newspack_Newsletters::EMAIL_HTML_META => $email_post_data['email_html'],
				'font_body'                            => 'Arial, Helvetica, sans-serif',
				'font_header'                          => 'Arial, Helvetica, sans-serif',
			];
			$post_id                        = wp_insert_post( $email_post_data );
			Logger::log( sprintf( 'Creating email of type %s (id: %s).', $type, $post_id ) );
			return self::serialize_email(
				$type,
				$post_id
			);
		}
	}

	/**
	 * Get the emails per-purpose.
	 *
	 * @param string[] $config_names Configuration names of the emails.
	 * @param bool     $get_full_configs Whether to get the full configs or just the minimum amount of data.
	 */
	public static function get_emails( $config_names = [], $get_full_configs = true ) {
		$emails = [];
		if ( ! self::supports_emails() ) {
			return $emails;
		}
		$configs = self::get_email_configs();
		foreach ( $configs as $type => $email_config ) {
			if ( ! empty( $config_names ) && ! in_array( $type, $config_names, true ) ) {
				continue;
			}
			$found_config = self::get_email_config_by_type( $type );
			if ( $found_config ) {
				if ( false == $get_full_configs ) {
					unset( $found_config['html_payload'] );
				}
				$emails[ $type ] = $found_config;
			}
		}
		return $emails;
	}

	/**
	 * Send a test email.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function api_send_test_email( $request ) {
		$was_sent = self::send_email(
			$request->get_param( 'post_id' ),
			$request->get_param( 'recipient' )
		);
		if ( $was_sent ) {
			return \rest_ensure_response( [] );
		} else {
			return new \WP_Error(
				'newspack_test_email_not_sent',
				__( 'Test email was not sent.', 'newspack-plugin' )
			);
		}
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public static function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/newspack-emails/test',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_send_test_email' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				'args'                => [
					'recipient' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_id'   => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Check capabilities for using API.
	 *
	 * @codeCoverageIgnore
	 * @param WP_REST_Request $request API request object.
	 * @return bool|WP_Error
	 */
	public static function api_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Get a password reset URL.
	 *
	 * @param WP_User $user WP user object.
	 * @param string  $key Reset key.
	 */
	public static function get_password_reset_url( $user, $key ) {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return add_query_arg(
				[
					'key' => $key,
					'id'  => $user->ID,
				],
				wc_get_account_endpoint_url( 'lost-password' )
			);
		}
		return add_query_arg(
			[
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user->user_login ),
			],
			wp_lostpassword_url()
		);
	}

	/**
	 * Trigger an update to all email template posts when theme color is updated in customizer.
	 * This is to force an update of dynamic properties such as theme colors.
	 *
	 * @param string|array $previous_value previous option value.
	 * @param string|array $updated_value  updated option value.
	 *
	 * @return void
	 */
	public static function maybe_update_email_templates( $previous_value, $updated_value ) {
		// Do nothing if newsletters is not active.
		if ( ! self::supports_emails() ) {
			return;
		}

		// Check for theme mod color settings in case a non-newspack theme is installed.
		if ( ! isset( $previous_value['primary_color_hex'], $updated_value['primary_color_hex'] ) ) {
			return;
		}

		if ( ( $previous_value['primary_color_hex'] !== $updated_value['primary_color_hex'] ) || ( $previous_value['secondary_color_hex'] !== $updated_value['secondary_color_hex'] ) ) {
			// Update the newsletters color palette.
			$updated = \Newspack_Newsletters::update_color_palette(
				[
					'primary'             => $updated_value['primary_color_hex'],
					'primary-text'        => newspack_get_color_contrast( $updated_value['primary_color_hex'] ),
					'primary-variation'   => newspack_adjust_brightness( $updated_value['primary_color_hex'], -40 ),
					'secondary'           => $updated_value['secondary_color_hex'],
					'secondary-text'      => newspack_get_color_contrast( $updated_value['secondary_color_hex'] ),
					'secondary-variation' => newspack_adjust_brightness( $updated_value['secondary_color_hex'], -40 ),

				]
			);

			if ( ! $updated ) {
				Logger::error( 'There was an error updating the newsletters color palette.' );
			}

			// Trigger an update of all email templates to regenerate HTML.
			$templates = get_posts(
				[
					'post_type'      => self::POST_TYPE,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				]
			);

			foreach ( $templates as $template ) {
				// Find/replace the old hex values with the new ones in the rendered email HTML.
				$email_html = get_post_meta( $template->ID, \Newspack_Newsletters::EMAIL_HTML_META, true );
				$email_html = str_replace(
					[
						$previous_value['primary_color_hex'],
						newspack_get_color_contrast( $previous_value['primary_color_hex'] ),
						newspack_adjust_brightness( $previous_value['primary_color_hex'], -40 ),
						$previous_value['secondary_color_hex'],
						newspack_get_color_contrast( $previous_value['secondary_color_hex'] ),
						newspack_adjust_brightness( $previous_value['secondary_color_hex'], -40 ),
					],
					[
						$updated_value['primary_color_hex'],
						newspack_get_color_contrast( $updated_value['primary_color_hex'] ),
						newspack_adjust_brightness( $updated_value['primary_color_hex'], -40 ),
						$updated_value['secondary_color_hex'],
						newspack_get_color_contrast( $updated_value['secondary_color_hex'] ),
						newspack_adjust_brightness( $updated_value['secondary_color_hex'], -40 ),
					],
					$email_html
				);
				update_post_meta( $template->ID, \Newspack_Newsletters::EMAIL_HTML_META, $email_html );

				wp_update_post( [ 'ID' => $template->ID ] );
			}
		}
	}

	/**
	 * Inject dynamic email template styles for dynamic text colors in the editor.
	 *
	 * @return void
	 */
	public static function inject_dynamic_email_template_styles() {
		if ( get_post_type() !== self::POST_TYPE ) {
			return;
		}

		[ 'primary_text_color' => $primary_text_color ] = newspack_get_theme_colors();

		?>
		<style type="text/css">
			.<?php echo esc_html( self::POST_TYPE ); ?>-has-primary-text-color,
			.<?php echo esc_html( self::POST_TYPE ); ?>-has-primary-text-color a {
				color: <?php echo esc_attr( $primary_text_color ); ?> !important;
			}

			.is-style-filled-primary-text li {
				background: transparent !important;
			}

			.is-style-filled-primary-text li svg {
				color: <?php echo esc_attr( $primary_text_color ); ?> !important;
			}
		</style>
		<?php
	}
}

Emails::init();
