/**
 * Audience Management prerequisite state for Access Control.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { people } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Grid, SectionHeader } from '../../../../../packages/components/src';

const AudienceManagementRequired = () => {
	const audienceManagementUrl = window.newspackAudienceContentGates?.audience_management_url || '';

	return (
		<Grid columns={ 4 } noMargin>
			<VStack start={ 2 } end={ 4 } spacing={ 8 }>
				<SectionHeader
					icon={ people }
					title={ __( 'Set up Audience Management first', 'newspack-plugin' ) }
					description={ __(
						'Access Control needs reader accounts, sign-in and account emails. Audience Management provides them.',
						'newspack-plugin'
					) }
					pageHeader
					noMargin
				/>
				<VStack alignment="center" spacing={ 4 }>
					<Button variant="primary" href={ audienceManagementUrl }>
						{ __( 'Set up Audience Management', 'newspack-plugin' ) }
					</Button>
					<ExternalLink href="https://help.newspack.com/engagement/reader-activation-system/">
						{ __( 'Learn more', 'newspack-plugin' ) }
					</ExternalLink>
				</VStack>
			</VStack>
		</Grid>
	);
};

export default AudienceManagementRequired;
