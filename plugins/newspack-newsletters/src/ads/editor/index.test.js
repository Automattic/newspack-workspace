/**
 * The ad sidebar stores `start_date` / `expiry_date` as a bare `Y-m-d` and
 * hands that same string to core's `<DatePicker currentDate>`. Core decides
 * whether the string already carries a UTC offset with
 * `/Z|[+-]\d{2}(:?\d{2})?$/`, which also matches the `-DD` **day** component
 * of a bare `Y-m-d` — so `2026-08-05` is read as UTC midnight rather than
 * site-local midnight. Rendered back in the site timezone that lands on the
 * previous day for any negative UTC offset, while the stored value and the
 * ads list stay correct (NPPM-3078).
 *
 * Passing the format the component documents — `TIMEZONELESS_FORMAT`,
 * `Y-m-d\TH:i:s` — leaves nothing for that regex to mistake for an offset.
 * These tests pin both directions: what the picker is handed, and what is
 * written back to meta.
 */

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: { use: jest.fn() },
} ) );

// `DatePicker` is what's under test, so its props are captured; the rest of
// the sidebar's controls are stubbed. Pulling in the real
// `@wordpress/components` is not an option here — it loads the block editor
// stores, which the `@wordpress/data` mock below cannot satisfy.
const mockDatePickerProps = [];
jest.mock( '@wordpress/components', () => {
	const stub = name => () => <div data-testid={ name } />;
	return {
		DatePicker: props => {
			mockDatePickerProps.push( props );
			return <div data-testid="date-picker" />;
		},
		ToggleControl: stub( 'toggle' ),
		TextControl: stub( 'text' ),
		RadioControl: stub( 'radio' ),
		RangeControl: stub( 'range' ),
		Notice: stub( 'notice' ),
		Button: stub( 'button' ),
		Modal: stub( 'modal' ),
	};
} );

jest.mock( '@wordpress/edit-post', () => ( {
	PluginDocumentSettingPanel: ( { children } ) => <div>{ children }</div>,
	PluginPrePublishPanel: ( { children } ) => <div>{ children }</div>,
	store: 'core/edit-post',
} ) );

jest.mock( 'newspack-components', () => ( {
	SelectControl: () => <div />,
} ) );

jest.mock( '../../components/ad-placements', () => ( {
	__esModule: true,
	default: () => <div />,
} ) );

const mockEditPost = jest.fn();

jest.mock( '@wordpress/data', () => {
	const select = store => {
		if ( store === 'core/editor' ) {
			return {
				isSavingPost: () => false,
				getEditedPostAttribute: attribute => {
					if ( 'meta' === attribute ) {
						return global.__adMeta;
					}
					if ( 'status' === attribute ) {
						return 'publish';
					}
					return '';
				},
			};
		}
		return { getEntityRecords: () => [] };
	};
	return {
		useSelect: callback => callback( select ),
		useDispatch: () => ( {
			editPost: ( ...args ) => mockEditPost( ...args ),
			saveEntityRecord: jest.fn(),
			removeEditorPanel: jest.fn(),
		} ),
	};
} );

// `registerPlugin` runs on import; capturing its `render` is how we get at
// the sidebar component without exporting it just for the test. The capture
// lands on `global` because imports are hoisted above any `let` here.
jest.mock( '@wordpress/plugins', () => ( {
	registerPlugin: ( name, options ) => {
		global.__adSidebar = options.render;
	},
} ) );

import { render } from '@testing-library/react';
import './index';

const BASE_META = {
	price: '0',
	insertion_strategy: 'percentage',
	position_in_content: 50,
	position_block_count: 5,
	start_date: null,
	expiry_date: null,
};

const renderSidebar = meta => {
	mockDatePickerProps.length = 0;
	global.__adMeta = { ...BASE_META, ...meta };
	const AdSidebar = global.__adSidebar;
	return render( <AdSidebar /> );
};

// Core's own offset detection, verbatim from
// `@wordpress/components` `date-time/utils.js` `inputToDate()`.
const CORE_HAS_TIMEZONE = /Z|[+-]\d{2}(:?\d{2})?$/;

describe( 'Newsletter ad sidebar date pickers', () => {
	beforeEach( () => {
		mockEditPost.mockReset();
	} );

	it( 'hands the start date to the picker as a timezone-less datetime', () => {
		renderSidebar( { start_date: '2026-08-05' } );

		expect( mockDatePickerProps[ 0 ].currentDate ).toBe( '2026-08-05T00:00:00' );
		expect( CORE_HAS_TIMEZONE.test( mockDatePickerProps[ 0 ].currentDate ) ).toBe( false );
	} );

	it( 'hands the expiry date to the picker as a timezone-less datetime', () => {
		renderSidebar( { expiry_date: '2026-08-05' } );

		expect( mockDatePickerProps[ 0 ].currentDate ).toBe( '2026-08-05T00:00:00' );
		expect( CORE_HAS_TIMEZONE.test( mockDatePickerProps[ 0 ].currentDate ) ).toBe( false );
	} );

	it( 'normalizes a legacy stored datetime before handing it to the picker', () => {
		renderSidebar( { start_date: '2026-08-05T23:59:59' } );

		expect( mockDatePickerProps[ 0 ].currentDate ).toBe( '2026-08-05T00:00:00' );
	} );

	it( 'still writes a bare Y-m-d back to start_date meta', () => {
		renderSidebar( { start_date: '2026-08-05' } );
		mockDatePickerProps[ 0 ].onChange( '2026-08-10T00:00:00' );

		expect( mockEditPost ).toHaveBeenCalledWith( { meta: { start_date: '2026-08-10' } } );
	} );

	it( 'still writes a bare Y-m-d back to expiry_date meta', () => {
		renderSidebar( { expiry_date: '2026-08-05' } );
		mockDatePickerProps[ 0 ].onChange( '2026-08-10T00:00:00' );

		expect( mockEditPost ).toHaveBeenCalledWith( { meta: { expiry_date: '2026-08-10' } } );
	} );
} );
