/**
 * Content Gates Onboarding component.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { envelope, postList, settings } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Card } from '../../../../../packages/components/src';
import EmptyState from '../../../../../packages/components/src/empty-state';
import { contentLocked, emailPremium } from '../../../../../packages/icons';

const ContentGatesOnboarding = ( { isNewsletter = false }: { isNewsletter?: boolean } ) => {
	return (
		<EmptyState.Root>
			<EmptyState.Header
				icon={ isNewsletter ? emailPremium : contentLocked }
				title={ isNewsletter ? __( 'No premium newsletters yet', 'newspack-plugin' ) : __( 'No content gates yet', 'newspack-plugin' ) }
				description={
					isNewsletter
						? __(
								'Restrict newsletter lists to registered or paying readers. Pick the lists, then choose who gets access.',
								'newspack-plugin'
						  )
						: __(
								'Gates control who can read which content. Pick what to restrict, then require registration, payment, or both.',
								'newspack-plugin'
						  )
				}
			/>
			<VStack spacing={ 4 }>
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
			</VStack>
		</EmptyState.Root>
	);
};

export default ContentGatesOnboarding;
