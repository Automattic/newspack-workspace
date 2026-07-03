<?php
/**
 * Newspack Tag Labels
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Tag Labels
 */
class Tag_Labels {

	/**
	 * Key names.
	 */
	const TAG_LABEL_META_KEY = '_np_label_enabled';
	const TAG_LABEL_FLAG_META_KEY = '_np_label_flag';


	// Helper functions for themes to get arrays of labels and flags.
	/**
	 * Given a term, check if labels are enabled for it.
	 *
	 * @param WP_Term $term Term to check.
	 *
	 * @return bool
	 */
	public static function has_label( $term ) {
		if ( ! $term || ! $term->term_id ) {
			return false;
		}
		return ! empty( get_term_meta( $term->term_id, self::TAG_LABEL_META_KEY, true ) );
	}

	/**
	 * Given a term, return the flag (text) of its label and
	 * the link to the term archive.
	 *
	 * Will return null if label isn't enabled for the term.
	 *
	 * @param WP_Term $term Term to check.
	 *
	 * @return array|null As ['flag' => FLAG_NAME, 'link' => TERM_LINK].
	 */
	public static function get_tag_label_for_term( $term ) {
		if ( ! $term || ! $term->term_id || ! self::has_label( $term ) ) {
			return null;
		}

		// A little fancy in case someone wants to give a tag a
		// falsy label flag.  Empty string still gets default value.
		$term_label_flag = get_term_meta( $term->term_id, self::TAG_LABEL_FLAG_META_KEY, true );
		if ( '' === $term_label_flag ) {
			$term_label_flag = $term->name;
		}

		$term_label_link = get_term_link( $term->term_id );
		if ( is_wp_error( $term_label_link ) ) {
			return null;
		}

		return [
			'flag' => $term_label_flag,
			'link' => $term_label_link,
		];
	}

	/**
	 * Given a post ID, grab array of tag labels (if any) for it.
	 *
	 * @param int|WP_Post|null $post Post to check.
	 *
	 * @return array|null Elements as ['flag' => FLAG_NAME, 'link' => TERM_LINK].
	 */
	public static function get_labels_for_post( $post ) {
		if ( ! $post ) {
			return null;
		}

		$post_id = ( is_a( $post, 'WP_Post' ) ? $post->ID : (int) $post );
		$post_terms = get_the_terms( $post_id, 'post_tag' );

		if ( empty( $post_terms ) || is_wp_error( $post_terms ) ) {
			return [];
		}

		return array_filter(
			array_map(
				function( $term ) {
					return self::get_tag_label_for_term( $term );
				},
				$post_terms
			)
		);
	}

	/**
	 * Generates HTML for given tag labels.
	 *
	 * @param array  $labels        Labels to display.
	 * @param bool   $links         Whether to include links to tag archives.
	 * @param array  $outer_classes Classes to apply to the outer container.
	 * @param array  $inner_classes Classes to apply to the inner container.
	 * @param string $outer_element HTML element to use for the outer container.
	 *
	 * @return string Tag labels as HTML.
	 */
	public static function generate_html( $labels = null, $links = true, $outer_classes = array( 'tag-labels' ), $inner_classes = array( 'tag-label', 'flag' ), $outer_element = 'span' ) {
		if ( empty( $labels ) ) {
			return '';
		}

		$outer_element = in_array( $outer_element, [ 'span', 'div' ], true ) ? $outer_element : 'span';

		$labels_html  = '';
		$labels_html .= '<' . $outer_element . ' class="' . join( ' ', array_map( 'esc_attr', $outer_classes ) ) . '">';
		foreach ( $labels as $label ) {
			if ( $links && isset( $label['flag'] ) && $label['link'] ) {
				$labels_html .= '<a class="' . join( ' ', array_map( 'esc_attr', $inner_classes ) ) . '" href="' . esc_url( $label['link'] ) . '" rel="tag">' . esc_html( $label['flag'] ) . '</a>';
			} elseif ( isset( $label['flag'] ) ) {
				$labels_html .= '<span class="' . join( ' ', array_map( 'esc_attr', $inner_classes ) ) . '">' . esc_html( $label['flag'] ) . '</span>';
			}
		}
		$labels_html .= '</' . $outer_element . '><!-- .tag-labels -->';

		return $labels_html;
	}

