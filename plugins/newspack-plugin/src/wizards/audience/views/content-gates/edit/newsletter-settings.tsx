/**
 * Premium newsletter list settings, as rows of the Paid Access card: how readers join a gate's lists, and whether they can see them.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CardBody, CardDivider, SelectControl, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { getAutoSignupOptions } from '../utils';

interface NewsletterSettingsProps {
	autoSignup: AutoSignupMode;
	hideLists: boolean;
	/** The site-wide auto-signup setting, which the "Use site setting" choice follows. */
	siteAutoSignup?: boolean;
	onChange: ( change: { auto_signup?: AutoSignupMode; hide_lists?: boolean } ) => void;
}

export default function NewsletterSettings( { autoSignup, hideLists, siteAutoSignup, onChange }: NewsletterSettingsProps ) {
	return (
		<>
			<CardDivider />
			<CardBody size="small">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Auto-signup', 'newspack-plugin' ) }
					help={ __(
						'Add readers to these lists when they gain access. Readers who leave a list aren’t added back when they renew.',
						'newspack-plugin'
					) }
					value={ autoSignup }
					options={ getAutoSignupOptions( siteAutoSignup ) }
					onChange={ ( value: AutoSignupMode ) => onChange( { auto_signup: value } ) }
				/>
			</CardBody>
			<CardDivider />
			<CardBody size="small">
				<ToggleControl
					label={ __( 'Hide from readers', 'newspack-plugin' ) }
					help={ __(
						'Keep these lists out of My Account, sign-up forms, registration, and the post-checkout modal. Auto-signup and removal when access ends still apply.',
						'newspack-plugin'
					) }
					checked={ hideLists }
					onChange={ value => onChange( { hide_lists: value } ) }
				/>
			</CardBody>
		</>
	);
}
