/**
 * Tab-level tests for EngagementTab (NPPD-1649): the tab-level connect banner
 * replaces sections when GA4 isn't connected; sections render on success; and
 * comparison deltas are gated on the toggle (previousRange).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import EngagementTab from './EngagementTab';
import useEngagementData from '../hooks/useEngagementData';
import type { DateRange } from '../state/useDateRange';

jest.mock( '../hooks/useEngagementData' );

const mockHook = useEngagementData as jest.Mock;
const range = { start: '2026-05-09', end: '2026-06-08', preset: 'last-30' } as unknown as DateRange;

describe( 'EngagementTab', () => {
	afterEach( () => {
		mockHook.mockReset();
	} );

	it( 'renders the connect banner (and no sections) on a tab-level OAuth error', () => {
		mockHook.mockReturnValue( {
			status: 'success',
			data: { tab_error: 'oauth_not_connected', banner_text: 'Connect Google Analytics.' },
			error: null,
			refetch: () => {},
		} );

		render( <EngagementTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Connect Google Analytics.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Overall engagement quality' ) ).not.toBeInTheDocument();
	} );

	it( 'renders sections with values on success', () => {
		mockHook.mockReturnValue( {
			status: 'success',
			error: null,
			refetch: () => {},
			data: {
				current: {
					avg_pages_per_session: { value: 3.2, computable: true, type: 'decimal' },
				},
				previous: null,
			},
		} );

		render( <EngagementTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Overall engagement quality' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Avg Pages per Session' ) ).toBeInTheDocument();
		expect( screen.getByText( '3.2' ) ).toBeInTheDocument();
	} );

	it( 'suppresses comparison deltas when the toggle is off (no previousRange)', () => {
		mockHook.mockReturnValue( {
			status: 'success',
			error: null,
			refetch: () => {},
			data: {
				current: { avg_pages_per_session: { value: 3.2, computable: true, type: 'decimal' } },
				// Fixture mode returns a previous window unconditionally; the tab must
				// gate the delta on the toggle, not on the response.
				previous: { avg_pages_per_session: { value: 2.0, computable: true, type: 'decimal' } },
			},
		} );

		render( <EngagementTab range={ range } previousRange={ null } /> );

		// No delta arrow rendered because comparison is off.
		expect( screen.queryByText( '↑' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( '↓' ) ).not.toBeInTheDocument();
	} );
} );
