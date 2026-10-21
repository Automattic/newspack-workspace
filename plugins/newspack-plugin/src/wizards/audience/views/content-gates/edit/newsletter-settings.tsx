/**
 * Premium newsletter list settings: how readers join a gate's lists, and whether they can see them.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CardBody, SelectControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { people, unseen } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { CardSettingsGroup } from '../../../../../../packages/components/src';
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
		<Stack direction="column" gap="lg">
			<CardSettingsGroup
				actionType="none"
				title={ __( 'Auto-signup', 'newspack-plugin' ) }
				description={ __(
					'Add readers to these lists when they gain access. Readers who leave a list aren’t added back when they renew.',
					'newspack-plugin'
				) }
				icon={ people }
				// A "none" card shows its body only while active, and there is nothing to collapse.
				isActive
			>
				<CardBody size="small">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Auto-signup', 'newspack-plugin' ) }
						hideLabelFromVision
						value={ autoSignup }
						options={ getAutoSignupOptions( siteAutoSignup ) }
						onChange={ ( value: AutoSignupMode ) => onChange( { auto_signup: value } ) }
					/>
				</CardBody>
			</CardSettingsGroup>
			<CardSettingsGroup
				actionType="toggle"
				title={ __( 'Hide from readers', 'newspack-plugin' ) }
				description={ __(
					'Keep these lists out of My Account, sign-up forms, registration, and the post-checkout modal. Readers are still added and removed as their access changes.',
					'newspack-plugin'
				) }
				icon={ unseen }
				isActive={ hideLists }
				onEnable={ () => onChange( { hide_lists: ! hideLists } ) }
			/>
		</Stack>
	);
}
