/**
 * External dependencies.
 */
import { act, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { speak } from '@wordpress/a11y';
import domReady from '@wordpress/dom-ready';
import { SlotFillProvider, __experimentalUseSlot as useSlot } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import GlobalNotices, { GlobalNoticeFill } from './';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.mock( '@wordpress/dom-ready', () => jest.fn( cb => cb() ) );

jest.mock( '@wordpress/components', () => {
	const actual = jest.requireActual( '@wordpress/components' );
	return { ...actual, __experimentalUseSlot: jest.fn( actual.__experimentalUseSlot ) };
} );

const setLocation = ( search, hash = '' ) => {
	window.history.replaceState( {}, '', `/wp-admin/admin.php${ search }${ hash }` );
};

const setSearch = search => setLocation( search );

describe( 'GlobalNotices', () => {
	beforeEach( () => {
		speak.mockClear();
		domReady.mockClear();
		domReady.mockImplementation( cb => cb() );
		useSlot.mockImplementation( jest.requireActual( '@wordpress/components' ).__experimentalUseSlot );
	} );

	it( 'renders a success notice from the query parameter', () => {
		setSearch( '?newspack-notice=Settings%20saved' );
		render(
			<SlotFillProvider>
				<GlobalNotices />
			</SlotFillProvider>
		);
		expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
	} );

	it( 'renders an error notice for a prefixed message', () => {
		setSearch( '?newspack-notice=_error_Something%20went%20wrong' );
		const { container } = render(
			<SlotFillProvider>
				<GlobalNotices />
			</SlotFillProvider>
		);
		expect( screen.getByText( 'Something went wrong' ) ).toBeInTheDocument();
		expect( container.querySelector( '.components-notice' ) ).toHaveClass( 'is-error' );
	} );

	it( 'escapes markup rather than rendering it', () => {
		setSearch( `?newspack-notice=${ encodeURIComponent( '_error_<img src=x onerror=alert(1)>' ) }` );
		const { container } = render(
			<SlotFillProvider>
				<GlobalNotices />
			</SlotFillProvider>
		);
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( screen.getByText( '<img src=x onerror=alert(1)>' ) ).toBeInTheDocument();
	} );

	it( 'ignores a parameter that is not a string', () => {
		setSearch( '?newspack-notice[]=a' );
		const { container } = render(
			<SlotFillProvider>
				<GlobalNotices />
			</SlotFillProvider>
		);
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders nothing without the parameter', () => {
		setSearch( '?page=newspack-settings' );
		const { container } = render(
			<SlotFillProvider>
				<GlobalNotices />
			</SlotFillProvider>
		);
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'announces only the first of several messages', () => {
		setSearch( '?newspack-notice=_error_First%20failure,Second%20message' );
		render(
			<SlotFillProvider>
				<GlobalNotices />
			</SlotFillProvider>
		);
		expect( screen.getByText( 'First failure' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Second message' ) ).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( 'First failure', 'assertive' );
	} );

	it( 'renders nothing when there are no notices and no fills', () => {
		setSearch( '?page=newspack-settings' );
		const { container } = render(
			<SlotFillProvider>
				<GlobalNotices />
			</SlotFillProvider>
		);
		expect( container.querySelector( '.newspack-global-notices' ) ).toBeNull();
	} );

	it( 'renders a fill inside the region', () => {
		setSearch( '?page=newspack-settings' );
		render(
			<SlotFillProvider>
				<GlobalNotices />
				<GlobalNoticeFill>
					<span>Filled notice</span>
				</GlobalNoticeFill>
			</SlotFillProvider>
		);
		expect( screen.getByText( 'Filled notice' ) ).toBeInTheDocument();
	} );

	it( 'renders query notices and fills together in one region', () => {
		setSearch( '?newspack-notice=Settings%20saved' );
		const { container } = render(
			<SlotFillProvider>
				<GlobalNotices />
				<GlobalNoticeFill>
					<span>Filled notice</span>
				</GlobalNoticeFill>
			</SlotFillProvider>
		);
		expect( container.querySelectorAll( '.newspack-global-notices' ) ).toHaveLength( 1 );
		expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Filled notice' ) ).toBeInTheDocument();
	} );

	describe( 'nested inside another region', () => {
		// The shape a `Wizard` inside a `withWizard` page renders: an outer region
		// and a second one further down, sharing one slot-fill registry.
		const renderNested = () =>
			render(
				<SlotFillProvider>
					<GlobalNotices />
					<GlobalNoticeFill>
						<span>Filled notice</span>
					</GlobalNoticeFill>
					<div className="deeper">
						<SlotFillProvider passthrough>
							<GlobalNotices />
						</SlotFillProvider>
					</div>
				</SlotFillProvider>
			);

		it( 'renders one region, the innermost one', () => {
			setSearch( '?page=newspack-settings' );
			const { container } = renderNested();
			const regions = container.querySelectorAll( '.newspack-global-notices' );
			expect( regions ).toHaveLength( 1 );
			expect( container.querySelector( '.deeper' ) ).toContainElement( regions[ 0 ] );
		} );

		it( 'renders a fill from the outer level in that region', () => {
			setSearch( '?page=newspack-settings' );
			const { container } = renderNested();
			expect( container.querySelector( '.newspack-global-notices' ) ).toContainElement( screen.getByText( 'Filled notice' ) );
		} );

		it( 'renders a query-parameter notice once, not twice', () => {
			setSearch( '?newspack-notice=Settings%20saved' );
			renderNested();
			expect( screen.getAllByText( 'Settings saved' ) ).toHaveLength( 1 );
		} );

		// Both regions mount in the same commit and both see an empty registry, so
		// both used to render the notice and fire core's announcement effect before
		// the ownership re-render dropped the loser.
		it( 'announces a query-parameter notice once, not twice', () => {
			setSearch( '?newspack-notice=_error_Something%20went%20wrong' );
			renderNested();
			expect( speak ).toHaveBeenCalledTimes( 1 );
			expect( speak ).toHaveBeenCalledWith( 'Something went wrong', 'assertive' );
		} );

		it( 'renders no region when there is nothing to show', () => {
			setSearch( '?page=newspack-settings' );
			const { container } = render(
				<SlotFillProvider>
					<GlobalNotices />
					<div className="deeper">
						<SlotFillProvider passthrough>
							<GlobalNotices />
						</SlotFillProvider>
					</div>
				</SlotFillProvider>
			);
			expect( container.querySelector( '.newspack-global-notices' ) ).toBeNull();
		} );
	} );

	describe( 'stripping the newspack-notice parameter after mount', () => {
		it( 'removes the parameter from the URL once the notice has rendered', () => {
			setSearch( '?newspack-notice=Settings%20saved' );
			render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);
			expect( window.location.search ).toBe( '' );
		} );

		it( 'keeps every other parameter and the hash', () => {
			setLocation( '?page=newspack-settings&newspack-notice=Settings%20saved', '#/segments' );
			render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);
			expect( window.location.search ).toBe( '?page=newspack-settings' );
			expect( window.location.hash ).toBe( '#/segments' );
		} );

		it( 'leaves the announced notice on screen after the strip', () => {
			setSearch( '?newspack-notice=Settings%20saved' );
			render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);
			expect( window.location.search ).toBe( '' );
			expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
		} );

		it( 'renders nothing on a remount once the parameter is already stripped', () => {
			setSearch( '?newspack-notice=Settings%20saved' );
			const first = render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);
			first.unmount();
			expect( window.location.search ).toBe( '' );

			const { container } = render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);
			expect( container ).toBeEmptyDOMElement();
		} );

		it( 'does not touch history when the parameter is absent', () => {
			setSearch( '?page=newspack-settings' );
			const replaceState = jest.spyOn( window.history, 'replaceState' );

			render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);

			expect( replaceState ).not.toHaveBeenCalled();
			replaceState.mockRestore();
		} );

		it( 'does not throw when history.replaceState is unavailable', () => {
			setSearch( '?newspack-notice=Settings%20saved' );
			Object.defineProperty( window.history, 'replaceState', { value: undefined, configurable: true } );

			expect( () =>
				render(
					<SlotFillProvider>
						<GlobalNotices />
					</SlotFillProvider>
				)
			).not.toThrow();
			expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
		} );

		// Restores History.prototype's method; in the test body a failing assertion
		// would skip it and leave jsdom's history broken for everything after.
		afterEach( () => {
			delete window.history.replaceState;
		} );
	} );

	describe( 'slot ownership', () => {
		it( 'renders both fills at once', () => {
			setSearch( '?page=newspack-settings' );
			render(
				<SlotFillProvider>
					<GlobalNotices />
					<GlobalNoticeFill>
						<span>First fill</span>
					</GlobalNoticeFill>
					<GlobalNoticeFill>
						<span>Second fill</span>
					</GlobalNoticeFill>
				</SlotFillProvider>
			);

			expect( screen.getByText( 'First fill' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Second fill' ) ).toBeInTheDocument();
			expect( document.querySelectorAll( '.newspack-global-notices' ) ).toHaveLength( 1 );
		} );

		it( 'still renders when the slot registration is an unrecognised shape', () => {
			setSearch( '?newspack-notice=Settings%20saved' );
			// Every region standing down would take withWizard's error notices with it.
			useSlot.mockReturnValue( { ref: {} } );

			render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);

			expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
		} );
	} );

	describe( 'announcing only once the live regions exist', () => {
		// @wordpress/a11y creates its live regions on domReady, and speak() drops the
		// message when they are absent. The wizard bundle mounts before that point.
		it( 'holds the announcement until domReady fires', () => {
			let fire;
			domReady.mockImplementation( cb => {
				fire = cb;
			} );
			setSearch( '?newspack-notice=_error_Something%20went%20wrong' );

			render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);

			expect( screen.getByText( 'Something went wrong' ) ).toBeInTheDocument();
			expect( speak ).not.toHaveBeenCalled();

			act( () => fire() );

			expect( speak ).toHaveBeenCalledTimes( 1 );
			expect( speak ).toHaveBeenCalledWith( 'Something went wrong', 'assertive' );
		} );

		it( 'does not wait on domReady when there is nothing to announce', () => {
			setSearch( '?page=newspack-settings' );

			render(
				<SlotFillProvider>
					<GlobalNotices />
				</SlotFillProvider>
			);

			expect( domReady ).not.toHaveBeenCalled();
			expect( speak ).not.toHaveBeenCalled();
		} );
	} );
} );
