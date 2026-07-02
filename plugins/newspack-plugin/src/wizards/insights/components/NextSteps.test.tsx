/**
 * Tests for the NextSteps strip (NPPD-1842).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import NextSteps from './NextSteps';
import type { NextStepLink } from './InsightsWizard';

const links: NextStepLink[] = [
	{ label: 'Grow reader revenue', url: 'https://help.newspack.com/playbooks/grow-reader-revenue/' },
	{ label: 'Recover lapsed donors', url: 'https://help.newspack.com/playbooks/recover-lapsed-donors/' },
];

describe( 'NextSteps', () => {
	it( 'renders nothing when there are no links', () => {
		const { container } = render( <NextSteps links={ [] } /> );
		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders each link with its outcome label and href, opening in a new tab', () => {
		render( <NextSteps links={ links } /> );

		const revenue = screen.getByRole( 'link', { name: 'Grow reader revenue' } );
		expect( revenue ).toHaveAttribute( 'href', 'https://help.newspack.com/playbooks/grow-reader-revenue/' );
		expect( revenue ).toHaveAttribute( 'target', '_blank' );
		expect( revenue ).toHaveAttribute( 'rel', 'noreferrer' );

		expect( screen.getByRole( 'link', { name: 'Recover lapsed donors' } ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'link' ) ).toHaveLength( 2 );
	} );
} );
