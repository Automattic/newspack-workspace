/**
 * External dependencies
 */
import { act, render, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { SettingsSection } from './settings-section';

const mockCardFeatureProps = [];
const mockEnableModalProps = [];

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../../../../packages/components/src', () => ( {
	Card: ( { children } ) => children,
	Grid: ( { children } ) => children,
	CardFeature: props => {
		mockCardFeatureProps.push( props );
		return null;
	},
	IntegrationIcon: ( { provider } ) => <span data-provider={ provider } />,
	espProviderOrder: [ 'active_campaign', 'mailchimp', 'constant_contact', 'manual' ],
} ) );
jest.mock( './enable-modal', () => {
	const actual = jest.requireActual( './enable-modal' );
	return {
		...actual,
		EnableModal: props => {
			mockEnableModalProps.push( props );
			return null;
		},
	};
} );
jest.mock(
	'../../../wizards-tab',
	() =>
		( { children } ) =>
			children
);
jest.mock(
	'../../../wizards-section',
	() =>
		( { children } ) =>
			children
);

const SETUP_URL = 'https://example.com/wp-admin/admin.php?page=newspack-newsletters';
const RETURN_URL = 'https://example.com/wp-admin/admin.php?page=newspack-audience-integrations#/settings';
const HANDOFF_LINK = SETUP_URL + '&newspack_handoff=1';

const requiredAudienceField = {
	key: 'mailchimp_audience_id',
	type: 'select',
	label: 'Mailchimp Audience',
	required: true,
	value: '',
	options: [
		{ label: 'None', value: '' },
		{ label: 'Weekly', value: 'abc123' },
	],
};

const baseIntegration = {
	id: 'esp',
	name: 'Newsletter ESP',
	description: 'Syncs reader data with your ESP.',
	enabled: false,
	is_set_up: false,
	is_connected: false,
	setup_url: SETUP_URL,
	settings: [],
	required_plugins: [ { slug: 'newspack-newsletters', name: 'Newspack Newsletters', is_active: true, is_installed: true } ],
};

const renderSection = ( integrationOverrides = {}, extraProps = {} ) => {
	const history = { push: jest.fn() };
	const onToggleEnabled = jest.fn();
	const onSetupAndEnable = jest.fn( () => Promise.resolve() );
	render(
		<SettingsSection
			integrations={ { esp: { ...baseIntegration, ...integrationOverrides } } }
			loading={ false }
			onToggleEnabled={ onToggleEnabled }
			onActivatePlugin={ jest.fn() }
			onSetupAndEnable={ onSetupAndEnable }
			history={ history }
			{ ...extraProps }
		/>
	);
	return { history, onToggleEnabled, onSetupAndEnable, cardProps: mockCardFeatureProps[ 0 ] };
};

