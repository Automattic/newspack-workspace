/**
 * Render smoke test for ReaderLifecycleSection (Section 1).
 *
 * NOTE: `.test.tsx` is not collected by CI (testMatch matches only `.js` /
 * `.jsx`, NPPD-1683) — written to the sibling convention, runs once that lands.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ReaderLifecycleSection from './ReaderLifecycleSection';
import { makeConversionWindow } from './fixtures';

describe( 'ReaderLifecycleSection', () => {
	it( 'renders the heading and the zero-data funnel empty state', () => {
		render( <ReaderLifecycleSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'The reader lifecycle' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough data to chart the funnel.' ) ).toBeInTheDocument();
	} );
} );
