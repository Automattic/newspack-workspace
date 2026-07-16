/**
 * Reusable empty-state for admin-shell list screens.
 *
 * Strict-empty only — render this when the unfiltered list has zero
 * items. Filter-/search-empty case keeps the DataView's built-in
 * "no results" treatment.
 */

import { Button, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Grid, SectionHeader } from 'newspack-components';

import type { ComponentProps, ComponentType, ReactElement } from 'react';

// `VStack` carries grid-placement props (`start`/`end`) here that its own type
// doesn't model; widen the component's props at this @wordpress/components boundary.
const PlacementVStack = VStack as ComponentType< ComponentProps< typeof VStack > & { start?: number; end?: number } >;

interface EmptyStateProps {
	/** Icon component (from `@wordpress/icons` or similar) for the page header. */
	icon: ReactElement;
	/** Page-header title (e.g. "Get started with advertisers"). */
	title: string;
	/** Short, value-prop description below the title. */
	description: string;
	/** Button label (e.g. "Add new advertiser"). */
	ctaTitle: string;
	/** Button link target. Mutually exclusive with `ctaOnClick`. */
	ctaHref?: string;
	/** Click handler for an in-page create flow (e.g. opens a modal). Mutually exclusive with `ctaHref`. */
	ctaOnClick?: () => void;
}

export default function EmptyState( { icon, title, description, ctaTitle, ctaHref, ctaOnClick }: EmptyStateProps ): ReactElement {
	const hasCtaHref = typeof ctaHref === 'string' && ctaHref.length > 0;
	const hasCtaOnClick = typeof ctaOnClick === 'function';
	const hasExactlyOneCtaAction = hasCtaHref !== hasCtaOnClick;

	if ( ! hasExactlyOneCtaAction && process.env.NODE_ENV !== 'production' ) {
		throw new Error( 'EmptyState requires exactly one of `ctaHref` or `ctaOnClick`.' );
	}

	// Exactly one of href / onClick drives the CTA. A misconfigured prod build (the dev-time
	// throw above surfaces neither-or-both) renders a disabled button rather than an active CTA;
	// undefined href/onClick are omitted by React, matching the original conditional prop set.
	const buttonHref = hasExactlyOneCtaAction && hasCtaHref ? ctaHref : undefined;
	const buttonOnClick = hasExactlyOneCtaAction && ! hasCtaHref ? ctaOnClick : undefined;
	const buttonDisabled = ! hasExactlyOneCtaAction;

	return (
		<Grid className="newspack-newsletters-admin__empty-state" columns={ 4 } noMargin>
			<PlacementVStack start={ 2 } end={ 4 } spacing={ 8 }>
				<SectionHeader icon={ icon } title={ title } description={ description } pageHeader noMargin />
				<HStack justify="center">
					<Button variant="primary" href={ buttonHref } onClick={ buttonOnClick } disabled={ buttonDisabled }>
						{ ctaTitle }
					</Button>
				</HStack>
			</PlacementVStack>
		</Grid>
	);
}
