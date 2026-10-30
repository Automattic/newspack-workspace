<?php
/**
 * Class for Newspack UI styles.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use Newspack\Newspack_UI_Icons;

/**
 * Class for reCAPTCHA integration.
 */
class Newspack_UI {
	/**
	 * The WooCommerce notice template being buffered, if any.
	 *
	 * @var string|null
	 */
	private static ?string $buffered_notice_template = null;

	/**
	 * Array of notices to display.
	 *
	 * @var array
	 */
	private static array $notices = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_assets' ] );
		\add_filter( 'the_content', [ __CLASS__, 'load_demo' ] );
		\add_filter( 'newspack_popups_assess_has_disabled_popups', [ __CLASS__, 'disable_prompts_on_demo' ] );
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'theme_colors_css' ] );
		// Only run if the site is using a block theme.
		if ( wp_theme_has_theme_json() ) {
			\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'colors_css_wrap' ] );
			\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'colors_css_wrap' ] );
		}
		add_filter( 'body_class', [ __CLASS__, 'add_block_theme_body_class' ] );
		add_action( 'wp_footer', [ __CLASS__, 'print_notices' ], 100 );
		add_action( 'woocommerce_before_template_part', [ __CLASS__, 'start_woocommerce_notice' ] );
		add_action( 'woocommerce_after_template_part', [ __CLASS__, 'add_woocommerce_notice_icons' ] );
		add_filter( 'woocommerce_kses_notice_allowed_tags', [ __CLASS__, 'allow_woocommerce_notice_icons' ], 20 );

		add_action( 'wp_ajax_newspack_ui_notice_dismissed', [ __CLASS__, 'ajax_notice_dismissed' ] );
		add_action( 'wp_ajax_nopriv_newspack_ui_notice_dismissed', [ __CLASS__, 'ajax_notice_dismissed' ] );
	}

	/**
	 * Mark pages rendered by a block theme, so newspack-ui styles that only suit block themes can be scoped to them.
	 *
	 * @param string[] $classes Body classes.
	 *
	 * @return string[]
	 */
	public static function add_block_theme_body_class( array $classes ): array {
		if ( wp_is_block_theme() ) {
			$classes[] = 'newspack-is-block-theme';
		}
		return $classes;
	}

	/**
	 * Enqueue assets for the Newspack UI.
	 */
	public static function enqueue_assets() {
		\wp_enqueue_style(
			'newspack-ui',
			Newspack::plugin_url() . '/dist/newspack-ui.css',
			[],
			Newspack::asset_version( 'newspack-ui' )
		);

		wp_enqueue_script(
			'newspack-ui',
			Newspack::plugin_url() . '/dist/newspack-ui.js',
			[ 'wp-util' ],
			Newspack::asset_version( 'newspack-ui' ),
			true
		);

		// Defer on the front end only; admin and block editor requests (is_admin()) are left untouched.
		if ( ! is_admin() ) {
			wp_script_add_data( 'newspack-ui', 'strategy', 'defer' );
		}

		$notice_icons = [];
		foreach ( self::get_notice_icons() as $type => $icon_name ) {
			$notice_icons[ $type ] = wp_kses( Newspack_UI_Icons::get_svg( $icon_name ), Newspack_UI_Icons::sanitize_svgs() );
		}
		wp_localize_script(
			'newspack-ui',
			'newspackUIData',
			[
				'noticeIcons' => $notice_icons,
				'closeIcon'   => wp_kses( Newspack_UI_Icons::get_svg( 'closeSmall' ), Newspack_UI_Icons::sanitize_svgs() ),
				'closeLabel'  => __( 'Close', 'newspack-plugin' ),
			]
		);
	}

	/**
	 * Icons for the snackbar notice types that carry one. Types absent from the
	 * map render no icon.
	 *
	 * @return array<string, string> Notice type => icon name.
	 */
	private static function get_type_icons(): array {
		return array_intersect_key( self::get_notice_icons(), array_flip( [ 'info', 'error', 'warning' ] ) );
	}

	/**
	 * Map of notice types to the Newspack UI icon each notice prints, for
	 * inline notices and field errors, plus the plain `check` that marks a
	 * field's success status line.
	 *
	 * @return array<string, string> Notice type => icon name.
	 */
	public static function get_notice_icons(): array {
		return [
			'info'    => 'info',
			'success' => 'published',
			'warning' => 'error',
			'error'   => 'caution',
			'check'   => 'check',
		];
	}

	/**
	 * WooCommerce notice templates mapped to their notice type, for the classic
	 * notices newspack-ui styles.
	 *
	 * @return array<string, string> Template name => notice type.
	 */
	private static function get_woocommerce_notice_templates(): array {
		return [
			'notices/success.php' => 'success',
			'notices/notice.php'  => 'info',
			'notices/error.php'   => 'error',
		];
	}

	/**
	 * Whether WooCommerce's classic notices render inside newspack-ui: the v1 My Account
	 * a signed-in reader sees, and the modal checkout.
	 *
	 * @return bool
	 */
	private static function woocommerce_notices_use_newspack_ui(): bool {
		$is_newspack_ui_account = function_exists( 'is_account_page' ) && \did_action( 'wp' ) && \is_account_page()
			&& \is_user_logged_in()
			&& Reader_Activation::is_enabled()
			&& version_compare( WooCommerce_My_Account::get_version(), '1.0.0', '>=' );
		$uses_newspack_ui       = $is_newspack_ui_account
			|| ( method_exists( 'Newspack_Blocks\Modal_Checkout', 'is_modal_checkout' ) && \Newspack_Blocks\Modal_Checkout::is_modal_checkout() );
		/**
		 * Filters whether WooCommerce's classic notices get newspack-ui notice icons.
		 *
		 * @param bool $uses_newspack_ui Whether the notices render inside newspack-ui.
		 */
		return (bool) \apply_filters( 'newspack_ui_woocommerce_notice_icons', $uses_newspack_ui );
	}

	/**
	 * Buffer a WooCommerce notice template so its notices can get an icon.
	 *
	 * @param string $template_name Template name.
	 */
	public static function start_woocommerce_notice( string $template_name ): void {
		if ( isset( self::get_woocommerce_notice_templates()[ $template_name ] ) && self::woocommerce_notices_use_newspack_ui() ) {
			ob_start();
			self::$buffered_notice_template = $template_name;
		}
	}

	/**
	 * Print a buffered WooCommerce notice template with the notice icon at the
	 * start of each notice: inside each classic success or info box, inside each
	 * item of a classic error list, and in place of the block notice banner's own icon.
	 *
	 * @param string $template_name Template name.
	 */
	public static function add_woocommerce_notice_icons( string $template_name ): void {
		if ( $template_name !== self::$buffered_notice_template ) {
			return;
		}
		self::$buffered_notice_template = null;
		$html = (string) ob_get_clean();
		$type = self::get_woocommerce_notice_templates()[ $template_name ];
		ob_start();
		self::print_notice_icon( $type );
		$icon = (string) ob_get_clean();

		if ( str_contains( $html, 'wc-block-components-notice-banner' ) ) {
			$html = preg_replace_callback(
				'#(<div\b[^>]*\bclass="wc-block-components-notice-banner\b[^"]*"[^>]*>\s*)<svg\b.*?</svg>(\s*<div\b[^>]*wc-block-components-notice-banner__content[^>]*>.*?</div>)#s',
				function ( $banner ) use ( $type, $icon ) {
					if ( 'info' !== $type || ! self::is_urgent_notice( $banner[2] ) ) {
						return $banner[1] . $icon . $banner[2];
					}
					ob_start();
					self::print_notice_icon( 'warning' );
					$warning_icon = (string) ob_get_clean();
					return str_replace( 'is-info', 'is-warning', $banner[1] ) . $warning_icon . $banner[2];
				},
				$html
			) ?? $html;
		} elseif ( 'error' === $type ) {
			$list_depth  = 0;
			$error_depth = null;
			$html        = preg_replace_callback(
				'#<(/?)(?:ul|ol)\b[^>]*>|<li\b[^>]*>#i',
				function ( $tag ) use ( &$list_depth, &$error_depth, $icon ) {
					if ( str_starts_with( strtolower( $tag[0] ), '<li' ) ) {
						return $error_depth === $list_depth ? $tag[0] . $icon : $tag[0];
					}
					if ( '/' === $tag[1] ) {
						if ( $error_depth === $list_depth ) {
							$error_depth = null;
						}
						--$list_depth;
					} else {
						++$list_depth;
						if ( null === $error_depth && preg_match( '#\bclass="woocommerce-error"#', $tag[0] ) ) {
							$error_depth = $list_depth;
						}
					}
					return $tag[0];
				},
				$html
			) ?? $html;
		} else {
			$html = preg_replace_callback(
				'#<div\b[^>]*\bclass="woocommerce-(message|info)"[^>]*>(.*?)</div>#s',
				function ( $box ) use ( $type, $icon ) {
					if ( 'info' !== $type || ! self::is_urgent_notice( $box[2] ) ) {
						return substr( $box[0], 0, strpos( $box[0], '>' ) + 1 ) . $icon . $box[2] . '</div>';
					}
					ob_start();
					self::print_notice_icon( 'warning' );
					$warning_icon = (string) ob_get_clean();
					$open         = str_replace( 'class="woocommerce-info"', 'class="woocommerce-info newspack-ui__notice--warning"', substr( $box[0], 0, strpos( $box[0], '>' ) + 1 ) );
					return $open . $warning_icon . $box[2] . '</div>';
				},
				$html
			) ?? $html;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Whether a printed WooCommerce notice is urgent, so it needs the warning type.
	 *
	 * @param string $notice_html The printed notice markup.
	 * @return bool
	 */
	private static function is_urgent_notice( string $notice_html ): bool {
		return (bool) \apply_filters( 'newspack_ui_notice_is_urgent', false, trim( \wp_strip_all_tags( $notice_html ) ) );
	}

	/**
	 * Let the notice icons through WooCommerce's notice sanitizing, which runs
	 * after the icons are added and would otherwise strip their attributes. Runs
	 * after WooCommerce Blocks, which replaces the `svg` and `path` rules.
	 *
	 * @param mixed $allowed_tags Allowed HTML elements and attributes, or a context name.
	 *
	 * @return mixed
	 */
	public static function allow_woocommerce_notice_icons( mixed $allowed_tags ): mixed {
		if ( ! is_array( $allowed_tags ) || ! self::woocommerce_notices_use_newspack_ui() ) {
			return $allowed_tags;
		}
		return array_replace_recursive( $allowed_tags, Newspack_UI_Icons::sanitize_svgs() );
	}

	/**
	 * Print the icon for a notice type.
	 *
	 * @param string $type Notice type: info, success, warning or error.
	 */
	public static function print_notice_icon( string $type ): void {
		$icons = self::get_notice_icons();
		if ( isset( $icons[ $type ] ) ) {
			Newspack_UI_Icons::print_svg( $icons[ $type ] );
		}
	}

	/**
	 * Print the show/hide button for a newspack-ui password field. It carries both
	 * eye icons; CSS shows the one that matches the field's current state.
	 */
	public static function print_password_toggle(): void {
		?>
		<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__password-toggle" aria-label="<?php \esc_attr_e( 'Show password', 'newspack-plugin' ); ?>" data-label-show="<?php \esc_attr_e( 'Show password', 'newspack-plugin' ); ?>" data-label-hide="<?php \esc_attr_e( 'Hide password', 'newspack-plugin' ); ?>">
			<?php Newspack_UI_Icons::print_svg( 'seen' ); ?>
			<?php Newspack_UI_Icons::print_svg( 'unseen' ); ?>
		</button>
		<?php
	}

	/**
	 * Add a snackbar notice.
	 *
	 * @param string       $message The notice message.
	 * @param string|array $args    Notice arguments array, or a notice type string as shorthand.
	 *
	 * @return string The notice ID.
	 */
	public static function add_notice( string $message, string|array $args = [] ): string {
		if ( is_string( $args ) ) {
			$args = [ 'type' => $args ];
		}
		$notice = wp_parse_args(
			$args,
			[
				'message'        => $message,
				'type'           => 'success', // Severity; drives the ARIA announcement politeness ('error' assertive, else polite), the type icon, and the [data-type] styling.
				'id'             => uniqid(),
				'autohide'       => true, // If false, the notice will have a close button. Errors never hide on their own.
				'active_on_load' => true, // Whether the notice should be visible on page load.
			]
		);
		if ( 'error' === $notice['type'] ) {
			$notice['autohide'] = false;
		}
		self::$notices[ $notice['id'] ] = $notice;

		return $notice['id'];
	}

	/**
	 * Print the notices.
	 */
	public static function print_notices() {
		if ( empty( self::$notices ) ) {
			return;
		}
		?>
		<div id="newspack-ui__notices" class="newspack-ui">
			<div class="newspack-ui__snackbar">
				<?php foreach ( self::$notices as $notice ) : ?>
					<div
						class="newspack-ui__snackbar__item"
						data-type="<?php echo esc_attr( $notice['type'] ); ?>"
						data-notice-id="<?php echo esc_attr( $notice['id'] ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'newspack_ui_notice_dismissed' ) ); ?>"
						data-autohide="<?php echo $notice['autohide'] ? 'true' : 'false'; ?>"
						data-active-on-load="<?php echo $notice['active_on_load'] ? 'true' : 'false'; ?>"
					>
						<?php $type_icons = self::get_type_icons(); ?>
						<?php if ( isset( $type_icons[ $notice['type'] ] ) ) : ?>
							<span class="newspack-ui__snackbar__icon"><?php Newspack_UI_Icons::print_svg( $type_icons[ $notice['type'] ] ); ?></span>
						<?php endif; ?>
						<div class="newspack-ui__snackbar__content">
							<?php echo wp_kses_post( $notice['message'] ); ?>
						</div>
						<?php if ( ! $notice['autohide'] ) : ?>
							<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost-dark newspack-ui__snackbar__close" aria-label="<?php esc_attr_e( 'Close', 'newspack-plugin' ); ?>" title="<?php esc_attr_e( 'Close', 'newspack-plugin' ); ?>">
								<?php Newspack_UI_Icons::print_svg( 'closeSmall' ); ?>
							</button>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Ajax handler when a notice is dismissed.
	 */
	public static function ajax_notice_dismissed() {
		check_ajax_referer( 'newspack_ui_notice_dismissed', 'nonce' );
		$notice_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( empty( $notice_id ) ) {
			wp_send_json_error( 'No notice ID provided' );
		}
		/**
		 * Fires when a notice is dismissed.
		 *
		 * @param string $notice_id The ID of the notice that was dismissed.
		 */
		do_action( 'newspack_ui_notice_dismissed', $notice_id );
		wp_send_json_success( 'Notice dismissed' );
	}

	/**
	 * Adds inline styles CSS for the theme colors.
	 */
	public static function theme_colors_css() {
		if ( ! function_exists( 'newspack_get_colors' ) || ! function_exists( 'newspack_color_with_contrast' ) ) {
			return;
		}
		$colors = newspack_get_colors();
		$custom_css = ':root {';
		$custom_css .= '--newspack-theme-color-primary: ' . esc_attr( $colors['primary'] ) . ';';
		$custom_css .= '--newspack-theme-color-primary-variation: ' . esc_attr( newspack_adjust_brightness( $colors['primary'], -30 ) ) . ';';
		$custom_css .= '--newspack-theme-color-secondary: ' . esc_attr( $colors['secondary'] ) . ' !important;';
		$custom_css .= '--newspack-theme-color-secondary-variation: ' . esc_attr( newspack_adjust_brightness( $colors['secondary'], -40 ) ) . ';';
		$custom_css .= '--newspack-theme-color-primary-against-white: ' . esc_attr( newspack_color_with_contrast( $colors['primary'] ) ) . ';';
		$custom_css .= '--newspack-theme-color-secondary-against-white: ' . esc_attr( newspack_color_with_contrast( $colors['secondary'] ) ) . ';';
		$custom_css .= '--newspack-theme-color-primary-variation-against-white: ' . esc_attr( newspack_color_with_contrast( newspack_adjust_brightness( $colors['primary'], -30 ) ) ) . ';';
		$custom_css .= '--newspack-theme-color-secondary-variation-against-white: ' . esc_attr( newspack_color_with_contrast( newspack_adjust_brightness( $colors['secondary'], -40 ) ) ) . ';';
		$custom_css .= '--newspack-theme-color-against-primary: ' . esc_attr( $colors['primary_contrast'] ) . ';';
		$custom_css .= '--newspack-theme-color-against-secondary: ' . esc_attr( $colors['secondary_contrast'] ) . ';';
		$custom_css .= '}';
		wp_add_inline_style( 'newspack-ui', $custom_css );
	}

	/**
	 * Adds inline styles CSS for the element/button colors from the theme.json.
	 * See: https://developer.wordpress.org/reference/functions/wp_get_global_styles/
	 */
	public static function colors_css_wrap() {
		$global_styles = wp_get_global_styles();

		$custom_css = 'body {';
		if ( isset( $global_styles['elements']['button']['color']['background'] ) ) {
			$custom_css .= '--newspack-ui-color-primary: ' . $global_styles['elements']['button']['color']['background'] . ';';
		}
		if ( isset( $global_styles['elements']['button']['color']['text'] ) ) {
			$custom_css .= '--newspack-ui-color-against-primary: ' . $global_styles['elements']['button']['color']['text'] . ';';
		}
		$custom_css .= '}';
		wp_add_inline_style( 'newspack-ui', $custom_css );
	}

	/**
	 * Generate markup for a Newspack UI modal.
	 *
	 * @param array $args {
	 *     Arguments for building the modal.
	 *     @type string $id The modal ID.
	 *     @type string $title The modal title.
	 *     @type string $content The modal content HTML.
	 *     @type bool   $content_is_safe Whether the content is already safe HTML.
	 *     @type bool   $hide_header Optional. Hide the title bar and Close button, as on a success screen. Escape and a
	 *                              swipe down still close the modal, and focus and the dialog's name move to the
	 *                              first heading in the content.
	 *     @type string $footer The modal footer HTML.
	 *     @type string $form The form method to use. If given, modal content and action buttons will be wrapped in a form element.
	 *     @type array $actions {
	 *         @type string $label The button label.
	 *         @type string|string[] $type The button type, or several combined variants such as [ 'destructive', 'outline' ].
	 *         @type string $action The action to perform when the button is clicked. Currently only: 'close' to close the modal.
	 *         @type string $url The URL to navigate to when the button is clicked.
	 *         @type bool   $autofocus Whether focus lands on this action when the modal or step opens.
	 *         @type array {
	 *               $fetch Data to trigger a REST API request.
	 *               @type string $url The URL to send the request to.
	 *               @type string $method The HTTP method to use.
	 *               @type string $nonce The nonce to use for the request.
	 *               @type array $body The body to send with a POST request.
	 *               @type array $params Parameters to send with a GET request.
	 *               @type string $next The step to show, or the ID for another modal to open, after the action is completed.
	 *         }
	 *     }
	 *     @type string $actions_layout Optional. A medium or larger modal or step prints its actions in a right-aligned
	 *                                  row at their natural width, filling the width on small screens; 'column' stacks
	 *                                  them at full width instead. A small or x-small modal or step always stacks them.
	 *     @type array $steps Optional. Steps shown one at a time in the same dialog, keyed by step name. Each step takes
	 *                        its own title, content, content_is_safe, footer, form, actions and hide_header, which default to empty
	 *                        rather than the modal's. Size, actions_layout, form_action and form_class fall back to the
	 *                        modal's. A step with a form gets its own form_id: the given one, else the modal's suffixed
	 *                        with the step name. The first step shows when the modal opens.
	 * }
	 */
	public static function generate_modal( $args ) {
		$args = wp_parse_args(
			$args,
			[
				'id'          => 'modal-' . wp_rand( 1, 1000 ),
				'size'        => 'small',
				'state'       => 'closed',
				'form_action' => '',
				'form_id'     => '',
				'form_class'  => '',
			]
		);
		$steps = ! empty( $args['steps'] ) && is_array( $args['steps'] ) ? $args['steps'] : [];
		?>
		<dialog id="newspack-my-account__<?php echo esc_attr( $args['id'] ); ?>" class="newspack-ui__modal-container" data-state="<?php echo esc_attr( $args['state'] ); ?>"<?php echo $steps ? ' data-step="' . esc_attr( array_key_first( $steps ) ) . '"' : ''; ?>>
			<div class="newspack-ui__modal-container__overlay"></div>
			<?php if ( $steps ) : ?>
			<div class="newspack-ui__modal newspack-ui__modal--steps">
				<div class="newspack-ui__modal__steps">
					<?php
					$is_first_step = true;
					foreach ( $steps as $step_name => $step ) :
						$step = array_merge(
							$args,
							[
								'title'           => '',
								'content'         => '',
								'content_is_safe' => false,
								'footer'          => '',
								'form'            => '',
								'form_id'         => $args['form_id'] ? $args['form_id'] . '-' . $step_name : '',
								'actions'         => [],
								'hide_header'     => false,
							],
							$step
						);
						?>
						<div class="newspack-ui__modal__step newspack-ui__modal--<?php echo esc_attr( $step['size'] ); ?>" data-step="<?php echo esc_attr( $step_name ); ?>"<?php echo $is_first_step ? '' : ' inert'; ?>>
							<?php self::render_modal_body( $step ); ?>
						</div>
						<?php
						$is_first_step = false;
					endforeach;
					?>
				</div>
			</div>
			<?php else : ?>
			<div class="newspack-ui__modal newspack-ui__modal--<?php echo esc_attr( $args['size'] ); ?>">
				<?php self::render_modal_body( $args ); ?>
			</div><!-- .newspack-ui__modal__small -->
			<?php endif; ?>
		</dialog>
		<?php
	}

	/**
	 * Render a modal's header, content, actions and footer.
	 *
	 * @param array $args Modal or step arguments, as described in generate_modal().
	 */
	private static function render_modal_body( array $args ): void {
		?>
				<header class="newspack-ui__modal__header"<?php echo empty( $args['hide_header'] ) ? '' : ' hidden'; ?>>
					<?php if ( ! empty( $args['title'] ) ) : ?>
						<h2><?php echo wp_kses_post( $args['title'] ); ?></h2>
					<?php endif; ?>
					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</header>

				<?php if ( ! empty( $args['form'] ) ) : ?>
				<form class="newspack-ui__modal__content <?php echo esc_attr( $args['form_class'] ?? '' ); ?>" method="<?php echo esc_attr( $args['form'] ); ?>"<?php echo $args['form_id'] ? ' id="' . esc_attr( $args['form_id'] ) . '"' : ''; ?><?php echo $args['form_action'] ? ' action="' . esc_url( $args['form_action'] ) . '"' : ''; ?>>
				<?php else : ?>
				<section class="newspack-ui__modal__content">
				<?php endif; ?>
						<?php
						if ( ! empty( $args['content_is_safe'] ) ) {
							echo $args['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo wp_kses(
								$args['content'],
								array_merge(
									\wp_kses_allowed_html( 'post' ),
									Newspack_UI_Icons::sanitize_svgs(),
									[
										'input'    => [
											'type'        => true,
											'name'        => true,
											'id'          => true,
											'class'       => true,
											'tabindex'    => true,
											'placeholder' => true,
											'required'    => true,
											'aria-hidden' => true,
											'aria-required' => true,
											'value'       => true,
											'disabled'    => true,
											'checked'     => true,
										],
										'select'   => [
											'name'         => true,
											'id'           => true,
											'class'        => true,
											'tabindex'     => true,
											'required'     => true,
											'aria-hidden'  => true,
											'aria-required' => true,
											'value'        => true,
											'disabled'     => true,
											'multiple'     => true,
											'autocomplete' => true,
											'data-label'   => true,
											'data-placeholder' => true,
										],
										'option'   => [
											'value'    => true,
											'selected' => true,
											'disabled' => true,
										],
										'noscript' => [],
										'iframe'   => [
											'src' => true,
										],
									]
								)
							);
						}
						?>
						<?php
						if ( ! empty( $args['actions'] ) ) :
							$actions_in_row = 'column' !== ( $args['actions_layout'] ?? '' ) && ! in_array( $args['size'] ?? 'small', [ 'small', 'x-small' ], true );
							if ( $actions_in_row ) :
								?>
								<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--justify-end newspack-ui__stack--gap-2 newspack-ui__spacing-top--5">
								<?php
							endif;
							foreach ( $args['actions'] as $action ) :
								$classes = [
									'newspack-ui__button',
									$actions_in_row ? 'newspack-ui__button--wide-on-mobile' : 'newspack-ui__button--wide',
								];
								foreach ( (array) ( $action['type'] ?? 'secondary' ) as $type ) {
									$classes[] = 'newspack-ui__button--' . $type;
								}
								if ( ! empty( $action['action'] ) ) {
									$classes[] = 'newspack-ui__modal__' . $action['action'];
								}
								$fetch_data = ! empty( $action['fetch'] ) ? 'data-fetch=' . \wp_json_encode( $action['fetch'] ) : '';
								?>
								<?php if ( isset( $action['url'] ) ) : ?>
								<a href="<?php echo esc_url( $action['url'] ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" <?php echo esc_attr( $fetch_data ); ?><?php echo ! empty( $action['autofocus'] ) ? ' data-autofocus' : ''; ?>>
									<span><?php echo wp_kses_post( $action['label'] ); ?></span>
								</a>
							<?php else : ?>
								<button type="submit" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" <?php echo esc_attr( $fetch_data ); ?><?php echo ! empty( $action['autofocus'] ) ? ' data-autofocus' : ''; ?>>
									<span><?php echo wp_kses_post( $action['label'] ); ?></span>
								</button>
								<?php
							endif;
						endforeach;
							if ( $actions_in_row ) :
								?>
								</div>
								<?php
							endif;
						endif;
						?>
				<?php if ( ! empty( $args['form'] ) ) : ?>
				</form>
				<?php else : ?>
				</section>
				<?php endif; ?>
				<?php if ( ! empty( $args['footer'] ) ) : ?>
				<footer class="newspack-ui__modal__footer">
					<?php echo wp_kses_post( $args['footer'] ); ?>
				</footer>
				<?php endif; ?>
		<?php
	}

	/**
	 * Make a page to demo these components
	 */
	public static function return_demo_content() {
		self::add_notice(
			'Unsubscribed from The Weekly. <a href="#notices">Undo</a>',
			[
				'id'             => 'ui-demo-snackbar-action',
				'active_on_load' => false,
			]
		);
		ob_start();
		self::generate_modal(
			[
				'id'      => 'ui-demo-confirm',
				'title'   => 'Cancel subscription',
				'content' => '<p>If you cancel now, your subscription will remain active until <strong>November 8, 2026</strong>.</p><p class="newspack-ui__color-text-gray">After this, your subscription access will end unless you choose to renew.</p>',
				'actions' => [
					'confirm' => [
						'label'  => 'Cancel subscription',
						'type'   => 'destructive',
						'action' => 'close',
					],
					'cancel'  => [
						'label'  => 'Keep subscription',
						'type'   => 'outline',
						'action' => 'close',
					],
				],
			]
		);
		$modal = ob_get_clean();
		ob_start();
		?>
		<div class="newspack-ui newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-9">
			<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-3">
				<h1>Component demo</h1>
				<nav class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-5" aria-label="Components">
					<a class="newspack-ui__link" href="#typography">Typography</a>
					<a class="newspack-ui__link" href="#buttons">Buttons</a>
					<a class="newspack-ui__link" href="#links">Links</a>
					<a class="newspack-ui__link" href="#form-elements">Inputs and select</a>
					<a class="newspack-ui__link" href="#choices">Checkboxes, radios and choice cards</a>
					<a class="newspack-ui__link" href="#badges">Badge and status</a>
					<a class="newspack-ui__link" href="#notices">Notices and snackbar</a>
					<a class="newspack-ui__link" href="#boxes">Boxes</a>
					<a class="newspack-ui__link" href="#tabs">Tabs and segmented control</a>
					<a class="newspack-ui__link" href="#dropdown">Dropdown and confirm dialog</a>
					<a class="newspack-ui__link" href="#order-table">Order table</a>
					<a class="newspack-ui__link" href="#modals">Modals</a>
				</nav>
			</div>

			<section id="typography" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Typography</h2>
				<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-4">
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Display, <code>newspack-ui__font--xl</code></p>
						<p class="newspack-ui__font--xl newspack-ui__font--bold">Thank you for your support</p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Title L, <code>newspack-ui__font--l</code></p>
						<p class="newspack-ui__font--l newspack-ui__font--bold">Account settings</p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Title M, <code>newspack-ui__font--m</code></p>
						<p class="newspack-ui__font--m newspack-ui__font--bold">Sign in or register</p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Strong, <code>newspack-ui__font--s newspack-ui__font--bold</code></p>
						<p class="newspack-ui__font--s newspack-ui__font--bold">Billing history</p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Body, <code>newspack-ui__font--s</code></p>
						<p class="newspack-ui__font--s">We sent a code to your email address.</p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Label, <code>newspack-ui__font--s newspack-ui__font--medium</code></p>
						<p class="newspack-ui__font--s newspack-ui__font--medium">Email address</p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Small, <code>newspack-ui__font--xs</code></p>
						<p class="newspack-ui__font--xs">This is how your name is displayed publicly.</p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
						<p class="newspack-ui__font--2xs newspack-ui__color-text-gray">Caption, <code>newspack-ui__font--2xs</code></p>
						<p class="newspack-ui__font--2xs">By signing in, you agree to our Terms of Service and Privacy Policy.</p>
					</div>
				</div>
			</section>

			<section id="buttons" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Buttons</h2>
				<p>Six variants: primary, secondary, outline, ghost, destructive (<code>--destructive --outline</code>) and destructive confirm (<code>--destructive</code>), the solid red only for the final step of a confirm dialog. <code>--accent</code> takes the publisher's primary color. Hover, press and keyboard focus are live.</p>

				<h3>Variants</h3>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2 newspack-ui__stack--align-start">
					<button type="button" class="newspack-ui__button newspack-ui__button--primary">Primary</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--secondary">Secondary</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--outline">Outline</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--ghost">Ghost</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--destructive newspack-ui__button--outline">Destructive</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--destructive">Destructive, confirm</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--accent">Accent</button>
				</div>

				<h3>States</h3>
				<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2">
				<?php
				$demo_button_variants = [
					'primary'              => 'Primary',
					'secondary'            => 'Secondary',
					'outline'              => 'Outline',
					'ghost'                => 'Ghost',
					'destructive--outline' => 'Destructive',
					'destructive'          => 'Destructive, confirm',
				];
				foreach ( $demo_button_variants as $demo_variant => $demo_label ) :
					$demo_classes = 'newspack-ui__button newspack-ui__button--' . str_replace( '--', ' newspack-ui__button--', $demo_variant );
					?>
					<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2 newspack-ui__stack--align-start">
						<button type="button" class="<?php echo esc_attr( $demo_classes ); ?>"><?php echo esc_html( $demo_label ); ?></button>
						<button type="button" class="<?php echo esc_attr( $demo_classes ); ?>" disabled>Disabled</button>
						<button type="button" class="<?php echo esc_attr( $demo_classes . ' newspack-ui__button--loading' ); ?>" aria-busy="true"><span>Loading</span></button>
					</div>
				<?php endforeach; ?>
				</div>

				<h3>Sizes and icons</h3>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2 newspack-ui__stack--align-center">
					<button type="button" class="newspack-ui__button newspack-ui__button--primary">Medium 48</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--small">Small 36</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--x-small">X-small 28</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small">
						Manage subscription
						<?php Newspack_UI_Icons::print_svg( 'chevronDownSmall' ); ?>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--small">
						<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
						Sign in
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--google-oauth">
						<?php Newspack_UI_Icons::print_svg( 'google' ); ?>
						Continue with Google
					</button>
				</div>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2 newspack-ui__stack--align-center">
					<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost">
						<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--primary">
						<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'Open menu', 'newspack-plugin' ); ?></span>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--secondary">
						<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'Open menu', 'newspack-plugin' ); ?></span>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--outline">
						<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'Open menu', 'newspack-plugin' ); ?></span>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__button--x-small">
						<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'More', 'newspack-plugin' ); ?></span>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__button--small">
						<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'More', 'newspack-plugin' ); ?></span>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__button--medium">
						<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'More', 'newspack-plugin' ); ?></span>
					</button>
				</div>

				<h3>Wide</h3>
				<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1">
					<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Continue</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--wide">Email me a code instead</button>
				</div>
			</section>

			<section id="links" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Links</h2>
				<p>For minor actions next to a primary button. The hit area grows to at least 24px tall, 44px under a coarse pointer, without changing the layout. A real alternative stays an outline button.</p>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-6 newspack-ui__stack--align-baseline">
					<a class="newspack-ui__link" href="#links">Forgot password</a>
					<button type="button" class="newspack-ui__link">Change</button>
					<a class="newspack-ui__link newspack-ui__link--muted" href="#links">Sign out</a>
					<button type="button" class="newspack-ui__link" disabled>Resend code in 0:57</button>
				</div>
			</section>

			<section id="form-elements" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Inputs and select</h2>
				<form class="newspack-ui__grid" onsubmit="return false;">
					<p>
						<label for="ui-demo-email">Email address <abbr class="newspack-ui__required" title="required">*</abbr></label>
						<input id="ui-demo-email" type="email" placeholder="Your email address">
					</p>
					<p>
						<label for="ui-demo-display-name">Display name</label>
						<input id="ui-demo-display-name" type="text" value="Robin Hale" aria-describedby="ui-demo-display-name-help">
						<span id="ui-demo-display-name-help" class="newspack-ui__helper-text">This is how your name is displayed publicly.</span>
					</p>
					<p>
						<label for="ui-demo-email-error">Email address <abbr class="newspack-ui__required" title="required">*</abbr></label>
						<input id="ui-demo-email-error" type="email" value="zoe@exmaple" aria-invalid="true" aria-describedby="ui-demo-email-error-message">
						<span id="ui-demo-email-error-message" class="newspack-ui__inline-error"><?php self::print_notice_icon( 'error' ); ?>Enter a valid email address.</span>
					</p>
					<div>
						<span class="newspack-ui__label-row newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--justify-between newspack-ui__stack--align-baseline newspack-ui__stack--gap-2 newspack-ui__spacing-bottom--1">
							<label for="ui-demo-password">Password</label>
							<a class="newspack-ui__link" href="#form-elements">Forgot password</a>
						</span>
						<div class="newspack-ui__password-field">
							<input id="ui-demo-password" type="password" value="correct horse">
							<?php self::print_password_toggle(); ?>
						</div>
					</div>
					<p>
						<label for="ui-demo-amount">Donation amount</label>
						<span class="newspack-ui__currency-input">
							<span class="newspack-ui__currency-input__currency">$</span>
							<input id="ui-demo-amount" type="number" value="25">
						</span>
					</p>
					<p>
						<label for="ui-demo-country">Country <span class="newspack-ui__label-optional">(optional)</span></label>
						<select id="ui-demo-country">
							<option value="us">United States</option>
							<option value="ca">Canada</option>
							<option value="gb">United Kingdom</option>
						</select>
					</p>
					<p>
						<label for="ui-demo-message">Message</label>
						<textarea id="ui-demo-message" rows="3" placeholder="Anything you'd like us to know"></textarea>
					</p>
					<p>
						<label for="ui-demo-disabled">Email address</label>
						<input id="ui-demo-disabled" type="email" value="robin@example.com" disabled>
					</p>
				</form>
			</section>

			<section id="choices" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Checkboxes, radios and choice cards</h2>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-6">
					<label><input type="checkbox"> Unchecked</label>
					<label><input type="checkbox" checked> Checked</label>
					<label><input type="checkbox" aria-invalid="true"> Error</label>
					<label><input type="checkbox" disabled> Disabled</label>
					<label><input type="checkbox" checked disabled> Checked, disabled</label>
				</div>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-6">
					<label><input type="radio" name="ui-demo-radio"> Radio</label>
					<label><input type="radio" name="ui-demo-radio" checked> Selected</label>
					<label><input type="radio" name="ui-demo-radio-error" aria-invalid="true"> Error</label>
					<label><input type="radio" name="ui-demo-radio-disabled" disabled> Disabled</label>
					<label><input type="radio" name="ui-demo-radio-disabled" checked disabled> Selected, disabled</label>
				</div>

				<div class="newspack-ui__grid newspack-ui__grid--cols-3">
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-3">
						<h3>Radio cards</h3>
						<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2">
							<label class="newspack-ui__input-card">
								<input type="radio" name="ui-demo-newsletter" checked>
								<strong>The Weekly</strong>
								<span class="newspack-ui__badge">Most popular</span>
								<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
							</label>
							<label class="newspack-ui__input-card">
								<input type="radio" name="ui-demo-newsletter">
								<strong>The Daily</strong>
								<span class="newspack-ui__helper-text">Every morning, the stories you need.</span>
							</label>
							<label class="newspack-ui__input-card">
								<input type="radio" name="ui-demo-newsletter" disabled>
								<strong>The Monthly</strong>
								<span class="newspack-ui__helper-text">Coming soon.</span>
							</label>
						</div>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-3">
						<h3>Group error</h3>
						<div class="newspack-ui__field-error newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2">
							<label class="newspack-ui__input-card">
								<input type="radio" name="ui-demo-amount-choice" aria-describedby="ui-demo-amount-choice-error">
								<strong>$5 a month</strong>
							</label>
							<label class="newspack-ui__input-card">
								<input type="radio" name="ui-demo-amount-choice" aria-describedby="ui-demo-amount-choice-error">
								<strong>$15 a month</strong>
							</label>
							<span id="ui-demo-amount-choice-error" class="newspack-ui__inline-error"><?php self::print_notice_icon( 'error' ); ?>Choose an amount to continue.</span>
						</div>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-3">
						<h3>Checkbox cards</h3>
						<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2">
							<label class="newspack-ui__input-card">
								<input type="checkbox" checked>
								<strong>Breaking news alerts</strong>
								<span class="newspack-ui__helper-text">Only when it matters.</span>
							</label>
							<label class="newspack-ui__input-card">
								<input type="checkbox">
								<strong>Weekly digest</strong>
								<span class="newspack-ui__helper-text">A weekly summary of our top stories.</span>
							</label>
						</div>
					</div>
				</div>
			</section>

			<section id="badges" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Badge and status</h2>
				<p>A badge is a label attached to a title or a card. A status is an icon and plain text, for status columns and list rows; Live keeps a pulsing dot.</p>
				<h3>Badge</h3>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2 newspack-ui__stack--align-center">
					<span class="newspack-ui__badge">Most popular</span>
					<span class="newspack-ui__badge newspack-ui__badge--primary">New</span>
					<span class="newspack-ui__badge newspack-ui__badge--secondary">5 seats</span>
					<span class="newspack-ui__badge newspack-ui__badge--outline">Outline</span>
					<span class="newspack-ui__badge newspack-ui__badge--success">Active</span>
					<span class="newspack-ui__badge newspack-ui__badge--warning">Pending</span>
					<span class="newspack-ui__badge newspack-ui__badge--error">Expired</span>
				</div>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2 newspack-ui__stack--align-center">
					<p class="newspack-ui__font--l newspack-ui__font--bold">Donate: Monthly</p>
					<span class="newspack-ui__badge newspack-ui__badge--success">Active</span>
				</div>
				<h3>Status</h3>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-5">
					<span class="newspack-ui__status newspack-ui__status--success"><?php Newspack_UI_Icons::print_svg( 'published' ); ?>Active</span>
					<span class="newspack-ui__status newspack-ui__status--success"><?php Newspack_UI_Icons::print_svg( 'published' ); ?>Paid</span>
					<span class="newspack-ui__status newspack-ui__status--warning"><?php Newspack_UI_Icons::print_svg( 'pending' ); ?>On hold</span>
					<span class="newspack-ui__status newspack-ui__status--warning"><?php Newspack_UI_Icons::print_svg( 'caution' ); ?>Payment failed</span>
					<span class="newspack-ui__status newspack-ui__status--warning"><?php Newspack_UI_Icons::print_svg( 'pending' ); ?>Pending payment</span>
					<span class="newspack-ui__status"><?php Newspack_UI_Icons::print_svg( 'scheduled' ); ?>Pending cancel</span>
					<span class="newspack-ui__status newspack-ui__status--error"><?php Newspack_UI_Icons::print_svg( 'notAllowed' ); ?>Cancelled</span>
					<span class="newspack-ui__status newspack-ui__status--warning"><?php Newspack_UI_Icons::print_svg( 'caution' ); ?>Failed</span>
					<span class="newspack-ui__status newspack-ui__status--error"><?php Newspack_UI_Icons::print_svg( 'notAllowed' ); ?>Expired</span>
					<span class="newspack-ui__status newspack-ui__status--live">Live</span>
				</div>
			</section>

			<section id="notices" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Notices and snackbar</h2>
				<div class="newspack-ui__notice" role="status">
					<?php self::print_notice_icon( 'info' ); ?>
					<div>
						<p>Your newsletter preferences are saved per email address.</p>
					</div>
				</div>
				<div class="newspack-ui__notice newspack-ui__notice--success" role="status">
					<?php self::print_notice_icon( 'success' ); ?>
					<div>
						<p>Your profile has been updated.</p>
					</div>
				</div>
				<div class="newspack-ui__notice newspack-ui__notice--warning" role="status">
					<?php self::print_notice_icon( 'warning' ); ?>
					<div>
						<p><strong>Your card expires soon</strong><br>Update your payment method before November 8 to keep your donation going.</p>
					</div>
				</div>
				<div class="newspack-ui__notice newspack-ui__notice--error" role="alert">
					<?php self::print_notice_icon( 'error' ); ?>
					<div>
						<p>We couldn't process your payment. Try another card.</p>
					</div>
				</div>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2">
					<button type="button" id="ui-demo-snackbar" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small">Show snackbar</button>
					<button type="button" id="ui-demo-snackbar-with-action" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small">Show snackbar with an action</button>
					<button type="button" id="ui-demo-snackbar-warning" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small">Show warning snackbar</button>
					<button type="button" id="ui-demo-snackbar-error" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small">Show error snackbar</button>
				</div>
			</section>

			<section id="boxes" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Boxes</h2>
				<div class="newspack-ui__box newspack-ui__spacing-bottom--0">
					<p class="newspack-ui__font--m newspack-ui__font--bold">Default box</p>
					<p>Groups related content on a subtle surface.</p>
				</div>
				<div class="newspack-ui__box newspack-ui__box--border newspack-ui__spacing-bottom--0">
					<div>
						<p class="newspack-ui__font--m newspack-ui__font--bold">Group subscription</p>
						<p>3 members, 1 pending invitation.</p>
					</div>
					<div class="newspack-ui__box__actions">
						<div class="newspack-ui__dropdown">
							<button type="button" class="newspack-ui__dropdown__toggle newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost" aria-expanded="false">
								<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( 'More', 'newspack-plugin' ); ?></span>
							</button>
							<div class="newspack-ui__dropdown__content">
								<ul>
									<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#boxes">Rename</a></li>
									<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#boxes">Invite members</a></li>
								</ul>
							</div>
						</div>
						<span class="newspack-ui__badge newspack-ui__badge--secondary">5 seats</span>
					</div>
				</div>
				<div class="newspack-ui__box newspack-ui__box--border newspack-ui__spacing-bottom--0">
					<p class="newspack-ui__font--m newspack-ui__font--bold">Thank you for your support</p>
					<p>Your monthly donation of $15.00 starts today. A receipt is on its way to zoe@example.com.</p>
				</div>
			</section>

			<section id="tabs" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Tabs and segmented control</h2>
				<p>Tabs switch views; the segmented control picks a value.</p>
				<h3>Tabs</h3>
				<div class="newspack-ui__tabs">
					<div class="newspack-ui__tabs__list" role="tablist">
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost selected">
							Members
							<span class="newspack-ui__badge newspack-ui__badge--secondary">12</span>
						</button>
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost">
							Invitations
							<span class="newspack-ui__badge newspack-ui__badge--secondary">3</span>
						</button>
					</div>
					<div class="newspack-ui__tabs__content">
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>12 people share this subscription.</p></div>
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>3 invitations are waiting for a reply.</p></div>
					</div>
				</div>
				<div class="newspack-ui__tabs newspack-ui__tabs--small">
					<div class="newspack-ui__tabs__list" role="tablist">
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost selected">Overview</button>
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost">Billing history</button>
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost" disabled>Invoices</button>
					</div>
					<div class="newspack-ui__tabs__content">
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>Your subscription renews on November 8, 2026.</p></div>
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>Your last payment was $15.00 on October 8, 2026.</p></div>
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>No invoices yet.</p></div>
					</div>
				</div>
				<div class="newspack-ui__tabs newspack-ui__tabs--small newspack-ui__tabs--stretch">
					<div class="newspack-ui__tabs__list" role="tablist">
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost selected">All</button>
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost">Active</button>
						<button type="button" role="tab" class="newspack-ui__button newspack-ui__button--ghost">Archived</button>
					</div>
					<div class="newspack-ui__tabs__content">
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>All your subscriptions.</p></div>
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>Subscriptions that renew.</p></div>
						<div class="newspack-ui__tabs__panel" role="tabpanel"><p>Subscriptions that ended.</p></div>
					</div>
				</div>

				<h3>Navigation, scrolling</h3>
				<nav class="newspack-ui__tabs newspack-ui__tabs--scroll" aria-label="Account">
					<div class="newspack-ui__tabs__list">
						<a href="#tabs">Account settings</a>
						<a href="#tabs">Newsletters</a>
						<a href="#tabs">Subscriptions</a>
						<a href="#tabs">Orders</a>
						<a href="#tabs" aria-current="page">Payment methods</a>
						<a href="#tabs">Addresses</a>
						<a href="#tabs">Groups</a>
					</div>
				</nav>

				<h3>Segmented control</h3>
				<div class="newspack-ui__segmented-control">
					<div class="newspack-ui__segmented-control__tabs">
						<button type="button" class="newspack-ui__button newspack-ui__button--small selected" aria-pressed="true">Monthly</button>
						<button type="button" class="newspack-ui__button newspack-ui__button--small" aria-pressed="false">Annually</button>
						<button type="button" class="newspack-ui__button newspack-ui__button--small" aria-pressed="false" disabled>Lifetime</button>
					</div>
					<div class="newspack-ui__segmented-control__content">
						<div class="newspack-ui__segmented-control__panel"><p>Billed every month.</p></div>
						<div class="newspack-ui__segmented-control__panel"><p>Billed once a year.</p></div>
						<div class="newspack-ui__segmented-control__panel"><p>Billed once.</p></div>
					</div>
				</div>
				<div class="newspack-ui__segmented-control__form-control">
					<label>Edition <abbr class="newspack-ui__required" title="required">*</abbr></label>
					<div class="newspack-ui__segmented-control__tabs">
						<button type="button" class="newspack-ui__button newspack-ui__button--small selected" aria-pressed="true">Print</button>
						<button type="button" class="newspack-ui__button newspack-ui__button--small" aria-pressed="false">Digital</button>
					</div>
				</div>
			</section>

			<section id="dropdown" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Dropdown and confirm dialog</h2>
				<p>The dropdown is a disclosure: a button with <code>aria-expanded</code> and a list of links. Escape closes it and returns focus to the button. The destructive item comes last.</p>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-2 newspack-ui__stack--align-start">
					<div class="newspack-ui__dropdown">
						<button type="button" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small newspack-ui__dropdown__toggle" aria-expanded="false">
							Manage subscription
							<?php Newspack_UI_Icons::print_svg( 'chevronDownSmall' ); ?>
						</button>
						<div class="newspack-ui__dropdown__content">
							<ul>
								<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#dropdown">Change payment</a></li>
								<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#dropdown">Renew now</a></li>
								<li><a class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive" href="#dropdown">Cancel</a></li>
							</ul>
						</div>
					</div>
					<button type="button" id="ui-demo-open-confirm" class="newspack-ui__button newspack-ui__button--destructive newspack-ui__button--outline newspack-ui__button--small">Cancel subscription</button>
				</div>
			</section>

			<section id="order-table" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Order table</h2>
				<div id="order_review" class="woocommerce-checkout-review-order newspack-ui__box newspack-ui__spacing-bottom--0">
					<table class="shop_table woocommerce-checkout-review-order-table">
						<thead>
							<tr>
								<th class="product-name">Product</th>
								<th class="product-total">Subtotal</th>
							</tr>
						</thead>
						<tbody>
							<tr class="cart_item">
								<td class="product-name">
									Donate: Yearly&nbsp; <strong class="product-quantity">×&nbsp;1</strong>
								</td>
								<td class="product-total">
									<span class="subscription-price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>180.00</bdi></span> <span class="subscription-details"> / year</span></span>
								</td>
							</tr>
						</tbody>
						<tfoot>
							<tr class="cart-subtotal">
								<th>Subtotal</th>
								<td><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>180.00</bdi></span></td>
							</tr>
							<tr class="tax-rate tax-rate-ca-bc-gst-5-1">
								<th>GST 5%</th>
								<td><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol">$</span>9.00</span></td>
							</tr>
							<tr class="tax-rate tax-rate-ca-bc-pst-7-2">
								<th>PST (7%)</th>
								<td><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol">$</span>12.60</span></td>
							</tr>
							<tr class="order-total">
								<th>Total</th>
								<td><strong><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>201.60</bdi></span></strong></td>
							</tr>
						</tfoot>
					</table>
				</div>
			</section>

			<section id="modals" class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<h2>Modals</h2>
				<p>Inline previews of modal content. The confirm dialog above opens a real modal: centered on wide screens, a bottom sheet under 600px.</p>
				<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--gap-5 newspack-ui__stack--align-start">
					<div class="newspack-ui__box newspack-ui__spacing-bottom--0">
						<div class="newspack-ui__modal newspack-ui__modal--small">
							<header class="newspack-ui__modal__header">
								<h2 class="newspack-ui__font--m">Sign in or register</h2>
								<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
									<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
									<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
								</button>
							</header>
							<section class="newspack-ui__modal__content">
								<form onsubmit="return false;">
									<p class="newspack-ui__spacing-bottom--0">
										<label for="ui-demo-auth-email">Email address</label>
										<input id="ui-demo-auth-email" type="email" placeholder="Your email address">
									</p>
									<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1 newspack-ui__spacing-top--5">
										<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Continue</button>
									</div>
									<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-3 newspack-ui__spacing-top--3">
										<div class="newspack-ui__word-divider">Or</div>
										<button type="button" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--google-oauth newspack-ui__button--wide">
											<?php Newspack_UI_Icons::print_svg( 'google' ); ?>
											Continue with Google
										</button>
									</div>
								</form>
							</section>
							<footer class="newspack-ui__modal__footer">
								<p>By signing in, you agree to our <a href="#modals">Terms of Service</a> and <a href="#modals">Privacy Policy</a>.</p>
							</footer>
						</div>
					</div>

					<div class="newspack-ui__box newspack-ui__spacing-bottom--0">
						<div class="newspack-ui__modal newspack-ui__modal--small">
							<header class="newspack-ui__modal__header">
								<h2 class="newspack-ui__font--m">Newsletters</h2>
								<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
									<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
									<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
								</button>
							</header>
							<section class="newspack-ui__modal__content">
								<p>Get the best of The News Paper directly to your email inbox.<br>
								<span class="newspack-ui__color-text-gray">Sending to: <strong>zoe@example.com</strong></span></p>
								<form class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2" onsubmit="return false;">
									<label class="newspack-ui__input-card">
										<input type="checkbox" checked>
										<strong>Daily News</strong>
										<span class="newspack-ui__helper-text">Get the latest news delivered to your inbox every morning.</span>
									</label>
									<label class="newspack-ui__input-card">
										<input type="checkbox">
										<strong>Weekly Digest</strong>
										<span class="newspack-ui__helper-text">A weekly summary of our top stories.</span>
									</label>
									<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-ui__spacing-top--3">Continue</button>
								</form>
							</section>
						</div>
					</div>

					<div class="newspack-ui__box newspack-ui__spacing-bottom--0">
						<div class="newspack-ui__modal newspack-ui__modal--small">
							<header class="newspack-ui__modal__header">
								<h2 class="newspack-ui__font--m">Change subscription</h2>
								<button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
									<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
									<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
								</button>
							</header>
							<section class="newspack-ui__modal__content">
								<div class="newspack-ui__segmented-control">
									<div class="newspack-ui__segmented-control__tabs">
										<button type="button" class="newspack-ui__button newspack-ui__button--small selected">Monthly</button>
										<button type="button" class="newspack-ui__button newspack-ui__button--small">Annually</button>
									</div>
									<div class="newspack-ui__segmented-control__content">
										<div class="newspack-ui__segmented-control__panel newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2">
											<label class="newspack-ui__input-card">
												<input type="radio" name="ui-demo-tier" checked>
												<strong>Supporter</strong>
												<span class="newspack-ui__helper-text">$5 a month</span>
											</label>
											<label class="newspack-ui__input-card">
												<input type="radio" name="ui-demo-tier">
												<strong>Champion</strong>
												<span class="newspack-ui__helper-text">$15 a month</span>
											</label>
										</div>
										<div class="newspack-ui__segmented-control__panel newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2">
											<label class="newspack-ui__input-card">
												<input type="radio" name="ui-demo-tier-annual">
												<strong>Supporter</strong>
												<span class="newspack-ui__helper-text">$50 a year</span>
											</label>
											<label class="newspack-ui__input-card">
												<input type="radio" name="ui-demo-tier-annual">
												<strong>Champion</strong>
												<span class="newspack-ui__helper-text">$150 a year</span>
											</label>
										</div>
									</div>
								</div>
								<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1 newspack-ui__spacing-top--5">
									<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Change subscription</button>
								</div>
							</section>
						</div>
					</div>
				</div>
			</section>
			<?php echo $modal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<script>
				( function() {
					document.getElementById( 'ui-demo-snackbar' ).addEventListener( 'click', function() {
						window.newspackUI.notices.createNotice( "You're signed in." );
					} );
					document.getElementById( 'ui-demo-snackbar-warning' ).addEventListener( 'click', function() {
						window.newspackUI.notices.createNotice( 'Your card expires soon.', 'warning' );
					} );
					document.getElementById( 'ui-demo-snackbar-error' ).addEventListener( 'click', function() {
						window.newspackUI.notices.createNotice( "We couldn't process your payment.", 'error' );
					} );
					var actionNotice = document.querySelector( '[data-notice-id="ui-demo-snackbar-action"]' );
					document.getElementById( 'ui-demo-snackbar-with-action' ).addEventListener( 'click', function() {
						actionNotice = actionNotice || document.querySelector( '[data-notice-id="ui-demo-snackbar-action"]' );
						if ( ! actionNotice.isConnected ) {
							document.querySelector( '#newspack-ui__notices .newspack-ui__snackbar' ).appendChild( actionNotice );
							void actionNotice.offsetWidth;
						}
						window.newspackUI.notices.openNotice( actionNotice, false );
					} );
					document.getElementById( 'ui-demo-open-confirm' ).addEventListener( 'click', function() {
						document.getElementById( 'newspack-my-account__ui-demo-confirm' ).setAttribute( 'data-state', 'open' );
					} );
				} )();
			</script>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Whether the current request shows the component demo.
	 *
	 * @return bool
	 */
	private static function is_demo_request(): bool {
		return isset( $_REQUEST['ui-demo'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Keep campaign prompts off the component demo, so they don't cover the components.
	 *
	 * @param bool $disabled Whether prompts are disabled.
	 * @return bool
	 */
	public static function disable_prompts_on_demo( $disabled ) {
		return self::is_demo_request() ? true : $disabled;
	}

	/**
	 * Append the demo content when the ui-demo query string is used.
	 *
	 * @param string $content The page content.
	 * @return string Modified $content with demo appended.
	 */
	public static function load_demo( $content ) {
		if ( self::is_demo_request() ) {
			$content .= self::return_demo_content();
		}
		return $content;
	}
}
Newspack_UI::init();
