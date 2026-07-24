/**
 * Contextual Prompts settings content.
 *
 * Presentational: the parent tab owns the fetched status/values and the header
 * Save/Disable actions. When the feature is off this renders an empty state with
 * an admin opt-in (AI-use disclosure modal); when on, the publisher-profile and
 * site-wide override fields in the branch's grid/divider layout.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Notice,
	TextControl,
	TextareaControl,
	ToggleControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { megaphone } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Divider, Grid, Modal, SectionHeader } from '../../../../../../packages/components/src';
import WizardsTab from '../../../../wizards-tab';

const DISCLOSURE = __(
	'Enabling Contextual Prompts lets editors generate donation call-to-action copy for their stories using AI. When used, the content of the post is sent to a third-party AI provider to draft suggestions. It is retained by the provider for up to 30 days for abuse monitoring, is not used to train AI models, and never appears in other AI products. Every suggestion is a draft an editor reviews and approves — nothing is ever published automatically.',
	'newspack-plugin'
);

const CONFIRMATION = __(
	'Some newsrooms have policies or union agreements that restrict the use of AI. By enabling this, you confirm your organization permits it. Only administrators can change this setting, and you can turn it off at any time.',
	'newspack-plugin'
);

// The override's enable toggle gates its whole section: copy and CTA fields
// only show while the override is on. The CTA choice is only sent for sites
// with native Newspack donations; without it the CTA is always a button, so
// the button fields follow the enable toggle alone.
const OVERRIDE_ENABLED_KEY = 'newspack_contextual_prompts_override_enabled';
const OVERRIDE_CTA_KEY = 'newspack_contextual_prompts_override_cta';
const OVERRIDE_BUTTON_KEYS = [ 'newspack_contextual_prompts_override_label', 'newspack_contextual_prompts_override_url' ];

const ContextualPromptsSettings = ( { status, values, error, inFlight, onSetValue, onEnable } ) => {
	const [ modalOpen, setModalOpen ] = useState( false );
	const { enabled, can_manage: canManage, fields } = status;

	const errorNotice = error && (
		<Notice status="error" isDismissible={ false }>
			{ error.message }
		</Notice>
	);

	// Empty state: the feature is off. Admins can opt in via the disclosure modal.
	if ( ! enabled ) {
		return (
			<div
				style={ {
					margin: '0 auto',
					maxWidth: 'calc(var(--newspack-wizard-section-space) * 2 + var(--newspack-wizard-section-width))',
					padding: '0 var(--newspack-wizard-section-space) 0',
				} }
			>
				<Grid columns={ 4 } noMargin>
					<VStack start={ 2 } end={ 4 } spacing={ 8 }>
						{ errorNotice }
						<SectionHeader
							icon={ megaphone }
							title={ __( 'Get started with Contextual Prompts', 'newspack-plugin' ) }
							description={ __(
								'Let editors generate story-specific donation prompts with AI. Approved copy appears in the story as a Contextual Prompt block, pairing a tailored message with your donation call to action.',
								'newspack-plugin'
							) }
							pageHeader
							noMargin
						/>
						<HStack alignment="center">
							{ canManage ? (
								<Button variant="primary" onClick={ () => setModalOpen( true ) }>
									{ __( 'Enable Contextual Prompts', 'newspack-plugin' ) }
								</Button>
							) : (
								<VStack alignment="center" spacing={ 2 }>
									<Button variant="primary" disabled>
										{ __( 'Enable Contextual Prompts', 'newspack-plugin' ) }
									</Button>
									<p style={ { margin: 0 } }>{ __( 'An administrator must enable this feature.', 'newspack-plugin' ) }</p>
								</VStack>
							) }
						</HStack>
					</VStack>
				</Grid>
				{ modalOpen && (
					<Modal
						title={ __( 'Enable Contextual Prompts?', 'newspack-plugin' ) }
						onRequestClose={ () => ! inFlight && setModalOpen( false ) }
					>
						<p>{ DISCLOSURE }</p>
						<Notice status="warning" isDismissible={ false }>
							{ CONFIRMATION }
						</Notice>
						<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
							<Button variant="secondary" onClick={ () => setModalOpen( false ) } disabled={ inFlight }>
								{ __( 'Cancel', 'newspack-plugin' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ () =>
									onEnable()
										.then( () => setModalOpen( false ) )
										.catch( () => {} )
								}
								disabled={ inFlight }
							>
								{ __( 'Enable', 'newspack-plugin' ) }
							</Button>
						</HStack>
					</Modal>
				) }
			</div>
		);
	}

	// Enabled: render the settings directly on the tab.
	const hasCtaToggle = ( fields || [] ).some( field => OVERRIDE_CTA_KEY === field.key );
	const effectiveCta = hasCtaToggle ? values[ OVERRIDE_CTA_KEY ] || 'form' : 'button';
	const overrideEnabled = !! values[ OVERRIDE_ENABLED_KEY ];

	// Fields are grouped by section server-side so the override controls can sit
	// under their own heading rather than trailing the publisher profile.
	const renderFields = section =>
		( fields || [] )
			.filter( field => ( field.section || 'profile' ) === section )
			// Until the override is on, only its enable toggle shows.
			.filter( field => 'override' !== ( field.section || 'profile' ) || OVERRIDE_ENABLED_KEY === field.key || overrideEnabled )
			// The button label/URL only apply when the override CTA is a button.
			.filter( field => 'button' === effectiveCta || ! OVERRIDE_BUTTON_KEYS.includes( field.key ) )
			.map( field => {
				if ( 'togglegroup' === field.type ) {
					return (
						<ToggleGroupControl
							key={ field.key }
							label={ field.label }
							help={ field.help }
							value={ values[ field.key ] || 'form' }
							onChange={ next => onSetValue( field.key, next ) }
							isBlock
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						>
							{ ( field.options || [] ).map( option => (
								<ToggleGroupControlOption key={ option.value } value={ option.value } label={ option.label } />
							) ) }
						</ToggleGroupControl>
					);
				}
				if ( 'toggle' === field.type ) {
					return (
						<ToggleControl
							key={ field.key }
							label={ field.label }
							help={ field.help }
							checked={ !! values[ field.key ] }
							onChange={ next => onSetValue( field.key, next ? '1' : '' ) }
							__nextHasNoMarginBottom
						/>
					);
				}
				if ( 'textarea' === field.type ) {
					return (
						<TextareaControl
							key={ field.key }
							label={ field.label }
							help={ field.help }
							value={ values[ field.key ] ?? '' }
							onChange={ value => onSetValue( field.key, value ) }
							__nextHasNoMarginBottom
						/>
					);
				}
				return (
					<TextControl
						key={ field.key }
						label={ field.label }
						help={ field.help }
						value={ values[ field.key ] ?? '' }
						onChange={ value => onSetValue( field.key, value ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				);
			} );

	return (
		<WizardsTab>
			{ errorNotice }
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					heading={ 2 }
					title={ __( 'Publisher Profile', 'newspack-plugin' ) }
					description={ __( 'Details used to tailor AI-generated Contextual Prompt copy to your newsroom.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 6 }>{ renderFields( 'profile' ) }</VStack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					heading={ 2 }
					title={ __( 'Site-Wide Override', 'newspack-plugin' ) }
					description={ __( 'Temporarily replace every Contextual Prompt with a single call to action.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 6 }>{ renderFields( 'override' ) }</VStack>
			</Grid>
		</WizardsTab>
	);
};

export default ContextualPromptsSettings;