	/**
	 * Outputs HTML for given tag labels.
	 *
	 * @param array  $labels        Labels to display.
	 * @param bool   $links         Whether to include links to tag archives.
	 * @param string $outer_element HTML element to use for the outer container.
	 *
	 * @return void
	 */
	public static function display( $labels = null, $links = true, $outer_element = 'span' ) {
		if ( empty( $labels ) ) {
			return;
		}

		echo wp_kses_post( self::generate_html( $labels, $links, array( 'tag-labels', 'cat-links' ), array( 'tag-label', 'flag' ), $outer_element ) . ' ' );
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'post_tag_pre_add_form', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'post_tag_term_edit_form_top', array( __CLASS__, 'enqueue_scripts' ) );

		add_action( 'post_tag_add_form_fields', [ __CLASS__, 'add_term' ] );
		add_action( 'post_tag_edit_form_fields', [ __CLASS__, 'edit_term' ] );

		add_action( 'created_post_tag', [ __CLASS__, 'save_term' ] );
		add_action( 'edited_post_tag', [ __CLASS__, 'save_term' ] );
	}

	/**
	 * Enqueues js script
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script(
			'newspack_tag_labels',
			Newspack::plugin_url() . '/dist/other-scripts/tag-labels.js',
			[ 'jquery' ],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Term creation fields.
	 *
	 * Toggle to determine if the term is a label.
	 * Also, override for flag (text used on label).
	 */
	public static function add_term() {
		$checkbox_id = self::TAG_LABEL_META_KEY;
		?>
		<div class="form-field newspack-label-enable term-<?php echo esc_attr( self::TAG_LABEL_META_KEY ); ?>-wrap">
			<label for="<?php echo esc_attr( $checkbox_id ); ?>"><?php esc_html_e( 'Display as label', 'newspack-plugin' ); ?></label>
			<input
				aria-describedby="<?php echo esc_attr( self::TAG_LABEL_META_KEY ); ?>-description"
				type="checkbox"
				id="<?php echo esc_attr( $checkbox_id ); ?>"
				name="<?php echo esc_attr( $checkbox_id ); ?>"
				value="true"
			>
			<p class="description" id="<?php echo esc_attr( self::TAG_LABEL_META_KEY ); ?>-description">
				<?php echo esc_html__( 'Show this tag as a highlighted label wherever posts are displayed.', 'newspack-plugin' ); ?>
			</p>
		</div>
		<div class="form-field newspack-label-setting term-<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>-wrap" style="display: none;">
			<label for="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>"><?php esc_html_e( 'Label text', 'newspack-plugin' ); ?></label>
			<input
				aria-describedby="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>-description"
				type="text"
				id="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>"
				name="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>"
				placeholder="<?php echo esc_attr__( 'Enter custom label text', 'newspack-plugin' ); ?>"
				value=""
				disabled
			>
			<p class="description" id="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>-description">
				<?php echo esc_html__( 'Custom text to display instead of the tag name.', 'newspack-plugin' ); ?>
			</p>
		</div>
		<?php wp_nonce_field( 'newspack_tag_labels_save', 'newspack_tag_labels_nonce' ); ?>
		<?php
	}

	/**
	 * Term edit fields.
	 *
	 * Toggle to determine if the term is a label.
	 * Also, override for flag (text used on label).
	 *
	 * @param WP_Term $term The current WP_Term object.
	 */
	public static function edit_term( $term ) {
		$checkbox_id = self::TAG_LABEL_META_KEY;
		$is_label = self::has_label( $term );

		// Read the stored flag directly so the input value survives disable→re-enable cycles —
		// get_tag_label_for_term() returns null when has_label() is false, which would hide it.
		$stored_flag      = get_term_meta( $term->term_id, self::TAG_LABEL_FLAG_META_KEY, true );
		$input_label_flag = ( '' === $stored_flag || $term->name === $stored_flag ) ? '' : $stored_flag;
		?>
		<tr class="form-field newspack-label-enable term-<?php echo esc_attr( self::TAG_LABEL_META_KEY ); ?>-wrap">
			<th scope="row"><label for="<?php echo esc_attr( $checkbox_id ); ?>"><?php esc_html_e( 'Display as label', 'newspack-plugin' ); ?></label></th>
			<td>
				<input
					aria-describedby="<?php echo esc_attr( self::TAG_LABEL_META_KEY ); ?>-description"
					type="checkbox"
					id="<?php echo esc_attr( $checkbox_id ); ?>"
					name="<?php echo esc_attr( $checkbox_id ); ?>"
					value="true"
					<?php
						checked( $is_label, true );
					?>
				>
				<p class="description" id="<?php echo esc_attr( self::TAG_LABEL_META_KEY ); ?>-description">
					<?php echo esc_html__( 'Show this tag as a highlighted label wherever posts are displayed.', 'newspack-plugin' ); ?>
				</p>
			</td>
		</tr>
		<tr class="form-field newspack-label-setting term-<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>-wrap"<?php echo $is_label ? '' : ' style="display: none;"'; ?>>
			<th scope="row"><label for="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>"><?php esc_html_e( 'Label text', 'newspack-plugin' ); ?></label></th>
			<td>
				<input
					aria-describedby="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>-description"
					type="text"
					id="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>"
					name="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>"
					placeholder="<?php echo esc_attr( $term->name ); ?>"
					value="<?php echo esc_attr( $input_label_flag ); ?>"
					<?php
					if ( ! $is_label ) {
						echo ' disabled'; }
					?>
				>
				<p class="description" id="<?php echo esc_attr( self::TAG_LABEL_FLAG_META_KEY ); ?>-description">
					<?php echo esc_html__( 'Custom text to display instead of the tag name.', 'newspack-plugin' ); ?>
				</p>
			</td>
		</tr>
		<tr><td colspan="2"><?php wp_nonce_field( 'newspack_tag_labels_save', 'newspack_tag_labels_nonce' ); ?></td></tr>
		<?php
	}

	/**
	 * Store custom term meta on save.
	 *
	 * Bails on any term mutation that didn't originate from this UI's form — `created_post_tag`
	 * and `edited_post_tag` also fire on REST edits, importers, and third-party
	 * `wp_update_term()` calls, none of which post our fields.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['newspack_tag_labels_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['newspack_tag_labels_nonce'] ) ),
				'newspack_tag_labels_save'
			)
		) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// Save label data if label is enabled; otherwise kill it.
		if ( ! empty( $_POST[ self::TAG_LABEL_META_KEY ] ) ) {
			update_term_meta( $term_id, self::TAG_LABEL_META_KEY, true );

			// Save falsy values other than empty string in case someone wants a flag of '0' or something.
			if ( isset( $_POST[ self::TAG_LABEL_FLAG_META_KEY ] ) && $_POST[ self::TAG_LABEL_FLAG_META_KEY ] !== '' ) {
				update_term_meta( $term_id, self::TAG_LABEL_FLAG_META_KEY, sanitize_text_field( wp_unslash( $_POST[ self::TAG_LABEL_FLAG_META_KEY ] ) ) );
			} else {
				delete_term_meta( $term_id, self::TAG_LABEL_FLAG_META_KEY );
			}
		} else {
			delete_term_meta( $term_id, self::TAG_LABEL_META_KEY );
		}
	}
}

Tag_Labels::init();
