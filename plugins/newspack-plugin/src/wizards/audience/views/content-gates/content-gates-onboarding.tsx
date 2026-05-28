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
import { Card, Grid, SectionHeader } from '../../../../../packages/components/src';
import { contentLocked, emailPremium } from '../../../../../packages/icons';

const ContentGatesOnboarding = ( { isNewsletter = false }: { isNewsletter?: boolean } ) => {
	return (
		<Grid columns={ 4 } noMargin>
			<VStack start={ 2 } end={ 4 } spacing={ 8 }>
				<SectionHeader
					icon={ isNewsletter ? emailPremium : contentLocked }
					title={ sprintf(
						// translators: %s is the type of content to restrict.
						__( 'Get started with %s', 'newspack-plugin' ),
						isNewsletter ? __( 'premium newsletters', 'newspack-plugin' ) : __( 'access control', 'newspack-plugin' )
					) }
					description={
						isNewsletter
							? __(
									'Set up premium newsletters to manage which lists readers can sign up for. Start by selecting which lists to restrict, then configure access through registered and/or paid options.',
									'newspack-plugin'
							  )
							: __(
									'Set up gates to manage what content readers can access across your site. Start by selecting which content to restrict, then configure access through registered and/or paid options (including metered rules).',
									'newspack-plugin'
							  )
					}
					pageHeader
					noMargin
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
			</VStack>
		</Grid>
	);
};

export default ContentGatesOnboarding;
