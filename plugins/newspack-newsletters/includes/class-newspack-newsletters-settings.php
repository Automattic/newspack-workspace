<?php
/**
 * Newspack Newsletters Settings Page
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack\Newsletters\Subscription_Lists;

/**
 * Manages Settings page.
 */
class Newspack_Newsletters_Settings {
	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_plugin_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'page_init' ] );
		add_action( 'admin_head', [ __CLASS__, 'admin_head' ] );
		add_action( 'admin_footer', [ __CLASS__, 'admin_footer' ] );
		add_action( 'admin_init', [ __CLASS__, 'process_subscription_lists_update' ] );
		add_action( 'update_option_newspack_newsletters_public_posts_slug', [ __CLASS__, 'update_option_newspack_newsletters_public_posts_slug' ], 10, 2 );
	}

	/**
	 * Get newsletters settings url.
	 *
	 * @return string URL to settings page.
	 */
	public static function get_settings_url() {
		$url = admin_url( 'edit.php?post_type=newspack_nl_cpt&page=newspack-newsletters-settings-admin' );

		/**
		 * Filters the URL to the Newspack Newsletters settings page.
		 *
		 * @param string $url URL to the Newspack Newsletters settings page.
		 */
		return apply_filters( 'newspack_newsletters_settings_url', $url );
	}

	/**
	 * Retrieves list of settings.
	 *
	 * @return array Settings list.
	 */
	public static function get_settings_list() {

		$esp_list = [
			[
				'name'  => esc_html__( 'Select service provider', 'newspack-newsletter' ),
				'value' => '',
			],
		];

		foreach ( Newspack_Newsletters::get_registered_providers() as $provider_slug => $provider ) {
			$esp_list[] = [
				'name'  => $provider['name'],
				'value' => $provider_slug,
			];
		}

		$esp_list[] = [
			'name'  => esc_html__( 'Manual / Other', 'newspack-newsletters' ),
			'value' => 'manual',
		];

		$settings_list = array(
			array(
				'description' => esc_html__( 'Service Provider', 'newspack-newsletters' ),
				'key'         => 'newspack_newsletters_service_provider',
				'options'     => $esp_list,
				'type'        => 'select',
				'onboarding'  => true,
			),
			array(
				'description' => esc_html__( 'Mailchimp API Key', 'newspack-newsletters' ),
				'key'         => 'newspack_mailchimp_api_key',
				'type'        => 'text',
				'default'     => get_option( 'newspack_mailchimp_api_key', get_option( 'newspack_newsletters_mailchimp_api_key' ) ),
				'provider'    => 'mailchimp',
				'placeholder' => esc_attr( '123457103961b1f4dc0b2b2fd59c137b-us1' ),
				'help'        => esc_html__( 'Find or generate your API key', 'newspack-newsletter' ),
				'helpURL'     => esc_url( 'https://mailchimp.com/help/about-api-keys/#Find_or_generate_your_API_key' ),
				'onboarding'  => true,
			),
			array(
				'description'       => esc_html__( 'Append recommended Mailchimp footer to layouts', 'newspack-newsletters' ),
				'key'               => 'newspack_mailchimp_auto_append_footer',
				'type'              => 'checkbox',
				'default'           => false,
				'provider'          => 'mailchimp',
				'sanitize_callback' => 'boolval',
				'onboarding'        => true,
			),
			array(
				'description' => esc_html__( 'Constant Contact API Key', 'newspack-newsletters' ),
				'key'         => 'newspack_newsletters_constant_contact_api_key',
				'type'        => 'text',
				'provider'    => 'constant_contact',
				'onboarding'  => true,
			),
			array(
				'description' => esc_html__( 'Constant Contact API Secret', 'newspack-newsletters' ),
				'key'         => 'newspack_newsletters_constant_contact_api_secret',
				'type'        => 'text',
				'provider'    => 'constant_contact',
				'onboarding'  => true,
			),
			array(
				'description' => esc_html__( 'ActiveCampaign API URL', 'newspack-newsletters' ),
				'key'         => 'newspack_newsletters_active_campaign_url',
				'type'        => 'text',
				'provider'    => 'active_campaign',
				'onboarding'  => true,
			),
			array(
				'description' => esc_html__( 'ActiveCampaign API Key', 'newspack-newsletters' ),
				'key'         => 'newspack_newsletters_active_campaign_key',
				'type'        => 'text',
				'provider'    => 'active_campaign',
				'onboarding'  => true,
			),
			array(
				'default'           => 'newsletter',
				'description'       => esc_html__( 'Public Newsletter Posts Slug', 'newspack-newsletters' ),
				'key'               => 'newspack_newsletters_public_posts_slug',
				'sanitize_callback' => 'sanitize_title',
				'type'              => 'text',
				'onboarding'        => false,
			),

			/**
			 * Letterhead Creator key
			 *
			 * This key is required for folks who want to integrate promotions served through
			 * Letterhead into their newsletters. It's generally passed as a bearer token against LH
			 * API.
			 *
			 * @see https://help.tryletterhead.com/promotions-api-reference
			 */
			array(
				'default'     => '',
				'description' => esc_html__( 'Letterhead API Key', 'newspack-newsletters' ),
				'key'         => Newspack_Newsletters_Letterhead::LETTERHEAD_WP_OPTION_KEY,
				'type'        => 'text',
				'help'        => esc_html__( 'Promotions API reference', 'newspack-newsletter' ),
				'helpURL'     => esc_url( 'https://help.tryletterhead.com/promotions-api-reference' ),
				'onboarding'  => false,
			),

			/**
			 * Post Comments support.
			 */
			array(
				'default'           => false,
				'description'       => esc_html__( 'Allow comments to be enabled for public Newsletters', 'newspack-newsletters' ),
				'key'               => 'newspack_newsletters_support_comments',
				'sanitize_callback' => 'boolval',
				'type'              => 'checkbox',
				'onboarding'        => false,
			),
		);

		if ( class_exists( 'Jetpack' ) && \Jetpack::is_module_active( 'related-posts' ) ) {
			$settings_list[] = array(
				'description' => esc_html__( 'Disable Related Posts on public newsletter posts?', 'newspack-newsletters' ),
				'key'         => 'newspack_newsletters_disable_related_posts',
				'type'        => 'checkbox',
				'onboarding'  => false,
			);
		}

		/**
		 * Filters the settings list.
		 *
		 * @param array $settings_list The settings list.
		 */
		$settings_list = apply_filters( 'newspack_newsletters_settings_list', $settings_list );

		// Filter out options related to unsupported providers.
		$supported_providers = Newspack_Newsletters::get_supported_providers();
		$settings_list       = array_reduce(
			$settings_list,
			function ( $acc, $item ) use ( $supported_providers ) {
				if ( ! empty( $item['provider'] ) && ! in_array( $item['provider'], $supported_providers, true ) ) {
					return $acc;
				}
				if ( 'select' === $item['type'] && ! empty( $item['options'] ) ) {
					$item['options'] = array_values(
						array_filter(
							$item['options'],
							function ( $option ) use ( $supported_providers ) {
								return ! $option['value'] || in_array( $option['value'], $supported_providers, true );
							}
						)
					);
				}
				$default       = ! empty( $item['default'] ) ? $item['default'] : false;
				$item['value'] = get_option( $item['key'], $default );
				$acc[]         = $item;
				return $acc;
			},
			[]
		);

		return $settings_list;
	}

	/**
	 * Add options page
	 */
	public static function add_plugin_page() {
		add_submenu_page(
			'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			esc_html__( 'Newsletters Settings', 'newspack-newsletters' ),
			esc_html__( 'Settings', 'newspack-newsletters' ),
			'manage_options',
			'newspack-newsletters-settings-admin',
			[ __CLASS__, 'create_admin_page' ]
		);
	}

	/**
	 * Options page callback
	 */
	public static function create_admin_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Newsletters Settings', 'newspack-newsletters' ); ?></h1>
			<?php if ( Newspack_Newsletters::should_deprecate_campaign_monitor() ) : ?>
			<div class="newspack-newsletters-oauth notice notice-warning">
				<h2><?php esc_html_e( 'Campaign Monitor support will be deprecated', 'newspack-newsletters' ); ?></h2>
				<p><?php esc_html_e( 'Please connect a different service provider to ensure continued support.', 'newspack-newsletters' ); ?></p>
			</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php
				self::render_oauth_authorization();
				settings_fields( 'newspack_newsletters_options_group' );
				do_settings_sections( 'newspack-newsletters-settings-admin' );
				self::render_lists_table();
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render OAuth authorization.
	 */
	private static function render_oauth_authorization() {
		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) || ! method_exists( $provider, 'verify_token' ) ) {
			return;
		}
		$token = $provider->verify_token( true );
		if ( true === $token['valid'] || ! $token['auth_url'] ) {
			return;
		}
		?>
		<div class="newspack-newsletters-oauth notice notice-warning">
			<h2><?php esc_html_e( 'Authorize the application', 'newspack-newsletters' ); ?></h2>
			<p>
			<?php
			printf(
				/* translators: %s: email service provider name */
				esc_html__( 'Authorize %s to connect to Newspack.', 'newspack-newsletters' ),
				esc_html( $provider->name )
			);
			?>
				</p>
			<p><a href="<?php echo esc_url( $token['auth_url'] ); ?>" class="button"><?php esc_html_e( 'Authorize', 'newspack-newsletters' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Render table for subscription lists management.
	 */
	private static function render_lists_table() {
		$lists = Newspack_Newsletters_Subscription::get_lists();
		if ( is_wp_error( $lists ) || empty( $lists ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html( is_wp_error( $lists ) ? $lists->get_error_message() : __( 'No list data found.', 'newspack-newsletters' ) ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<div class="newspack-newsletters-subscription-lists">
			<h2><?php esc_html_e( 'Subscription Lists', 'newspack-newsletters' ); ?></h2>
			<?php if ( Subscription_Lists::get_add_new_url() ) : ?>
				<a class="primary button" id="newspack-newsletters-create" href="<?php echo esc_url( Subscription_Lists::get_add_new_url() ); ?>"><?php esc_html_e( 'Add new', 'newspack-newsletters' ); ?></a>
			<?php endif; ?>
			<div class="notice notice-warning changed-provider">
				<p><?php esc_html_e( 'Save changes to display the selected provider lists.', 'newspack-newsletters' ); ?></p>
			</div>
			<p><?php esc_html_e( 'Manage the lists available for subscription.', 'newspack-newsletters' ); ?></p>
			<table class="newspack-newsletters-lists-table">
				<thead>
					<tr>
						<th colspan="2" class="name"><?php esc_html_e( 'List name', 'newspack-newsletters' ); ?></th>
						<th class="details"><?php esc_html_e( 'List details', 'newspack-newsletters' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $lists as $list_index => $list ) :
						if ( ! is_array( $list ) || ! isset( $list['name'] ) ) {
							continue;
						}
						$checkbox_id = sprintf( 'newspack_newsletters_lists_%s_active', $list['id'] );
						?>
						<tr>
							<td class="active">
								<input
									id="<?php echo esc_attr( $checkbox_id ); ?>"
									type="checkbox"
									name="lists[<?php echo esc_attr( $list['id'] ); ?>][active]"
									<?php
									if ( $list['active'] ) {
										echo 'checked';
									}
									?>
								/>
							</td>
							<td class="name">
								<label for="<?php echo esc_attr( $checkbox_id ); ?>"><?php echo esc_html( $list['remote_name'] ); ?></strong>
								<br/>
								<small>
									<?php echo esc_html( $list['type_label'] ); ?>
									<?php if ( $list['edit_link'] ) : ?>
										(<a href="<?php echo esc_url( $list['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'newspack-newsletters' ); ?></a>)
									<?php endif; ?>
								</small>
							</td>
							<td class="details">
								<?php if ( 'local' === $list['type'] ) : ?>
									<b><?php echo esc_html( $list['title'] ); ?></b>
									<p><?php echo esc_html( $list['description'] ); ?></p>
									<input type="hidden" name="lists[<?php echo esc_attr( $list['id'] ); ?>][title]" value="<?php echo esc_attr( $list['title'] ); ?>" />
									<input type="hidden" name="lists[<?php echo esc_attr( $list['id'] ); ?>][description]" value="<?php echo esc_attr( $list['description'] ); ?>" />
								<?php else : ?>
									<input type="text" placeholder="<?php esc_attr_e( 'List title', 'newspack-newsletters' ); ?>" name="lists[<?php echo esc_attr( $list['id'] ); ?>][title]" value="<?php echo esc_attr( $list['title'] ); ?>" />
									<textarea placeholder="<?php esc_attr_e( 'List description', 'newspack-newsletters' ); ?>" name="lists[<?php echo esc_attr( $list['id'] ); ?>][description]"><?php echo esc_textarea( $list['description'] ); ?></textarea>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Print settings page styles.
	 */
	public static function admin_head() {
		if ( ! isset( $_GET['page'] ) || 'newspack-newsletters-settings-admin' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		?>
		<style>
			.newspack-newsletters-lists-table {
				width: 100%;
			}
			.newspack-newsletters-lists-table th,
			.newspack-newsletters-lists-table td {
				text-align: left;
				padding-bottom: 1em;
				vertical-align: top;
			}
			.newspack-newsletters-lists-table td input[type=text],
			.newspack-newsletters-lists-table td textarea {
				width: 100%;
				display: block;
				margin: 0 0 1rem;
			}
			.newspack-newsletters-lists-table td textarea {
				height: 80px;
			}
			.newspack-newsletters-lists-table .active {
				width: 1%;
			}
			.newspack-newsletters-lists-table .name {
				padding-right: 1rem;
			}
			.newspack-newsletters-lists-table .details {
				width: 85%;
			}
		</style>
		<?php
	}

	/**
	 * Print settings scripts.
	 */
	public static function admin_footer() {
		if ( ! isset( $_GET['page'] ) || 'newspack-newsletters-settings-admin' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		?>
		<script type="text/javascript">
			( function($) {
				$( document ).ready( function() {
					$( '.newspack-newsletters-subscription-lists' ).each( function() {
						var $container = $( this );
						var $changedNotice = $container.find( '.changed-provider' );
						$changedNotice.hide();
						$( 'select#newspack_newsletters_service_provider' ).on( 'change', function() {
							$container.hide();
							$changedNotice.show();
						} );
						$container.find( 'tr' ).each( function() {
							var $row      = $( this );
							var $checkbox = $row.find( 'input[type="checkbox"]' );
							var $inputs   = $row.find( 'input[type="text"],textarea' );
							$inputs.attr( 'disabled', ! $checkbox.is( ':checked' ) );
							$checkbox.on( 'change', function() {
								$inputs.attr( 'disabled', ! $checkbox.is( ':checked' ) );
							} );
						} );
					} );
					$( '.newspack-newsletters-oauth' ).each( function() {
						var $container = $( this );
						var $button = $container.find( '.button' );
						var authWindow;
						var onAuthorize = function() {
							location.reload();
						};
						$button.on( 'click', function( ev ) {
							ev.preventDefault();
							authWindow = window.open( $button.attr( 'href' ), 'newspack_newsletters_oauth', 'width=500,height=600' );
							authWindow.opener = { verify: onAuthorize };
						} );
					} );
				} );
			} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Process subscription lists update.
	 */
	public static function process_subscription_lists_update() {
		$action = 'newspack_newsletters_options_group';
		if ( ! isset( $_POST['option_page'] ) || $action !== $_POST['option_page'] ) {
			return;
		}
		if ( ! \check_admin_referer( "$action-options" ) ) {
			\wp_die( \esc_html__( 'Invalid request.', 'newspack-newsletters' ), '', 400 );
		}
		if ( ! isset( $_POST['lists'] ) ) {
			return;
		}
		if ( ! is_array( $_POST['lists'] ) ) {
			\wp_die( \esc_html__( 'Invalid request.', 'newspack-newsletters' ), '', 400 );
		}
		$lists = [];
		foreach ( $_POST['lists'] as $list_id => $list_data ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$lists[] = [
				'id'          => $list_id,
				'active'      => isset( $list_data['active'] ) ? (bool) $list_data['active'] : false,
				'title'       => isset( $list_data['title'] ) ? sanitize_text_field( $list_data['title'] ) : '',
				'description' => isset( $list_data['description'] ) ? sanitize_textarea_field( wp_unslash( $list_data['description'] ) ) : '',
			];
		}
		Newspack_Newsletters_Subscription::update_lists( $lists );
	}

	/**
	 * Register and add settings
	 */
	public static function page_init() {
		add_settings_section(
			'newspack_newsletters_options_group',
			null,
			null,
			'newspack-newsletters-settings-admin'
		);
		foreach ( self::get_settings_list() as $setting ) {
			$args = [
				'sanitize_callback' => ! empty( $setting['sanitize_callback'] ) ? $setting['sanitize_callback'] : 'sanitize_text_field',
			];
			register_setting(
				'newspack_newsletters_options_group',
				$setting['key'],
				$args
			);
			add_settings_field(
				$setting['key'],
				$setting['description'],
				[ __CLASS__, 'newspack_newsletters_settings_callback' ],
				'newspack-newsletters-settings-admin',
				'newspack_newsletters_options_group',
				$setting
			);
		}
	}

	/**
	 * Render settings fields.
	 *
	 * @param string $setting Setting key.
	 */
	public static function newspack_newsletters_settings_callback( $setting ) {
		$key         = $setting['key'];
		$type        = $setting['type'];
		$description = $setting['description'];
		$value       = empty( $setting['value'] ) && ! empty( $setting['default'] ) ? $setting['default'] : $setting['value'];

		if ( 'select' === $type ) {
			$options     = $setting['options'];
			$add_options = '';

			foreach ( $options as $option ) {
				$add_options .= '<option ' . ( $value === $option['value'] ? 'selected' : '' ) . ' value="' . esc_attr( $option['value'] ) . '">' . esc_html( $option['name'] ) . '</option>';
			}

			$allowed_html = array(
				'option' => array(
					'value'    => array(),
					'selected' => array(),
				),
			);

			printf(
				'<select id="%s" name="%s" value="%s">%s</select>',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value ),
				wp_kses( $add_options, $allowed_html )
			);
		} elseif ( 'checkbox' === $type ) {
			?>
				<input
					type="checkbox"
					id="<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					<?php if ( ! empty( $value ) ) : ?>
						checked
					<?php endif; ?>
				/>
			<?php
		} else {
			printf(
				'<input type="text" id="%s" name="%s" value="%s" class="widefat" />',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value )
			);
		}
	}

	/**
	 * Hook into public posts slug option after save, to flush permalinks.
	 *
	 * @param string $old_value The old value.
	 * @param string $new_value The new value.
	 */
	public static function update_option_newspack_newsletters_public_posts_slug( $old_value, $new_value ) {
		// Prevent empty slug value.
		if ( empty( $new_value ) ) {
			return update_option( 'newspack_newsletters_public_posts_slug', 'newsletter' ); // Return early to prevent flushing rewrite rules twice.
		}

		Newspack_Newsletters::register_cpt();
		flush_rewrite_rules(); // phpcs:ignore
	}

	/**
	 * Update settings.
	 *
	 * @param array $settings Update.
	 */
	public static function update_settings( $settings ) {
		foreach ( $settings as $key => $value ) {
			update_option( $key, $value );
		}
	}
}

if ( is_admin() ) {
	Newspack_Newsletters_Settings::init();
}
