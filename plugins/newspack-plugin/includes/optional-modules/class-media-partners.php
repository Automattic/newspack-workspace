<?php
/**
 * Media Partners.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Media partners.
 */
class Media_Partners {
	private static $is_apple_news_exporting = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	/**
	 * Initialize everything.
	 */
	public static function init() {
		if ( ! Optional_Modules::is_optional_module_active( 'media-partners' ) ) {
			return;
		}

		add_action( 'init', [ __CLASS__, 'register_taxonomies' ] );
		add_action( 'partner_add_form_fields', [ __CLASS__, 'add_partner_meta_fields' ] );
		add_action( 'partner_edit_form_fields', [ __CLASS__, 'edit_partner_meta_fields' ] );
		add_action( 'after-partner-table', [ __CLASS__, 'add_global_settings' ] );
		add_action( 'edited_partner', [ __CLASS__, 'save_partner_meta_fields' ] );
		add_action( 'create_partner', [ __CLASS__, 'save_partner_meta_fields' ] );
		add_action( 'init', [ __CLASS__, 'add_partners_shortcode' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_settings_update' ] );
		add_filter( 'the_content', [ __CLASS__, 'add_content_partner_logo' ] );
		add_action( 'apple_news_do_fetch_exporter', [ __CLASS__, 'apple_news_do_fetch_exporter' ] );
	}

	/**
	 * Register Partner taxonomy.
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			'partner',
			'post',
			[
				'hierarchical'      => true,
				'labels'            => [
					'name'              => esc_html_x( 'Media Partners', 'taxonomy general name', 'newspack-plugin' ),
					'singular_name'     => esc_html_x( 'Media Partner', 'taxonomy singular name', 'newspack-plugin' ),
					'search_items'      => esc_html__( 'Search Media Partners', 'newspack-plugin' ),
					'all_items'         => esc_html__( 'All Media Partners', 'newspack-plugin' ),
					'parent_item'       => esc_html__( 'Parent Media Partner', 'newspack-plugin' ),
					'parent_item_colon' => esc_html__( 'Parent Media Partner:', 'newspack-plugin' ),
					'edit_item'         => esc_html__( 'Edit Media Partner', 'newspack-plugin' ),
					'view_item'         => esc_html__( 'View Media Partner', 'newspack-plugin' ),
					'update_item'       => esc_html__( 'Update Media Partner', 'newspack-plugin' ),
					'add_new_item'      => esc_html__( 'Add New Media Partner', 'newspack-plugin' ),
					'new_item_name'     => esc_html__( 'New Media Partner Name', 'newspack-plugin' ),
					'menu_name'         => esc_html__( 'Media Partners' ),
				],
				'public'            => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'query_var'         => true,
				'rewrite'           => [ 'slug' => 'partners' ],
				'show_in_rest'      => true,
			]
		);
	}

	/**
	 * Render a settings field.
	 *
	 * @param string $key The key for the field.
	 * @param string $label The label for the field.
	 * @param array  $partner The partner data.
	 * @param string $context The context for the field.
	 */
	private static function render_settings_field( $key, $label, $partner = [], $context = 'new_partner' ) {
		$tag   = 'input';
		$value = isset( $partner[ $key ] ) ? $partner[ $key ] : '';
		if ( in_array( $key, [ 'attribution_message' ] ) ) {
			$tag = 'textarea';
		}

		ob_start();
		if ( 'textarea' === $tag ) {
			?>
				<textarea name="<?php echo esc_attr( $key ); ?>" rows="2" cols="40"><?php echo esc_html( $value ); ?></textarea>
			<?php
		} else {
			?>
				<input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"/>
			<?php
		}
		$input_html = ob_get_clean();

		switch ( $context ) {
			case 'new_partner':
				?>
				<div class="form-field">
					<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<?php echo $input_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<?php
				break;
			case 'edit_partner':
				?>
				<tr class="form-field">
					<th scope="row" valign="top"><label for="<?php esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><?php echo $input_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
				<?php
				break;
		}
	}

	/**
	 * Get default config for a media partner.
	 */
	private static function get_default_media_partner_config() {
		return [
			'attribution_message' => __( 'This story also appeared in', 'newspack-plugin' ),
		];
	}

	/**
	 * Get partner logo upload setting JS script.
	 */
	private static function get_logo_upload_script() {
		ob_start()
		?>
		<script>
		jQuery( document ).ready( function() {
			const removeBtn = jQuery( '#remove_partner_logo' );
			jQuery( '#add_partner_logo' ).click( function() {
				wp.media.editor.send.attachment = function( props, attachment ) {
					jQuery( '#partner_logo' ).val( attachment.id );
					jQuery( '#partner_logo_preview' ).attr( 'src', attachment.url );
					removeBtn[0].classList.remove('hidden')
				}
				wp.media.editor.open( this );
				return false;
			} );
			removeBtn.click( function() {
				jQuery( '#partner_logo' ).val( '' );
				jQuery( '#partner_logo_preview' ).attr( 'src', '' );
				removeBtn[0].classList.add('hidden')
			} );
		} );
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Add custom meta to the Add New Partner screen.
	 */
	public static function add_partner_meta_fields() {
		?>
		<div class="form-field">
			<label for="partner_logo"><?php esc_html_e( 'Partner Logo:', 'newspack-plugin' ); ?></label>
			<input type="hidden" name="partner_logo" id="partner_logo" value="" />
			<input class="upload_image_button button" name="add_partner_logo" id="add_partner_logo" type="button" value="<?php esc_attr_e( 'Select/Upload Image', 'newspack-plugin' ); ?>" />
			<input class="button button-link-delete hidden" name="remove_partner_logo" id="remove_partner_logo" type="button" value="<?php esc_attr_e( 'Remove Image', 'newspack-plugin' ); ?>" />
			<img src='' id='partner_logo_preview' style='max-width: 250px; width: 100%; height: auto' />
			<?php echo self::get_logo_upload_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<?php
		$defaults = self::get_default_media_partner_config();
		self::render_settings_field( 'partner_url', __( 'Partner URL', 'newspack-plugin' ) );
		self::render_settings_field( 'attribution_message', __( 'Attribution message', 'newspack-plugin' ), $defaults );
	}

	/**
	 * Add custom meta to the Edit Partner screen.
	 *
	 * @param WP_Term $term Current term object.
	 */
	public static function edit_partner_meta_fields( $term ) {
		$logo_id = (int) get_term_meta( $term->term_id, 'logo', true );
		$logo    = '';
		if ( $logo_id ) {
			$logo_atts = wp_get_attachment_image_src( $logo_id );
			if ( $logo_atts ) {
				$logo = $logo_atts[0];
			}
		}

		?>
		<tr class="form-field">
			<th scope="row" valign="top"><label for="add_partner_logo"><?php esc_html_e( 'Partner Logo', 'newspack-plugin' ); ?></label></th>
			<td>
				<input type="hidden" name="partner_logo" id="partner_logo" value="<?php echo esc_attr( $logo_id ); ?>" />
				<input class="upload_image_button button" name="add_partner_logo" id="add_partner_logo" type="button" value="<?php esc_attr_e( 'Select/Upload Image', 'newspack-plugin' ); ?>" />
				<input class="button button-link-delete <?php echo empty( $logo ) ? 'hidden' : ''; ?>" name="remove_partner_logo" id="remove_partner_logo" type="button" value="<?php esc_attr_e( 'Remove Image', 'newspack-plugin' ); ?>" />
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row" valign="top"></th>
			<td>
				<div class="img-preview">
					<img src='<?php echo esc_url( $logo ); ?>' id='partner_logo_preview' style='max-width: 250px; width: 100%; height: auto' />
				</div>
				<?php echo self::get_logo_upload_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</td>
		</tr>

		<?php
		$partner = self::get_partner_config( $term );
		self::render_settings_field( 'partner_url', __( 'Partner URL', 'newspack-plugin' ), $partner, 'edit_partner' );
		self::render_settings_field( 'attribution_message', __( 'Attribution message', 'newspack-plugin' ), $partner, 'edit_partner' );
	}

	/**
	 * Get partner config.
	 *
	 * @param WP_Term $partner The partner term.
	 */
	private static function get_partner_config( $partner ) {
		$defaults         = self::get_default_media_partner_config();
		$partner_settings = [
			'partner_url'         => esc_url( get_term_meta( $partner->term_id, 'partner_homepage_url', true ) ),
			'attribution_message' => get_term_meta( $partner->term_id, 'attribution_message', true ),
		];
		if ( ! metadata_exists( 'term', $partner->term_id, 'attribution_message' ) ) {
			$partner_settings['attribution_message'] = $defaults['attribution_message'];
		}
		return $partner_settings;
	}

	/**
	 * Save the meta fields for the Partner taxonomy.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_partner_meta_fields( $term_id ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$partner_logo = filter_input( INPUT_POST, 'partner_logo', FILTER_SANITIZE_NUMBER_INT );
		if ( $partner_logo ) {
			update_term_meta( $term_id, 'logo', (int) $partner_logo );
		} else {
			delete_term_meta( $term_id, 'logo' );
		}

		$partner_url = filter_input( INPUT_POST, 'partner_url', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( $partner_url ) {
			update_term_meta( $term_id, 'partner_homepage_url', esc_url( $partner_url ) );
		}

		$attribution_message = filter_input( INPUT_POST, 'attribution_message', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( $attribution_message ) {
			update_term_meta( $term_id, 'attribution_message', $attribution_message );
		}
	}

	/**
	 * Register the 'partners' shortcode.
	 * Can be inserted into a post or page to display a list of partners.
	 */
	public static function add_partners_shortcode() {
		add_shortcode( 'partners', [ __CLASS__, 'render_partners_shortcode' ] );
	}

	/**
	 * Get partner logo, if set to be displayed.
	 *
	 * @param WP_Term $partner The partner object.
	 */
	private static function get_partner_logo( $partner ) {
		return get_term_meta( $partner->term_id, 'logo', true );
	}

	/**
	 * Render the 'partners' shortcode.
	 */
	public static function render_partners_shortcode() {
		$partners = get_terms(
			[
				'taxonomy'   => 'partner',
				'hide_empty' => false,
			]
		);

		ob_start();

		?>
		<style>
			.wp-block-image.media-partner img {
				max-height: 200px;
			}
		</style>
		<?php

		$elements = [];
		foreach ( $partners as $partner ) {
			$partner_html = '';
			$partner_logo = self::get_partner_logo( $partner );
			$partner_url  = get_term_meta( $partner->term_id, 'partner_homepage_url', true );

			if ( $partner_logo ) {
				$logo_html = '';
				$logo_atts = wp_get_attachment_image_src( $partner_logo, 'full' );
				$logo_alt  = $partner->name;

				if ( $partner_url ) {
					$logo_alt = sprintf(
						/* translators: replaced with the name of the Media Partner */
						__( 'Website for %s', 'newspack-plugin' ),
						$partner->name
					);
				}

				if ( $logo_atts ) {
					$logo_html = '<figure class="wp-block-image newspack-media-partners media-partner"><img class="aligncenter" src="' . esc_url( $logo_atts[0] ) . '" alt="' . esc_attr( $logo_alt ) . '" /></figure>';
				}

				if ( $logo_html && $partner_url ) {
					$logo_html = '<a href="' . esc_url( $partner_url ) . '">' . $logo_html . '</a>';
				}

				$partner_html .= $logo_html;
			}

			$partner_name = $partner->name;
			if ( $partner_url ) {
				$partner_name = '<a href="' . esc_url( $partner_url ) . '">' . $partner_name . '</a>';
			}
			$partner_html .= '<p class="has-text-align-center">' . $partner_name . '</p>';

			$elements[] = $partner_html;
		}

		$num_columns      = 3;
		$current          = 0;
		$container_closed = true;
		foreach ( $elements as $element ) {
			if ( 0 == $current ) {
				echo '<div class="wp-block-columns is-style-borders">';
				$container_closed = false;
			}

			echo '<div class="wp-block-column">';
			echo wp_kses_post( $element );
			echo '</div>';

			++$current;

			if ( $num_columns == $current ) {
				echo '</div><hr class="wp-block-separator is-style-wide">';
				$current          = 0;
				$container_closed = true;
			}
		}

		// Close last div if needed.
		if ( ! $container_closed ) {
			echo '</div><hr class="wp-block-separator is-style-wide">';
		}

		return ob_get_clean();
	}

	/**
	 * Filter in a partner logo on posts that have partners.
	 *
	 * @param string $content The post content.
	 * @return string Modified $content.
	 */
	public static function add_content_partner_logo( $content ) {
		// Skip partners in RSS feed.
		$settings = self::get_settings();
		if ( $settings['skip_in_feeds'] && ( is_feed() || self::$is_apple_news_exporting ) ) {
			return $content;
		}

		$id       = get_the_ID();
		$partners = get_the_terms( $id, 'partner' );
		if ( ! $partners ) {
			return $content;
		}

		$partner_images = [];
		$partner_names  = [];
		foreach ( $partners as $partner ) {
			$partner_image_id = self::get_partner_logo( $partner );
			$partner_url      = esc_url( get_term_meta( $partner->term_id, 'partner_homepage_url', true ) );
			$image            = '';
			$image_alt        = $partner->name;

			if ( $partner_url ) {
				$image_alt = sprintf(
					/* translators: replaced with the name of the Media Partner */
					__( 'Website for %s', 'newspack-plugin' ),
					$partner->name
				);
			}

			if ( $partner_image_id ) {
				$image = wp_get_attachment_image( $partner_image_id, [ 200, 999 ], false, [ 'alt' => esc_attr( $image_alt ) ] );
				if ( $image && $partner_url ) {
					$image = '<a href="' . $partner_url . '" target="_blank">' . $image . '</a>';
				}
			}

			$partner_images[] = $image;

			$partner_name = $partner->name;
			if ( $partner_url ) {
				$partner_name = '<a href="' . $partner_url . '" target="_blank">' . $partner_name . '</a>';
			}
			$partner_names[] = $partner_name;
		}

		$partner_settings = self::get_partner_config( $partner );

		ob_start();
		?>
		<div class="wp-block-group alignright newspack-media-partners">
			<div class="wp-block-group__inner-container">
				<figure class="wp-block-image size-full is-resized">
					<?php echo implode( '<br/>', $partner_images ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<figcaption>
						<?php
						echo wp_kses_post(
							sprintf(
								$partner_settings['attribution_message'] . ' %s',
								implode( esc_html__( ' and ', 'newspack-plugin' ), $partner_names )
							)
						);
						?>
					</figcaption>
				</figure>
			</div>
		</div>

		<?php
		$partner_html = ob_get_clean();

		// Inject logo in between 2 paragraph elements.
		$content_halves = preg_split( '#<\/p>\s*<p>#', $content, 2 );

		// Just append it to the top if a good injection spot can't be found..
		if ( 1 === count( $content_halves ) ) {
			$content = $partner_html . $content;
		} else {
			$content = $content_halves[0] . '</p>' . $partner_html . '<p>' . $content_halves[1];
		}

		return $content;
	}

	/**
	 * Add global settings UI.
	 */
	public static function add_global_settings() {
		$settings = self::get_settings();
		?>
			<div class="form-wrap" style="margin-top: 3em;">
				<h2><?php echo esc_html__( 'Global Settings', 'newspack-plugin' ); ?></h2>
				<form id="settings" method="post" action="edit-tags.php">
					<input type="hidden" name="taxonomy" value="partner">
					<input type="hidden" name="media_partners_settings" value="1">
					<?php wp_nonce_field( 'partners-settings', '_wpnonce_partners-settings' ); ?>
					<div class="form-field">
						<label>
							<input type="checkbox" name="skip_in_feeds" <?php echo ( $settings['skip_in_feeds'] ? 'checked' : '' ); ?>>
							<span class="checkbox-title"><?php echo esc_html__( 'Skip in RSS feeds and Apple News', 'newspack-plugin' ); ?></span>
							<p><?php echo esc_html__( 'If checked, the media partner markup will be skipped in RSS feeds and Apple News.', 'newspack-plugin' ); ?></p>
						</label>
					</div>
					<p class="submit">
						<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr__( 'Save', 'newspack-plugin' ); ?>">
					</p>
				</form>
			</div>
		<?php
	}

	/**
	 * Get saved settings.
	 */
	private static function get_settings() {
		return [
			'skip_in_feeds' => get_option( 'newspack_media_partners_skip_in_feeds', false ),
		];
	}

	/**
	 * Handle settings update.
	 */
	public static function handle_settings_update() { // phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement
		$media_partners_settings = filter_input( INPUT_POST, 'media_partners_settings', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! current_user_can( 'manage_options' ) || ! $media_partners_settings ) {
			return true;
		}
		if ( ! wp_verify_nonce( filter_input( INPUT_POST, '_wpnonce_partners-settings', FILTER_SANITIZE_FULL_SPECIAL_CHARS ), 'partners-settings' ) ) {
			return true;
		}

		$skip_in_feeds = filter_input( INPUT_POST, 'skip_in_feeds', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		update_option( 'newspack_media_partners_skip_in_feeds', boolval( $skip_in_feeds ) );

		wp_safe_redirect( admin_url( 'edit-tags.php?taxonomy=partner' ) );
		exit;
	}

	/**
	 * Mark this request as an Apple News exporter request.
	 */
	public static function apple_news_do_fetch_exporter() {
		self::$is_apple_news_exporting = true;
	}
}
Media_Partners::init();
