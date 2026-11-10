/**
 * External dependencies
 */
import { render, screen, fireEvent, within } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

const withOptions = options => {
	window.newspackAudienceContentGates = {
		available_archive_rules: { post_type_archives: { name: 'Archive pages', default: [], options } },
	};
};
const COLLECTIONS = [ { value: 'newspack_collection', label: 'Collections' } ];
const COLLECTIONS_RULE = { slug: 'post_type_archives', value: [ 'newspack_collection' ] };

const renderRules = ( rules, onChange = jest.fn() ) => {
	const ArchiveRules = require( './archive-rules' ).default;
	const view = render( <ArchiveRules rules={ rules } onChange={ onChange } /> );
	return { onChange, ...view };
};

const toggle = () => screen.getByRole( 'checkbox', { name: 'Archive pages' } );
const collections = () => screen.queryByRole( 'checkbox', { name: 'Collections' } );

describe( 'ArchiveRules', () => {
	it( 'starts switched off, with no options shown, for a gate without archive pages', () => {
		withOptions( COLLECTIONS );
		renderRules( [] );
		expect( toggle() ).not.toBeChecked();
		expect( collections() ).toBeNull();
	} );

	it( 'opens switched on for a gate with archive pages', () => {
		withOptions( COLLECTIONS );
		renderRules( [ COLLECTIONS_RULE ] );
		expect( toggle() ).toBeChecked();
		expect( collections() ).toBeChecked();
	} );

	it( 'groups the options under a labeled group once switched on', () => {
		withOptions( COLLECTIONS );
		renderRules( [] );
		fireEvent.click( toggle() );
		const group = screen.getByRole( 'group', { name: 'Archive pages to gate' } );
		expect( within( group ).getByRole( 'checkbox', { name: 'Collections' } ) ).not.toBeChecked();
	} );

	it( 'checking adds the rule', () => {
		withOptions( COLLECTIONS );
		const { onChange } = renderRules( [] );
		fireEvent.click( toggle() );
		fireEvent.click( collections() );
		expect( onChange ).toHaveBeenCalledWith( [ COLLECTIONS_RULE ] );
	} );

	it( 'unchecking the last value removes the rule but keeps the options open', () => {
		withOptions( COLLECTIONS );
		const ArchiveRules = require( './archive-rules' ).default;
		const Harness = () => {
			const [ rules, setRules ] = useState( [ COLLECTIONS_RULE ] );
			return <ArchiveRules rules={ rules } onChange={ setRules } />;
		};
		render( <Harness /> );
		fireEvent.click( collections() );
		expect( collections() ).not.toBeChecked();
		expect( toggle() ).toBeChecked();
	} );

	it( 'switching off clears the rule', () => {
		withOptions( COLLECTIONS );
		const { onChange } = renderRules( [ COLLECTIONS_RULE ] );
		fireEvent.click( toggle() );
		expect( onChange ).toHaveBeenCalledWith( [] );
	} );

	it( 'keeps values that are not offered', () => {
		withOptions( COLLECTIONS );
		const { onChange } = renderRules( [ { slug: 'post_type_archives', value: [ 'newspack_collection', 'gone_cpt' ] } ] );
		fireEvent.click( collections() );
		expect( onChange ).toHaveBeenCalledWith( [ { slug: 'post_type_archives', value: [ 'gone_cpt' ] } ] );
	} );

	it( 'renders nothing without options', () => {
		withOptions( [] );
		const { container } = renderRules( [] );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
