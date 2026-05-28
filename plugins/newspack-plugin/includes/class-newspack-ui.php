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
	 * Array of notices to display.
	 *
	 * @var array
	 */
	private static $notices = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_assets' ] );
		\add_filter( 'the_content', [ __CLASS__, 'load_demo' ] );
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'theme_colors_css' ] );
		// Only run if the site is using a block theme.
		if ( wp_theme_has_theme_json() ) {
			\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'colors_css_wrap' ] );
			\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'colors_css_wrap' ] );
		}
		add_action( 'wp_footer', [ __CLASS__, 'print_notices' ], 100 );

		add_action( 'wp_ajax_newspack_ui_notice_dismissed', [ __CLASS__, 'ajax_notice_dismissed' ] );
		add_action( 'wp_ajax_nopriv_newspack_ui_notice_dismissed', [ __CLASS__, 'ajax_notice_dismissed' ] );
	}

	/**
	 * Enqueue assets for the Newspack UI.
	 */
	public static function enqueue_assets() {
		\wp_enqueue_style(
			'newspack-ui',
			Newspack::plugin_url() . '/dist/newspack-ui.css',
			[],
			NEWSPACK_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'newspack-ui',
			Newspack::plugin_url() . '/dist/newspack-ui.js',
			[ 'wp-util' ],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Add a snackbar notice.
	 *
	 * @param string       $message The notice message.
	 * @param string|array $args    Notice arguments array or notice type.
	 *
	 * @return string The notice ID.
	 */
	public static function add_notice( $message, $args = [] ) {
		if ( is_string( $args ) ) {
			$args = [
				'type' => $args,
			];
		}
		$notice = wp_parse_args(
			$args,
			[
				'message'        => $message,
				'corner'         => 'top-right',
				'type'           => 'success',
				'id'             => uniqid(),
				'autohide'       => true, // If false, the notice will have a close button.
				'active_on_load' => true, // Whether the notice should be visible on page load.
			]
		);
		self::$notices[ $notice['corner'] ][ $notice['id'] ] = $notice;

		return $notice['id'];
	}

	/**
	 * Print the notices.
	 */
	public static function print_notices() {
		if ( empty( self::$notices ) ) {
			return;
		}

		foreach ( self::$notices as $corner => $notices ) {
			if ( empty( $notices ) ) {
				continue;
			}
			?>
			<div class="newspack-ui">
				<div class="newspack-ui__snackbar newspack-ui__snackbar--<?php echo esc_attr( $corner ); ?>">
					<?php foreach ( $notices as $notice ) : ?>
						<div
							class="newspack-ui__snackbar__item newspack-ui__snackbar__item--<?php echo esc_attr( $notice['type'] ); ?>"
							data-notice-id="<?php echo esc_attr( $notice['id'] ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'newspack_ui_notice_dismissed' ) ); ?>"
							data-autohide="<?php echo $notice['autohide'] ? 'true' : 'false'; ?>"
							data-active-on-load="<?php echo $notice['active_on_load'] ? 'true' : 'false'; ?>"
						>
							<?php if ( ! $notice['autohide'] ) : ?>
								<button class="newspack-ui__snackbar__close" aria-label="<?php esc_attr_e( 'Close', 'newspack-plugin' ); ?>" title="<?php esc_attr_e( 'Close', 'newspack-plugin' ); ?>">
									<?php Newspack_UI_Icons::print_svg( 'closeSmall' ); ?>
								</button>
							<?php endif; ?>
							<div class="newspack-ui__snackbar__content">
								<?php echo wp_kses_post( $notice['message'] ); ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}
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
	 *     @type string $footer The modal footer HTML.
	 *     @type string $form The form method to use. If given, modal content and action buttons will be wrapped in a form element.
	 *     @type array $actions {
	 *         @type string $label The button label.
	 *         @type string $type The button type.
	 *         @type string $action The action to perform when the button is clicked. Currently only: 'close' to close the modal.
	 *         @type string $url The URL to navigate to when the button is clicked.
	 *         @type array {
	 *               $fetch Data to trigger a REST API request.
	 *               @type string $url The URL to send the request to.
	 *               @type string $method The HTTP method to use.
	 *               @type string $nonce The nonce to use for the request.
	 *               @type array $body The body to send with a POST request.
	 *               @type array $params Parameters to send with a GET request.
	 *               @type string $next The ID for another modal to open after the action is completed.
	 *         }
	 *     }
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
			]
		);
		?>
		<div id="newspack-my-account__<?php echo esc_attr( $args['id'] ); ?>" class="newspack-ui__modal-container" data-state="<?php echo esc_attr( $args['state'] ); ?>">
			<div class="newspack-ui__modal-container__overlay"></div>
			<div class="newspack-ui__modal newspack-ui__modal--<?php echo esc_attr( $args['size'] ); ?>">
				<header class="newspack-ui__modal__header">
					<?php if ( ! empty( $args['title'] ) ) : ?>
						<h2 class="newspack-ui__font--l"><?php echo wp_kses_post( $args['title'] ); ?></h2>
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
							foreach ( $args['actions'] as $action ) :
								$classes = [
									'newspack-ui__button',
									'newspack-ui__button--wide',
									'newspack-ui__button--' . ( $action['type'] ?? 'secondary' ),
								];
								if ( ! empty( $action['action'] ) ) {
									$classes[] = 'newspack-ui__modal__' . $action['action'];
								}
								$fetch_data = ! empty( $action['fetch'] ) ? 'data-fetch=' . \wp_json_encode( $action['fetch'] ) : '';
								?>
								<?php if ( isset( $action['url'] ) ) : ?>
								<a href="<?php echo esc_url( $action['url'] ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" <?php echo esc_attr( $fetch_data ); ?>>
									<?php echo wp_kses_post( $action['label'] ); ?>
								</a>
							<?php else : ?>
								<button type="submit" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" <?php echo esc_attr( $fetch_data ); ?>>
									<?php echo wp_kses_post( $action['label'] ); ?>
								</button>
								<?php
							endif;
						endforeach;
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
			</div><!-- .newspack-ui__modal__small -->
		</div> <!-- .newspack-ui__modal-container -->
		<?php
	}

	/**
	 * Make a page to demo these components
	 */
	public static function return_demo_content() {
		ob_start();
		?>
		<div class="newspack-ui newspack-ui__demo">
			<h1>Component Demo</h1>

			<ul>
				<li><a href="?ui-demo#typography">Typography</a></li>
				<li><a href="?ui-demo#boxes">Boxes</a></li>
				<li><a href="?ui-demo#form-elements">Form Elements</a></li>
				<li><a href="?ui-demo#checkbox-radio-lists">Checkbox/Radio Lists</a></li>
				<li><a href="?ui-demo#order-table">Order table</a></li>
				<li><a href="?ui-demo#buttons">Buttons</a></li>
				<li><a href="#buttons-icon">Buttons Icon</a></li>
				<li><a href="?ui-demo#modals">Modals</a></li>
			</ul>

			<hr>

			<h2 id="typography">Typography</h2>

			<p class="newspack-ui__font--2xs">2X-Small text</p>
			<p class="newspack-ui__font--xs">X-Small text</p>
			<p>Small text (default)</p>
			<p class="newspack-ui__font--m">Medium text</p>
			<p class="newspack-ui__font--l">Large text</p>
			<p class="newspack-ui__font--xl">X-Large text</p>
			<p class="newspack-ui__font--2xl">2X-Large text</p>
			<p class="newspack-ui__font--3xl">3X-Large text</p>
			<p class="newspack-ui__font--4xl">4X-Large text</p>
			<p class="newspack-ui__font--5xl">5X-Large text</p>
			<p class="newspack-ui__font--6xl">6X-Large text</p>

			<hr>

			<h2 id="boxes">Boxes</h2> <?php // TODO: figure out correct name. ?>

			<div class="newspack-ui__box">
				<p>Default box style</p>
			</div>

			<div class="newspack-ui__box newspack-ui__box--border">
				<p>Border box style</p>
			</div>

			<div class="newspack-ui__box newspack-ui__box--success">
				<p>"Success" box style</p>
			</div>

			<div class="newspack-ui__box newspack-ui__box--success newspack-ui__box--text-center">
				<span class="newspack-ui__icon newspack-ui__icon--success">
					<?php Newspack_UI_Icons::print_svg( 'check' ); ?>
				</span>
				<p>
					<strong>Success box style, plus icon + <code>newspack-ui__box--text-center</code> class.</strong>
				</p>
				<p>Plus a little bit of text below it.</p>
			</div>

			<div class="newspack-ui__box newspack-ui__box--warning newspack-ui__box--text-center">
				<span class="newspack-ui__icon newspack-ui__icon--warning">
					<?php Newspack_UI_Icons::print_svg( 'check' ); ?>
				</span>
				<p>
					<strong>Warning box style, plus icon + <code>newspack-ui__box--text-center</code> class.</strong>
				</p>
				<p>Plus a little bit of text below it.</p>
			</div>

			<div class="newspack-ui__box newspack-ui__box--error newspack-ui__box--text-center">
				<span class="newspack-ui__icon newspack-ui__icon--error">
					<?php Newspack_UI_Icons::print_svg( 'check' ); ?>
				</span>
				<p>
					<strong>Error box style, plus icon + <code>newspack-ui__box--text-center</code> class.</strong>
				</p>
				<p>Plus a little bit of text below it.</p>
			</div>

			<div class="newspack-ui__box newspack-ui__box--border">
				<p>Box<br />with "more"-style dropdown menu</p>
				<div class="newspack-ui__dropdown">
					<button class="newspack-ui__dropdown__toggle newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost">
						<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( 'More', 'newspack-plugin' ); ?></span>
					</button>
					<div class="newspack-ui__dropdown__content">
						<ul>
							<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 1</a></li>
							<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 2</a></li>
							<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 3</a></li>
							<li><a class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive" href="#">Cancel</a></li>
						</ul>
					</div>
				</div>
			</div>

			<div class="newspack-ui__box newspack-ui__box--border newspack-ui__box--small">
				<p>Box<br />with "more"-style dropdown menu<br />and badge,<br />plus <code>newspack-ui__box--small</code> class.</p>
				<div class="newspack-ui__box__actions">
					<span class="newspack-ui__badge newspack-ui__badge--primary">Badge</span>
					<div class="newspack-ui__dropdown">
						<button class="newspack-ui__dropdown__toggle newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost">
							<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( 'More', 'newspack-plugin' ); ?></span>
						</button>
						<div class="newspack-ui__dropdown__content">
							<ul>
								<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 1</a></li>
								<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 2</a></li>
								<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 3</a></li>
								<li><a class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive" href="#">Cancel</a></li>
							</ul>
						</div>
					</div>
				</div>
			</div>

			<hr>

			<h2 id="notices">Notices</h2>

			<div class="newspack-ui__notice">
				<div>
					<p>Default notice style</p>
				</div>
			</div>

			<div class="newspack-ui__notice newspack-ui__notice--success">
				<div>
					<p>"Success" notice style</p>
				</div>
			</div>

			<div class="newspack-ui__notice newspack-ui__notice--warning">
				<div>
					<p>"Warning" notice style</p>
				</div>
			</div>

			<div class="newspack-ui__notice newspack-ui__notice--error">
				<div>
					<p>"Error" notice style</p>
				</div>
			</div>

			<div class="newspack-ui__notice">
				<?php Newspack_UI_Icons::print_svg( 'info' ); ?>
				<div>
					<p>Default notice with icon style</p>
				</div>
			</div>

			<div class="newspack-ui__notice newspack-ui__notice--success">
				<?php Newspack_UI_Icons::print_svg( 'check' ); ?>
				<div>
					<p>"Success" notice with icon style</p>
				</div>
			</div>

			<div class="newspack-ui__notice newspack-ui__notice--warning">
				<?php Newspack_UI_Icons::print_svg( 'info' ); ?>
				<div>
					<p>"Warning" notice with icon style</p>
				</div>
			</div>

			<div class="newspack-ui__notice newspack-ui__notice--error">
				<?php Newspack_UI_Icons::print_svg( 'error' ); ?>
				<div>
					<p>"Error" notice with icon style</p>
				</div>
			</div>
			<button id="show-snackbar-example" class="newspack-ui__button newspack-ui__button--primary">Show snackbar</button>
			<div class="newspack-ui__snackbar newspack-ui__snackbar--top-right">
				<div id="snackbar-example" class="newspack-ui__snackbar__item newspack-ui__snackbar__item--success" data-autohide="true">
					<div class="newspack-ui__snackbar__content">This is a snackbar message</div>
				</div>
			</div>
			<script>
				( function() {
					const snackbar = document.getElementById( 'snackbar-example' );
					const button = document.getElementById( 'show-snackbar-example' );
					button.addEventListener( 'click', function() {
						newspackUI.notices.openNotice( snackbar, false );
					} );
				} )();
			</script>

			<hr>

			<h2 id="form-elements">Form elements</h2>
			<form>
				<p>
					<label for="text-input-demo">Text input</label>
					<input type="text" placeholder="Regular text">
				</p>

				<p>
					<label for="email-input-demo">Email input <abbr class="newspack-ui__required" title="required">*</abbr></label>
					<input type="email" placeholder="Email Address">
				</p>

				<p>
					<label for="text-input-demo">Text input <span class="newspack-ui__label-optional">(additional text)</span> <abbr class="newspack-ui__required" title="required">*</abbr></label>
					<input type="text" placeholder="Regular text">
					<span class="newspack-ui__helper-text">Some helper text.</span>
				</p>

				<p>
					<label for="text-input-demo">Currency input</label>
					<div class="newspack-ui__currency-input">
						<span class="newspack-ui__currency-input__currency">$</span>
						<input type="number">
					</div>
				</p>

				<p>
					<label for="text-input-demo" class="newspack-ui__field-error">Text input <span class="newspack-ui__label-optional">(additional text)</span></label>
					<input type="text" placeholder="Regular text" class="newspack-ui__field-error">
					<span class="newspack-ui__helper-text">Some helper text.</span>
					<span class="newspack-ui__helper-text newspack-ui__inline-error">An error message.</span>
				</p>

				<p>
					<label>
						<input type="radio" name="radio-control-demo">
						This is a radio input.
						<span class="newspack-ui__helper-text">Some helper text.</span>
					</label>
				</p>

				<p>
					<label>
						<input type="radio" name="radio-control-demo">
						This is a radio input.
					</label>
				</p>

				<p>
					<label class="newspack-ui__field-error">
						<input type="radio" name="radio-control-demo">
						This is a radio input.
						<span class="newspack-ui__helper-text">Some helper text.</span>
						<span class="newspack-ui__helper-text newspack-ui__inline-error">An error message.</span>
					</label>
				</p>

				<p>
					<label>
						<input type="checkbox">
						This is a checkbox input.
					</label>
				</p>

				<p>
					<label class="newspack-ui__field-error">
						<input type="checkbox">
						This is a checkbox input.
					</label>
				</p>

				<p>
					<label for="select-control-demo">Select Controls</label>
					<select id="select-control-demo">
						<option value="1">Option 1</option>
						<option value="2">Option 2</option>
						<option value="3">Option 3</option>
					</select>
				</p>
			</form>


			<hr>

			<h2 id="checkbox-radio-lists">Checkbox/Radio Lists</h2>

			<label class="newspack-ui__input-card">
				<input type="radio" name="list-radio-option" checked>
				<strong>The Weekly</strong>
				<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
			</label>

			<label class="newspack-ui__input-card">
				<input type="radio" name="list-radio-option">
				<strong>The Weekly</strong>
				<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
			</label>

			<br>

			<label class="newspack-ui__input-card">
				<input type="checkbox" name="checkbox-option-1">
				<strong>The Weekly</strong>
				<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
			</label>

			<label class="newspack-ui__input-card">
				<input type="checkbox" name="checkbox-option-1">
				<strong>The Weekly</strong>
				<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
			</label>

			<label class="newspack-ui__input-card">
				<input type="checkbox" name="checkbox-option-2">
				<strong>The Weekly</strong>
				<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
			</label>

			<br>

			<label class="newspack-ui__input-card">
				<input type="radio" name="list-radio-option" checked>
				<strong>The Weekly</strong>
				<span class="newspack-ui__badge newspack-ui__badge--primary">Badge</span>
				<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
			</label>

			<label class="newspack-ui__input-card">
				<input type="radio" name="list-radio-option">
				<strong>The Weekly</strong>
				<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
			</label>

			<hr>

			<h2 id="order-table">Order table</h2>
			<h3 id="order_review_heading">Transaction details</h3>
			<div id="order_review" class="woocommerce-checkout-review-order newspack-ui__box">
				<table class="shop_table woocommerce-checkout-review-order-table" style="position: static; zoom: 1;">
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
							<td><strong><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>201.60</bdi></span></strong> </td>
						</tr>
					</tfoot>
				</table>
			</div>

			<hr>

			<h2 id="badges">Badges</h2>
			<span class="newspack-ui__badge newspack-ui__badge--primary">Badge</span><br>
			<span class="newspack-ui__badge newspack-ui__badge--secondary">Badge</span><br>
			<span class="newspack-ui__badge newspack-ui__badge--outline">Badge</span><br>
			<span class="newspack-ui__badge newspack-ui__badge--success">Badge</span><br>
			<span class="newspack-ui__badge newspack-ui__badge--error">Badge</span><br>
			<span class="newspack-ui__badge newspack-ui__badge--warning">Badge</span><br>


			<hr>

			<h2 id="buttons">Buttons</h2>
			<p><code>newspack-ui__button--primary</code>, <code>--accent</code>, <code>--secondary</code>, <code>--ghost</code>, and <code>--destructive</code> classes for colours/borders, and <code>newspack-ui__button--wide</code> for being 100% wide</p>
			<button class="newspack-ui__button newspack-ui__button--primary">Primary Button</button><br>
			<button class="newspack-ui__button newspack-ui__button--primary" disabled>Primary Button Disabled</button><br>
			<button class="newspack-ui__button newspack-ui__button--accent">Accent Button</button><br>
			<button class="newspack-ui__button newspack-ui__button--accent" disabled>Accent Button Disabled</button><br>
			<button class="newspack-ui__button newspack-ui__button--secondary">Secondary Button</button><br>
			<button class="newspack-ui__button newspack-ui__button--secondary" disabled>Secondary Button Disabled</button><br>
			<button class="newspack-ui__button newspack-ui__button--ghost">Ghost Button</button><br>
			<button class="newspack-ui__button newspack-ui__button--ghost" disabled>Ghost Button Disabled</button><br>
			<button class="newspack-ui__button newspack-ui__button--outline">Outline Button</button><br>
			<button class="newspack-ui__button newspack-ui__button--outline" disabled>Outline Button Disabled</button><br>
			<button class="newspack-ui__button newspack-ui__button--destructive">Destructive Button</button><br>
			<button class="newspack-ui__button newspack-ui__button--destructive" disabled>Destructive Button Disabled</button><br>
			<button class="newspack-ui__button newspack-ui__button--destructive newspack-ui__button--ghost">Destructive Ghost Button</button><br>
			<button class="newspack-ui__button newspack-ui__button--destructive newspack-ui__button--ghost" disabled>Destructive Ghost Button Disabled</button><br>
			<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--google-oauth">
				<?php Newspack_UI_Icons::print_svg( 'google' ); ?>
				<span>
					Sign in with Google
				</span>
			</button>

			<h3>Wide buttons</h3>
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Primary Button</button>
			<button class="newspack-ui__button newspack-ui__button--accent newspack-ui__button--wide">Accent Button</button>
			<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide">Secondary Button</button>
			<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide">Ghost Button</button>
			<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide">
				<?php Newspack_UI_Icons::print_svg( 'google' ); ?>
				<span>
					Sign up with Google
				</span>
			</button>

			<h3>Dropdown buttons</h3>
			<div class="newspack-ui__dropdown">
				<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__dropdown__toggle">
					<span><?php esc_html_e( 'More', 'newspack-plugin' ); ?></span>
					<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
				</button>
				<div class="newspack-ui__dropdown__content">
					<ul>
						<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 1</a></li>
						<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 2</a></li>
						<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 3</a></li>
						<li><a class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive" href="#">Cancel</a></li>
					</ul>
				</div>
			</div>
			<div class="newspack-ui__spacing-top--3"></div>
			<div class="newspack-ui__dropdown">
				<button class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small newspack-ui__dropdown__toggle">
					<span>Actions</span>
					<?php Newspack_UI_Icons::print_svg( 'arrowRight' ); ?>
				</button>
				<div class="newspack-ui__dropdown__content">
					<ul>
						<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 1</a></li>
						<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 2</a></li>
						<li><a class="newspack-ui__button newspack-ui__button--ghost" href="#">Dropdown item 3</a></li>
						<li><a class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive" href="#">Cancel</a></li>
					</ul>
				</div>
			</div>

			<hr>

			<p>Uses the <code>newspack-ui__button--x-small</code> and <code>newspack-ui__button--small</code> classes to get different sizes (medium is the default for this button styles).</p>

			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--x-small">X-Small Button</button><br />
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--x-small">
				<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
				X-Small Button
			</button><br />
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--x-small">
				X-Small Button
				<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
			</button><br />
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--small">Small Button</button><br />
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--small">
				<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
				Small Button
			</button><br />
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--small">
				Small Button
				<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
			</button><br />
			<button class="newspack-ui__button newspack-ui__button--primary">
				<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
				Medium Button (default)
			</button><br />
			<button class="newspack-ui__button newspack-ui__button--primary">
				Medium Button (default)
				<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
			</button>

			<hr>

			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--loading">
				<span>Loading Button</span>
			</button><br>

			<button class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small newspack-ui__button--loading">
				<span>Loading Button</span>
			</button><br>

			<button class="newspack-ui__button newspack-ui__button--destructive newspack-ui__button--x-small newspack-ui__button--loading">
				<span>Loading Button</span>
			</button>

			<hr>

			<h2>Segmented Controls</h2>

			<div class="newspack-ui__segmented-control">
				<div class="newspack-ui__segmented-control__tabs">
					<button class="newspack-ui__button newspack-ui__button--small selected">Tab One</button>
					<button class="newspack-ui__button newspack-ui__button--small">Tab Two</button>
					<button class="newspack-ui__button newspack-ui__button--small">Tab Three</button>
					<button class="newspack-ui__button newspack-ui__button--small">Tab Four</button>
					<button class="newspack-ui__button newspack-ui__button--small">Tab Five</button>
				</div>
			</div>

			<div class="newspack-ui__segmented-control__form-control newspack-ui__spacing-top--6">
				<label>Segmented Control (Form) <abbr class="newspack-ui__required" title="required">*</abbr></label>
				<div class="newspack-ui__segmented-control__tabs">
					<button class="newspack-ui__button newspack-ui__button--small selected">True</button>
					<button class="newspack-ui__button newspack-ui__button--small">False</button>
				</div>
			</div>

			<hr>

			<div class="newspack-ui__segmented-control">
				<div class="newspack-ui__segmented-control__tabs">
					<button class="newspack-ui__button newspack-ui__button--small selected">Monthly</button>
					<button class="newspack-ui__button newspack-ui__button--small">Annually</button>
				</div>
				<div class="newspack-ui__segmented-control__content">
					<div class="newspack-ui__segmented-control__panel">
						<label class="newspack-ui__input-card">
							<input type="checkbox" name="checkbox-option-1">
							<strong>Monthly Option 1</strong>
							<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
						</label>

						<label class="newspack-ui__input-card">
							<input type="checkbox" name="checkbox-option-1">
							<strong>Monthly Option 2</strong>
							<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
						</label>
					</div><!-- .newspack-ui__segmented-control__panel -->
					<div class="newspack-ui__segmented-control__panel">
						<label class="newspack-ui__input-card">
							<input type="checkbox" name="checkbox-option-1">
							<strong>Annual Option 1</strong>
							<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
						</label>

						<label class="newspack-ui__input-card">
							<input type="checkbox" name="checkbox-option-1">
							<strong>Annual Option 2</strong>
							<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
						</label>
					</div><!-- .newspack-ui__segmented-control__panel -->
				</div><!-- .newspack-ui__segmented-control__content -->
			</div><!-- .newspack-ui__segmented-control -->

			<hr>

			<h2 id="buttons-icon">Buttons Icon</h2>

			<p>Uses the same classes as the <code>newspack-ui__button</code> but we add an extra class to it <code>newspack-ui__button--icon</code></p>
			<button class="newspack-ui__button newspack-ui__button--icon">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--icon">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>
			<button class="newspack-ui__button newspack-ui__button--accent newspack-ui__button--icon">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>
			<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--icon">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>
			<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--icon">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>
			<button class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--icon">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>
			<button class="newspack-ui__button newspack-ui__button--destructive newspack-ui__button--icon">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>

			<p>Uses the <code>newspack-ui__button--small</code> and <code>newspack-ui__button--medium</code> CSS classes to get different sizes (x-small is the default).</p>

			<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--small">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>

			<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--medium">
				<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Open Menu', 'newspack-plugin' ); ?></span>
			</button>

			<hr>

			<h2 id="modals">Modals</h2>

			<div class="newspack-ui__box">

				<div class="newspack-ui__modal">
					<header class="newspack-ui__modal__header">
						<h2>This is a header</h2>
						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>

					<section class="newspack-ui__modal__content">
						<p>This is the modal content</p>
					</section>

					<footer class="newspack-ui__modal__footer">
						<p>This is the modal footer.</p>
					</footer>
				</div><!-- .newspack-ui__modal -->
			</div><!-- .newspack-ui__box -->

			<h2>Small size</h2>

			<div class="newspack-ui__box">
				<div class="newspack-ui__modal newspack-ui__modal--small">
					<header class="newspack-ui__modal__header">
						<h2>Auth Modal Contents Default</h2>

						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>

					<section class="newspack-ui__modal__content">

						<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--google-oauth newspack-ui__button--wide">
							<?php Newspack_UI_Icons::print_svg( 'google' ); ?>
							Sign in with Google
						</button>

						<div class="newspack-ui__word-divider">
							Or
						</div>

						<form>
							<p>
								<label for="email-input-demo">Email input</label>
								<input type="email" placeholder="Email Address">
							</p>

							<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Sign In</button>
							<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide">Sign in to existing account</button>
						</form>
					</section>

					<footer class="newspack-ui__modal__footer">
						<p>This is the modal footer.</p>
					</footer>
				</div><!-- .newspack-ui__modal--small -->
			</div><!-- .newspack-ui__box -->

			<div class="newspack-ui__box">
				<div class="newspack-ui__modal newspack-ui__modal--small">
					<header class="newspack-ui__modal__header">
						<h2>Auth Modal Contents OTP</h2>

						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>

					<section class="newspack-ui__modal__content">
						<form>
							<p>
								<label>Entry the code sent to your email</label>
								<div class="newspack-ui__code-input">
									<input type="text" maxlength="1">
									<input type="text" maxlength="1">
									<input type="text" maxlength="1">
									<input type="text" maxlength="1">
									<input type="text" maxlength="1">
									<input type="text" maxlength="1">
								</div>
							</p>

							<p class="newspack-ui__helper-text">Sign in by entering the code sent to email@address.com, or by clicking the magic link in the email.</p>

							<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Continue</button>
							<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide">Resend Code</button>
							<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide">Go Back</button>
						</form>
					</section>
				</div><!-- .newspack-ui__modal--small -->
			</div><!-- .newspack-ui__box -->

			<div class="newspack-ui__box">
				<div class="newspack-ui__modal newspack-ui__modal--small">
					<header class="newspack-ui__modal__header">
						<h2>Auth Modal Contents Success</h2>

						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>

					<section class="newspack-ui__modal__content">

						<div class="newspack-ui__box newspack-ui__box--success newspack-ui__box--text-center">
							<span class="newspack-ui__icon newspack-ui__icon--success">
							<?php Newspack_UI_Icons::print_svg( 'check' ); ?>
							</span>

							<p>
								<strong>Success! Your account was created and you're signed in.</strong>
							</p>

							<p>In the future, you'll sign in with a code sent to your email. If you'd rather use a password, you can set one in <a href="#">My Account</a>.</p>
						</div>


						<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Continue</button>
					</section>
				</div><!-- .newspack-ui__modal--small -->
			</div><!-- .newspack-ui__box -->


			<div class="newspack-ui__box">
				<div class="newspack-ui__modal newspack-ui__modal--small">
					<header class="newspack-ui__modal__header">
						<h2>Auth Modal Contents Success + PW</h2>

						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>

					<section class="newspack-ui__modal__content">

						<div class="newspack-ui__box newspack-ui__box--success newspack-ui__box--text-center">
							<span class="newspack-ui__icon newspack-ui__icon--success">
								<?php Newspack_UI_Icons::print_svg( 'check' ); ?>
							</span>

							<p>
								<strong>Success! Your account was created and you're signed in.</strong>
							</p>
						</div>

						<form>
							<p>
								<label>Set a display name</label>
								<input type="text">
								<span class="newspack-ui__helper-text">This will be used to address you in emails, and when you leave comments.</span>
							</p>

							<p>
								<label>Create password</label>
								<input type="password">
							</p>
							<p>
								<label>Confirm Password</label>
								<input type="password">
								<span class="newspack-ui__helper-text">If you don't set a password, you can always log in with a magic link or one-time code sent to your email.</span>
							</p>

							<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Continue</button>
							<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide">Skip for now</button>
						</form>
					</section>
				</div><!-- .newspack-ui__modal--small -->
			</div><!-- .newspack-ui__box -->


			<div class="newspack-ui__box">
				<div class="newspack-ui__modal newspack-ui__modal--small">
					<header class="newspack-ui__modal__header">
						<h2>Newsletters Signup Form</h2>

						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>

					<section class="newspack-ui__modal__content">

						<p>Get the best of The News Paper directly to your email inbox.<br>
						<span class="newspack-ui__color-text-gray">Sending to: <strong>email@address.</strong></span></p>

						<?php
						// Sample data for testing the newsletters signup form.
						$demo_email_address = 'demo@example.com';
						$demo_newsletters_lists = [
							[
								'id'          => '1',
								'title'       => 'Daily News',
								'description' => 'Get the latest news delivered to your inbox every morning.',
								'checked'     => true,
							],
							[
								'id'          => '2',
								'title'       => 'Weekly Digest',
								'description' => 'A weekly summary of our top stories.',
								'checked'     => false,
							],
							[
								'id'          => '3',
								'title'       => 'Breaking News',
								'description' => 'Receive instant alerts for breaking news stories.',
								'checked'     => false,
							],
							[
								'id'          => '4',
								'title'       => 'Sports Updates',
								'description' => 'Stay up to date with all the latest sports news.',
								'checked'     => false,
							],
							[
								'id'          => '5',
								'title'       => 'Technology News',
								'description' => 'The latest in technology and innovation.',
								'checked'     => false,
							],
						];
						$demo_default_list_size = 2;

						// Render the newsletters signup form, replicating the function structure.
						$loop_index = 0;
						?>
						<div class="newspack-ui newspack-newsletters-signup">
							<form method="post" target="_top">
								<input type="hidden" name="reader-activation-newsletters-signup" value="1" />
								<input type="hidden" name="email_address" value="<?php echo esc_attr( $demo_email_address ); ?>" />

								<?php $demo_has_overflow = count( $demo_newsletters_lists ) > (int) $demo_default_list_size; ?>
								<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2 overflow-hidden position-relative newsletter-list-container">
								<?php
								foreach ( $demo_newsletters_lists as $list ) {
									$checkbox_id   = sprintf( 'newspack-plugin-list-%s', $list['id'] );
									$is_peek       = $loop_index === (int) $demo_default_list_size;
									$is_hidden     = $loop_index > (int) $demo_default_list_size;
									$label_classes = 'newspack-ui__input-card' . ( $is_hidden ? ' hidden' : '' );
									$label_inert   = ( $is_peek || $is_hidden ) ? ' inert' : '';
									$loop_index++;
									?>
									<label class="<?php echo esc_attr( $label_classes ); ?>" for="<?php echo esc_attr( $checkbox_id ); ?>"<?php echo esc_attr( $label_inert ); ?>>
										<input
											type="checkbox"
											name="lists[]"
											value="<?php echo esc_attr( $list['id'] ); ?>"
											id="<?php echo esc_attr( $checkbox_id ); ?>"
											<?php
											if ( isset( $list['checked'] ) && $list['checked'] ) {
												echo 'checked';
											}
											?>
										>
										<strong><?php echo esc_html( $list['title'] ); ?></strong>
										<?php if ( ! empty( $list['description'] ) ) : ?>
											<span class="newspack-ui__helper-text"><?php echo esc_html( $list['description'] ); ?></span>
										<?php endif; ?>
									</label>
									<?php
								}
								?>
								<?php if ( $demo_has_overflow ) : ?>
									<div class="newspack-ui__gradient-divider"></div>
								<?php endif; ?>
								</div>

								<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2 newspack-ui__spacing-top--5">
									<?php if ( $demo_has_overflow ) : ?>
										<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary see-all-button" aria-label="<?php esc_attr_e( 'See all newsletters', 'newspack-plugin' ); ?>">
											<span aria-hidden="true"><?php esc_html_e( 'See all', 'newspack-plugin' ); ?></span>
											<?php Newspack_UI_Icons::print_svg( 'chevronDownSmall' ); ?>
										</button>
									<?php endif; ?>
									<button type="submit" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--primary"><?php esc_html_e( 'Continue', 'newspack-plugin' ); ?></button>
								</div>
							</form>
						</div>
						<script>
							( function() {
								const setupReveal = function( container ) {
									const seeAllButton = container.querySelector( '.see-all-button' );
									const newsletterContainer = container.querySelector( '.newsletter-list-container' );
									if ( ! seeAllButton || ! newsletterContainer ) {
										return;
									}
									const divider = newsletterContainer.querySelector( '.newspack-ui__gradient-divider' );
									const peekItem = newsletterContainer.querySelector( '.newspack-ui__input-card[inert]:not(.hidden)' );

									seeAllButton.addEventListener( 'click', function() {
										const firstRevealed = newsletterContainer.querySelector( '.newspack-ui__input-card[inert]' );
										newsletterContainer.querySelectorAll( '.newspack-ui__input-card[inert]' ).forEach( function( item ) {
											item.classList.remove( 'hidden' );
											item.removeAttribute( 'inert' );
										} );
										newsletterContainer.style.maxHeight = 'none';
										if ( divider ) {
											divider.classList.add( 'hidden' );
										}
										seeAllButton.classList.add( 'hidden' );
										if ( firstRevealed ) {
											const firstInput = firstRevealed.querySelector( 'input' );
											if ( firstInput ) {
												firstInput.focus();
											}
										}
									} );

									if ( peekItem ) {
										const peekAmount = ( divider && divider.offsetHeight ) || 32;
										newsletterContainer.style.maxHeight = ( peekItem.offsetTop + peekAmount ) + 'px';
									}
								};

								const container = document.querySelector( '.newspack-newsletters-signup' );
								if ( container ) {
									setupReveal( container );
								}
							} )();
						</script>
					</section>
				</div><!-- .newspack-ui__modal--small -->
			</div><!-- .newspack-ui__box -->

			<div class="newspack-ui__box">
				<div class="newspack-ui__modal newspack-ui__modal--small">
					<header class="newspack-ui__modal__header">
						<h2>Change Subscription</h2>

						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>

					<section class="newspack-ui__modal__content">
						<div class="newspack-ui__segmented-control">
							<div class="newspack-ui__segmented-control__tabs">
								<button class="newspack-ui__button newspack-ui__button--small selected">Monthly</button>
								<button class="newspack-ui__button newspack-ui__button--small">Annually</button>
							</div>
							<div class="newspack-ui__segmented-control__content">
								<div class="newspack-ui__segmented-control__panel">
									<label class="newspack-ui__input-card">
										<input type="checkbox" name="checkbox-option-1">
										<strong>Monthly Option 1</strong>
										<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
									</label>

									<label class="newspack-ui__input-card">
										<input type="checkbox" name="checkbox-option-1">
										<strong>Monthly Option 2</strong>
										<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
									</label>
								</div><!-- .newspack-ui__segmented-control__panel -->
								<div class="newspack-ui__segmented-control__panel">
									<label class="newspack-ui__input-card">
										<input type="checkbox" name="checkbox-option-1">
										<strong>Annual Option 1</strong>
										<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
									</label>

									<label class="newspack-ui__input-card">
										<input type="checkbox" name="checkbox-option-1">
										<strong>Annual Option 2</strong>
										<span class="newspack-ui__helper-text">Friday roundup of the most relevant stories.</span>
									</label>
								</div><!-- .newspack-ui__segmented-control__panel -->
							</div><!-- .newspack-ui__segmented-control__content -->
						</div><!-- .newspack-ui__segmented-control -->
						<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Change Subscription</button>
						<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide">Cancel</button>
					</section>
				</div><!-- .newspack-ui__modal--small -->
			</div><!-- .newspack-ui__box -->

			<button id="open-modal-example" class="newspack-ui__button newspack-ui__button--primary">Open Modal</button>
			<div id="newspack-modal-example" class="newspack-ui__modal-container">
				<div class="newspack-ui__modal-container__overlay"></div>
				<div class="newspack-ui__modal newspack-ui__modal--small">
						<header class="newspack-ui__modal__header">
							<h2>Auth Modal Contents Default</h2>

							<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
								<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
								<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
							</button>
						</header>

						<section class="newspack-ui__modal__content">

							<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--google-oauth newspack-ui__button--wide">
								<?php Newspack_UI_Icons::print_svg( 'google', 20 ); ?>
								Sign in with Google
							</button>

							<div class="newspack-ui__word-divider">
								Or
							</div>

							<form>
								<p>
									<label for="email-input-demo">Email input</label>
									<input type="email" placeholder="Email Address">
								</p>

								<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Sign In</button>
								<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide">Sign in to existing account</button>
							</form>
						</section>

						<footer class="newspack-ui__modal__footer">
							<p>This is the modal footer.</p>
						</footer>
					</div><!-- .newspack-ui__modal__small -->
			</div> <!-- .newspack-ui__modal-container -->
			<script>
				( function() {
					var newspackModal = document.getElementById( 'newspack-modal-example' );
					var openModal = document.getElementById( 'open-modal-example' );
					var closeModal = newspackModal.querySelector( '.newspack-ui__modal__close' );
					openModal.onclick = function() {
						newspackModal.setAttribute( 'data-state', 'open' );
					}
					closeModal.onclick = function() {
						newspackModal.setAttribute( 'data-state', 'closed' );
					}
				} )();
			</script>

		</div><!-- .newspack-ui -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Append the demo content when the ui-demo query string is used.
	 *
	 * @param string $content The page content.
	 * @return string Modified $content with demo appended.
	 */
	public static function load_demo( $content ) {
		if ( isset( $_REQUEST['ui-demo'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$content .= self::return_demo_content();
		}
		return $content;
	}
}
Newspack_UI::init();
