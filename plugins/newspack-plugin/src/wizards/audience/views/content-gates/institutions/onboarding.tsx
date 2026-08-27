/**
 * Content Gates Onboarding component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { institution } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button } from '../../../../../../packages/components/src';
import EmptyState from '../../../../../../packages/components/src/empty-state';

const InstitutionsOnboarding = () => {
	return (
		<div className="newspack-wizard__column">
			<EmptyState.Root>
				<EmptyState.Header
					icon={ institution }
					title={ __( 'Get started with institutions', 'newspack-plugin' ) }
					description={ __(
						'Create institutions to manage access to your content by email domain, IP range, or reader data.',
						'newspack-plugin'
					) }
				/>
				<EmptyState.Actions>
					<Button variant="primary" href="#/institutions/new">
						{ __( 'Add Institution', 'newspack-plugin' ) }
					</Button>
				</EmptyState.Actions>
			</EmptyState.Root>
		</div>
	);
};

export default InstitutionsOnboarding;
