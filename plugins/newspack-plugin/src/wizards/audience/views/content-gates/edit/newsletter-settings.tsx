/**
 * Premium newsletter list settings: how readers join a gate's lists, and whether they can see them.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	CardBody,
	CardDivider,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { getAutoSignupOptions } from '../utils';

interface NewsletterSettingsProps {
	autoSignup: AutoSignupMode;
	hideLists: boolean;
	/** The site-wide auto-signup setting, which the "Default" choice follows. Undefined until the wizard data loads. */
	siteAutoSignup?: boolean;
	onChange: ( change: { auto_signup?: AutoSignupMode; hide_lists?: boolean } ) => void;
}

/**
 * Whether a gate's lists sign readers up. Treated as yes while the site setting loads, so the
 * hiding toggle isn't disabled before there's a reason to.
 *
 * @param autoSignup     The gate's auto-signup mode.
 * @param siteAutoSignup The site-wide auto-signup setting, if loaded.
 */
const signsReadersUp = ( autoSignup: AutoSignupMode, siteAutoSignup?: boolean ) => {
	if ( autoSignup === 'default' ) {
		return siteAutoSignup !== false;
	}
	return autoSignup === 'on';
};

export default function NewsletterSettings( { autoSignup, hideLists, siteAutoSignup, onChange }: NewsletterSettingsProps ) {
	const canHide = signsReadersUp( autoSignup, siteAutoSignup );
	// Left enabled while the lists are hidden, so a gate hiding lists nobody can join can still be fixed here.
	const hideDisabled = ! canHide && ! hideLists;

	return (
		<>
			<CardBody size="small">
				<ToggleGroupControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					isBlock
					label={ __( 'Auto-signup', 'newspack-plugin' ) }
					help={ __(
						'Add readers to these lists when they gain access. Readers who leave a list aren’t added back when they renew.',
						'newspack-plugin'
					) }
					value={ autoSignup }
					onChange={ value => {
						const mode = value as AutoSignupMode;
						// Hidden lists that nobody is added to automatically can't be joined at all.
						onChange( signsReadersUp( mode, siteAutoSignup ) ? { auto_signup: mode } : { auto_signup: mode, hide_lists: false } );
					} }
				>
					{ getAutoSignupOptions( siteAutoSignup ).map( option => (
						<ToggleGroupControlOption key={ option.value } value={ option.value } label={ option.label } />
					) ) }
				</ToggleGroupControl>
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
					disabled={ hideDisabled }
					onChange={ value => onChange( { hide_lists: value } ) }
				/>
			</CardBody>
		</>
	);
}
