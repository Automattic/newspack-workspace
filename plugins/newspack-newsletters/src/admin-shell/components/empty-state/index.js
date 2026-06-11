/**
 * Reusable empty-state for admin-shell list screens.
 *
 * Strict-empty only — render this when the unfiltered list has zero
 * items. Filter-/search-empty case keeps the DataView's built-in
 * "no results" treatment.
 */

import { Button, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Grid, SectionHeader } from 'newspack-components';

/**
 * @typedef {Object} EmptyStateProps
 * @property {*}        icon         Icon component (from `@wordpress/icons` or similar) for the page header.
 * @property {string}   title        Page-header title (e.g. "Get started with advertisers").
 * @property {string}   description  Short, value-prop description below the title.
 * @property {string}   ctaTitle     Button label (e.g. "Add new advertiser").
 * @property {string}   [ctaHref]    Button link target. Mutually exclusive with `ctaOnClick`.
 * @property {Function} [ctaOnClick] Click handler. Used when the create flow is in-page (e.g. opens a modal)
 *                                   rather than a navigation. Mutually exclusive with `ctaHref`.
 */

/**
 * @param {EmptyStateProps} props
 * @return {JSX.Element} The rendered empty state.
 */
export default function EmptyState( { icon, title, description, ctaTitle, ctaHref, ctaOnClick } ) {
	const hasCtaHref = typeof ctaHref === 'string' && ctaHref.length > 0;
	const hasCtaOnClick = typeof ctaOnClick === 'function';
	const hasExactlyOneCtaAction = hasCtaHref !== hasCtaOnClick;

	if ( ! hasExactlyOneCtaAction && process.env.NODE_ENV !== 'production' ) {
		throw new Error( 'EmptyState requires exactly one of `ctaHref` or `ctaOnClick`.' );
	}

	const buttonProps = { variant: 'primary' };
	if ( hasExactlyOneCtaAction ) {
		if ( hasCtaHref ) {
			buttonProps.href = ctaHref;
		} else {
			buttonProps.onClick = ctaOnClick;
		}
	} else {
		// Production fallback: the dev-time throw above surfaces misuse (neither or both CTAs set).
		// In a misconfigured prod build, disable the button rather than render an active CTA.
		buttonProps.disabled = true;
	}

	return (
		<Grid className="newspack-newsletters-admin__empty-state" columns={ 4 } noMargin>
			<VStack start={ 2 } end={ 4 } spacing={ 8 }>
				<SectionHeader icon={ icon } title={ title } description={ description } pageHeader noMargin />
				<HStack justify="center">
					<Button { ...buttonProps }>{ ctaTitle }</Button>
				</HStack>
			</VStack>
		</Grid>
	);
}
