<?php
/**
 * Template part for displaying content of corrections in archive pages
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Newspack
 */

$related_post_id = get_post_meta( get_the_ID(), 'newspack_correction-post-id', true );
$correction_type = '';
if ( method_exists( 'Newspack\Corrections', 'get_correction_type' ) ) {
	$correction_type = Newspack\Corrections::get_correction_type();
}

$correction_heading = sprintf(
	'%s, %s %s:',
	$correction_type,
	get_the_date(),
	get_the_time()
);
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

	<div class="entry-container">

		<strong class="correction__item-title">
			<?php echo esc_html( $correction_heading ); ?>
		</strong>
		<?php echo esc_html( get_the_content() ); ?>
		<br/>
		<a class="correction__post-link" href="<?php echo esc_url( get_permalink( $related_post_id ) ); ?>">
			<u><?php echo esc_html( get_the_title( $related_post_id ) ); ?></u>
		</a>

	</div><!-- .entry-container -->
</article><!-- #post-${ID} -->
