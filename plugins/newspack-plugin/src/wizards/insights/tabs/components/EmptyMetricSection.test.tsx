/**
 * Tests for EmptyMetricSection (NPPD-1694): the three `state` values, both
 * branches of `{N}` interpolation, presence/absence of `caption`, and the
 * non-dismissible callout.
 *
 * Body assertions check the rendered container (not `screen`): the callout's
 * `Notice` announces its text via `@wordpress/a11y` `speak()`, which duplicates
 * the copy into a global live-region outside the container — so a `screen`-level
 * text query would match twice.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import EmptyMetricSection from './EmptyMetricSection';

describe( 'EmptyMetricSection', () => {
	it( 'renders the title and caption as the section identity', () => {
		// Sample props exercising the component's rendering — NOT corpus copy, so
		// they are intentionally not part of the "window" → "timeframe"
		// normalization (NPPD-1698). Don't "sync" them.
		const { container } = render(
			<EmptyMetricSection
				title="Paid reader conversion"
				caption="How effectively paid access gates convert visitors."
				state="no_opportunity"
				body="No paid access gate attempts in this window."
			/>
		);
		expect( container ).toHaveTextContent( 'Paid reader conversion' );
		expect( container ).toHaveTextContent( 'How effectively paid access gates convert visitors.' );
		expect( container ).toHaveTextContent( 'No paid access gate attempts in this window.' );
	} );

	it( 'omits the caption when none is passed', () => {
		const { container } = render(
			<EmptyMetricSection title="Free reader conversion" state="no_opportunity" body="No registration impressions." />
		);
		expect( container ).not.toHaveTextContent( 'How effectively paid access gates convert visitors.' );
	} );

	it.each( [ 'no_opportunity', 'no_conversions', 'configuration_missing' ] as const )( 'exposes the %s state via the data attribute', state => {
		const { container } = render( <EmptyMetricSection title="Section" state={ state } body="Body copy." /> );
		expect( container.querySelector( `[data-empty-state="${ state }"]` ) ).toBeInTheDocument();
	} );

	it( 'is non-dismissible — renders no dismiss button', () => {
		render( <EmptyMetricSection title="Section" state="no_conversions" body="Body copy." /> );
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	describe( '{N} interpolation', () => {
		it( 'substitutes {N} with the localized signalCount when provided', () => {
			const { container } = render(
				<EmptyMetricSection
					title="Section"
					state="no_conversions"
					body="Your paid access gate reached {N} readers."
					signalCount={ 1234567 }
				/>
			);
			expect( container ).toHaveTextContent( 'Your paid access gate reached 1,234,567 readers.' );
		} );

		it( 'handles signalCount={0} gracefully', () => {
			const { container } = render(
				<EmptyMetricSection title="Section" state="no_conversions" body="Reached {N} readers." signalCount={ 0 } />
			);
			expect( container ).toHaveTextContent( 'Reached 0 readers.' );
		} );

		it( 'leaves {N} visible (not stripped) when signalCount is absent', () => {
			const { container } = render( <EmptyMetricSection title="Section" state="no_conversions" body="Reached {N} readers." /> );
			expect( container ).toHaveTextContent( 'Reached {N} readers.' );
		} );

		it( 'renders body verbatim when it has no placeholder, even with a signalCount', () => {
			const { container } = render(
				<EmptyMetricSection title="Section" state="no_opportunity" body="No attempts at all." signalCount={ 42 } />
			);
			expect( container ).toHaveTextContent( 'No attempts at all.' );
		} );
	} );
} );
