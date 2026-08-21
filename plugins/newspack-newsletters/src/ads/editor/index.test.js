/**
 * Handing `DatePicker` a bare `Y-m-d` made it select — and save back — the
 * previous day wherever the UTC offset is negative (NPPM-3078); see
 * `toTimezonelessDateTime` in ./index.js for why, on both supported
 * WordPress versions. These tests pin both directions: that the picker is
 * handed a datetime rather than a bare date, and that a bare `Y-m-d` is
 * still what reaches meta.
 *
 * They deliberately assert the value passed to a stubbed picker rather than
 * rendering the real one: `@wordpress/components` is externalized to
 * `wp.components` at runtime, so the copy in node_modules is not the code
 * any site runs — and its date handling differs from both shipped versions.
 * Rendering it here would pin an implementation nobody has. The end-to-end
 * behavior is covered by manual QA against a negative-offset site.
 */

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: { use: jest.fn() },
} ) );

// `DatePicker` is what's under test, so its props are captured; the rest of
// the sidebar's controls are stubbed.
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

// WordPress 7.0's offset detection, from `inputToDate()`. A value that
// matches this is treated as already carrying a UTC offset — which is what a
// bare `Y-m-d` wrongly did.
const WP70_HAS_TIMEZONE = /Z|[+-]\d{2}(:?\d{2})?$/;

describe( 'Newsletter ad sidebar date pickers', () => {
	beforeEach( () => {
		mockEditPost.mockReset();
	} );

	it( 'hands the start date to the picker as a timezone-less datetime', () => {
		renderSidebar( { start_date: '2026-08-05' } );

		expect( mockDatePickerProps[ 0 ].currentDate ).toBe( '2026-08-05T12:00:00' );
		expect( WP70_HAS_TIMEZONE.test( mockDatePickerProps[ 0 ].currentDate ) ).toBe( false );
	} );

	it( 'hands the expiry date to the picker as a timezone-less datetime', () => {
		renderSidebar( { expiry_date: '2026-08-05' } );

		expect( mockDatePickerProps[ 0 ].currentDate ).toBe( '2026-08-05T12:00:00' );
		expect( WP70_HAS_TIMEZONE.test( mockDatePickerProps[ 0 ].currentDate ) ).toBe( false );
	} );

	it( 'normalizes a legacy stored datetime before handing it to the picker', () => {
		renderSidebar( { start_date: '2026-08-05T23:59:59' } );

		expect( mockDatePickerProps[ 0 ].currentDate ).toBe( '2026-08-05T12:00:00' );
	} );

	it( 'still writes a bare Y-m-d back to start_date meta', () => {
		renderSidebar( { start_date: '2026-08-05' } );
		mockDatePickerProps[ 0 ].onChange( '2026-08-10T12:00:00' );

		expect( mockEditPost ).toHaveBeenCalledWith( { meta: { start_date: '2026-08-10' } } );
	} );

	it( 'still writes a bare Y-m-d back to expiry_date meta', () => {
		renderSidebar( { expiry_date: '2026-08-05' } );
		mockDatePickerProps[ 0 ].onChange( '2026-08-10T12:00:00' );

		expect( mockEditPost ).toHaveBeenCalledWith( { meta: { expiry_date: '2026-08-10' } } );
	} );
} );
