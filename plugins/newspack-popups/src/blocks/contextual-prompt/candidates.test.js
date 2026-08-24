import { act, render, screen, fireEvent } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import { CandidateList, framingForPosition, generateCandidates, toRichTextContent } from './candidates';

jest.mock( '@wordpress/api-fetch' );

const mockCreateSuccessNotice = jest.fn();
jest.mock( '@wordpress/data', () => ( {
	dispatch: () => ( { createSuccessNotice: mockCreateSuccessNotice } ),
} ) );

// Card is the radio, so the stub has to carry the props that make it one — and
// forward the ref the list focuses it with.
jest.mock( '@wordpress/components', () => {
	const { forwardRef } = require( 'react' );
	return {
		Card: forwardRef( ( props, ref ) => (
			<div
				ref={ ref }
				className={ props.className }
				role={ props.role }
				aria-checked={ props[ 'aria-checked' ] }
				aria-label={ props[ 'aria-label' ] }
				tabIndex={ props.tabIndex }
				onClick={ props.onClick }
				onKeyDown={ props.onKeyDown }
			>
				{ props.children }
			</div>
		) ),
		CardBody: ( { children } ) => <div>{ children }</div>,
		// The radiogroup wrapper: role and label must survive the stub.
		__experimentalVStack: ( { children, role, 'aria-label': ariaLabel } ) => (
			<div role={ role } aria-label={ ariaLabel }>
				{ children }
			</div>
		),
		Button: ( { children, onClick, disabled, 'aria-label': ariaLabel } ) => (
			<button onClick={ onClick } disabled={ disabled } aria-label={ ariaLabel }>
				{ children }
			</button>
		),
	};
} );

describe( 'framingForPosition', () => {
	// Boundary cases mirroring get_placement() in
	// class-newspack-popups-contextual-prompt-render.php: the two must agree.
	it.each( [
		[ 0, 1, 'top' ],
		[ 0, 4, 'top' ],
		[ 1, 4, 'top' ], // ratio exactly 1/3.
		[ 2, 4, 'end' ], // ratio exactly 2/3.
		[ 3, 4, 'end' ],
		[ 2, 6, 'mid' ],
		[ 0, 10, 'top' ],
		[ 3, 10, 'top' ], // 3/9 = 1/3.
		[ 4, 10, 'mid' ],
		[ 6, 10, 'end' ], // 6/9 = 2/3.
		[ 9, 10, 'end' ],
	] )( 'index %i of %i → %s', ( index, total, expected ) => {
		expect( framingForPosition( index, total ) ).toEqual( expected );
	} );
} );

describe( 'generateCandidates', () => {
	const args = { postId: 1, content: '' };

	it( 'returns the candidate list from a valid response', async () => {
		const candidates = [ { body: 'Support us.', framing: 'top' } ];
		apiFetch.mockResolvedValueOnce( { candidates } );
		await expect( generateCandidates( args ) ).resolves.toEqual( candidates );
	} );

	it( 'unwraps a data-enveloped response', async () => {
		const candidates = [ { body: 'Support us.', framing: 'end' } ];
		apiFetch.mockResolvedValueOnce( { data: { candidates } } );
		await expect( generateCandidates( args ) ).resolves.toEqual( candidates );
	} );

	it.each( [
		[ 'candidates is an object', { candidates: {} } ],
		[ 'candidates is a string', { candidates: 'nope' } ],
		[ 'candidates is missing', {} ],
		[ 'the response is a string', 'nope' ],
		[ 'the response is null', null ],
	] )( 'rejects when %s', async ( label, response ) => {
		apiFetch.mockResolvedValueOnce( response );
		await expect( generateCandidates( args ) ).rejects.toThrow();
	} );

	it( 'drops entries the UI cannot render', async () => {
		apiFetch.mockResolvedValueOnce( {
			candidates: [
				null,
				'just a string',
				[ 'an', 'array' ],
				{ framing: 'top' }, // Missing body.
				{ body: '   ', framing: 'top' }, // Blank body.
				{ body: 42, framing: 'top' }, // Non-string body.
				{ body: 'ok', framing: { nested: true } }, // Non-string framing.
				{ body: 'ok', framing: 'sideways' }, // Unknown framing.
				{ body: 'kept', framing: 'mid' },
				{ body: 'kept too' }, // Framing is optional.
			],
		} );
		await expect( generateCandidates( args ) ).resolves.toEqual( [ { body: 'kept', framing: 'mid' }, { body: 'kept too' } ] );
	} );
} );

