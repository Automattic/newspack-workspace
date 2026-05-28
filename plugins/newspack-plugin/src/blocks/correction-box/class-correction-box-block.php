<?php
/**
 * Correction Box Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Correction_Box;

use Newspack\Corrections;

defined( 'ABSPATH' ) || exit;

/**
 * Corrections Box class.
 */
final class Correction_Box_Block {
	/**
	 * Initializes the block.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block.
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
				'uses_context'    => [ 'postId' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array  $attributes The block attributes.
	 * @param string $content    The block content.
	 * @param object $block      The block.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes, string $content, $block ) {
		$post_id = $block->context['postId'] ?? null;

		if ( empty( $post_id ) ) {
			return '';
		}

		// Fetch corrections.
		$corrections = Corrections::get_corrections( $post_id );

		if ( empty( $corrections ) ) {
			return '';
		}

		$block_wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => 'correction-box',
			]
		);
		$corrections_archive_url = get_post_type_archive_link( Corrections::POST_TYPE );

		ob_start();
		?>
		<div <?php echo $block_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			foreach ( $corrections as $correction ) :
				// Check for priority.
				if ( empty( $attributes['priority'] ) || ( $correction->correction_priority !== $attributes['priority'] && 'all' !== $attributes['priority'] ) ) {
					continue;
				}
				$correction_content = $correction->post_content;
				$correction_date    = \get_the_date( get_option( 'date_format' ), $correction->ID );
				$correction_time    = \get_the_time( get_option( 'time_format' ), $correction->ID );
				$correction_heading = sprintf(
					'%s, %s %s:',
					Corrections::get_correction_type( $correction->ID ),
					$correction_date,
					$correction_time
				);
				?>
				<p class="correction">
					<a class="correction-title" href="<?php echo esc_url( $corrections_archive_url ); ?>">
						<?php echo esc_html( $correction_heading ); ?>
					</a>
					<span class="correction-content">
						<?php echo esc_html( $correction_content ); ?>
					</span>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

Correction_Box_Block::init();
