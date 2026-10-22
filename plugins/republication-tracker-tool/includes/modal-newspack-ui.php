<?php
/**
 * The republish modal built from newspack-ui components, used when newspack-plugin
 * provides them. Included by shareable-content.php, which prepares the variables.
 *
 * Variables the includer must provide:
 *
 * @var bool        $is_amp                    Whether the request is an AMP page.
 * @var bool        $show_format_tabs          Whether to show the HTML and plain text tabs.
 * @var string      $article_info              Article title and byline markup.
 * @var string      $content                   Article content markup.
 * @var string      $content_footer            Footer markup appended to the HTML copy.
 * @var string      $canonical_tag             Canonical tag for the plain text copy.
 * @var string      $plain_text_content        Plain text article content.
 * @var string      $plain_text_tracking_snippet Tracking snippet for the plain text copy.
 * @var bool        $using_license             Whether a Creative Commons license is selected.
 * @var string      $license_key               Key of the selected license.
 * @var string|null $license_statement         Custom license statement.
 *
 * @package Republication_Tracker_Tool
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<textarea
	id="republication-tracker-tool-shareable-content"
	readonly
	rows="5"
	aria-label="<?php esc_attr_e( 'Republish this article (HTML format)', 'republication-tracker-tool' ); ?>"
><?php echo esc_html( $article_info ); ?><?php echo wp_kses_post( $content ); ?><?php echo wp_kses_post( htmlspecialchars( $content_footer, ENT_QUOTES, 'UTF-8' ) ); ?></textarea>
<?php if ( ! $is_amp ) : ?>
	<button type="button" data-copy-active class="newspack-ui__button newspack-ui__button--primary"><?php esc_html_e( 'Copy to Clipboard', 'republication-tracker-tool' ); ?></button>
<?php endif; ?>
<?php
$html_format = ob_get_clean();

if ( $is_amp ) :
	?>
	<amp-lightbox id="republication-tracker-tool-modal" class="newspack-ui" layout="nodisplay">
		<div id="republication-tracker-tool-modal-content" class="newspack-ui__modal" role="dialog" aria-modal="true" aria-labelledby="republish-modal-label">
<?php else : ?>
	<dialog id="republication-tracker-tool-modal" class="newspack-ui newspack-ui__modal-container" data-state="closed" aria-labelledby="republish-modal-label">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div id="republication-tracker-tool-modal-content" class="newspack-ui__modal">
<?php endif; ?>
			<header class="newspack-ui__modal__header">
				<h2 id="republish-modal-label"><?php esc_html_e( 'Republish this article', 'republication-tracker-tool' ); ?></h2>
				<button type="button" <?php echo $is_amp ? 'on="tap:republication-tracker-tool-modal.close"' : ''; ?> class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
					<span class="screen-reader-text"><?php esc_html_e( 'Close window', 'republication-tracker-tool' ); ?></span>
					<?php \Newspack\Newspack_UI_Icons::print_svg( 'close' ); ?>
				</button>
			</header>
			<section class="newspack-ui__modal__content newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5">
				<div class="article-info newspack-ui__box newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1">
					<?php echo wp_kses_post( $article_info ); ?>
				</div>
				<?php if ( $show_format_tabs ) : ?>
					<div class="newspack-ui__tabs">
						<div class="newspack-ui__tabs__list" role="tablist" aria-label="<?php esc_attr_e( 'Republishing format', 'republication-tracker-tool' ); ?>">
							<button type="button" id="republish-format-tab-html" class="newspack-ui__button newspack-ui__button--ghost selected" role="tab" aria-selected="true" aria-controls="republish-format-panel-html" aria-label="<?php esc_attr_e( 'HTML format', 'republication-tracker-tool' ); ?>">
								<?php esc_html_e( 'HTML', 'republication-tracker-tool' ); ?>
							</button>
							<button type="button" id="republish-format-tab-plain-text" class="newspack-ui__button newspack-ui__button--ghost" role="tab" aria-selected="false" aria-controls="republish-format-panel-plain-text" tabindex="-1" aria-label="<?php esc_attr_e( 'Plain text format', 'republication-tracker-tool' ); ?>">
								<?php esc_html_e( 'Plain Text', 'republication-tracker-tool' ); ?>
							</button>
						</div>
						<div class="newspack-ui__tabs__content">
							<div id="republish-format-panel-html" class="newspack-ui__tabs__panel newspack-ui__spacing-bottom--0 newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5 newspack-ui__stack--align-start selected" role="tabpanel" aria-labelledby="republish-format-tab-html">
								<?php echo $html_format; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
							<div id="republish-format-panel-plain-text" class="newspack-ui__tabs__panel newspack-ui__spacing-bottom--0 newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5" role="tabpanel" aria-labelledby="republish-format-tab-plain-text">
								<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1 newspack-ui__stack--align-start">
									<label for="republication-tracker-tool-canonical-url"><?php esc_html_e( 'Canonical Tag:', 'republication-tracker-tool' ); ?></label>
									<input type="text" id="republication-tracker-tool-canonical-url" readonly value="<?php echo esc_attr( $canonical_tag ); ?>" />
									<button type="button" class="plain-text-field__button newspack-ui__button newspack-ui__button--secondary" data-target="#republication-tracker-tool-canonical-url"><?php esc_html_e( 'Copy Tag', 'republication-tracker-tool' ); ?></button>
								</div>
								<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1 newspack-ui__stack--align-start">
									<label for="republication-tracker-tool-plain-text-content"><?php esc_html_e( 'Article Content:', 'republication-tracker-tool' ); ?></label>
									<textarea id="republication-tracker-tool-plain-text-content" readonly rows="8"><?php echo wp_kses_post( $plain_text_content ); ?></textarea>
									<button type="button" class="plain-text-field__button newspack-ui__button newspack-ui__button--secondary" data-target="#republication-tracker-tool-plain-text-content"><?php esc_html_e( 'Copy Content', 'republication-tracker-tool' ); ?></button>
								</div>
								<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1 newspack-ui__stack--align-start">
									<label for="republication-tracker-tool-tracking-snippet"><?php esc_html_e( 'Tracking snippet:', 'republication-tracker-tool' ); ?></label>
									<input type="text" id="republication-tracker-tool-tracking-snippet" readonly value="<?php echo esc_attr( $plain_text_tracking_snippet ); ?>" />
									<button type="button" class="plain-text-field__button newspack-ui__button newspack-ui__button--secondary" data-target="#republication-tracker-tool-tracking-snippet"><?php esc_html_e( 'Copy Snippet', 'republication-tracker-tool' ); ?></button>
								</div>
							</div>
						</div>
					</div>
				<?php else : ?>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5 newspack-ui__stack--align-start">
						<?php echo $html_format; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			</section>
			<?php if ( $using_license || ! empty( $license_statement ) ) : ?>
				<footer class="newspack-ui__modal__footer newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-3 newspack-ui__stack--align-start">
					<?php
					if ( $using_license ) {
						printf( '<a rel="noreferrer license" target="_blank" href="%s"><img alt="%s" style="border-width:0" src="%s" /></a>', esc_url( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'] ), esc_attr( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description'] ), esc_url( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['badge'] ) );
						echo wp_kses_post(
							wpautop(
								sprintf(
									// translators: %1$s is the URL to the particular Creative Commons license.
									__( 'This work is licensed under a <a rel="noreferrer license" target="_blank" href="%1$s">%2$s</a>.', 'republication-tracker-tool' ),
									REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'],
									REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description']
								)
							)
						);
					}
					if ( ! empty( $license_statement ) ) {
						echo wp_kses_post( wpautop( $license_statement ) );
					}
					?>
				</footer>
			<?php endif; ?>
		</div>
<?php if ( $is_amp ) : ?>
	</amp-lightbox>
<?php else : ?>
	</dialog>
<?php endif; ?>
