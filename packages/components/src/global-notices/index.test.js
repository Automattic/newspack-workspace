/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { speak } from '@wordpress/a11y';
import { SlotFillProvider } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import GlobalNotices, { GlobalNoticeFill } from './';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const setSearch = search => {
	delete window.location;
	window.location = { search };
};

describe( 'GlobalNotices', () => {
	const originalLocation = window.location;

	beforeEach( () => speak.mockClear() );
	afterAll( () => {
		window.location = originalLocation;
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
} );
