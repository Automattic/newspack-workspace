<?php
/**
 * Newspack Authors Primary Brand.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Admin;

use Newspack_Multibranded_Site\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Authors Primary Brand.
 */
class Cat_Primary_Brand {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'category_edit_form_fields', [ __CLASS__, 'edit_term' ] );
		\add_action( 'post_tag_edit_form_fields', [ __CLASS__, 'edit_term' ] );

		\add_action( 'edited_category', [ __CLASS__, 'save_term' ] );
		\add_action( 'edited_post_tag', [ __CLASS__, 'save_term' ] );
	}

	/**
	 * Add term edit fields.
	 *
	 * @param WP_Term $term The current WP_Term object.
	 */
	public static function edit_term( $term ) {
		$brands = get_terms(
			[
				'taxonomy'   => 'brand',
				'hide_empty' => false,
			]
		);
		?>
		<tr class="form-field term-<?php echo esc_attr( Taxonomy::PRIMARY_META_KEY ); ?>-wrap">
			<th scope="row"><label for="<?php echo esc_attr( Taxonomy::PRIMARY_META_KEY ); ?>"><?php esc_html_e( 'Primary Brand', 'newspack-multibranded-site' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( Taxonomy::PRIMARY_META_KEY ); ?>" id="<?php echo esc_attr( Taxonomy::PRIMARY_META_KEY ); ?>">
					<option value=""><?php echo esc_html__( 'None', 'newspack-multibranded-site' ); ?></option>
					<?php foreach ( $brands as $brand ) : ?>
						<option value="<?php echo esc_attr( $brand->term_id ); ?>" <?php selected( (string) $brand->term_id, \get_term_meta( $term->term_id, Taxonomy::PRIMARY_META_KEY, true ) ); ?>>
							<?php echo esc_html( $brand->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description" id="<?php echo esc_attr( Taxonomy::PRIMARY_META_KEY ); ?>-description">
					<?php echo esc_html__( 'The primary brand defines what brand will be applied to the term\'s archive. (added by Newspack Multibranded site)', 'newspack-multibranded-site' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the term meta when term is saved
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		// Only act on the edit term screen.
		if ( ! isset( $_POST[ Taxonomy::PRIMARY_META_KEY ] ) ) {
			return;
		}

		check_admin_referer( 'update-tag_' . $term_id );

		if ( ! empty( $_POST[ Taxonomy::PRIMARY_META_KEY ] ) ) {
			\update_term_meta( $term_id, Taxonomy::PRIMARY_META_KEY, \sanitize_text_field( wp_unslash( $_POST[ Taxonomy::PRIMARY_META_KEY ] ) ) );
		} else {
			\delete_term_meta( $term_id, Taxonomy::PRIMARY_META_KEY );
		}
	}
}
