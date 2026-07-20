/**
 * External dependencies
 */
import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import DefaultTemplates, { TemplateOptions } from './default-templates';

const options: TemplateOptions = {
	post: [
		{ label: 'Default', value: 'default' },
		{ label: 'Large Image', value: 'single/large-image' },
	],
	page: [
		{ label: 'Default', value: 'default' },
		{ label: 'Wide Page', value: 'page/wide' },
	],
};

// The component only reads the two template fields, so the fixture carries
// just those and is cast to the full theme-mods shape.
const baseData = {
	post_template_default: 'default',
	page_template_default: 'default',
} as AdvancedSettings;

describe( 'DefaultTemplates', () => {
	it( 'renders the post and page template options', () => {
		render( <DefaultTemplates data={ baseData } update={ () => {} } options={ options } /> );
		expect( screen.getByText( 'Large Image' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Wide Page' ) ).toBeInTheDocument();
	} );

	it( 'calls update with the chosen post template', () => {
		const update = jest.fn();
		render( <DefaultTemplates data={ baseData } update={ update } options={ options } /> );
		const [ postSelect ] = screen.getAllByRole( 'combobox' );
		fireEvent.change( postSelect, { target: { value: 'single/large-image' } } );
		expect( update ).toHaveBeenCalledWith( { post_template_default: 'single/large-image' } );
	} );

	it( 'calls update with the chosen page template', () => {
		const update = jest.fn();
		render( <DefaultTemplates data={ baseData } update={ update } options={ options } /> );
		const [ , pageSelect ] = screen.getAllByRole( 'combobox' );
		fireEvent.change( pageSelect, { target: { value: 'page/wide' } } );
		expect( update ).toHaveBeenCalledWith( { page_template_default: 'page/wide' } );
	} );
} );
