/**
 * Component tests for the UI introduced by Tab 8 (Advertising, NPPD-1618): the
 * FinishConnectingDiagnostic and DataLagIndicator components, plus the shared
 * graceful-failure / mapping extensions they rely on (the `data_unavailable`
 * overlay note and the `currency` payload format).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import FinishConnectingDiagnostic from './FinishConnectingDiagnostic';
import DataLagIndicator from './DataLagIndicator';
import MetricCard from './MetricCard';
import { payloadToCard } from './metrics';

describe( 'FinishConnectingDiagnostic', () => {
	const issue = ( code: string, message: string, url: string ) => ( { code, message, remediation_url: url } );

	it( 'renders a single readiness issue with its remediation link', () => {
		render( <FinishConnectingDiagnostic issues={ [ issue( 'oauth_scope_missing', 'Reconnect Google.', 'http://example.test/settings' ) ] } /> );
		expect( screen.getByText( 'Reconnect Google.' ) ).toBeInTheDocument();
		const links = screen.getAllByRole( 'link' );
		expect( links ).toHaveLength( 1 );
		expect( links[ 0 ] ).toHaveAttribute( 'href', 'http://example.test/settings' );
	} );

	it( 'renders one item per issue with distinct remediation URLs', () => {
		render(
			<FinishConnectingDiagnostic
				issues={ [
					issue( 'oauth_scope_missing', 'Scope missing.', 'http://example.test/settings' ),
					issue( 'network_code_missing', 'Network missing.', 'http://example.test/advertising' ),
				] }
			/>
		);
		expect( screen.getByText( 'Scope missing.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Network missing.' ) ).toBeInTheDocument();
		const hrefs = screen.getAllByRole( 'link' ).map( a => a.getAttribute( 'href' ) );
		expect( hrefs ).toEqual( [ 'http://example.test/settings', 'http://example.test/advertising' ] );
	} );
} );

describe( 'DataLagIndicator', () => {
	// `@wordpress/components` Notice (via InfoCallout) renders both visible
	// content and a hidden a11y-speak region with the same text. Scope queries
	// to the visible notice element to avoid duplicate matches.
	const getCallout = ( container: HTMLElement ): HTMLElement | null => container.querySelector( '.components-notice' );

	it( 'renders the as-of date in an info callout, not dismissible', () => {
		const { container } = render( <DataLagIndicator dataAsOf="2026-05-30" hasEstimatedData={ false } /> );
		const callout = getCallout( container );
		expect( callout ).not.toBeNull();
		expect( callout ).toHaveTextContent( 'About this data' );
		expect( callout ).toHaveTextContent( /Data as of/ );
		// Not dismissible — no close button.
		expect( callout!.querySelector( 'button' ) ).toBeNull();
	} );

	it( 'appends the estimated-data note to the same line when estimated', () => {
		const { container } = render( <DataLagIndicator dataAsOf="2026-05-30" hasEstimatedData /> );
		expect( getCallout( container ) ).toHaveTextContent( /Data as of .*\. Recent days are estimated and may shift until Google finalizes\./ );
	} );

	it( 'still warns about estimated data when there is no as-of date', () => {
		const { container } = render( <DataLagIndicator dataAsOf={ null } hasEstimatedData /> );
		expect( getCallout( container ) ).toHaveTextContent( 'Recent days are estimated and may shift until Google finalizes.' );
	} );

	it( 'renders nothing with neither an as-of date nor estimated data', () => {
		const { container } = render( <DataLagIndicator dataAsOf={ null } hasEstimatedData={ false } /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'data_unavailable overlay + currency mapping (Tab 8 extensions)', () => {
	it( 'renders the generic note for a dimension-less data_unavailable overlay', () => {
		render( <MetricCard label="Viewability Rate" overlay={ { type: 'data_unavailable' } } /> );
		expect( screen.getByText( 'Not available for this site.' ) ).toBeInTheDocument();
	} );

	it( 'maps a currency payload to the currency format', () => {
		const card = payloadToCard( { label: 'Total Revenue', current: { value: 4200, computable: true, type: 'currency' } } );
		expect( card?.format ).toBe( 'currency' );
	} );

	it( 'formats a currency MetricCard value', () => {
		// `formatCurrency` drops cents in the $1K–<$1M tier (see format.ts), so
		// $4,200 renders without a decimal.
		render( <MetricCard label="Total Revenue" value={ 4200 } format="currency" /> );
		expect( screen.getByText( '$4,200' ) ).toBeInTheDocument();
	} );
} );
