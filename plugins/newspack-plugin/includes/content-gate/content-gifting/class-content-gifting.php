<?php
/**
 * Newspack Content Gifting functionality.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

/**
 * Content Gifting class.
 */
class Content_Gifting {
	/**
	 * The query arg for the content key.
	 *
	 * @var string
	 */
	const QUERY_ARG = 'content_key';

	/**
	 * The action for the content key generation.
	 *
	 * @var string
	 */
	const GENERATE_ACTION = 'newspack_generate_content_key';

	/**
	 * The meta for content gifting, both enabled option and user keys.
	 *
	 * @var string
	 */
	const META = 'newspack_content_gifting';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'hook_gift_button' ] );
		add_action( 'wp', [ __CLASS__, 'unrestrict_content' ], 5 );
		add_filter( 'newspack_content_gate_restrict_post', [ __CLASS__, 'restrict_post' ], 10, 2 );
		add_filter( 'newspack_content_gate_metering_short_circuit', [ __CLASS__, 'short_circuit_metering' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'localize_assets' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'localize_assets' ] );
		add_action( 'wp_ajax_' . self::GENERATE_ACTION, [ __CLASS__, 'ajax_generate_content_key' ] );
		add_action( 'wp_footer', [ __CLASS__, 'print_gift_modal' ] );
		add_action( 'newspack_content_gifting_enabled_status_changed', [ __CLASS__, 'update_jetpack_sharing_services' ] );
		add_filter( 'sharing_default_services', [ __CLASS__, 'filter_sharing_default_services' ] );

		require_once __DIR__ . '/class-content-gifting-cta.php';
	}

	/**
	 * Hook the gift button.
	 */
	public static function hook_gift_button() {
		if ( class_exists( 'Jetpack' ) && \Jetpack::is_module_active( 'sharedaddy' ) ) {
			add_filter( 'sharing_services', [ __CLASS__, 'filter_jetpack_sharing_services' ] );
		} else {
			add_action( 'newspack_theme_entry_meta', [ __CLASS__, 'print_gift_button' ] );
		}
	}


	/**
	 * Update Jetpack's sharing services to include the gift button upon enabling
	 * content gifting.
	 *
	 * @param bool $enabled Whether the content gifting is enabled.
	 *
	 * @return void
	 */
	public static function update_jetpack_sharing_services( $enabled ) {
		if ( ! $enabled ) {
			return;
		}
		if ( class_exists( 'Jetpack' ) && \Jetpack::is_module_active( 'sharedaddy' ) ) {
			$services = get_option( 'sharing-services' );
			// If not set, rely on the `sharing_default_services` filter and don't change anything.
			if ( ! is_array( $services ) ) {
				return;
			}
			if ( ! isset( $services['visible'] ) ) {
				return;
			}
			if ( in_array( 'newspack-gift-article', $services['visible'], true ) ) {
				return;
			}
			// Add at the top of the array.
			$services['visible'] = array_merge( [ 'newspack-gift-article' ], $services['visible'] );
			update_option( 'sharing-services', $services );

		}
	}

	/**
	 * Filter the sharing default services to include the gift button.
	 *
	 * @param array $services The sharing default services.
	 *
	 * @return array The filtered sharing default services.
	 */
	public static function filter_sharing_default_services( $services ) {
		if ( ! self::is_enabled() ) {
			return $services;
		}
		if ( ! isset( $services['visible'] ) ) {
			return $services;
		}
		if ( in_array( 'newspack-gift-article', $services['visible'], true ) ) {
			return $services;
		}
		$services['visible'] = array_merge( [ 'newspack-gift-article' ], $services['visible'] );
		return $services;
	}

	/**
	 * Filters the Jetpack sharing services to add the gift button.
	 *
	 * @param array $services The Jetpack sharing services.
	 *
	 * @return array The filtered Jetpack sharing services.
	 */
	public static function filter_jetpack_sharing_services( $services ) {
		if ( ! self::can_use_gifting() ) {
			return $services;
		}
		$services['newspack-gift-article'] = 'Newspack_Jetpack_Gift_Article';
		return $services;
	}

	/**
	 * Print the gift button to the entry meta.
	 */
	public static function print_gift_button() {
		if ( ! is_singular() ) {
			return;
		}
		if ( ! self::can_gift_post() ) {
			return;
		}
		$url = self::get_gift_url();
		?>
		<div class="newspack-ui newspack-content-gifting__gift-button">
			<a href="<?php echo esc_url( $url ); ?>" class="newspack-ui__button newspack-ui__button--x-small newspack-ui__button--outline">
				<?php Newspack_UI_Icons::print_svg( 'gift' ); ?>
				<?php esc_html_e( 'Gift this article', 'newspack-plugin' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Whether the current post has been gifted.
	 *
	 * @param int|null    $post_id The post ID. Default is the current post.
	 * @param string|null $key     The content key. Default is from the request (query arg or cookie).
	 *
	 * @return bool
	 */
	public static function is_gifted_post( $post_id = null, $key = null ) {
		if ( ! $key && ! isset( $_GET[ self::QUERY_ARG ] ) && ! isset( $_COOKIE['wp_newspack_content_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		if ( isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = sanitize_text_field( $_GET[ self::QUERY_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_COOKIE['wp_newspack_content_key'] ) ) {
			$key = sanitize_text_field( $_COOKIE['wp_newspack_content_key'] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		}
		if ( ! $key ) {
			return false;
		}

		if ( ! $post_id && ! is_singular() ) {
			return false;
		}

		$key_data = self::get_key_data( $post_id ?? get_the_ID(), $key );
		if ( ! $key_data ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the gift URL.
	 *
	 * @param int|null $post_id The post ID. Default is the current post.
	 *
	 * @return string The gift URL.
	 */
	public static function get_gift_url( $post_id = null ) {
		$post_id = $post_id ?? get_the_ID();
		return add_query_arg( self::GENERATE_ACTION, wp_create_nonce( self::GENERATE_ACTION ), get_permalink( $post_id ) );
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return [
			'enabled'              => self::is_enabled(),
			'limit'                => self::get_gifting_limit(),
			'interval'             => self::get_gifting_reset_interval(),
			'expiration_time'      => self::get_expiration_time(),
			'expiration_time_unit' => self::get_expiration_time_unit(),
			'cta_label'            => Content_Gifting_CTA::get_cta_label(),
			'button_label'         => Content_Gifting_CTA::get_button_label(),
			'cta_type'             => Content_Gifting_CTA::get_cta_type(),
			'cta_product_id'       => Content_Gifting_CTA::get_cta_product_id(),
			'cta_url'              => Content_Gifting_CTA::get_cta_url(),
			'style'                => Content_Gifting_CTA::get_style(),
		];
	}

	/**
	 * Get gifting limit.
	 *
	 * @return int The gifting limit.
	 */
	public static function get_gifting_limit() {
		return (int) get_option( 'newspack_content_gifting_limit', 10 );
	}

	/**
	 * Set gifting limit.
	 *
	 * @param int $limit The gifting limit.
	 *
	 * @return void
	 */
	public static function set_gifting_limit( $limit ) {
		update_option( 'newspack_content_gifting_limit', $limit, false );
	}

	/**
	 * Get gifting reset interval
	 *
	 * @return string The gifting reset interval.
	 */
	public static function get_gifting_reset_interval() {
		return (string) get_option( 'newspack_content_gifting_reset_interval', 'month' );
	}

	/**
	 * Set gifting reset interval.
	 *
	 * @param string $interval The gifting reset interval.
	 *
	 * @return void|WP_Error The gifting reset interval or an error.
	 */
	public static function set_gifting_reset_interval( $interval ) {
		$options = self::get_gifting_reset_interval_options();
		if ( ! isset( $options[ $interval ] ) ) {
			return new WP_Error( 'invalid_interval', __( 'Must be one of the following: day, week, month.', 'newspack-plugin' ) );
		}
		update_option( 'newspack_content_gifting_reset_interval', $interval, false );
	}

	/**
	 * Get gifting reset interval options.
	 *
	 * @return array The gifting reset interval options.
	 */
	public static function get_gifting_reset_interval_options() {
		return [
			'day'   => __( 'Day', 'newspack-plugin' ),
			'week'  => __( 'Week', 'newspack-plugin' ),
			'month' => __( 'Month', 'newspack-plugin' ),
		];
	}

	/**
	 * Get expiration time.
	 *
	 * @return int The expiration time.
	 */
	public static function get_expiration_time() {
		return (int) get_option( 'newspack_content_gifting_expiration_time', 5 );
	}

	/**
	 * Set expiration time.
	 *
	 * @param int $expiration_time The expiration time.
	 *
	 * @return void
	 */
	public static function set_expiration_time( $expiration_time ) {
		update_option( 'newspack_content_gifting_expiration_time', $expiration_time, false );
	}

	/**
	 * Get expiration time unit.
	 *
	 * @return string The expiration time unit.
	 */
	public static function get_expiration_time_unit() {
		return (string) get_option( 'newspack_content_gifting_expiration_time_unit', 'days' );
	}

	/**
	 * Set expiration time unit.
	 *
	 * @param string $expiration_time_unit The expiration time unit.
	 *
	 * @return void|WP_Error The expiration time unit or an error.
	 */
	public static function set_expiration_time_unit( $expiration_time_unit ) {
		$options = self::get_expiration_time_unit_options();
		if ( ! isset( $options[ $expiration_time_unit ] ) ) {
			return new WP_Error( 'invalid_time_unit', __( 'Must be one of the following: hours, days.', 'newspack-plugin' ) );
		}
		update_option( 'newspack_content_gifting_expiration_time_unit', $expiration_time_unit, false );
	}

	/**
	 * Get expiration time in seconds.
	 *
	 * @return int The expiration time in seconds.
	 */
	public static function get_expiration_time_in_seconds() {
		$expiration_time      = self::get_expiration_time();
		$expiration_time_unit = self::get_expiration_time_unit();
		switch ( $expiration_time_unit ) {
			case 'hours':
				return $expiration_time * HOUR_IN_SECONDS;
			case 'days':
				return $expiration_time * DAY_IN_SECONDS;
			default:
				return 0;
		}
	}

	/**
	 * Get expiration time label.
	 *
	 * @return string The expiration time label.
	 */
	public static function get_expiration_time_label() {
		$time = self::get_expiration_time();
		$unit = self::get_expiration_time_unit();

		$unit_labels = [
			'hours' => [
				'singular' => __( 'hour', 'newspack-plugin' ),
				'plural'   => __( 'hours', 'newspack-plugin' ),
			],
			'days'  => [
				'singular' => __( 'day', 'newspack-plugin' ),
				'plural'   => __( 'days', 'newspack-plugin' ),
			],
		];

		if ( ! isset( $unit_labels[ $unit ] ) ) {
			return '';
		}

		$labels = $unit_labels[ $unit ];
		return sprintf(
			// translators: %1$d is the expiration time, %2$s is the singular expiration time unit label, %3$s is the plural expiration time unit label.
			_n( '%1$d %2$s', '%1$d %3$s', $time, 'newspack-plugin' ), // phpcs:ignore WordPress.WP.I18n.MismatchedPlaceholders
			$time,
			$labels['singular'],
			$labels['plural']
		);
	}

	/**
	 * Get expiration time unit options.
	 *
	 * @return array The expiration time unit options.
	 */
	public static function get_expiration_time_unit_options() {
		return [
			'hours' => __( 'Hours', 'newspack-plugin' ),
			'days'  => __( 'Days', 'newspack-plugin' ),
		];
	}

	/**
	 * Whether content gifting can be used on the site.
	 *
	 * @param bool $return_errors Whether to return errors instead of a boolean.
	 *
	 * @return bool|WP_Error Whether content gifting can be used or an error.
	 */
	public static function can_use_gifting( $return_errors = false ) {
		$errors = new WP_Error();

		// Check whether content gifting is enabled.
		if ( ! self::is_enabled() ) {
			$errors->add( 'not_enabled', __( 'Content gifting is not enabled.', 'newspack-plugin' ) );
		}

		if ( $return_errors ) {
			return $errors;
		}

		return ! $errors->has_errors();
	}

	/**
	 * Whether content gifting is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$enabled = (bool) get_option( self::META, false );

		/**
		 * Filters whether content gifting is enabled.
		 *
		 * @param bool $enabled Whether content gifting is enabled.
		 */
		return apply_filters( 'newspack_content_gifting_enabled', $enabled );
	}

	/**
	 * Set the enabled status.
	 *
	 * @param bool $enabled Whether the content gifting is enabled.
	 *
	 * @return void
	 */
	public static function set_enabled( $enabled = true ) {
		update_option( self::META, (int) $enabled );
		/**
		 * Fires when the content gifting enabled status is changed.
		 *
		 * @param bool $enabled Whether the content gifting is enabled.
		 */
		do_action( 'newspack_content_gifting_enabled_status_changed', $enabled );
	}

	/**
	 * Should assets be enqueued?
	 *
	 * @return bool
	 */
	public static function should_enqueue_assets() {
		// Enqueue assets only if the user can gift the post, being accessed with a content key, in the admin or customizer.
		if ( self::can_gift_post() || self::is_gifted_post() || isset( $_GET[ self::QUERY_ARG ] ) || is_customize_preview() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return false;
	}

	/**
	 * Localize assets.
	 */
	public static function localize_assets() {
		if ( ! self::should_enqueue_assets() ) {
			return;
		}

		if ( is_singular() ) {
			wp_localize_script(
				'newspack-content-banner',
				'newspack_content_gifting',
				[
					'ajax_url'        => add_query_arg(
						[
							'action' => self::GENERATE_ACTION,
							'nonce'  => wp_create_nonce( self::GENERATE_ACTION ),
						],
						admin_url( 'admin-ajax.php' )
					),
					'post_id'         => get_the_ID(),
					'copied_label'    => __( 'Link copied', 'newspack-plugin' ),
					'expiration_time' => self::get_expiration_time_in_seconds(),
				]
			);
		}
	}

	/**
	 * Generate a content key.
	 *
	 * @return void
	 */
	public static function ajax_generate_content_key() {
		check_ajax_referer( self::GENERATE_ACTION, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'newspack-plugin' ) );
		}
		$key = self::generate_key( $post_id );

		$response = [];
		if ( is_wp_error( $key ) ) {
			$response['error'] = $key->get_error_message();
		}
		$response['body'] = self::get_gift_modal_info( $key );
		$response['key']  = ! is_wp_error( $key ) ? $key : null;
		$response['url']  = ! is_wp_error( $key ) ? add_query_arg( self::QUERY_ARG, $key, get_the_permalink( $post_id ) ) : get_the_permalink( $post_id );
		wp_send_json( $response );
	}

	/**
	 * Get the gift modal info.
	 *
	 * @param string|WP_Error $key The content key.
	 *
	 * @return string The gift modal info.
	 */
	public static function get_gift_modal_info( $key ) {
		$user_data          = get_user_meta( get_current_user_id(), self::META, true );
		$current_expiration = self::get_current_expiration_timestamp();
		$limit              = isset( $user_data['limits'][ $current_expiration ] ) ? self::get_gifting_limit() - $user_data['limits'][ $current_expiration ] : self::get_gifting_limit();
		$interval           = self::get_gifting_reset_interval();

		$interval_label_map = [
			'day'   => [ __( 'today', 'newspack-plugin' ), __( 'tomorrow', 'newspack-plugin' ) ],
			'week'  => [ __( 'this week', 'newspack-plugin' ), __( 'next week', 'newspack-plugin' ) ],
			'month' => [ __( 'this month', 'newspack-plugin' ), __( 'next month', 'newspack-plugin' ) ],
			'year'  => [ __( 'this year', 'newspack-plugin' ), __( 'next year', 'newspack-plugin' ) ],
		];
		$interval_labels = $interval_label_map[ $interval ];

		ob_start();
		?>
		<p>
			<?php
			if ( ! is_wp_error( $key ) ) {
				echo esc_html(
					sprintf(
						// translators: %s is the expiration time label.
						__( 'Give someone access to this article for %s.', 'newspack-plugin' ),
						self::get_expiration_time_label()
					)
				);
			}
			?>
			<strong>
				<?php
				if ( $limit > 0 ) {
					echo esc_html(
						sprintf(
							// translators: %1$d is the number of gift articles left, %2$s is the interval label.
							_n( 'You have %1$d gift article left %2$s.', 'You have %1$d gift articles left %2$s.', $limit, 'newspack-plugin' ),
							$limit,
							$interval_labels[0]
						)
					);
				} else {
					echo esc_html(
						sprintf(
							// translators: %1$d is the number of gift articles limit, %2$s is the interval label.
							__( 'You\'ve reached your limit of %1$d gifted articles %2$s.', 'newspack-plugin' ),
							self::get_gifting_limit(),
							$interval_labels[0]
						)
					);
				}
				?>
			</strong>
			<?php
			if ( is_wp_error( $key ) && $limit <= 0 ) {
				echo esc_html(
					sprintf(
						// translators: %s is the interval label.
						__( 'New ones will be available %s.', 'newspack-plugin' ),
						$interval_labels[1]
					)
				);
			}
			?>
		</p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Print the gift modal.
	 */
	public static function print_gift_modal() {
		?>
		<div class="newspack-ui">
			<div id="newspack-content-gifting-modal" class="newspack-ui__modal-container" data-state="closed">
				<div class="newspack-ui__modal-container__overlay"></div>
				<div class="newspack-ui__modal newspack-ui__modal--small">
					<header class="newspack-ui__modal__header">
						<h2><?php esc_html_e( 'Gift this article', 'newspack-plugin' ); ?></h2>
						<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
							<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
						</button>
					</header>
					<div class="newspack-ui__modal__content">
						<div class="newspack-ui__notice newspack-ui__notice--error" data-error-message></div>
						<div class="newspack-content-gifting__info"></div>
						<div class="newspack-content-gifting__link-container">
							<p>
								<label for="content-gifting-url"><?php esc_html_e( 'Link', 'newspack-plugin' ); ?></label>
								<input type="text" id="content-gifting-url" readonly />
								<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-content-gifting__copy-button" data-copy-button>
									<?php _e( 'Copy link', 'newspack-plugin' ); ?>
								</button>
							</p>
						</div>
						<div class="newspack-ui__spinner"><span></span></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the content key query arg to unrestrict content.
	 */
	public static function unrestrict_content() {
		if ( ! is_singular() ) {
			return;
		}
		if ( ! self::is_gifted_post( get_the_ID() ) ) {
			return;
		}

		if ( ! function_exists( 'wc_memberships' ) ) {
			return;
		}

		$restriction_instance = \wc_memberships()->get_restrictions_instance()->get_posts_restrictions_instance();
		\remove_action( 'wp', spl_object_hash( $restriction_instance ) . 'handle_restriction_modes', 9 );
		\remove_action( 'wp', spl_object_hash( $restriction_instance ) . 'handle_restriction_modes' ); // For compatibility with Woo Memberships < 1.27.2.
		\add_filter( 'wc_memberships_restrictable_comment_types', '__return_empty_array' );
		\add_filter( 'newspack_can_render_overlay_gate', '__return_false' );
	}

	/**
	 * Whether to restrict the post.
	 *
	 * @param bool $restrict Whether to restrict the post.
	 * @param int  $post_id  Post ID.
	 *
	 * @return bool
	 */
	public static function restrict_post( $restrict, $post_id ) {
		if ( self::is_gifted_post( $post_id ) ) {
			return false;
		}
		return $restrict;
	}

	/**
	 * Short-circuit the metering check.
	 *
	 * @param mixed $short_circuit Short-circuit value. Default is null.
	 *
	 * @return mixed Short-circuit value.
	 */
	public static function short_circuit_metering( $short_circuit ) {
		if ( self::is_gifted_post( get_the_ID() ) ) {
			return true;
		}
		return $short_circuit;
	}

	/**
	 * Whether the current user can gift a given post.
	 *
	 * @param int|null $post_id       Optional post ID. Default is the current post.
	 * @param bool     $return_errors Whether to return errors instead of a boolean.
	 *
	 * @return bool|WP_Error Whether the user can gift the post or an error.
	 */
	public static function can_gift_post( $post_id = null, $return_errors = false ) {
		$post_id = $post_id ?? get_the_ID();
		$errors  = new WP_Error();

		if ( ! self::is_enabled() ) {
			$errors->add( 'not_enabled', __( 'Content gifting is not enabled.', 'newspack-plugin' ) );
		}

		if ( ! is_user_logged_in() ) {
			$errors->add( 'not_logged_in', __( 'You must be logged in to gift content.', 'newspack-plugin' ) );
		}

		if ( ! Content_Gate::post_has_restrictions( $post_id ) ) {
			$errors->add( 'not_restricted', __( 'This post does not have any restrictions.', 'newspack-plugin' ) );
		}

		if ( Content_Gate::is_post_restricted( $post_id ) ) {
			$errors->add( 'post_restricted', __( 'User does not have access to this post.', 'newspack-plugin' ) );
		}

		if ( $return_errors ) {
			return $errors;
		}

		/**
		 * Filters whether the current user can gift a given post.
		 *
		 * @param bool $can_gift Whether the user can gift the post.
		 * @param int  $user_id  The user ID.
		 * @param int  $post_id  The post ID.
		 */
		return apply_filters( 'newspack_user_can_gift_post', ! $errors->has_errors(), get_current_user_id(), $post_id );
	}

	/**
	 * Get the data for a content key.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $key     The content key.
	 *
	 * @return array|false The data for the content key or false if invalid or expired.
	 */
	public static function get_key_data( $post_id, $key ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$parsed_key = explode( '|', $key );
		if ( count( $parsed_key ) !== 2 ) {
			return false;
		}

		$user_id = (int) $parsed_key[0];

		if ( ! $user_id ) {
			return false;
		}

		$data = get_user_meta( $user_id, self::META, true );
		if ( ! $data || ! isset( $data['keys'] ) ) {
			return false;
		}

		if ( ! isset( $data['keys'][ $post_id ] ) ) {
			return false;
		}

		if ( $data['keys'][ $post_id ]['timestamp'] + self::get_expiration_time_in_seconds() < time() ) {
			return false;
		}

		if ( $data['keys'][ $post_id ]['key'] !== $parsed_key[1] ) {
			return false;
		}

		return $data['keys'][ $post_id ];
	}

	/**
	 * Get the timestamp of the current expiration period for the gifting reset interval.
	 *
	 * @return int
	 */
	public static function get_current_expiration_timestamp() {
		$interval = self::get_gifting_reset_interval();
		switch ( $interval ) {
			case 'day':
				return strtotime( 'tomorrow' );
			case 'week':
				return strtotime( 'next monday' );
			case 'month':
				return mktime( 0, 0, 0, gmdate( 'n' ) + 1, 1 );
			default:
				return 0;
		}
	}

	/**
	 * Generate a key for a restricted post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string|WP_Error The key or error.
	 */
	public static function generate_key( $post_id ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'not_enabled', __( 'Content gifting is not enabled.', 'newspack-plugin' ) );
		}

		if ( ! self::can_gift_post( $post_id ) ) {
			return new WP_Error( 'not_allowed', __( 'You are not allowed to generate a content key.', 'newspack-plugin' ) );
		}

		$user_id = get_current_user_id();

		$user_data = get_user_meta( $user_id, self::META, true );
		if ( ! $user_data ) {
			$user_data = [
				'keys'   => [],
				'limits' => [],
			];
		}

		if ( ! isset( $user_data['keys'] ) ) {
			$user_data['keys'] = [];
		}
		if ( ! isset( $user_data['limits'] ) ) {
			$user_data['limits'] = [];
		}

		// Cleanup expired keys.
		foreach ( $user_data['keys'] as $key => $data ) {
			if ( $data['timestamp'] + self::get_expiration_time_in_seconds() < time() ) {
				unset( $user_data['keys'][ $key ] );
			}
		}

		// Return existing key if found.
		if ( isset( $user_data['keys'][ $post_id ] ) ) {
			return $user_id . '|' . $user_data['keys'][ $post_id ]['key'];
		}

		$interval           = self::get_gifting_reset_interval();
		$interval_options   = self::get_gifting_reset_interval_options();
		$current_expiration = self::get_current_expiration_timestamp();

		// Check if the user has reached their limit.
		if ( isset( $user_data['limits'][ $current_expiration ] ) && $user_data['limits'][ $current_expiration ] >= self::get_gifting_limit() ) {
			return new WP_Error(
				'limit_reached',
				sprintf(
					// translators: %1$d is the number of gift articles limit, %2$s is the interval.
					_n( 'You have reached the limit of %1$d gifted article for this %2$s.', 'You have reached the limit of %1$d gifted articles for this %2$s.', self::get_gifting_limit(), 'newspack-plugin' ),
					self::get_gifting_limit(),
					strtolower( $interval_options[ $interval ] )
				)
			);
		}

		// Add the new key.
		$key = wp_generate_password( 32, false );

		$user_data['keys'][ $post_id ] = [
			'key'       => $key,
			'timestamp' => time(),
		];

		// Update the user gifting limit.
		if ( ! isset( $user_data['limits'][ $current_expiration ] ) ) {
			$user_data['limits'][ $current_expiration ] = 0;
		}
		$user_data['limits'][ $current_expiration ]++;

		// Cleanup limits older than 60 days.
		foreach ( $user_data['limits'] as $timestamp => $limit ) {
			if ( $timestamp + 60 * DAY_IN_SECONDS < time() ) {
				unset( $user_data['limits'][ $timestamp ] );
			}
		}

		update_user_meta( $user_id, self::META, $user_data );

		return $user_id . '|' . $key;
	}
}
Content_Gifting::init();
