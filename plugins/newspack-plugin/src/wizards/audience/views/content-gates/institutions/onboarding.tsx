/**
 * Content Gates Onboarding component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { institution } from '@wordpress/icons';
import { EmptyState, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button } from '../../../../../../packages/components/src';

const InstitutionsOnboarding = () => {
	return (
		<div className="newspack-wizard__column">
			<Stack direction="column" align="center">
				<EmptyState.Root>
					<EmptyState.Icon icon={ institution } />
					<EmptyState.Title>{ __( 'No institutions yet', 'newspack-plugin' ) }</EmptyState.Title>
					<EmptyState.Description>
						{ __( 'Give an organization access to gated content by email domain, IP range, or reader data.', 'newspack-plugin' ) }
					</EmptyState.Description>
					<EmptyState.Actions>
						<Button variant="primary" href="#/institutions/new">
							{ __( 'Add Institution', 'newspack-plugin' ) }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			</Stack>
		</div>
	);
};

export default InstitutionsOnboarding;
