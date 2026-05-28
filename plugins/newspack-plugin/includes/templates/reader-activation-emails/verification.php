<?php
/**
 * Account Verification Email Template.
 *
 * @package Newspack
 */

namespace Newspack;

// phpcs:disable WordPressVIPMinimum.Security.Mustache.OutputNotation

[
	'primary_color'        => $primary_color,
	'primary_text_color'   => $primary_text_color,
] = newspack_get_theme_colors();

[
	'block_markup' => $social_links,
	'html_markup'  => $social_links_html
] = newspack_get_social_markup( $primary_text_color );

$post_content =
	// Main body.
	'<!-- wp:group {"style":{"spacing":{"padding":{"top":"56px","bottom":"56px","left":"56px","right":"56px"}},"elements":{"link":{"color":{"textColor":"primary","text":"var:preset|color|primary"}}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group has-link-color" style="padding-top:56px;padding-right:56px;padding-bottom:56px;padding-left:56px">

	<!-- wp:site-logo {"width":125} /-->

	<!-- wp:heading {"style":{"typography":{"fontSize":"46px","fontStyle":"normal","fontWeight":"500"}}} -->
	<h2 class="wp-block-heading" style="font-size:46px;font-style:normal;font-weight:500">' . __( 'Verify your email', 'newspack-plugin' ) . '</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"style":{"typography":{"fontStyle":"normal","fontWeight":"400"}}} -->
	<p style="font-style:normal;font-weight:400">' .
		sprintf(
			/* Translators: s: The site name. */
			__( 'Thanks for creating an account with %s! Click the button below to verify the email address for your account.', 'newspack-plugin' ),
			'*SITE_TITLE*'
		) .
	'</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","orientation":"horizontal","flexWrap":"nowrap","justifyContent":"left"},"style":{"typography":{"fontStyle":"normal","fontWeight":"400"}},"fontSize":"medium"} -->
	<div class="wp-block-buttons has-custom-font-size has-medium-font-size" style="font-style:normal;font-weight:400">
	<!-- wp:button {"textAlign":"center","backgroundColor":"primary","style":{"border":{"radius":"4px"},"elements":{"link":{"color":{"text":"secondary"}}}}} -->
	<div class="wp-block-button">
	<a class="wp-block-button__link has-primary-background-color has-background has-link-color has-text-align-center wp-element-button" href="*VERIFICATION_URL*" style="border-radius:4px">' .
		__( 'Verify my email', 'newspack-plugin' ) .
	'</a>
	</div>
	<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

	</div>
	<!-- /wp:group -->' .

	// Footer.
	'<!-- wp:group {"style":{"spacing":{"padding":{"top":"56px","bottom":"56px","left":"56px","right":"56px"}},"elements":{"link":{"color":{"text":"primary-text"}}}},"backgroundColor":"primary","textColor":"primary-text","className":"has-secondary-color has-secondary-text-color ' . \Newspack\Emails::POST_TYPE . '-has-primary-text-color","layout":{"type":"constrained"}} -->
	<div class="wp-block-group has-secondary-color has-secondary-text-color has-primary-text-color has-primary-background-color has-text-color has-background has-link-color ' . \Newspack\Emails::POST_TYPE . '-has-primary-text-color" style="padding-top:56px;padding-right:56px;padding-bottom:56px;padding-left:56px">' .

	$social_links .

	'<!-- wp:paragraph {"fontSize":"small"} -->
	<p class="has-small-font-size">*SITE_CONTACT*<br>' . sprintf( /* Translators: 1: link to site url. */ __( 'You received this email because you registered to %s', 'newspack-plugin' ), '<a href="*SITE_URL*">*SITE_URL*</a>' ) . '</p>
	<!-- /wp:paragraph --></div>

	<!-- /wp:group -->';

