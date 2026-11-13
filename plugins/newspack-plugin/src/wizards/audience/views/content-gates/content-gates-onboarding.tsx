/**
 * Content Gates Onboarding component.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { envelope, postList, settings } from '@wordpress/icons';
import { EmptyState, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Card } from '../../../../../packages/components/src';
import { contentLocked, emailPremium } from '../../../../../packages/icons';

const ContentGatesOnboarding = ( { isNewsletter = false }: { isNewsletter?: boolean } ) => {
	return (
		<Stack direction="column" align="center" gap="2xl">
			<EmptyState.Root>
				<EmptyState.Icon icon={ isNewsletter ? emailPremium : contentLocked } />
				<EmptyState.Title>
					{ isNewsletter ? __( 'No premium newsletters yet', 'newspack-plugin' ) : __( 'No content gates yet', 'newspack-plugin' ) }
				</EmptyState.Title>
				<EmptyState.Description>
					{ isNewsletter
						? __( 'Restrict newsletter lists to paying readers. Pick the lists, then choose who gets access.', 'newspack-plugin' )
						: __(
								'Gates control who can read which content. Pick what to restrict, then require registration, payment, or both.',
								'newspack-plugin'
						  ) }
				</EmptyState.Description>
			</EmptyState.Root>
			<Stack direction="column" gap="lg" style={ { width: '100%', maxWidth: '487px' } }>
				<Card
					actionType="chevron"
					isSmall
					__experimentalCoreCard
					__experimentalCoreProps={ {
						as: 'a',
						header: (
							<>
								<h3>
									{ sprintf(
										// translators: %s is the type of content to restrict.
										__( 'Restrict all %s', 'newspack-plugin' ),
										isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'posts', 'newspack-plugin' )
									) }
								</h3>
								<p>
									{ isNewsletter
										? __( 'All lists on your site will require access.', 'newspack-plugin' )
										: __( 'All posts on your site will require access.', 'newspack-plugin' ) }
								</p>
							</>
						),
						href: '#/edit/new/all',
						icon: isNewsletter ? envelope : postList,
						iconBackgroundColor: true,
					} }
				/>
				<Card
					actionType="chevron"
					isSmall
					__experimentalCoreCard
					__experimentalCoreProps={ {
						as: 'a',
						header: (
							<>
								<h3>
									{ sprintf(
										// translators: %s is the type of content to restrict.
										__( 'Choose specific %s', 'newspack-plugin' ),
										isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'posts', 'newspack-plugin' )
									) }
								</h3>
								<p>
									{ isNewsletter
										? __( 'Select which lists to restrict using custom rules.', 'newspack-plugin' )
										: __( 'Select which content to restrict using custom rules.', 'newspack-plugin' ) }
								</p>
							</>
						),
						href: '#/edit/new/custom',
						icon: settings,
						iconBackgroundColor: true,
					} }
				/>
			</Stack>
		</Stack>
	);
};

export default ContentGatesOnboarding;
