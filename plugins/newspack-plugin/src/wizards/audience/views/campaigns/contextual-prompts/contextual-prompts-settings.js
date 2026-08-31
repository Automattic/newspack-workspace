/**
 * Contextual Prompts settings content.
 *
 * Presentational: the parent tab owns the fetched status/values and the header
 * Save/Disable actions, along with the style editing both theme kinds get from
 * the header. When the feature is off this renders an empty state with an admin
 * opt-in (AI-use disclosure modal); when on, the publisher-profile and
 * site-wide override sections in the branch's grid/divider layout.
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
import EmptyState from '../../../../../../packages/components/src/empty-state';
import WizardsTab from '../../../../wizards-tab';

const DISCLOSURE = __(
	'Story content is sent to a third-party AI provider, which retains it for up to 30 days for abuse monitoring and never uses it to train AI models. Every suggestion is a draft an editor reviews and approves; nothing is published automatically.',
	'newspack-plugin'
);

const CONFIRMATION = __(
	'Some newsrooms restrict AI use by policy or union agreement; by enabling this, you confirm your newsroom permits it. You can turn it off at any time.',
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

	// Empty state: the feature is off. Admins can opt in via the disclosure
	// modal. WizardsTab carries the wizard's content sizing.
	if ( ! enabled ) {
		return (
			<WizardsTab>
				{ /* Sibling, not a child: the error is about the settings request, so it takes
				     the tab's full width rather than the empty state's centred column. */ }
				{ errorNotice }
				<EmptyState.Root>
					<EmptyState.Header
						icon={ megaphone }
						title={ __( 'Get started with Contextual Prompts', 'newspack-plugin' ) }
						description={ __(
							'Let editors generate story-specific donation prompts with AI. Approved copy appears in the story as a Contextual Prompt, pairing a tailored message with your donation call to action.',
							'newspack-plugin'
						) }
					/>
					<EmptyState.Actions orientation="column">
						<Button variant="primary" disabled={ ! canManage } onClick={ () => setModalOpen( true ) }>
							{ __( 'Enable Contextual Prompts', 'newspack-plugin' ) }
						</Button>
						{ ! canManage && <p style={ { margin: 0 } }>{ __( 'An administrator must enable this feature.', 'newspack-plugin' ) }</p> }
					</EmptyState.Actions>
				</EmptyState.Root>
				{ modalOpen && (
					<Modal
						title={ __( 'Enable Contextual Prompts?', 'newspack-plugin' ) }
						onRequestClose={ () => ! inFlight && setModalOpen( false ) }
					>
						<VStack spacing={ 4 }>
							<Notice status="warning" isDismissible={ false } style={ { margin: 0 } }>
								{ CONFIRMATION }
							</Notice>
							<p style={ { margin: 0 } }>{ DISCLOSURE }</p>
						</VStack>
						<HStack justify="flex-end" spacing={ 2 } wrap className="newspack-modal__footer">
							<Button variant="tertiary" onClick={ () => setModalOpen( false ) } disabled={ inFlight } __next40pxDefaultSize>
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
								isBusy={ inFlight }
								__next40pxDefaultSize
							>
								{ __( 'Enable', 'newspack-plugin' ) }
							</Button>
						</HStack>
					</Modal>
				) }
			</WizardsTab>
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
							disabled={ inFlight }
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
							disabled={ inFlight }
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
							disabled={ inFlight }
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
						disabled={ inFlight }
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
