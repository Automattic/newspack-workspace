/**
 * Audience Management prerequisite state, shown in place of a gate-editing screen
 * when Audience Management is not set up.
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
import { hasAudienceManagement } from './utils';

const AudienceManagementRequired = ( { isNewsletter = false }: { isNewsletter?: boolean } ) => {
	const audienceManagementUrl = window.newspackAudienceContentGates?.audience_management_url || '';

	return (
		<Grid columns={ 4 } noMargin>
			<VStack start={ 2 } end={ 4 } spacing={ 8 }>
				<SectionHeader
					icon={ people }
					title={ __( 'Set up Audience Management first', 'newspack-plugin' ) }
					description={
						isNewsletter
							? __(
									'Premium newsletters need reader accounts, sign-in and account emails. Audience Management provides them.',
									'newspack-plugin'
							  )
							: __(
									'Access Control needs reader accounts, sign-in and account emails. Audience Management provides them.',
									'newspack-plugin'
							  )
					}
					pageHeader
					noMargin
				/>
				<VStack alignment="center" spacing={ 4 }>
					{ /* Rendered only with a real destination: a primary CTA pointing at href=""
					     reloads the blocked screen, which is worse than offering no button. */ }
					{ audienceManagementUrl && (
						<Button variant="primary" href={ audienceManagementUrl }>
							{ __( 'Set up Audience Management', 'newspack-plugin' ) }
						</Button>
					) }
					<ExternalLink href="https://help.newspack.com/engagement/audience-management-system/">
						{ __( 'Learn more', 'newspack-plugin' ) }
					</ExternalLink>
				</VStack>
			</VStack>
		</Grid>
	);
};

/**
 * Wrap a wizard section so it is replaced by the prerequisite state when Audience
 * Management is off.
 *
 * Applied at the router level rather than inside each view, because every section of
 * these screens depends on the prerequisite - not just the gate list. `#/edit/new/all`,
 * the settings sections and Institutions are all reachable directly by bookmark or
 * browser history, and the gate editor there offers a working Save, so a publisher
 * could fill in an entire gate before the REST guard refused it. Guarding the router
 * means a new section inherits the block instead of relying on someone remembering.
 *
 * Safe to short-circuit the whole section: the Wizard resets header data on every
 * route change, so no stale header action survives into the blocked state.
 */
export const requireAudienceManagement = < P extends object >(
	Section: React.ComponentType< P >,
	{ isNewsletter = false }: { isNewsletter?: boolean } = {}
) => {
	const Guarded = ( props: P ) =>
		hasAudienceManagement() ? <Section { ...props } /> : <AudienceManagementRequired isNewsletter={ isNewsletter } />;
	// Named so the nine guarded sections are distinguishable in React DevTools
	// rather than all reading as `Anonymous`.
	Guarded.displayName = `RequireAudienceManagement(${ Section.displayName || Section.name || 'Section' })`;
	return Guarded;
};

export default AudienceManagementRequired;
