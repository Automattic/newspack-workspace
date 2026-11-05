/**
 * The A/B Test panel's states: a prompt not in a test can start one, version A
 * edits the goal and split until the test runs, and version B repeats them
 * read-only. The editor's component library is stubbed down to native inputs,
 * and the Start A/B Test modal down to its props, which have their own tests.
 */

/**
 * WordPress dependencies.
 */
import { render, screen, fireEvent } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

const mockEditor = {
	status: 'draft',
	title: 'Spring drive',
	content: '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
	blockNames: [ 'core/paragraph' ],
	dirty: false,
	saveFailed: false,
};
const mockSavePost = jest.fn( () => Promise.resolve() );
const mockSelect = store =>
	'core/block-editor' === store
		? {
				getClientIdsWithDescendants: () => mockEditor.blockNames.map( ( name, index ) => index ),
				getBlockName: index => mockEditor.blockNames[ index ],
		  }
		: {
				getCurrentPostAttribute: () => mockEditor.status,
				getEditedPostAttribute: () => mockEditor.title,
				getEditedPostContent: () => mockEditor.content,
				isEditedPostDirty: () => mockEditor.dirty,
				didPostSaveRequestFail: () => mockEditor.saveFailed,
		  };

jest.mock( '@wordpress/data', () => ( {
	select: store => mockSelect( store ),
	useSelect: fn => fn( store => mockSelect( store ) ),
	useDispatch: () => ( { savePost: mockSavePost } ),
} ) );
jest.mock( '@wordpress/compose', () => ( { useDebounce: fn => fn } ) );
jest.mock( '@wordpress/api-fetch' );
// Provided by WordPress at runtime, not installed here.
jest.mock( '@wordpress/ui', () => ( { Stack: ( { children } ) => <div>{ children }</div> } ), { virtual: true } );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, disabled, href } ) =>
		href ? (
			<a href={ href }>{ children }</a>
		) : (
			<button onClick={ onClick } disabled={ disabled }>
				{ children }
			</button>
		),
	SelectControl: ( { label, value, onChange, disabled, options, help } ) => (
		<div>
			<select aria-label={ label } value={ value } onChange={ e => onChange( e.target.value ) } disabled={ disabled }>
				{ options.map( option => (
					<option key={ option.value } value={ option.value } disabled={ option.disabled }>
						{ option.label }
					</option>
				) ) }
			</select>
			{ help && <p>{ help }</p> }
		</div>
	),
	RangeControl: ( { label, value, onChange, disabled, help } ) => (
		<div>
			<input type="range" aria-label={ label } value={ value } onChange={ e => onChange( Number( e.target.value ) ) } disabled={ disabled } />
			{ help && <p>{ help }</p> }
		</div>
	),
	Notice: ( { status, children, spokenMessage } ) => (
		<div role="note" data-status={ status } data-spoken={ spokenMessage }>
			{ children }
		</div>
	),
	ExternalLink: ( { href, children } ) => <a href={ href }>{ children }</a>,
	BaseControl: { VisualLabel: ( { id, children } ) => <span id={ id }>{ children }</span> },
} ) );

let mockModalProps = null;
jest.mock( '../ab-tests/components/StartTestModal', () => props => {
	mockModalProps = props;
	return <div role="dialog">{ props.promptTitle }</div>;
} );

import ABTestPanel from './ABTestPanel';

const AB_TESTING = {
	enabled: true,
	can_manage_tests: true,
	goals: {
		form_submission: 'Form submission',
		link_click: 'Link or button click',
		donation: 'Donation',
		subscription: 'Subscription',
	},
	variants: { a: 'A', b: 'B' },
	ineligible_placements: [ 'manual', 'custom1' ],
	results_url: '/wp-admin/admin.php?page=newspack-ab-tests',
	test_name: 'Spring drive',
};

let onMetaFieldChange;

const renderPanel = props =>
	render(
		<ABTestPanel
			newspack_popups_ab_test_id=""
			newspack_popups_ab_variant=""
			newspack_popups_ab_test_goal=""
			newspack_popups_ab_control_share={ 50 }
			placement="inline"
			postId={ 2 }
			onMetaFieldChange={ onMetaFieldChange }
			{ ...props }
		/>
	);

const inTest = ( variant, props = {} ) =>
	renderPanel( {
		newspack_popups_ab_test_id: 'spring-drive-1a2b',
		newspack_popups_ab_variant: variant,
		...props,
	} );

