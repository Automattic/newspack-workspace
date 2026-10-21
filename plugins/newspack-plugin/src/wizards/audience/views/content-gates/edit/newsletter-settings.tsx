/**
 * Premium newsletter list settings: how readers join a gate's lists, and whether they can see them.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CardBody, SelectControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { Stack } from '@wordpress/ui';
import { people, unseen } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Card, CardSettingsGroup } from '../../../../../../packages/components/src';
import { getAutoSignupOptions } from '../utils';

interface NewsletterSettingsProps {
	autoSignup: AutoSignupMode;
	hideLists: boolean;
	/** The site-wide auto-signup setting, which the "Use site setting" choice follows. */
	siteAutoSignup?: boolean;
	onChange: ( change: { auto_signup?: AutoSignupMode; hide_lists?: boolean } ) => void;
}

export default function NewsletterSettings( { autoSignup, hideLists, siteAutoSignup, onChange }: NewsletterSettingsProps ) {
	const explanationId = useInstanceId( NewsletterSettings, 'newspack-newsletter-settings-auto-signup' );

	return (
		<Stack direction="column" gap="lg">
			{ /*
			 * A plain Card, not a CardSettingsGroup: that component only shows its body while active, and an active
			 * card gets the blue "switched on" look, which this always-visible select must not have.
			 * The card reuses CardSettingsGroup's internal class and header markup
			 * (packages/components/src/card-settings-group/index.tsx), so a change there must be mirrored here.
			 */ }
			<Card
				className="newspack-card--core--settings-group"
				isSmall
				__experimentalCoreCard
				__experimentalCoreProps={ {
					header: (
						<>
							<h3>{ __( 'Auto-signup', 'newspack-plugin' ) }</h3>
							<p id={ explanationId }>
								{ __(
									'Add readers to these lists when they gain access. Readers who leave a list aren’t added back when they renew.',
									'newspack-plugin'
								) }
							</p>
						</>
					),
					icon: people,
					iconBackgroundColor: true,
					title: __( 'Auto-signup', 'newspack-plugin' ),
				} }
			>
				<CardBody size="small">
					{ /*
					 * The explanation sits in the card header, so it's tied to the select through a named group around it.
					 * SelectControl can't take it: its only description is `help`, which would repeat the text on screen,
					 * and it overrides any `aria-describedby` passed in.
					 */ }
					<div role="group" aria-label={ __( 'Auto-signup', 'newspack-plugin' ) } aria-describedby={ explanationId }>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Auto-signup', 'newspack-plugin' ) }
							hideLabelFromVision
							value={ autoSignup }
							options={ getAutoSignupOptions( siteAutoSignup ) }
							onChange={ ( value: AutoSignupMode ) => onChange( { auto_signup: value } ) }
						/>
					</div>
				</CardBody>
			</Card>
			<CardSettingsGroup
				actionType="toggle"
				title={ __( 'Hide from readers', 'newspack-plugin' ) }
				description={ __(
					'Keep these lists out of My Account, sign-up forms, registration, and the post-checkout modal. Auto-signup and removal when access ends still apply.',
					'newspack-plugin'
				) }
				icon={ unseen }
				isActive={ hideLists }
				onEnable={ () => onChange( { hide_lists: ! hideLists } ) }
			/>
		</Stack>
	);
}