$email_html = '
	<!doctype html>
	<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
		<head>
			<title>' . __( 'Verify your email', 'newspack-plugin' ) . '</title>
			<!--[if !mso]><!-->
			<meta http-equiv="X-UA-Compatible" content="IE=edge">
			<!--<![endif]-->
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<style type="text/css">
				#outlook a { padding:0; }
				body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
				table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
				img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
				p { display:block;margin:13px 0; }
			</style>
			<!--[if mso]>
			<noscript>
			<xml>
			<o:OfficeDocumentSettings>
			<o:AllowPNG/>
			<o:PixelsPerInch>96</o:PixelsPerInch>
			</o:OfficeDocumentSettings>
			</xml>
			</noscript>
			<![endif]-->
			<!--[if lte mso 11]>
			<style type="text/css">
			.mj-outlook-group-fix { width:100% !important; }
			</style>
			<![endif]-->
			<style type="text/css">
				@media only screen and (min-width:480px) {
					.mj-column-per-100 { width:100% !important; max-width: 100%; }
				}
			</style>
			<style media="screen and (min-width:480px)">
				.moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
			</style>
			<style type="text/css">
				@media only screen and (max-width:479px) {
					table.mj-full-width-mobile { width: 100% !important; }
					td.mj-full-width-mobile { width: auto !important; }
				}
			</style>
			<style type="text/css">
				/* Paragraph */
				p {
					margin-top: 0 !important;
					margin-bottom: 0 !important;
				}

				/* Link */
				a {
					color: ' . esc_attr( $primary_color ) . '!important;
					text-decoration: underline;
				}
				a:active, a:focus, a:hover {
					text-decoration: none;
				}
				a:focus {
					outline: thin dotted #000;
				}

				/* Button */
				.is-style-outline a {
					background: #fff !important;
					border: 2px solid !important;
					display: block !important;
				}

				/* Heading */
				h1, h2, h3, h4, h5, h6 {
					margin-top: 0;
					margin-bottom: 0;
				}

				h1 {
					font-size: 2.44em;
					line-height: 1.24;
				}

				h2 {
					font-size: 1.95em;
					line-height: 1.36;
				}

				h3 {
					font-size: 1.56em;
					line-height: 1.44;
				}

				h4 {
					font-size: 1.25em;
					line-height: 1.6;
				}

				h5 {
					font-size: 1em;
					line-height: 1.5em;
				}

				h6 {
					font-size: 0.8em;
					line-height: 1.56;
				}

				/* List */
				ul, ol {
					margin: 12px 0;
					padding: 0;
					list-style-position: inside;
				}

				/* Quote */
				blockquote,
				.wp-block-quote {
					background: transparent;
					border-left: 0.25em solid;
					margin: 0;
					padding-left: 24px;
				}
				blockquote cite,
				.wp-block-quote cite {
					color: #767676;
				}
				blockquote p,
				.wp-block-quote p {
					padding-bottom: 12px;
				}
				.wp-block-quote.is-style-plain,
				.wp-block-quote.is-style-large {
					border-left: 0;
					padding-left: 0;
				}
				.wp-block-quote.is-style-large p {
					font-size: 24px;
					font-style: italic;
					line-height: 1.6;
				}

				/* Image */
				@media all and (max-width: 590px) {
					img {
						height: auto !important;
					}
				}

				/* Social links */
				.social-element img {
					border-radius: 0 !important;
				}

				/* Has Background */
				.mj-column-has-width .has-background,
				.mj-column-per-50 .has-background {
					padding: 12px;
				}

				/* Mailchimp Footer */
				#canspamBarWrapper {
					border: 0 !important;
				}

				.has-primary-color { color: ' . esc_attr( $primary_color ) . '; }
			</style>
		</head>
		<body style="word-spacing:normal;background-color:#ffffff;">
			<div style="background-color:#ffffff;" lang="und" dir="auto"><!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff !important"><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
				<div style="margin:0px auto;max-width:600px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:56px 56px 56px 56px;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;border-spacing:0px;"><tbody><tr><td style="width:125px;"><a href="*SITE_URL*" target="_blank"><img src="*SITE_LOGO*" style="border:0;display:block;outline:none;text-decoration:none;height:auto;width:100%;font-size:13px;" width="125" height="auto"></a></td></tr></tbody></table></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;color:#000000"><h2 class="wp-block-heading" style="font-size:46px;font-style:normal;font-weight:500">' . __( 'Verify your email', 'newspack-plugin' ) . '</h2></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;color:#000000;"><p style="font-style:normal;font-weight:400">' . sprintf( /* Translators: s: The site name. */ __( 'Thanks for creating an account with %s! Click the button below to verify the email address for your account.', 'newspack-plugin' ), '*SITE_TITLE*' ) . '</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="mj-column-has-width-outlook" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix mj-column-has-width" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"><tbody><tr><td align="center" bgcolor="' . esc_attr( $primary_color ) . ' !important" role="presentation" style="border:none;border-radius:4px;cursor:auto;mso-padding-alt:12px 24px;background:' . esc_attr( $primary_color ) . ' !important;" valign="middle"><a href="*VERIFICATION_URL*" style="display:inline-block;background:' . esc_attr( $primary_color ) . ' !important;color:' . esc_attr( $primary_text_color ) . ' !important;font-family:Arial;font-size:16px;font-weight:bold;line-height:1.5;margin:0;text-decoration:none;text-transform:none;padding:12px 24px;mso-padding-alt:0px;border-radius:4px;" target="_blank">' . __( 'Verify my email', 'newspack-plugin' ) . '</a></td></tr></tbody></table></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="' . esc_attr( $primary_color ) . ' !important" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->



				<div style="background:' . esc_attr( $primary_color ) . ' !important;background-color:' . esc_attr( $primary_color ) . ' !important;margin:0px auto;max-width:600px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:' . esc_attr( $primary_color ) . ' !important;background-color:' . esc_attr( $primary_color ) . ' !important;width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:56px 56px 56px 56px;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><!--[if mso | IE]><table align="left" border="0" cellpadding="0" cellspacing="0" role="presentation" ><tr><td><![endif]-->' . $social_links_html . '<!--[if mso | IE]></td></td></tr></table><![endif]--></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:12px;line-height:1.5;text-align:left;color:' . esc_attr( $primary_text_color ) . ';"><p class="has-small-font-size">*SITE_CONTACT*<br> ' . sprintf( /* Translators: s: link to site url. */ __( 'You received this email because you have registered on %s', 'newspack-plugin' ), '<a style="color:' . esc_attr( $primary_text_color ) . ' !important" href="*SITE_URL*" target="_blank">*SITE_URL*</a>' ) . '</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]-->
			</div>
		</body>
	</html>';

return array(
	'post_title'   => __( 'Verify your email', 'newspack-plugin' ),
	'post_content' => $post_content,
	'email_html'   => $email_html,
);
