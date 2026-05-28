<?php
/**
 * Adds large featured image to the RSS feed.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * RSS_Add_Image class.
 */
class RSS_Add_Image {

	/**
	 * Option key for the RSS image width setting.
	 *
	 * @var string
	 */
	const OPTION_RSS_IMAGE_WIDTH = 'newspack_rss_image_size_width';

	/**
	 * Option key for the RSS image height setting.
	 *
	 * @var string
	 */
	const OPTION_RSS_IMAGE_HEIGHT = 'newspack_rss_image_size_height';

	/**
	 * The featured image size used in the RSS feed.
	 *
	 * @var string
	 */
	const RSS_IMAGE_SIZE = 'rss-image-size';

	/**
	 * Hook actions and filters.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'add_rss_image_size_section' ] );
		add_action( 'after_setup_theme', [ __CLASS__, 'rss_image_size' ] );
		add_filter( 'the_excerpt_rss', [ __CLASS__, 'thumbnails_in_rss' ] );
		add_filter( 'the_content_feed', [ __CLASS__, 'thumbnails_in_rss' ] );
	}

	/**
	 * Add RSS image size.
	 */
	public static function rss_image_size() {
		add_image_size( self::RSS_IMAGE_SIZE, self::get_rss_image_width(), self::get_rss_image_height() );
	}

	/**
	 * Register the settings.
	 */
	public static function add_rss_image_size_section() {
		add_settings_section(
			'rss_image_sizes',
			esc_html__( 'RSS Image Sizes', 'newspack' ),
			[ __CLASS__, 'add_image_size_fields' ],
			'media'
		);

		register_setting(
			'media',
			self::OPTION_RSS_IMAGE_WIDTH,
			[ __CLASS__, 'sanitize_rss_image_size_value' ]
		);

		register_setting(
			'media',
			self::OPTION_RSS_IMAGE_HEIGHT,
			[ __CLASS__, 'sanitize_rss_image_size_value' ]
		);

		add_settings_field(
			self::OPTION_RSS_IMAGE_WIDTH,
			__( 'RSS Image size', 'newspack' ),
			[ __CLASS__, 'render_rss_image_width' ],
			'media',
			'rss_image_sizes'
		);

		add_settings_field(
			self::OPTION_RSS_IMAGE_HEIGHT,
			'', // Empty to avoid a duplicate label.
			[ __CLASS__, 'render_rss_image_height' ],
			'media',
			'rss_image_sizes'
		);
	}

	/**
	 * Get the image width setting.
	 *
	 * @return int The image width.
	 */
	public static function get_rss_image_width() {
		$rss_image_width = get_option( self::OPTION_RSS_IMAGE_WIDTH, '1024' );
		if ( empty( $rss_image_width ) ) {
			return '';
		}

		return absint( $rss_image_width );
	}

	/**
	 * Get the image height setting.
	 *
	 * @return int The image height.
	 */
	public static function get_rss_image_height() {
		$rss_image_height = get_option( self::OPTION_RSS_IMAGE_HEIGHT, '1024' );
		if ( empty( $rss_image_height ) ) {
			return '';
		}

		return absint( $rss_image_height );
	}

	/**
	 * Render the width control.
	 */
	public static function render_rss_image_width() {
		$saved_width = self::get_rss_image_width();
		?>
		<fieldset style="margin-bottom: -40px;">
			<label for="<?php echo esc_attr( self::OPTION_RSS_IMAGE_WIDTH ); ?>">
				<?php esc_html_e( 'Max Width', 'newspack-plugin' ); ?>
			</label>
			<input name="<?php echo esc_attr( self::OPTION_RSS_IMAGE_WIDTH ); ?>" type="number" step="1" min="0" id="<?php echo esc_attr( self::OPTION_RSS_IMAGE_WIDTH ); ?>" value="<?php echo esc_attr( $saved_width ); ?>" class="small-text" />
		</fieldset>
		<?php
	}

	/**
	 * Render the height control.
	 */
	public static function render_rss_image_height() {
		$saved_height = self::get_rss_image_height();
		?>
		<fieldset>
			<label for="<?php echo esc_attr( self::OPTION_RSS_IMAGE_HEIGHT ); ?>">
				<?php esc_html_e( 'Max Height', 'newspack-plugin' ); ?>
			</label>
			<input name="<?php echo esc_attr( self::OPTION_RSS_IMAGE_HEIGHT ); ?>" type="number" step="1" min="0" id="<?php echo esc_attr( self::OPTION_RSS_IMAGE_HEIGHT ); ?>" value="<?php echo esc_attr( $saved_height ); ?>" class="small-text" />
		</fieldset>
		<?php
	}

	/**
	 * Render the size fields on the media settings page.
	 */
	public static function add_image_size_fields() {
		echo '<p>' . esc_html__( 'Customize the featured image sizes used in the RSS feed', 'newspack-plugin' ) . '</p>';
	}

	/**
	 * Sanitize the size values.
	 *
	 * @param int $input Raw input from the setting field.
	 * @return int Sanitized input.
	 */
	public function sanitize_rss_image_size_value( $input ) {
		if ( empty( $input ) ) {
			return '';
		}

		return absint( $input );
	}

	/**
	 * Display Featured Images in RSS feed.
	 *
	 * @param string $content Thumbail to add to RSS feed.
	 */
	public static function thumbnails_in_rss( $content ) {
		global $post;
		if ( has_post_thumbnail( $post->ID ) ) {
			$content = '<figure>' . get_the_post_thumbnail( $post->ID, self::RSS_IMAGE_SIZE ) . '</figure>' . $content;
		}
		return $content;
	}
}
RSS_Add_Image::init();
