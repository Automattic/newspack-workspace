/**
 * Tests for SitePerformanceSection (NPPD-1671): the network-only per-site table.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SitePerformanceSection from './SitePerformanceSection';
import type { InsightsWindow } from '../../../api/advertising';

const metrics = (): InsightsWindow => ( {
	top_sites: {
		type: 'table',
		computable: true,
		rows: [
			{ site: 'almanacnews.com', impressions: 980000, revenue: 1720, ecpm: 1.76 },
			{ site: 'mv-voice.com', impressions: 410000, revenue: 705, ecpm: 1.72 },
		],
	},
} );

describe( 'SitePerformanceSection', () => {
	it( 'renders the per-site table with one row per network site', () => {
		render( <SitePerformanceSection current={ metrics() } previous={ null } /> );

		expect( screen.getByText( 'Performance by Site' ) ).toBeInTheDocument();
		expect( screen.getByText( 'almanacnews.com' ) ).toBeInTheDocument();
		expect( screen.getByText( 'mv-voice.com' ) ).toBeInTheDocument();
	} );
} );
