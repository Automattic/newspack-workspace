/**
 * Contextual Prompts settings content: the disabled empty state (admin opt-in
 * modal, non-admin note), and the enabled settings body's field gating (override
 * enable toggle, CTA form/button choice, conditional button fields).
 */

import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import '@testing-library/jest-dom';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ContextualPromptsSettings from './contextual-prompts-settings';

// ControlPreview (mounted whenever the control section is on) fetches the
// preview via apiFetch; mocked so the enabled-body tests stay isolated from
// the network and keep passing when the control toggle is on.
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
apiFetch.mockResolvedValue( { interval: 3, limit: 10, offset: 0, total: 0, posts: [] } );

const FIELD_DEFAULTS = { section: 'override', value: '' };
const ENABLE_FIELD = {
	...FIELD_DEFAULTS,
	key: 'newspack_contextual_prompts_override_enabled',
	label: 'Enable site-wide override',
	type: 'toggle',
	value: '1',
};
const BODY_FIELD = { ...FIELD_DEFAULTS, key: 'newspack_contextual_prompts_override_body', label: 'Override copy', type: 'textarea' };
const TOGGLE_FIELD = {
	...FIELD_DEFAULTS,
	key: 'newspack_contextual_prompts_override_cta',
	label: 'Override call to action',
	type: 'togglegroup',
	options: [
		{ value: 'form', label: 'Donate Form' },
		{ value: 'button', label: 'Donate Button' },
	],
	value: 'form',
};
const LABEL_FIELD = { ...FIELD_DEFAULTS, key: 'newspack_contextual_prompts_override_label', label: 'Override button label', type: 'text' };
const URL_FIELD = { ...FIELD_DEFAULTS, key: 'newspack_contextual_prompts_override_url', label: 'Override button URL', type: 'text' };

// The pattern keys the status payload carries; the settings body must ignore
// them, since the design is edited in the pattern from the wizard header.
const PATTERN_PAYLOAD = {
	pattern_id: 42,
	pattern_edit_url: 'https://example.test/wp-admin/site-editor.php?postId=42&postType=wp_block&canvas=edit',
};

const fieldsToValues = fields => ( fields || [] ).reduce( ( acc, field ) => ( { ...acc, [ field.key ]: field.value ?? '' } ), {} );

// Enabled body needs live values so the field-gating interactions can be exercised.
const EnabledHarness = ( { fields } ) => {
	const [ values, setValues ] = useState( () => fieldsToValues( fields ) );
	return (
		<ContextualPromptsSettings
			status={ { enabled: true, can_manage: true, fields } }
			values={ values }
			error={ null }
			inFlight={ false }
			onSetValue={ ( key, value ) => setValues( previous => ( { ...previous, [ key ]: value } ) ) }
			onEnable={ () => Promise.resolve() }
		/>
	);
};

