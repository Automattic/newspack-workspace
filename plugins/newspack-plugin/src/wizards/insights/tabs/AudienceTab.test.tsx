/**
 * Tab-level tests for AudienceTab (NPPD-1649): the tab-level connect banner
 * replaces sections when GA4 isn't connected; sections render on success; and
 * a hidden_in_v1 metric is skipped.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AudienceTab from './AudienceTab';
import useAudienceData from '../hooks/useAudienceData';
import type { DateRange } from '../state/useDateRange';

jest.mock( '../hooks/useAudienceData' );

const mockHook = useAudienceData as jest.Mock;
const range = { start: '2026-05-09', end: '2026-06-08', preset: 'last-30' } as unknown as DateRange;

describe( 'AudienceTab', () => {
	afterEach( () => {
		mockHook.mockReset();
	} );

	it( 'renders the connect banner (and no sections) on a tab-level OAuth error', () => {
		mockHook.mockReturnValue( {
			status: 'success',
			data: { tab_error: 'oauth_not_connected', banner_text: 'Connect Google Analytics.' },
			error: null,
			refetch: () => {},
			computedAt: '2026-06-10T18:42:13Z',
			source: 'external',
			cooldownUntil: null,
		} );

		render( <AudienceTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Connect Google Analytics.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Reach' ) ).not.toBeInTheDocument();
	} );

	it( 'renders sections with values on success and skips hidden metrics', () => {
		mockHook.mockReturnValue( {
			status: 'success',
			error: null,
			refetch: () => {},
			data: {
				current: {
					active_readers: { value: 128430, computable: true, type: 'count' },
					returning_reader_rate_strict: { value: null, computable: false, hidden_in_v1: true },
				},
				previous: null,
			},
			computedAt: '2026-06-10T18:42:13Z',
			source: 'external',
			cooldownUntil: null,
		} );

		render( <AudienceTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Reach' ) ).toBeInTheDocument();
		expect( screen.getByText( '128,430' ) ).toBeInTheDocument();
		// The hidden BQ-only metric has no rendered card.
		expect( screen.queryByText( 'Returning Reader Rate' ) ).not.toBeInTheDocument();
	} );
} );
