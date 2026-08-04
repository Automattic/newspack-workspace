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
import Router from '../../../../../packages/components/src/proxied-imports/router';
import { hasAudienceManagement } from './utils';

const { Redirect } = Router;

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
									'Premium newsletters need accounts, sign-in, and account emails. Audience Management provides them.',
									'newspack-plugin'
							  )
							: __(
									'Access Control needs accounts, sign-in, and account emails. Audience Management provides them.',
									'newspack-plugin'
							  )
					}
					pageHeader
					noMargin
				/>
				<VStack alignment="center" spacing={ 4 }>
					{ /* Rendered only with a real destination: a primary CTA pointing at href=""
					     reloads this same screen, which is worse than offering no button. */ }
					{ audienceManagementUrl && (
						<Button variant="primary" href={ audienceManagementUrl }>
							{ __( 'Set up Audience Management', 'newspack-plugin' ) }
						</Button>
					) }
					{ /* Points at the Access Control doc rather than the Audience Management one:
					     the prerequisite is being added there, and that page is where the original
					     support question started. */ }
					<ExternalLink href="https://help.newspack.com/access-control/">{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
				</VStack>
			</VStack>
		</Grid>
	);
};

/**
 * Wrap a wizard section so it is replaced by the prerequisite state when Audience
 * Management is off.
 *
 * Reserved for a screen's landing section - the one route that is allowed to render
 * the prerequisite state. Every other section redirects to it via
 * `redirectWithoutAudienceManagement()` rather than rendering its own copy, because
 * the Wizard draws `section.title` and `section.description` above the section
 * component: on `#/settings/countdown-banner` that produced the settings page header,
 * implying the feature was configurable, stacked directly on top of this one.
 *
 * Safe to short-circuit the whole section: the Wizard resets header data on every
 * route change, so no stale header action survives into the blocked state.
 *
 * Call at module scope, never inside a component body. Each call mints a new
 * component type, and the Wizard renders sections as `<SectionComponent />` - a type
 * that changes identity between renders remounts the section subtree and discards
 * in-progress editor state.
 */
export const requireAudienceManagement = < P extends object >(
	Section: React.ComponentType< P >,
	{ isNewsletter = false }: { isNewsletter?: boolean } = {}
) => {
	const Guarded = ( props: P ) =>
		hasAudienceManagement() ? <Section { ...props } /> : <AudienceManagementRequired isNewsletter={ isNewsletter } />;
	// Named so the guarded sections are distinguishable in React DevTools
	// rather than all reading as `Anonymous`.
	Guarded.displayName = `RequireAudienceManagement(${ Section.displayName || Section.name || 'Section' })`;
	return Guarded;
};

/**
 * Wrap a wizard section so it redirects to the screen's landing route when Audience
 * Management is off.
 *
 * These routes stay reachable by bookmark and browser history, and the gate editor
 * among them offers a working Save, so they cannot simply render. Sending them to the
 * one route that explains the prerequisite keeps the explanation in a single place.
 *
 * Same module-scope requirement as `requireAudienceManagement()`.
 */
export const redirectWithoutAudienceManagement = < P extends object >( Section: React.ComponentType< P >, redirectTo: string ) => {
	const Guarded = ( props: P ) => ( hasAudienceManagement() ? <Section { ...props } /> : <Redirect to={ redirectTo } /> );
	Guarded.displayName = `RedirectWithoutAudienceManagement(${ Section.displayName || Section.name || 'Section' })`;
	return Guarded;
};

export default AudienceManagementRequired;
