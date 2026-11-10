/**
 * External dependencies
 */
import { render, screen, fireEvent, within } from '@testing-library/react';

const withOptions = options => {
	window.newspackAudienceContentGates = {
		available_archive_rules: { post_type_archives: { name: 'Archive pages', default: [], options } },
	};
};
const COLLECTIONS = [ { value: 'newspack_collection', label: 'Collections' } ];

const renderRules = ( rules, onChange = jest.fn() ) => {
	const ArchiveRules = require( './archive-rules' ).default;
	const view = render( <ArchiveRules rules={ rules } onChange={ onChange } /> );
	return { onChange, ...view };
};

describe( 'ArchiveRules', () => {
	it( 'renders the Collections option unchecked', () => {
		withOptions( COLLECTIONS );
		renderRules( [] );
		expect( screen.getByRole( 'checkbox', { name: 'Collections' } ) ).not.toBeChecked();
	} );

	it( 'groups the checkboxes under a labeled group', () => {
		withOptions( COLLECTIONS );
		renderRules( [] );
		const group = screen.getByRole( 'group', { name: 'Archive pages' } );
		expect( within( group ).getByRole( 'checkbox', { name: 'Collections' } ) ).toBeInTheDocument();
	} );

	it( 'checking adds the rule', () => {
		withOptions( COLLECTIONS );
		const { onChange } = renderRules( [] );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Collections' } ) );
		expect( onChange ).toHaveBeenCalledWith( [ { slug: 'post_type_archives', value: [ 'newspack_collection' ] } ] );
	} );

	it( 'unchecking the last value removes the rule', () => {
		withOptions( COLLECTIONS );
		const { onChange } = renderRules( [ { slug: 'post_type_archives', value: [ 'newspack_collection' ] } ] );
		expect( screen.getByRole( 'checkbox', { name: 'Collections' } ) ).toBeChecked();
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Collections' } ) );
		expect( onChange ).toHaveBeenCalledWith( [] );
	} );

	it( 'keeps values that are not offered', () => {
		withOptions( COLLECTIONS );
		const { onChange } = renderRules( [ { slug: 'post_type_archives', value: [ 'newspack_collection', 'gone_cpt' ] } ] );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Collections' } ) );
		expect( onChange ).toHaveBeenCalledWith( [ { slug: 'post_type_archives', value: [ 'gone_cpt' ] } ] );
	} );

	it( 'renders nothing without options', () => {
		withOptions( [] );
		const { container } = renderRules( [] );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