const versionA = ( overrides = {} ) => ( {
	id: 1,
	title: 'Spring drive',
	variant: 'a',
	goal: 'subscription',
	control_share: 60,
	status: 'publish',
	status_label: 'Published',
	edit_link: '/wp-admin/post.php?post=1&action=edit',
	...overrides,
} );
const versionB = ( overrides = {} ) => ( {
	id: 3,
	title: 'Spring drive (B)',
	variant: 'b',
	goal: '',
	control_share: 50,
	status: 'draft',
	status_label: 'Draft',
	edit_link: '/wp-admin/post.php?post=3&action=edit',
	...overrides,
} );

beforeEach( () => {
	window.newspack_popups_data = { ab_testing: { ...AB_TESTING } };
	onMetaFieldChange = jest.fn();
	Object.assign( mockEditor, { status: 'draft', title: 'Spring drive', blockNames: [ 'core/paragraph' ], dirty: false, saveFailed: false } );
	mockSavePost.mockClear();
	mockModalProps = null;
	apiFetch.mockResolvedValue( [] );
} );

afterEach( () => {
	delete window.newspack_popups_data;
} );

describe( 'not in a test', () => {
	it( 'offers Start A/B Test, which opens the modal for this prompt', () => {
		renderPanel();
		expect( screen.getByText( 'Compare this prompt with a second version to see which gets more readers to act.' ) ).toBeTruthy();
		expect( screen.queryByRole( 'combobox' ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Start A/B Test' } ) );

		expect( mockModalProps.promptId ).toBe( 2 );
		expect( mockModalProps.promptTitle ).toBe( 'Spring drive' );
	} );

	it( 'disables the button on a manual or custom placement and says why', () => {
		renderPanel( { placement: 'custom1' } );
		expect( screen.getByRole( 'button', { name: 'Start A/B Test' } ).disabled ).toBe( true );
		expect( screen.getByRole( 'note' ).textContent ).toBe( 'Prompts on manual or custom placements can’t be A/B tested.' );
	} );

	it( 'offers nothing to a user the site excludes from managing tests', () => {
		window.newspack_popups_data.ab_testing.can_manage_tests = false;
		renderPanel();
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'saves unsaved edits before the start, since the route copies the stored prompt', async () => {
		mockEditor.dirty = true;
		renderPanel();
		fireEvent.click( screen.getByRole( 'button', { name: 'Start A/B Test' } ) );
		await mockModalProps.beforeCreate();
		expect( mockSavePost ).toHaveBeenCalled();
	} );

	it( 'refuses to start when that save fails', async () => {
		mockEditor.dirty = true;
		mockEditor.saveFailed = true;
		renderPanel();
		fireEvent.click( screen.getByRole( 'button', { name: 'Start A/B Test' } ) );
		await expect( mockModalProps.beforeCreate() ).rejects.toThrow( 'Save this prompt, then try again.' );
	} );

	it( "opens B's editor once B exists, marked so its panel takes focus", () => {
		const assign = jest.fn();
		const { location } = window;
		delete window.location;
		window.location = { assign, reload: jest.fn() };
		renderPanel();
		fireEvent.click( screen.getByRole( 'button', { name: 'Start A/B Test' } ) );

		mockModalProps.onCreated( { version_b: { id: 3, edit_link: '/wp-admin/post.php?post=3&action=edit' } } );

		expect( assign ).toHaveBeenCalledWith( '/wp-admin/post.php?post=3&action=edit&newspack-ab-test=created' );
		window.location = location;
	} );
} );

describe( 'version A', () => {
	it( 'edits the goal and the split, with two linked ranges, before B is live', async () => {
		apiFetch.mockResolvedValue( [ versionA(), versionB() ] );
		inTest( 'a', { newspack_popups_ab_test_goal: 'subscription', newspack_popups_ab_control_share: 60 } );
		await screen.findByText( 'Spring drive (B)' );

		expect( screen.getByText( 'Version A in the Spring drive test.' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'Readers who see B' ).value ).toBe( '40' );
		fireEvent.change( screen.getByLabelText( 'Readers who see B' ), { target: { value: '30' } } );
		expect( onMetaFieldChange ).toHaveBeenCalledWith( { newspack_popups_ab_control_share: 70 } );
		fireEvent.change( screen.getByLabelText( 'Goal' ), { target: { value: 'donation' } } );
		expect( onMetaFieldChange ).toHaveBeenCalledWith( { newspack_popups_ab_test_goal: 'donation' } );
		expect( screen.getByText( 'Version B · Draft' ) ).toBeTruthy();
	} );

	it( 'shows the goal and split read-only once every version is published', async () => {
		mockEditor.status = 'publish';
		apiFetch.mockResolvedValue( [ versionA(), versionB( { status: 'publish', status_label: 'Published' } ) ] );
		inTest( 'a', { newspack_popups_ab_test_goal: 'subscription', newspack_popups_ab_control_share: 60 } );

		expect( await screen.findByText( 'Goal: Subscription. 60% see A, 40% see B.' ) ).toBeTruthy();
		expect( screen.getByText( 'End the test to change the goal or split.' ) ).toBeTruthy();
		expect( screen.queryByRole( 'combobox' ) ).toBeNull();
		expect( screen.queryByRole( 'slider' ) ).toBeNull();
	} );

	it( 'offers Create Version B when the test has no B, starting from its goal and split', async () => {
		apiFetch.mockResolvedValue( [ versionA() ] );
		inTest( 'a', { newspack_popups_ab_test_goal: 'donation', newspack_popups_ab_control_share: 70 } );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Create Version B' } ) );

		expect( mockModalProps ).toMatchObject( { promptTitle: 'Spring drive', initialGoal: 'donation', initialShare: 70 } );
	} );

	it( 'does not offer Create Version B while a B exists', async () => {
		apiFetch.mockResolvedValue( [ versionA(), versionB() ] );
		inTest( 'a' );
		await screen.findByText( 'Spring drive (B)' );
		expect( screen.queryByRole( 'button', { name: 'Create Version B' } ) ).toBeNull();
	} );
} );

describe( 'version B', () => {
	// B carries a block for A's goal, so the missing-block warning stays out of
	// tests about other notices; one test below removes it on purpose.
	beforeEach( () => {
		mockEditor.blockNames = [ 'core/paragraph', 'newspack-blocks/checkout-button' ];
	} );

	it( "repeats A's goal and split, links to A and asks for B to be published while B is a draft", async () => {
		apiFetch.mockResolvedValue( [ versionA(), versionB() ] );
		inTest( 'b', { postId: 3 } );

		expect( await screen.findByText( 'Goal: Subscription. 60% see A, 40% see B. Set on version A.' ) ).toBeTruthy();
		expect( screen.getByText( 'Version B in the Spring drive test.' ) ).toBeTruthy();
		expect( screen.getByRole( 'link', { name: 'Change these on version A' } ).getAttribute( 'href' ) ).toBe( versionA().edit_link );
		const notices = screen.getAllByRole( 'note' );
		expect( notices.map( notice => notice.textContent ) ).toEqual( [ 'Publish B to start the test.' ] );
		expect( screen.queryByRole( 'link', { name: 'View results' } ) ).toBeNull();
		expect( screen.queryByRole( 'combobox' ) ).toBeNull();
	} );

	it( 'shows View results in place of the notice once B is live', async () => {
		mockEditor.status = 'publish';
		apiFetch.mockResolvedValue( [ versionA(), versionB( { status: 'publish' } ) ] );
		inTest( 'b', { postId: 3 } );

		expect( await screen.findByRole( 'link', { name: 'View results' } ) ).toBeTruthy();
		expect( screen.queryByRole( 'note' ) ).toBeNull();
		expect( screen.queryByRole( 'link', { name: 'Change these on version A' } ) ).toBeNull();
	} );

	it( 'asks for another placement on an older B that sits on a manual or custom one', async () => {
		apiFetch.mockResolvedValue( [ versionA(), versionB() ] );
		inTest( 'b', { postId: 3, placement: 'manual' } );
		await screen.findByText( 'Goal: Subscription. 60% see A, 40% see B. Set on version A.' );
		expect(
			screen.getByText( 'Prompts on manual or custom placements can’t be A/B tested. Choose another placement to count B’s results.' )
		).toBeTruthy();
	} );

	it( "warns when B has no block for A's goal", async () => {
		apiFetch.mockResolvedValue( [ versionA( { goal: 'donation' } ), versionB() ] );
		inTest( 'b', { postId: 3 } );
		expect(
			await screen.findByText( "Readers can't donate from this prompt. Add a Donate block, or change the goal on version A." )
		).toBeTruthy();
	} );

	it( 'keeps the Goal select on a B whose test has no A', async () => {
		apiFetch.mockResolvedValue( [ versionB() ] );
		inTest( 'b', { postId: 3 } );
		expect( await screen.findByLabelText( 'Goal' ) ).toBeTruthy();
	} );

	it( 'warns about another prompt with the same version', async () => {
		apiFetch.mockResolvedValue( [ versionA(), versionB(), versionB( { id: 4, title: 'Extra B' } ) ] );
		inTest( 'b', { postId: 3 } );
		expect( await screen.findByText( 'Another prompt in this test is already version B. Unpublish one of them.' ) ).toBeTruthy();
	} );
} );