describe( 'ContextualPromptsSettings empty state', () => {
	it( 'shows the empty state and opens the disclosure modal, then calls onEnable', () => {
		const onEnable = jest.fn().mockResolvedValue( undefined );
		render(
			<ContextualPromptsSettings
				status={ { enabled: false, can_manage: true, fields: [] } }
				values={ {} }
				error={ null }
				inFlight={ false }
				onSetValue={ () => {} }
				onEnable={ onEnable }
			/>
		);

		expect( screen.getByText( 'Get started with Contextual Prompts' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Contextual Prompts' } ) );

		const dialog = screen.getByRole( 'dialog' );
		expect( within( dialog ).getByText( /confirm your newsroom permits it/ ) ).toBeInTheDocument();
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Enable' } ) );
		expect( onEnable ).toHaveBeenCalled();
	} );

	it( 'disables the button and shows the note for non-admins', () => {
		render(
			<ContextualPromptsSettings
				status={ { enabled: false, can_manage: false, fields: [] } }
				values={ {} }
				error={ null }
				inFlight={ false }
				onSetValue={ () => {} }
				onEnable={ () => Promise.resolve() }
			/>
		);

		expect( screen.getByText( 'An administrator must enable this feature.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Enable Contextual Prompts' } ) ).toBeDisabled();
	} );
} );

describe( 'ContextualPromptsSettings enabled body', () => {
	it( 'renders the two settings sections and no design controls, pattern payload or not', () => {
		render(
			<ContextualPromptsSettings
				status={ { enabled: true, can_manage: true, fields: [ ENABLE_FIELD ], ...PATTERN_PAYLOAD } }
				values={ fieldsToValues( [ ENABLE_FIELD ] ) }
				error={ null }
				inFlight={ false }
				onSetValue={ () => {} }
				onEnable={ () => Promise.resolve() }
			/>
		);

		expect( screen.getByRole( 'heading', { name: 'Publisher Profile' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Site-Wide Override' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: 'Edit Design' } ) ).not.toBeInTheDocument();
	} );

	it( 'shows only the enable toggle while the override is off', () => {
		render( <EnabledHarness fields={ [ { ...ENABLE_FIELD, value: '' }, BODY_FIELD, TOGGLE_FIELD, LABEL_FIELD, URL_FIELD ] } /> );

		expect( screen.getByText( 'Enable site-wide override' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Override copy' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Donate Form' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Override button label' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Enable site-wide override' } ) );
		expect( screen.getByText( 'Override copy' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Donate Form' ) ).toBeInTheDocument();
	} );

	it( 'hides the button fields under Donate Form and shows them under Donate Button', () => {
		render( <EnabledHarness fields={ [ ENABLE_FIELD, TOGGLE_FIELD, LABEL_FIELD, URL_FIELD ] } /> );

		expect( screen.getByText( 'Donate Form' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Override button label' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Override button URL' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByText( 'Donate Button' ) );
		expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
	} );

	it( 'shows the button fields when no toggle exists (off-site sites)', () => {
		render( <EnabledHarness fields={ [ ENABLE_FIELD, LABEL_FIELD, URL_FIELD ] } /> );

		expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Donate Form' ) ).not.toBeInTheDocument();
	} );

	it( 'lists the previewed stories as edit links while the control test is on', async () => {
		const controlEnabledField = {
			section: 'control',
			key: 'newspack_contextual_prompts_control_enabled',
			label: 'Enable control test',
			type: 'toggle',
			value: '1',
		};
		apiFetch.mockResolvedValueOnce( {
			interval: 3,
			limit: 10,
			offset: 0,
			total: 2,
			posts: [
				{
					id: 3,
					title: 'Local election results',
					edit_link: 'https://example.test/wp-admin/post.php?post=3&action=edit',
					permalink: 'https://example.test/?p=3',
				},
				{
					id: 6,
					title: 'City budget vote',
					edit_link: 'https://example.test/wp-admin/post.php?post=6&action=edit',
					permalink: 'https://example.test/?p=6',
				},
			],
		} );

		render( <EnabledHarness fields={ [ controlEnabledField ] } /> );

		expect( await screen.findByRole( 'link', { name: 'Local election results' } ) ).toHaveAttribute(
			'href',
			'https://example.test/wp-admin/post.php?post=3&action=edit'
		);
		expect( await screen.findByRole( 'link', { name: 'City budget vote' } ) ).toHaveAttribute(
			'href',
			'https://example.test/wp-admin/post.php?post=6&action=edit'
		);
	} );

	it( 'shows "Load more" while more stories remain, and appends the next page on click', async () => {
		const controlEnabledField = {
			section: 'control',
			key: 'newspack_contextual_prompts_control_enabled',
			label: 'Enable control test',
			type: 'toggle',
			value: '1',
		};
		apiFetch.mockResolvedValueOnce( {
			interval: 3,
			limit: 10,
			offset: 0,
			total: 3,
			posts: [
				{
					id: 3,
					title: 'Local election results',
					edit_link: 'https://example.test/wp-admin/post.php?post=3&action=edit',
					permalink: 'https://example.test/?p=3',
				},
				{
					id: 6,
					title: 'City budget vote',
					edit_link: 'https://example.test/wp-admin/post.php?post=6&action=edit',
					permalink: 'https://example.test/?p=6',
				},
			],
		} );

		render( <EnabledHarness fields={ [ controlEnabledField ] } /> );

		const loadMoreButton = await screen.findByRole( 'button', { name: 'Load more' } );
		expect( screen.getByText( 'Showing 2 of 3.' ) ).toBeInTheDocument();

		apiFetch.mockResolvedValueOnce( {
			interval: 3,
			limit: 10,
			offset: 2,
			total: 3,
			posts: [
				{
					id: 9,
					title: 'School board meeting',
					edit_link: 'https://example.test/wp-admin/post.php?post=9&action=edit',
					permalink: 'https://example.test/?p=9',
				},
			],
		} );

		fireEvent.click( loadMoreButton );

		expect( await screen.findByRole( 'link', { name: 'School board meeting' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Local election results' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'City budget vote' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Load more' } ) ).not.toBeInTheDocument();
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/newspack-popups/v1/contextual-prompt/control-preview?interval=3&offset=2',
		} );
	} );

	it( 'drops a stale "Load more" response that resolves after the interval has changed', async () => {
		const controlEnabledField = {
			section: 'control',
			key: 'newspack_contextual_prompts_control_enabled',
			label: 'Enable control test',
			type: 'toggle',
			value: '1',
		};
		const controlIntervalField = {
			section: 'control',
			key: 'newspack_contextual_prompts_control_interval',
			label: 'Show control copy on every Nth story',
			type: 'number',
			value: '3',
		};

		// Page 1 at interval 3.
		apiFetch.mockResolvedValueOnce( {
			interval: 3,
			limit: 10,
			offset: 0,
			total: 3,
			posts: [
				{
					id: 3,
					title: 'Interval 3, story 1',
					edit_link: 'https://example.test/wp-admin/post.php?post=3&action=edit',
					permalink: 'https://example.test/?p=3',
				},
				{
					id: 6,
					title: 'Interval 3, story 2',
					edit_link: 'https://example.test/wp-admin/post.php?post=6&action=edit',
					permalink: 'https://example.test/?p=6',
				},
			],
		} );

		render( <EnabledHarness fields={ [ controlEnabledField, controlIntervalField ] } /> );

		const loadMoreButton = await screen.findByRole( 'button', { name: 'Load more' } );

		// "Load more" page 2 at interval 3: deferred, resolved manually below.
		let resolveStalePage;
		apiFetch.mockImplementationOnce(
			() =>
				new Promise( resolve => {
					resolveStalePage = resolve;
				} )
		);
		const callsBeforeClick = apiFetch.mock.calls.length;
		fireEvent.click( loadMoreButton );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( callsBeforeClick + 1 ) );

		// Page 1 at the new interval, 5.
		apiFetch.mockResolvedValueOnce( {
			interval: 5,
			limit: 10,
			offset: 0,
			total: 1,
			posts: [
				{
					id: 15,
					title: 'Interval 5, story 1',
					edit_link: 'https://example.test/wp-admin/post.php?post=15&action=edit',
					permalink: 'https://example.test/?p=15',
				},
			],
		} );
		fireEvent.change( screen.getByRole( 'spinbutton', { name: 'Show control copy on every Nth story' } ), {
			target: { value: '5' },
		} );

		expect( await screen.findByRole( 'link', { name: 'Interval 5, story 1' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: 'Interval 3, story 1' } ) ).not.toBeInTheDocument();

		// The interval-3 "Load more" request finally resolves, after the interval
		// change already reset the list. Its rows must not appear.
		await act( async () => {
			resolveStalePage( {
				interval: 3,
				limit: 10,
				offset: 2,
				total: 3,
				posts: [
					{
						id: 9,
						title: 'Interval 3, story 3 (stale)',
						edit_link: 'https://example.test/wp-admin/post.php?post=9&action=edit',
						permalink: 'https://example.test/?p=9',
					},
				],
			} );
		} );

		expect( screen.queryByRole( 'link', { name: 'Interval 3, story 3 (stale)' } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Interval 5, story 1' } ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'link' ) ).toHaveLength( 1 );
	} );

	it( 'shows "500+" and the cap note when the scan hit its limit', async () => {
		const controlEnabledField = {
			section: 'control',
			key: 'newspack_contextual_prompts_control_enabled',
			label: 'Enable control test',
			type: 'toggle',
			value: '1',
		};
		apiFetch.mockResolvedValueOnce( {
			interval: 3,
			limit: 10,
			offset: 0,
			total: 500,
			capped: true,
			posts: [
				{
					id: 3,
					title: 'Local election results',
					edit_link: 'https://example.test/wp-admin/post.php?post=3&action=edit',
					permalink: 'https://example.test/?p=3',
				},
			],
		} );

		render( <EnabledHarness fields={ [ controlEnabledField ] } /> );

		expect( await screen.findByRole( 'button', { name: 'Load more' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Showing 1 of 500+.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Only the newest 500 stories are scanned.' ) ).toBeInTheDocument();
	} );
} );
