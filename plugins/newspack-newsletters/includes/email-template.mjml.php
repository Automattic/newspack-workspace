<?php
/**
 * Newspack Chart Block - horizontal bar chart base spec.
 *
 * @package WordPress
 */

// phpcs:disable
?>

<mjml>
	<mj-head>
		<mj-title><?php echo esc_html( $title ); ?></mj-title>
		<mj-style>
		<?php
			$default_css = file_get_contents( dirname( __FILE__ ) . '/email-template-mjml.css' );
			$css         = $default_css . "\n" . $custom_css;

			echo esc_html( $css );
			echo Newspack_Newsletters_Editor::get_color_palette_css();
			do_action( 'newspack_newsletters_editor_mjml_head', $post );
		?>
		</mj-style>
		<?php if ( isset( $preview_text ) ): ?>
			<mj-preview><?php echo esc_html( $preview_text ); ?></mj-preview>
		<?php endif; ?>
		<mj-attributes>
			<mj-all color="<?php echo esc_attr( $text_color ?? '#000000' ); ?>" />
			<mj-text color="<?php echo esc_attr( $text_color ?? '#000000' ); ?>" />
		</mj-attributes>
	</mj-head>
	<mj-body background-color="<?php echo esc_attr( $background_color ); ?>" css-class="updated-<?php echo esc_attr( $updated ); ?>">
		<?php echo $body; ?>
		<?php do_action( 'newspack_newsletters_editor_mjml_body', $post ); ?>
	</mj-body>
</mjml>
