<?php
/**
 * One Time Password Email Template.
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
	<h2 class="wp-block-heading" style="font-size:46px;font-style:normal;font-weight:500">' . __( 'Sign in', 'newspack-plugin' ) . '</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p>' . sprintf( /* Translators: s: the site title. */ __( 'Here\'s your one-time password to sign in to %s', 'newspack-plugin' ), '*SITE_TITLE*' ) . '</p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph {"style":{"typography":{"fontSize":"28px","letterSpacing":"5px"}}} -->
	<p style="font-size:28px;letter-spacing:5px">*MAGIC_LINK_OTP*</p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph -->
	<p style="font-style:normal;font-weight:400">' .
		sprintf(
			/* Translators: 1: Opening HTML anchor tag. 2: Closing HTML anchor tag. */
			__( 'Copy your one-time password, or click the magic link below to finish signing in. You also have the option to %1$screate a password%2$s for your account.', 'newspack-plugin' ),
			'<a href="*SET_PASSWORD_LINK*" data-type="link" data-id="*SET_PASSWORD_LINK*">',
			'</a>'
		) .
	'</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","orientation":"horizontal","flexWrap":"nowrap","justifyContent":"left"},"style":{"typography":{"fontStyle":"normal","fontWeight":"400"}},"fontSize":"medium"} -->
	<div class="wp-block-buttons has-custom-font-size has-medium-font-size" style="font-style:normal;font-weight:400">
	<!-- wp:button {"textAlign":"center","backgroundColor":"primary","style":{"border":{"radius":"4px"},"elements":{"link":{"color":{"text":"secondary"}}}}} -->
	<div class="wp-block-button">
	<a class="wp-block-button__link has-primary-background-color has-background has-link-color has-text-align-center wp-element-button" href="*MAGIC_LINK_URL*" style="border-radius:4px">' .
		sprintf( /* Translators: s: site title. */ __( 'Continue to %s', 'newspack-plugin' ), '*SITE_TITLE*' ) .
	'</a>
	</div>
	<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

	<!-- wp:paragraph -->
	<p>' . __( 'You can also copy and paste this link in your browser:', 'newspack-plugin' ) . '<br/><a href="*MAGIC_LINK_URL*" data-type="URL" data-id="*MAGIC_LINK_URL*">*MAGIC_LINK_URL*</a></p>
	<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->' .

	// FAQ.
	'<!-- wp:separator {"style":{"color":{"background":"#dddddd"}},"className":"is-style-wide"} -->
	<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="background-color:#dddddd;color:#dddddd"/>
	<!-- /wp:separator -->

	<!-- wp:group {"style":{"spacing":{"padding":{"top":"56px","bottom":"56px","left":"56px","right":"56px"}},"elements":{"link":{"color":{"textColor":"primary","text":"var:preset|color|primary"}}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group has-link-color" style="padding-top:56px;padding-right:56px;padding-bottom:56px;padding-left:56px">

	<!-- wp:heading {"level":3,"style":{"typography":{"fontStyle":"normal","fontWeight":"500","fontSize":"28px"}}} -->
	<h3 class="wp-block-heading" style="font-size:28px;font-style:normal;font-weight:500">' . __( 'Frequently Asked Questions', 'newspack' ) . '</h3>
	<!-- /wp:heading -->

	<!-- wp:paragraph -->
	<p><strong>' . __( 'What is a magic link?', 'newspack' ) . '</strong><br>' . __( 'It\’s a temporary, password-free link like the one above to help you quickly and securely verify your identity and sign in – all without needing to set a password.', 'newspack' ) . '</p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph -->
	<p><strong>' . __( 'Can I still create a password?', 'newspack' ) . '</strong><br>' . sprintf( /* Translators: 1: opening HTML anchor tag 2: closing HTML anchor tag */ __( 'Yes. For security reasons, we recommend signing in with Google or using a one-time code sent to your email, but you can set a password if you prefer. %1$sClick here%2$s to create a password.', 'newspack' ), '<a href="*SET_PASSWORD_LINK*" data-type="link" data-id="*SET_PASSWORD_LINK*">', '</a>' ) . '</p>
	<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->' .

	// Footer.
	'<!-- wp:group {"style":{"spacing":{"padding":{"top":"56px","bottom":"56px","left":"56px","right":"56px"}},"elements":{"link":{"color":{"text":"primary-text"}}}},"backgroundColor":"primary","textColor":"primary-text","className":"has-secondary-color has-secondary-text-color ' . \Newspack\Emails::POST_TYPE . '-has-primary-text-color","layout":{"type":"constrained"}} -->
	<div class="wp-block-group has-secondary-color has-secondary-text-color has-primary-text-color has-primary-background-color has-text-color has-background has-link-color ' . \Newspack\Emails::POST_TYPE . '-has-primary-text-color" style="padding-top:56px;padding-right:56px;padding-bottom:56px;padding-left:56px">' .

	$social_links .

	'<!-- wp:paragraph {"fontSize":"small"} -->
	<p class="has-small-font-size">*SITE_CONTACT*<br>' . sprintf( /* Translators: 1: link to site url. */ __( 'You received this email because you requested to sign in to %s', 'newspack-plugin' ), '<a href="*SITE_URL*">*SITE_URL*</a>' ) . '</p>
	<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->';

$email_html = '
	<!doctype html>
	<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
		<head>
			<title>' . __( 'Sign in', 'newspack-plugin' ) . '</title>
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
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;"><h2 class="wp-block-heading" style="font-size:46px;font-style:normal;font-weight:500">' . __( 'Sign in', 'newspack-plugin' ) . '</h2></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;"><p>' . sprintf( /* Translators: s: the site title. */ __( 'Here\'s your one-time password to sign in to %s', 'newspack-plugin' ), '*SITE_TITLE*' ) . '</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:36px;line-height:1.5;text-align:left;"><p style="font-size:28px;letter-spacing:5px">*MAGIC_LINK_OTP*</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;"><p style="font-style:normal;font-weight:400">' . sprintf( /* Translators: 1: Opening HTML anchor tag. 2: Closing HTML anchor tag. */ __( 'Copy your one-time password, or click the magic link below to finish signing in. You also have the option to %1$screate a password%2$s for your account.', 'newspack-plugin' ), '<a style="color: ' . esc_attr( $primary_color ) . ';" href="*SET_PASSWORD_LINK*" data-type="link" data-id="*SET_PASSWORD_LINK*">', '</a>' ) .
	'</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="mj-column-has-width-outlook" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix mj-column-has-width" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"><tbody><tr><td align="center" bgcolor="' . esc_attr( $primary_color ) . ' !important" role="presentation" style="border:none;border-radius:4px;cursor:auto;mso-padding-alt:12px 24px;background:' . esc_attr( $primary_color ) . ' !important;" valign="middle"><a href="*MAGIC_LINK_URL*" style="display:inline-block;background:' . esc_attr( $primary_color ) . ' !important;color:' . esc_attr( $primary_text_color ) . ' !important;font-family:Arial;font-size:16px;font-weight:bold;line-height:1.5;margin:0;text-decoration:none;text-transform:none;padding:12px 24px;mso-padding-alt:0px;border-radius:4px;" target="_blank">' . sprintf( /* Translators: s: site title. */ __( 'Continue to %s', 'newspack-plugin' ), '*SITE_TITLE*' ) . '</a></td></tr></tbody></table></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;"><p>' . __( 'You can also copy and paste this link in your browser:', 'newspack-plugin' ) . '<br /><a style="color: ' . esc_attr( $primary_color ) . '" href="*MAGIC_LINK_URL*" data-type="URL" data-id="*MAGIC_LINK_URL*" target="_blank">*MAGIC_LINK_URL*</a></p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="' . esc_attr( $primary_color ) . ' !important" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
				<div style="margin:0px auto;max-width:600px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td align="center" class="" style="" ><![endif]--><p style="border-top:solid 1px #dddddd;font-size:1px;margin:0px auto;width:100%;"></p><!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" style="border-top:solid 1px #dddddd;font-size:1px;margin:0px auto;width:600px;" role="presentation" width="600px" ><tr><td style="height:0;line-height:0;"> &nbsp;</td></tr></table></td></tr></table><![endif]--></td></tr></tbody></table></div>
				<div style="margin:0px auto;max-width:600px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:56px 56px 56px 56px;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;"><h3 class="wp-block-heading" style="font-size:28px;font-style:normal;font-weight:500">' . __( 'Frequently Asked Questions', 'newspack' ) . '</h3></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;"><p><strong>' . __( 'What is a magic link?', 'newspack' ) . '</strong><br>' . __( 'It\’s a temporary, password-free link like the one above to help you quickly and securely verify your identity and sign in – all without needing to set a password.', 'newspack' ) . '</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:16px;line-height:1.5;text-align:left;"><p><strong>' . __( 'Can I still create a password?', 'newspack' ) . '</strong><br>' . sprintf( /* Translators: 1: opening HTML anchor tag 2: closing HTML anchor tag */ __( 'Yes. For security reasons, we recommend signing in with Google or using a one-time code sent to your email, but you can set a password if you prefer. %1$sClick here%2$s to create a password.', 'newspack' ), '<a href="*SET_PASSWORD_LINK*" data-type="link" data-id="*SET_PASSWORD_LINK*">', '</a>' ) . '</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="' . esc_attr( $primary_color ) . ' !important" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
				<div style="background:' . esc_attr( $primary_color ) . ' !important;background-color:' . esc_attr( $primary_color ) . ' !important;margin:0px auto;max-width:600px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:' . esc_attr( $primary_color ) . ' !important;background-color:' . esc_attr( $primary_color ) . ' !important;width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:56px 56px 56px 56px;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><!--[if mso | IE]><table align="left" border="0" cellpadding="0" cellspacing="0" role="presentation" ><tr><td><![endif]-->' . $social_links_html . '<!--[if mso | IE]></td></td></tr></table><![endif]--></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr><tr><td class="" width="600px" ><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:488px;" width="488" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
					<div style="margin:0px auto;max-width:488px;"><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;"><tbody><tr><td style="direction:ltr;font-size:0px;padding:0;text-align:center;"><!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:488px;" ><![endif]-->
						<div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td style="vertical-align:top;padding:12px;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody><tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;"><div style="font-family:Arial;font-size:12px;line-height:1.5;text-align:left;color:' . esc_attr( $primary_text_color ) . ';"><p class="has-small-font-size">*SITE_CONTACT*<br> ' . sprintf( /* Translators: s: link to site url. */ __( 'You received this email because you requested to sign in to %s', 'newspack-plugin' ), '<a style="color:' . esc_attr( $primary_text_color ) . ' !important" href="*SITE_URL*" target="_blank">*SITE_URL*</a>' ) . '</p></div></td></tr></tbody></table></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table></td></tr></table><![endif]--></td></tr></tbody></table></div><!--[if mso | IE]></td></tr></table><![endif]-->
			</div>
		</body>
	</html>';

return array(
	'post_title'   => __( 'Sign in', 'newspack-plugin' ),
	'post_content' => $post_content,
	'email_html'   => $email_html,
);
