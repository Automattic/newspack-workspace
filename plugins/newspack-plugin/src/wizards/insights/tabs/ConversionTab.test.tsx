/**
 * Tab-level tests for ConversionTab (NPPD-1609, Phase 2).
 *
 * Confirms the tab renders under the state-envelope: the section
 * structure renders on success, loading + error states are exercised,
 * and the Phase 2 chrome (LastUpdated, no preview banner, tab_error
 * banner) is present / absent as expected.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ConversionTab from './ConversionTab';
import useConversionData from '../hooks/useConversionData';
import type { ConversionResponse } from '../api/conversion';
import { makeConversionWindow } from './conversion/fixtures';
import type { DateRange } from '../state/useDateRange';

jest.mock( '../hooks/useConversionData' );
jest.mock( '../components/LastUpdated', () => () => <span>last-updated-stub</span> );
jest.mock( '../components/CooldownNotice', () => () => null );

// `@wordpress/icons` ships pre-built SVG element trees created against its own
// bundled React copy; under the test runner's React they trip React's
// "element from an older version of React" guard. The icons are decorative
// here (no assertion depends on them), so stub the module.
jest.mock( '@wordpress/icons', () => ( {
	__esModule: true,
	Icon: () => null,
	info: 'info',
	closeSmall: 'closeSmall',
	chevronUp: 'chevronUp',
	chevronDown: 'chevronDown',
	caution: 'caution',
	scheduled: 'scheduled',
} ) );

const mockHook = useConversionData as jest.Mock;
const range = { start: '2026-05-09', end: '2026-06-08', preset: 'last-30' } as unknown as DateRange;

const makeResponse = ( tab_error = false ): ConversionResponse => ( {
	tab_error,
	current: makeConversionWindow(),
	previous: null,
} );

const mockSuccess = ( tab_error = false ) =>
	mockHook.mockReturnValue( {
		status: 'success',
		error: null,
		refetch: () => {},
		data: makeResponse( tab_error ),
		computedAt: '2026-06-16T00:00:00Z',
		source: 'bigquery',
		cooldownUntil: null,
	} );

describe( 'ConversionTab', () => {
	afterEach( () => {
		mockHook.mockReset();
	} );

	it( 'renders all eight section headings on success', () => {
		mockSuccess();
		render( <ConversionTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'The Reader Lifecycle' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Per-Journey Conversion Funnels' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Where Conversions Come From' ) ).toBeInTheDocument();
		expect( screen.getByText( 'How Long Conversions Take' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Cohort Retention' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Conversion Rate Trends' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Cross-Tab Influenced Attribution' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Opportunity Buckets' ) ).toBeInTheDocument();
	} );

	it( 'renders the LastUpdated chrome stub in the first section', () => {
		mockSuccess();
		render( <ConversionTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'last-updated-stub' ) ).toBeInTheDocument();
	} );

	it( 'does NOT render the ConversionPreviewBanner (Phase 2)', () => {
		mockSuccess();
		render( <ConversionTab range={ range } previousRange={ null } /> );
		expect( screen.queryByText( /This tab is live in preview mode/ ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /Real-time metrics will populate/ ) ).not.toBeInTheDocument();
	} );

	it( 'renders the tab_error banner when tab_error is true', () => {
		mockSuccess( true );
		render( <ConversionTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( /Unable to load this tab/ ) ).toBeInTheDocument();
	} );

	it( 'does not render the tab_error banner when tab_error is false', () => {
		mockSuccess( false );
		render( <ConversionTab range={ range } previousRange={ null } /> );
		expect( screen.queryByText( /Unable to load this tab/ ) ).not.toBeInTheDocument();
	} );

	it( 'renders the loading state', () => {
		mockHook.mockReturnValue( {
			status: 'loading',
			data: null,
			error: null,
			refetch: () => {},
			computedAt: null,
			source: null,
			cooldownUntil: null,
		} );
		render( <ConversionTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'Loading…' ) ).toBeInTheDocument();
	} );

	it( 'renders the error state', () => {
		mockHook.mockReturnValue( {
			status: 'error',
			data: null,
			error: 'Boom',
			refetch: () => {},
			computedAt: null,
			source: null,
			cooldownUntil: null,
		} );
		render( <ConversionTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'Could not load conversion data.' ) ).toBeInTheDocument();
	} );
} );