describe( 'CandidateList', () => {
	// Suggestions are a single choice: one is picked, then applied.
	const CANDIDATES = [
		{ body: 'Support us.', framing: 'top' },
		{ body: 'Chip in.', framing: 'mid' },
		{ body: 'Give today.', framing: 'end' },
	];

	const applyButton = () => screen.getByRole( 'button', { name: /^Apply/ } );
	const card = label => screen.getByRole( 'radio', { name: new RegExp( label ) } );

	// The application only lands after the busy hold plays out.
	const applyNow = async () => {
		fireEvent.click( applyButton() );
		await act( async () => {
			jest.advanceTimersByTime( 900 );
		} );
	};

	beforeEach( () => {
		jest.useFakeTimers();
		mockCreateSuccessNotice.mockClear();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'renders nothing without candidates', () => {
		const { container } = render( <CandidateList candidates={ [] } onApply={ jest.fn() } /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'offers no application until a suggestion is picked', () => {
		render( <CandidateList candidates={ CANDIDATES } onApply={ jest.fn() } /> );

		expect( screen.getAllByRole( 'radio' ) ).toHaveLength( 3 );
		expect( screen.queryAllByRole( 'radio', { checked: true } ) ).toHaveLength( 0 );
		expect( applyButton() ).toBeDisabled();
	} );

	it( 'applies the picked suggestion', async () => {
		const onApply = jest.fn();
		render( <CandidateList candidates={ CANDIDATES } onApply={ onApply } /> );

		fireEvent.click( card( 'Mid-Post' ) );

		expect( card( 'Mid-Post' ) ).toBeChecked();
		// The single Apply button names the choice for screen readers.
		expect( screen.getByRole( 'button', { name: 'Apply suggestion: Mid-Post' } ) ).toBeInTheDocument();
		await applyNow();
		expect( onApply ).toHaveBeenCalledWith( CANDIDATES[ 1 ] );
	} );

	// The busy hold plays out before the copy lands (the hosts hide the list as
	// soon as it does), and closes with a snackbar.
	it( 'holds Apply busy, reports it to the host, then applies and confirms', async () => {
		const onApply = jest.fn();
		const onApplyingChange = jest.fn();
		render( <CandidateList candidates={ CANDIDATES } onApply={ onApply } onApplyingChange={ onApplyingChange } /> );

		fireEvent.click( card( 'Top of Post' ) );
		fireEvent.click( applyButton() );

		expect( onApply ).not.toHaveBeenCalled();
		expect( onApplyingChange ).toHaveBeenCalledWith( true );
		expect( applyButton() ).toBeDisabled();
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();

		await act( async () => {
			jest.advanceTimersByTime( 900 );
		} );

		expect( onApply ).toHaveBeenCalledWith( CANDIDATES[ 0 ] );
		expect( onApplyingChange ).toHaveBeenLastCalledWith( false );
		expect( applyButton() ).toBeEnabled();
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith( 'Suggestion applied.', { type: 'snackbar' } );
	} );

	// Hosts whose apply does more than replace copy (the panel inserts the
	// prompt when the post has none) name what actually happened.
	it( 'confirms with the host-provided message', async () => {
		render( <CandidateList candidates={ CANDIDATES } onApply={ jest.fn() } confirmation="Contextual Prompt added." /> );

		fireEvent.click( card( 'Top of Post' ) );
		await applyNow();

		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith( 'Contextual Prompt added.', { type: 'snackbar' } );
	} );

	it.each( [ [ 'Enter' ], [ ' ' ] ] )( 'picks the focused suggestion on %s', async key => {
		const onApply = jest.fn();
		render( <CandidateList candidates={ CANDIDATES } onApply={ onApply } /> );

		fireEvent.keyDown( card( 'End of Post' ), { key } );
		await applyNow();

		expect( onApply ).toHaveBeenCalledWith( CANDIDATES[ 2 ] );
	} );

	// Standard radiogroup behaviour: the arrows move the choice, and it wraps.
	it.each( [
		[ 'ArrowDown', 'Chip in.' ],
		[ 'ArrowRight', 'Chip in.' ],
		[ 'ArrowUp', 'Give today.' ],
		[ 'ArrowLeft', 'Give today.' ],
	] )( '%s moves the choice to %s', async ( key, expected ) => {
		const onApply = jest.fn();
		render( <CandidateList candidates={ CANDIDATES } onApply={ onApply } /> );

		fireEvent.keyDown( card( 'Top of Post' ), { key } );
		await applyNow();

		expect( onApply ).toHaveBeenCalledWith( expect.objectContaining( { body: expected } ) );
	} );

	// The group is one tab stop, entered on the choice once there is one.
	it( 'moves the tab stop to the picked suggestion', () => {
		render( <CandidateList candidates={ CANDIDATES } onApply={ jest.fn() } /> );

		expect( card( 'Top of Post' ) ).toHaveAttribute( 'tabindex', '0' );

		fireEvent.click( card( 'End of Post' ) );

		expect( card( 'Top of Post' ) ).toHaveAttribute( 'tabindex', '-1' );
		expect( card( 'End of Post' ) ).toHaveAttribute( 'tabindex', '0' );
	} );

	// Regenerating replaces the list, so what was picked is no longer on offer.
	it( 'drops the choice when the candidates change', () => {
		const onApply = jest.fn();
		const { rerender } = render( <CandidateList candidates={ CANDIDATES } onApply={ onApply } /> );

		fireEvent.click( card( 'Top of Post' ) );
		expect( applyButton() ).toBeEnabled();

		rerender( <CandidateList candidates={ [ { body: 'Fresh copy.', framing: 'top' } ] } onApply={ onApply } /> );

		expect( applyButton() ).toBeDisabled();
		expect( screen.queryAllByRole( 'radio', { checked: true } ) ).toHaveLength( 0 );
	} );
} );

describe( 'toRichTextContent', () => {
	// A RichText attribute serializes strings as raw HTML, so the tag-opening
	// character is what makes model output dangerous: with it encoded, nothing
	// the model returns can reach the post as markup.
	it.each( [
		[ 'a script element', '<script>alert("xss")</script>', '&lt;script>alert("xss")&lt;/script>' ],
		[ 'an event handler', '<img src=x onerror="alert(1)">', '&lt;img src=x onerror="alert(1)">' ],
		[ 'a javascript: link', '<a href="javascript:alert(1)">Donate</a>', '&lt;a href="javascript:alert(1)">Donate&lt;/a>' ],
	] )( 'encodes %s', ( label, body, expected ) => {
		const content = toRichTextContent( body );
		expect( content ).toEqual( expected );
		expect( content ).not.toMatch( /</ );
	} );
} );
