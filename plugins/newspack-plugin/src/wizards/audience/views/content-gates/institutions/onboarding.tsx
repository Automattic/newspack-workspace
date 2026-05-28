/**
 * Content Gates Onboarding component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { institution } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Grid, SectionHeader } from '../../../../../../packages/components/src';

const InstitutionsOnboarding = () => {
	return (
		<div
			style={ {
				margin: '0 auto',
				maxWidth: 'calc(var(--newspack-wizard-section-space) * 2 + var(--newspack-wizard-section-width))',
				padding: '0 var(--newspack-wizard-section-space) 0',
			} }
		>
			<Grid columns={ 4 } noMargin>
				<VStack start={ 2 } end={ 4 } spacing={ 8 }>
					<SectionHeader
						icon={ institution }
						title={ __( 'Get started with institutions', 'newspack-plugin' ) }
						description={ __(
							'Create institutions to manage access to your content by email domain, IP range, or reader data.',
							'newspack-plugin'
						) }
						pageHeader
						noMargin
					/>
					<HStack alignment="center">
						<Button variant="primary" href="#/institutions/new">
							{ __( 'Add new institution', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Grid>
		</div>
	);
};

export default InstitutionsOnboarding;
