/**
 * External dependencies.
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import SettingsSection from './SettingsSection';

const DESCRIPTION = 'Number of articles read in the last 30 day period.';

describe( 'SettingsSection', () => {
	it( 'reveals the description from the info trigger', () => {
		render(
			<SettingsSection title="Articles read" description={ DESCRIPTION }>
				<input />
			</SettingsSection>
		);
		expect( screen.queryByText( DESCRIPTION ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'More information about Articles read' } ) );

		expect( screen.getByText( DESCRIPTION ) ).toBeInTheDocument();
	} );

	it( 'falls back to a plain trigger name when the title is not a string', () => {
		render(
			<SettingsSection title={ <em>Articles read</em> } description={ DESCRIPTION }>
				<input />
			</SettingsSection>
		);
		expect( screen.getByRole( 'button', { name: 'More information' } ) ).toBeInTheDocument();
	} );

	it( 'renders no info trigger without a description', () => {
		render(
			<SettingsSection title="Articles read">
				<input />
			</SettingsSection>
		);
		expect( screen.getByText( 'Articles read' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );
} );
