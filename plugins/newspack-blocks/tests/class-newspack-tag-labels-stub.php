<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for \Newspack\Tag_Labels.
 *
 * The newspack-blocks test suite runs without newspack-plugin loaded, so the
 * real \Newspack\Tag_Labels class is absent. This lightweight stub lets the
 * tests exercise the tag-label REST pass-through contract in isolation.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test stub deliberately impersonates the plugin's \Newspack\Tag_Labels.
namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Tag_Labels' ) ) {
	/**
	 * Minimal stub of the plugin's Tag_Labels class.
	 */
	class Tag_Labels {
		/**
		 * Labels returned by get_labels_for_post(). Set by the test.
		 *
		 * @var array|null
		 */
		public static $stub_labels = null;

		/**
		 * Return the stubbed labels, ignoring the post.
		 *
		 * @param int|\WP_Post|null $post Post to look up (ignored by the stub).
		 * @return array|null
		 */
		public static function get_labels_for_post( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Signature parity with the real class; the stub ignores the post.
			return self::$stub_labels;
		}

		/**
		 * Generates HTML for given tag labels. Mirrors the real
		 * \Newspack\Tag_Labels::generate_html() so caller contract tests
		 * exercise the classes the caller passes.
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
	}
}