describe( 'Audience Integrations settings section card action', () => {
	beforeEach( () => {
		mockCardFeatureProps.length = 0;
		mockEnableModalProps.length = 0;
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { HandoffLink: HANDOFF_LINK } );
		delete window.location;
		window.location = { href: RETURN_URL };
	} );

	it( 'offers "Connect" performing a handoff to the setup URL when the provider is not connected', async () => {
		const { history, cardProps } = renderSection( { is_connected: false } );
		expect( cardProps.enableLabel ).toBe( 'Connect' );
		cardProps.onEnable();
		await waitFor( () => expect( window.location.href ).toBe( HANDOFF_LINK ) );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack/v1/handoff',
			method: 'POST',
			data: {
				destinationUrl: SETUP_URL,
				handoffReturnUrl: RETURN_URL,
				bannerText: 'Return to Integrations after completing configuration',
				bannerButtonText: 'Back to Integrations',
			},
		} );
		expect( history.push ).not.toHaveBeenCalled();
	} );

	it( 'offers "Enable" opening the enable modal when connected with a missing required field', () => {
		const { onToggleEnabled, cardProps } = renderSection( { is_connected: true, settings: [ requiredAudienceField ] } );
		expect( cardProps.enableLabel ).toBe( 'Enable' );
		act( () => cardProps.onEnable() );
		expect( onToggleEnabled ).not.toHaveBeenCalled();
		expect( mockEnableModalProps.length ).toBeGreaterThan( 0 );
		expect( mockEnableModalProps[ 0 ].integration.id ).toBe( 'esp' );
	} );

	it( 'forwards the modal enable action to onSetupAndEnable', async () => {
		const { onSetupAndEnable, cardProps } = renderSection( { is_connected: true, settings: [ requiredAudienceField ] } );
		act( () => cardProps.onEnable() );
		const modalProps = mockEnableModalProps[ mockEnableModalProps.length - 1 ];
		await act( () => modalProps.onEnable( { mailchimp_audience_id: 'abc123' } ) );
		expect( onSetupAndEnable ).toHaveBeenCalledWith( 'esp', { mailchimp_audience_id: 'abc123' } );
	} );

	it( 'offers "Enable" toggling the integration directly when required settings are satisfied', () => {
		const { onToggleEnabled, cardProps } = renderSection( {
			is_connected: true,
			is_set_up: true,
			settings: [ { ...requiredAudienceField, value: 'abc123' } ],
		} );
		expect( cardProps.enableLabel ).toBe( 'Enable' );
		cardProps.onEnable();
		expect( onToggleEnabled ).toHaveBeenCalledWith( 'esp', true );
	} );

	it( 'routes the configure action to the configure view when connected', () => {
		const { history, cardProps } = renderSection( { is_connected: true, is_set_up: true, enabled: true } );
		cardProps.onConfigure();
		expect( history.push ).toHaveBeenCalledWith( '/settings/esp' );
	} );

	it( 'routes the configure action through the handoff while the provider is not connected', async () => {
		const { history, cardProps } = renderSection( { is_connected: false, enabled: true } );
		cardProps.onConfigure();
		await waitFor( () => expect( window.location.href ).toBe( HANDOFF_LINK ) );
		expect( history.push ).not.toHaveBeenCalled();
	} );

	it( 'falls back to direct navigation when the handoff request fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		const { cardProps } = renderSection( { is_connected: false } );
		cardProps.onEnable();
		await waitFor( () => expect( window.location.href ).toBe( SETUP_URL ) );
	} );

	it( 'marks the card busy with an ellipsis label while its required plugin is activating', () => {
		const { cardProps } = renderSection(
			{
				required_plugins: [ { slug: 'newspack-newsletters', name: 'Newspack Newsletters', is_active: false, is_installed: true } ],
			},
			{ activating: { 'newspack-newsletters': true } }
		);
		expect( cardProps.enableLabel ).toBe( 'Activating…' );
		expect( cardProps.busy ).toBe( true );
	} );

	it( 'offers the integration-supplied action label, not "Connect", when unsupported', async () => {
		const { history, onToggleEnabled, cardProps } = renderSection( {
			is_connected: true,
			unsupported_reason: 'Requires an API-based ESP',
			unsupported_action_label: 'Change provider',
		} );
		expect( cardProps.requirements ).toBe( 'Requires an API-based ESP' );
		expect( cardProps.requirementsActionable ).toBe( true );
		expect( cardProps.enableLabel ).toBe( 'Change provider' );
		cardProps.onEnable();
		await waitFor( () => expect( window.location.href ).toBe( HANDOFF_LINK ) );
		expect( onToggleEnabled ).not.toHaveBeenCalled();
		expect( history.push ).not.toHaveBeenCalled();
	} );

	it( 'disables the action for an unsupported integration with nowhere to send the user', () => {
		const { cardProps } = renderSection( {
			is_connected: true,
			unsupported_reason: 'Requires an API-based ESP',
			unsupported_action_label: 'Change provider',
			setup_url: '',
		} );
		expect( cardProps.requirements ).toBe( 'Requires an API-based ESP' );
		expect( cardProps.requirementsActionable ).toBe( false );
		// Still names the real remedy, not "Enable" — the button is inert, not a lie.
		expect( cardProps.enableLabel ).toBe( 'Change provider' );
	} );

	it( 'falls back to "Open settings" when unsupported with no integration-supplied label', () => {
		const { cardProps } = renderSection( {
			is_connected: true,
			unsupported_reason: 'Requires an API-based ESP',
		} );
		expect( cardProps.enableLabel ).toBe( 'Open settings' );
		expect( cardProps.requirementsActionable ).toBe( true );
	} );

	it( 'renders the connected provider brand icon via IntegrationIcon', () => {
		const { cardProps } = renderSection( { is_connected: true, provider: 'mailchimp' } );
		expect( cardProps.icon.props.provider ).toBe( 'mailchimp' );
	} );

	it( 'renders the manual provider brand icon via IntegrationIcon', () => {
		const { cardProps } = renderSection( { is_connected: true, provider: 'manual' } );
		expect( cardProps.icon.props.provider ).toBe( 'manual' );
	} );

	it( 'keeps the generic icon descriptor when no provider is connected', () => {
		const { cardProps } = renderSection( { provider: null } );
		expect( cardProps.icon.node ).toBeDefined();
		expect( cardProps.icon.props ).toBeUndefined();
	} );
} );
