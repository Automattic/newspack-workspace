/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { withWizardScreen, ActionCard, Button, Card, Grid, SectionHeader } from '../../../../../packages/components/src';

const Completed = () => {
	useEffect( () => {
		document.body.classList.add( 'newspack-wizard__completed' );
		return () => document.body.classList.remove( 'newspack-wizard__completed' );
	}, [] );

	return (
		<>
			<Card noBorder>
				<SectionHeader
					title={ __( 'You’re ready to go!', 'newspack' ) }
					description={ __( 'While you’re off to a great start, there’s plenty more you can do:', 'newspack' ) }
					heading={ 1 }
					centered
					isWhite
				/>
			</Card>

			<Card isWhite>
				<Grid>
					<ActionCard
						title={ __( 'Configure Newspack', 'newspack' ) }
						description={ __( 'Go in-depth with our various options to set up Newspack to meet your needs.', 'newspack' ) }
						className="newspack-setup-completed__card"
					>
						<HStack justify="flex-end">
							<Button variant="primary" href={ newspack_urls.dashboard }>
								{ __( 'Go to the Dashboard', 'newspack' ) }
							</Button>
						</HStack>
					</ActionCard>

					<ActionCard
						title={ __( 'Explore our documentation', 'newspack' ) }
						description={ __( 'Read about the different tools, plugins, and themes that make up Newspack.', 'newspack' ) }
						className="newspack-setup-completed__card"
					>
						<HStack justify="flex-end">
							<Button variant="primary" href={ newspack_urls.support }>
								{ __( 'Read Documentation', 'newspack' ) }
							</Button>
						</HStack>
					</ActionCard>

					<ActionCard
						title={ __( 'Update your homepage', 'newspack' ) }
						description={ __( 'We’ve created the basics, now it’s time to update the content.', 'newspack' ) }
						className="newspack-setup-completed__card"
					>
						<HStack justify="flex-end">
							<Button variant="primary" href={ newspack_urls.homepage }>
								{ __( 'Edit Homepage', 'newspack' ) }
							</Button>
						</HStack>
					</ActionCard>

					<ActionCard
						title={ __( 'View your site', 'newspack' ) }
						description={ __( 'Preview what you’ve created so far. It looks great!', 'newspack' ) }
						className="newspack-setup-completed__card"
					>
						<HStack justify="flex-end">
							<Button variant="primary" href={ newspack_urls.site }>
								{ __( 'Visit Site', 'newspack' ) }
							</Button>
						</HStack>
					</ActionCard>
				</Grid>
			</Card>
		</>
	);
};

export default withWizardScreen( Completed, { hidePrimaryButton: true, hideHeader: true } );
