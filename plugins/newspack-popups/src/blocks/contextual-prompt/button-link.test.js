/**
 * The prompt button link field. The block editor's ESM chain is not
 * transformable, so every editor module the field pulls in (and instance.js,
 * which it imports the card check from) is stubbed down to what it uses, as
 * in instance-inspector.test.js.
 */

/**
 * WordPress dependencies.
 */
import { render, screen, fireEvent } from '@testing-library/react';
import { useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
	select: jest.fn(),
} ) );

// instance.js uses `parse` only to look for the pattern's bound paragraph.
jest.mock( '@wordpress/blocks', () => ( { parse: () => [] } ) );

jest.mock( '@wordpress/api-fetch' );

jest.mock( '@wordpress/components', () => ( {
	Notice: ( { children } ) => <div>{ children }</div>,
	PanelBody: ( { children } ) => <section>{ children }</section>,
	Button: ( { children, onClick } ) => <button onClick={ onClick }>{ children }</button>,
	__experimentalVStack: ( { children } ) => <div>{ children }</div>,
	TextControl: ( { label, value, onChange, onBlur } ) => (
		<label htmlFor="prompt-button-link">
			{ label }
			<input id="prompt-button-link" value={ value } onChange={ event => onChange( event.target.value ) } onBlur={ onBlur } />
		</label>
	),
} ) );

const { isInsidePromptCard, withPromptButtonLink } = require( './button-link' );

const card = { name: 'core/group', attributes: { className: 'newspack-contextual-prompt' } };
const plainGroup = { name: 'core/group', attributes: {} };
const buttons = { name: 'core/buttons', attributes: {} };

describe( 'isInsidePromptCard', () => {
	// How a card is recognised is isDetachedPromptCard's job, covered in
	// instance.test.js; these cases cover the walk up the parents.
	it.each( [
		[ 'a card around the buttons', true, [ card, buttons ] ],
		[ 'a card further up', true, [ plainGroup, card, buttons ] ],
		[ 'no card', false, [ plainGroup, buttons ] ],
		[ 'no parents', false, [] ],
	] )( 'is %s → %s', ( label, expected, parents ) => {
		expect( isInsidePromptCard( parents ) ).toBe( expected );
	} );
} );

describe( 'withPromptButtonLink', () => {
	let mounts = 0;
	const BlockEdit = () => {
		useEffect( () => {
			mounts++;
		}, [] );
		return <p>Button editor</p>;
	};
	const Edit = withPromptButtonLink( BlockEdit );

	// Parents resolve by index from ids like `parent-0`, the way the block editor
	// store answers getBlockParents / getBlockName / getBlockAttributes.
	const mockParents = parents => {
		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getBlockParents: () => parents.map( ( parent, index ) => `parent-${ index }` ),
				getBlockName: id => parents[ Number( id.split( '-' )[ 1 ] ) ].name,
				getBlockAttributes: id => parents[ Number( id.split( '-' )[ 1 ] ) ].attributes,
			} ) )
		);
	};

	const props = ( overrides = {} ) => ( {
		name: 'core/button',
		clientId: 'button-1',
		isSelected: true,
		attributes: {},
		setAttributes: jest.fn(),
		...overrides,
	} );

	beforeEach( () => {
		mounts = 0;
		useSelect.mockReset();
		mockParents( [ card, buttons ] );
	} );

	it( 'shows the field, holding the current link, for a selected button inside a card', () => {
		render( <Edit { ...props( { attributes: { url: '#donate?amount=10' } } ) } /> );
		expect( screen.getByLabelText( 'Button link' ).value ).toBe( '#donate?amount=10' );
	} );

	it.each( [
		[ 'not selected', { isSelected: false } ],
		[ 'not a button', { name: 'core/paragraph' } ],
		[ 'a <button> element, which has no link', { attributes: { tagName: 'button' } } ],
	] )( 'hides the field when the block is %s', ( label, overrides ) => {
		render( <Edit { ...props( overrides ) } /> );
		expect( screen.queryByLabelText( 'Button link' ) ).toBeNull();
	} );

	it( 'hides the field for a button outside a prompt card', () => {
		mockParents( [ plainGroup, buttons ] );
		render( <Edit { ...props() } /> );
		expect( screen.queryByLabelText( 'Button link' ) ).toBeNull();
	} );

	it( 'writes the typed link to the url attribute, and clears it when emptied', () => {
		const setAttributes = jest.fn();
		render( <Edit { ...props( { setAttributes, attributes: { url: '/donate' } } ) } /> );
		const input = screen.getByLabelText( 'Button link' );

		fireEvent.change( input, { target: { value: '#donate?amount=10&frequency=one_time' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( { url: '#donate?amount=10&frequency=one_time' } );

		fireEvent.change( input, { target: { value: '' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( { url: undefined } );
	} );

	// An on-page link opening in a new tab would load the page again, and the
	// toolbar can't turn the setting off: it rejects the link before saving.
	it( 'turns off "open in new tab" when the link becomes an on-page link', () => {
		const setAttributes = jest.fn();
		render(
			<Edit
				{ ...props( {
					setAttributes,
					attributes: { url: 'https://donations.example.test/', linkTarget: '_blank', rel: 'noreferrer noopener' },
				} ) }
			/>
		);

		fireEvent.change( screen.getByLabelText( 'Button link' ), { target: { value: '#donate?amount=10' } } );
		expect( setAttributes.mock.calls.at( -1 )[ 0 ] ).toStrictEqual( { url: '#donate?amount=10', linkTarget: undefined, rel: 'noreferrer' } );
	} );

	// Blur trims the stored link, so a pasted leading space would otherwise
	// leave a `#` link opening in a new tab.
	it( 'treats a link with a leading space as an on-page link', () => {
		const setAttributes = jest.fn();
		render( <Edit { ...props( { setAttributes, attributes: { url: 'https://donations.example.test/', linkTarget: '_blank' } } ) } /> );

		fireEvent.change( screen.getByLabelText( 'Button link' ), { target: { value: ' #donate' } } );
		expect( setAttributes.mock.calls.at( -1 )[ 0 ] ).toStrictEqual( { url: ' #donate', linkTarget: undefined, rel: undefined } );
	} );

	// The toolbar adds the scheme to a bare domain; without it the link would
	// save as a path under the story.
	it( 'adds https:// to a bare domain when the field loses focus', () => {
		const setAttributes = jest.fn();
		render( <Edit { ...props( { setAttributes, attributes: { url: 'donations.example.test/give' } } ) } /> );

		fireEvent.blur( screen.getByLabelText( 'Button link' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( { url: 'https://donations.example.test/give' } );
	} );

	it.each( [ '#donate?amount=10&frequency=one_time', '/donate/', 'https://donations.example.test/give' ] )(
		'leaves %s as typed when the field loses focus',
		url => {
			const setAttributes = jest.fn();
			render( <Edit { ...props( { setAttributes, attributes: { url } } ) } /> );

			fireEvent.blur( screen.getByLabelText( 'Button link' ) );
			expect( setAttributes ).not.toHaveBeenCalled();
		}
	);

	// Remounting the button's own editor on selection would drop the cursor out
	// of its text while the publisher types.
	it( 'keeps the button editor mounted as selection changes', () => {
		const { rerender } = render( <Edit { ...props( { isSelected: false } ) } /> );
		rerender( <Edit { ...props( { isSelected: true } ) } /> );
		rerender( <Edit { ...props( { isSelected: false } ) } /> );
		expect( mounts ).toBe( 1 );
	} );
} );
