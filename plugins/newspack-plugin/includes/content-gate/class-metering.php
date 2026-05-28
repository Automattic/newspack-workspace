<?php
/**
 * WooCommerce Content Gate Metering.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * WooCommerce Content Gate Metering class.
 */
class Metering {

	const METERING_META_KEY = 'np_content_metering';

	/**
	 * Article view activity to be handled by frontend metering.
	 *
	 * @var array|null
	 */
	private static $article_view = null;

	/**
	 * Cache of the user's metering status for posts.
	 *
	 * @var boolean[] Map of post IDs to booleans.
	 */
	private static $logged_in_metering_cache = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'newspack_content_gate_restrict_post', [ __CLASS__, 'restrict_post' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ], 11 ); // Render after gate layout editor.
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'wp_footer', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_frontend_metering_gate' ] );
		add_filter( 'newspack_reader_activity_article_view', [ __CLASS__, 'get_article_view' ], 20 );
	}

	/**
	 * Whether to restrict the post.
	 *
	 * @param bool $restrict Whether to restrict the post.
	 *
	 * @return bool
	 */
	public static function restrict_post( $restrict ) {
		if ( $restrict && self::is_metering() ) {
			return false;
		}
		return $restrict;
	}

	/**
	 * Block editor assets for metering settings.
	 */
	public static function enqueue_block_editor_assets() {
		if ( ! in_array( get_post_type(), Content_Gate::get_gate_post_types(), true ) ) {
			return;
		}
		$asset = require dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-editor-metering.asset.php';
		wp_enqueue_script( 'newspack-content-gate-editor-metering', Newspack::plugin_url() . '/dist/content-gate-editor-metering.js', $asset['dependencies'], $asset['version'], true );
	}

	/**
	 * Render the frontend metering gate.
	 */
	public static function render_frontend_metering_gate() {
		if ( ! \is_singular() || ! Content_Gate::is_post_restricted() || ! self::is_frontend_metering() ) {
			return;
		}
		Content_Gate::mark_gate_as_rendered();
		echo '<div style="display:none">' . Content_Gate::get_inline_gate_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Register gate meta.
	 */
	public static function register_meta() {
		$meta = [
			'metering'                  => [
				'type'    => 'boolean',
				'default' => false,
			],
			'metering_anonymous_count'  => [
				'type'    => 'integer',
				'default' => 0,
			],
			'metering_registered_count' => [
				'type'    => 'integer',
				'default' => 0,
			],
			'metering_period'           => [
				'type'    => 'string',
				'default' => 'week',
			],
		];
		foreach ( Content_Gate::get_gate_post_types() as $cpt ) {
			foreach ( $meta as $key => $config ) {
				\register_meta(
					'post',
					$key,
					[
						'object_subtype' => $cpt,
						'show_in_rest'   => true,
						'type'           => $config['type'],
						'default'        => $config['default'],
						'single'         => true,
					]
				);
			}
		}
	}

	/**
	 * Get metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Metering settings.
	 */
	public static function get_metering_settings( $gate_id ) {
		$anonymous_settings  = self::get_anonymous_settings( $gate_id );
		$registered_settings = self::get_registered_settings( $gate_id );
		return [
			'enabled'           => $anonymous_settings['enabled'] || $registered_settings['enabled'],
			'period'            => $anonymous_settings['period'], // Legacy property, equivalent to anonymous_period.
			'anonymous_count'   => $anonymous_settings['count'],
			'anonymous_period'  => $anonymous_settings['period'],
			'registered_count'  => $registered_settings['count'],
			'registered_period' => $registered_settings['period'],
		];
	}

	/**
	 * Get legacy metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Metering settings.
	 */
	protected static function get_legacy_metering_settings( $gate_id ) {
		return [
			'enabled'          => (bool) \get_post_meta( $gate_id, 'metering', true ),
			'anonymous_count'  => absint( \get_post_meta( $gate_id, 'metering_anonymous_count', true ) ),
			'registered_count' => absint( \get_post_meta( $gate_id, 'metering_registered_count', true ) ),
			'period'           => \get_post_meta( $gate_id, 'metering_period', true ),
		];
	}

	/**
	 * Get anonymous metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Anonymous metering settings.
	 */
	public static function get_anonymous_settings( $gate_id ) {
		if ( Memberships::is_active() ) {
			// Fetch from legacy metering settings.
			$metering = self::get_legacy_metering_settings( $gate_id );
			return [
				'enabled' => $metering['enabled'],
				'count'   => $metering['anonymous_count'],
				'period'  => $metering['period'],
			];
		}

		$registration = Content_Gate::get_registration_settings( $gate_id );
		return [
			'enabled' => $registration['active'] && $registration['metering']['enabled'],
			'count'   => $registration['active'] ? absint( $registration['metering']['count'] ) : 0,
			'period'  => $registration['active'] ? $registration['metering']['period'] : 'month',
		];
	}

	/**
	 * Get registered metering settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Registered metering settings.
	 */
	public static function get_registered_settings( $gate_id ) {
		if ( Memberships::is_active() ) {
			// Fetch from legacy metering settings.
			$metering = self::get_legacy_metering_settings( $gate_id );
			return [
				'enabled' => $metering['enabled'],
				'count'   => $metering['registered_count'],
				'period'  => $metering['period'],
			];
		}

		$custom_access = Content_Gate::get_custom_access_settings( $gate_id );
		return [
			'enabled' => $custom_access['active'] && $custom_access['metering']['enabled'],
			'count'   => $custom_access['active'] ? absint( $custom_access['metering']['count'] ) : 0,
			'period'  => $custom_access['active'] ? $custom_access['metering']['period'] : 'month',
		];
	}

	/**
	 * Update metering settings for a gate.
	 *
	 * @param int   $gate_id  Gate ID.
	 * @param array $settings Metering settings.
	 *
	 * @return void
	 */
	public static function update_metering_settings( $gate_id, $settings ) {
		\update_post_meta( $gate_id, 'metering', $settings['enabled'] );
		\update_post_meta( $gate_id, 'metering_anonymous_count', $settings['anonymous_count'] );
		\update_post_meta( $gate_id, 'metering_registered_count', $settings['registered_count'] );
		\update_post_meta( $gate_id, 'metering_period', $settings['period'] );
	}

	/**
	 * Enqueue frontend scripts and styles for gated content.
	 */
	public static function enqueue_scripts() {
		if ( ! Content_Gate::has_gate() ) {
			return;
		}
		if ( ! \is_singular() || ! Content_Gate::is_post_restricted() || ! self::is_frontend_metering() ) {
			return;
		}
		$gate_layout_id = Content_Gate::get_gate_layout_id();
		$gate_post_id   = Content_Gate::get_gate_post_id();
		$handle       = 'newspack-content-gate-metering';
		\wp_enqueue_script(
			$handle,
			Newspack::plugin_url() . '/dist/content-gate-metering.js',
			[],
			filemtime( dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-metering.js' ),
			true
		);

		$anonymous_settings   = self::get_anonymous_settings( $gate_post_id );
		$registered_settings  = self::get_registered_settings( $gate_post_id );
		$settings = $anonymous_settings['enabled'] ? $anonymous_settings : $registered_settings;
		\wp_localize_script(
			$handle,
			'newspack_metering_settings',
			[
				'visible_paragraphs' => \get_post_meta( $gate_layout_id, 'visible_paragraphs', true ),
				'use_more_tag'       => \get_post_meta( $gate_layout_id, 'use_more_tag', true ),
				'count'              => $settings['count'],
				'period'             => $settings['period'],
				'gate_id'            => $gate_post_id,
				'post_id'            => get_the_ID(),
				'article_view'       => self::$article_view,
				'excerpt'            => apply_filters( 'newspack_gate_content', Content_Gate::get_restricted_post_excerpt( get_post() ) ),
				'other_settings'     => Content_Gate_Advanced_Settings::get_settings(),
			]
		);
	}

	/**
	 * Get the metering expiration time for the given date.
	 *
	 * @param string|null $period Metering period. Default is null, which will use the gate's metering period.
	 *
	 * @return int Timestamp of the expiration time.
	 */
	private static function get_expiration_time( $period = null ) {
		if ( ! $period ) {
			$settings = self::get_metering_settings( Content_Gate::get_gate_post_id() );
			$period = $settings['period'];
		}
		switch ( $period ) {
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
	 * Whether the post has metering enabled.
	 *
	 * @param int|null $post_id Post ID. Default is the current post.
	 *
	 * @return bool
	 */
	public static function has_metering( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		$gate_post_id = Content_Gate::get_gate_post_id( $post_id );

		$settings = self::get_metering_settings( $gate_post_id );
		if ( $settings['enabled'] && ( $settings['anonymous_count'] > 0 || $settings['registered_count'] > 0 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether to use the frontend metering strategy.
	 *
	 * @return bool
	 */
	public static function is_frontend_metering() {
		/**
		 * This filter documented in the `is_metering` method.
		 */
		$short_circuit = apply_filters( 'newspack_content_gate_metering_short_circuit', null );
		if ( null !== $short_circuit ) {
			return false;
		}

		// Frontend metering strategy should only be applied for anonymous readers.
		if ( \is_user_logged_in() ) {
			return false;
		}

		// Bail if not in a singular restricted post with available gate.
		if ( ! \is_singular() || ! Content_Gate::has_gate() || ! Content_Gate::is_post_restricted() ) {
			return false;
		}

		$gate_post_id         = Content_Gate::get_gate_post_id();
		$anonymous_settings   = self::get_anonymous_settings( $gate_post_id );
		$registered_settings  = self::get_registered_settings( $gate_post_id );
		$settings             = Memberships::is_active() || $anonymous_settings['enabled'] ? $anonymous_settings : $registered_settings;
		$is_frontend_metering = $settings['enabled'] && $settings['count'] > 0;

		/**
		 * Filters whether to use the frontend metering strategy.
		 *
		 * @param bool $is_frontend_metering Whether to use the frontend metering strategy.
		 */
		return apply_filters( 'newspack_content_gate_is_frontend_metering', $is_frontend_metering );
	}

	/**
	 * Whether to allow content rendering through metering for logged in users.
	 *
	 * @param int $post_id Optional post ID. Default is the current post.
	 *
	 * @return bool
	 */
	public static function is_logged_in_metering_allowed( $post_id = null ) {
		/**
		 * This filter documented in the `is_metering` method.
		 */
		$short_circuit = apply_filters( 'newspack_content_gate_metering_short_circuit', null );
		if ( null !== $short_circuit ) {
			return false;
		}

		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		// Metering back-end strategy is only for logged-in users.
		if ( ! \is_user_logged_in() ) {
			return false;
		}

		// Bail if the gate requires account verification and the reader is not verified.
		// Non-reader users (admins, editors) are exempt - they have full access through other means.
		$user = \wp_get_current_user();
		if ( Content_Gate::requires_account_verification() && Reader_Activation::is_user_reader( $user ) && ! Reader_Activation::is_reader_verified( $user ) ) {
			return false;
		}

		// Not in checkout modals.
		if ( method_exists( 'Newspack_Blocks\Modal_Checkout', 'is_modal_checkout' ) && \Newspack_Blocks\Modal_Checkout::is_modal_checkout() ) {
			return false;
		}

		$gate_post_id = Content_Gate::get_gate_post_id();
		$settings     = self::get_registered_settings( $gate_post_id );

		// Bail if metering is not enabled.
		if ( ! $settings['enabled'] || $settings['count'] <= 0 ) {
			return false;
		}

		// Return cached value if available.
		if ( isset( self::$logged_in_metering_cache[ $post_id ] ) ) {
			return self::$logged_in_metering_cache[ $post_id ];
		}

		$user_meta_key = self::METERING_META_KEY . '_' . $gate_post_id;

		$updated_user_data  = false;
		$user_metering_data = \get_user_meta( get_current_user_id(), $user_meta_key, true );
		if ( ! is_array( $user_metering_data ) ) {
			$user_metering_data = [];
		}

		$user_expiration = isset( $user_metering_data['expiration'] ) ? $user_metering_data['expiration'] : 0;

		$current_expiration = self::get_expiration_time( $settings['period'] );
		if ( $user_expiration !== $current_expiration ) {
			// Clear content if expired.
			if ( $user_expiration < $current_expiration ) {
				$user_metering_data['content'] = [];
			}
			// Reset expiration.
			$user_metering_data['expiration'] = $current_expiration;
			$updated_user_data                = true;
		}

		$limited          = count( $user_metering_data['content'] ) >= $settings['count'];
		$accessed_content = in_array( $post_id, $user_metering_data['content'], true );
		if ( ! $limited && ! $accessed_content ) {
			$user_metering_data['content'][] = $post_id;
			$updated_user_data               = true;
		}

		if ( $updated_user_data ) {
			\update_user_meta( get_current_user_id(), $user_meta_key, $user_metering_data );
		}

		// Allowed if the content has been accessed or the metering limit has not been reached.
		$allowed = $accessed_content || ! $limited;

		/**
		 * Filters whether to allow content rendering through metering for logged in user.
		 *
		 * @param bool $is_logged_in_metering_allowed Whether to allow content rendering through metering for logged in user
		 * @param int  $post_id                       Post ID.
		 */
		self::$logged_in_metering_cache[ $post_id ] = apply_filters( 'newspack_content_gate_is_logged_in_metering_allowed', $allowed, $post_id );

		return self::$logged_in_metering_cache[ $post_id ];
	}

	/**
	 * Whether the content should be allowed to render. If it's frontend metered,
	 * it will be handled by the frontend metering strategy.
	 *
	 * @return bool
	 */
	public static function is_metering() {
		/**
		 * Short-circuit the metering check. Anything other than null
		 * will prevent the metering logic from running.
		 *
		 * The `is_logged_in_metering_allowed` method also updates the user meta
		 * to track the content that's been allowed to access. This short-circuit
		 * prevents this from running if we want the entire metering feature to be
		 * skipped.
		 *
		 * @param mixed $short_circuit Short-circuit value. Default is null.
		 *
		 * @return mixed Short-circuit value.
		 */
		$short_circuit = apply_filters( 'newspack_content_gate_metering_short_circuit', null );
		if ( null !== $short_circuit ) {
			return false;
		}

		return self::is_frontend_metering() || self::is_logged_in_metering_allowed();
	}

	/**
	 * Store the article view activity push for use in the frontend metering
	 * strategy.
	 *
	 * @param array $activity Activity data.
	 *
	 * @return array
	 */
	public static function get_article_view( $activity ) {
		self::$article_view = $activity;
		return $activity;
	}

	/**
	 * Get the metering period for a post.
	 *
	 * @param int|null $post_id Post ID. Default is current post.
	 *
	 * @return string Metered period (day, week, month).
	 */
	public static function get_metering_period( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		$gate_post_id         = Content_Gate::get_gate_post_id( $post_id );
		$anonymous_settings   = self::get_anonymous_settings( $gate_post_id );
		$registered_settings  = self::get_registered_settings( $gate_post_id );
		$settings = $anonymous_settings['enabled'] ? $anonymous_settings : $registered_settings;
		return $settings['period'];
	}

	/**
	 * Get number of metered views for the current user.
	 *
	 * @return int Number of metered views.
	 */
	public static function get_current_user_metered_views() {
		if ( ! is_user_logged_in() ) {
			return 0;
		}

		$gate_post_id  = Content_Gate::get_gate_post_id();
		$meta_key      = self::METERING_META_KEY . '_' . $gate_post_id;
		$metering_data = \get_user_meta( get_current_user_id(), $meta_key, true );
		if ( ! is_array( $metering_data ) || ! isset( $metering_data['content'] ) ) {
			return 0;
		}
		return count( $metering_data['content'] );
	}

	/**
	 * Get total number of metered views for current post.
	 *
	 * @param boolean $is_logged_in Whether to check for logged-in or anonymous users. Default is false (anonymous).
	 *
	 * @return int|boolean Total number of metered views if metering is enabled, otherwise false.
	 */
	public static function get_total_metered_views( $is_logged_in = false ) {
		$gate_post_id = Content_Gate::get_gate_post_id();
		if ( ! $gate_post_id ) {
			return false;
		}
		$anonymous_settings   = self::get_anonymous_settings( $gate_post_id );
		$registered_settings  = self::get_registered_settings( $gate_post_id );
		if ( ! $is_logged_in && $anonymous_settings['enabled'] ) {
			return $anonymous_settings['count'];
		}
		return $registered_settings['count'];
	}
}
Metering::init();
