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
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	Notice,
	Spinner,
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

// The control test's enable toggle gates its section the same way the override does.
const CONTROL_ENABLED_KEY = 'newspack_contextual_prompts_control_enabled';
const CONTROL_INTERVAL_KEY = 'newspack_contextual_prompts_control_interval';

// How long to wait after the interval last changed before asking the server
// for a fresh preview, so typing a new value doesn't fire a request per digit.
const PREVIEW_DEBOUNCE_MS = 400;

/**
 * The published stories that will show the control copy at the current
 * interval, so an admin can see the effect of a value before saving it.
 * Renders nothing while the control test is off.
 *
 * @param {Object}  props          Component props.
 * @param {boolean} props.enabled  Whether the control test is on.
 * @param {number}  props.interval Every Nth story.
 */
const ControlPreview = ( { enabled, interval } ) => {
	const [ loading, setLoading ] = useState( true );
	const [ posts, setPosts ] = useState( [] );
	const [ limit, setLimit ] = useState( 10 );

	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		setLoading( true );
		const timeout = setTimeout( () => {
			apiFetch( { path: addQueryArgs( '/newspack-popups/v1/contextual-prompt/control-preview', { interval } ) } )
				.then( response => {
					setPosts( response.posts || [] );
					setLimit( response.limit || 10 );
				} )
				.catch( () => {
					setPosts( [] );
				} )
				.finally( () => setLoading( false ) );
		}, PREVIEW_DEBOUNCE_MS );
		return () => clearTimeout( timeout );
	}, [ enabled, interval ] );

	if ( ! enabled ) {
		return null;
	}

	return (
		<div>
			<p style={ { margin: '0 0 8px', fontWeight: 600 } }>{ __( 'Stories that will show the control copy', 'newspack-plugin' ) }</p>
			{ loading && <Spinner /> }
			{ ! loading && ! posts.length && (
				<p style={ { margin: 0 } }>{ __( 'No published stories with a Contextual Prompt match this interval yet.', 'newspack-plugin' ) }</p>
			) }
			{ ! loading && posts.length > 0 && (
				<ul style={ { margin: 0, paddingLeft: '1.2em' } }>
					{ posts.map( post => (
						<li key={ post.id }>
							<a href={ post.edit_link }>{ post.title || `#${ post.id }` }</a>
						</li>
					) ) }
				</ul>
			) }
			{ posts.length >= limit && (
				<p style={ { margin: '8px 0 0' } }>
					{ sprintf( /* translators: %d: row cap */ __( 'Showing the %d most recent.', 'newspack-plugin' ), limit ) }
				</p>
			) }
		</div>
	);
};

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
	const controlEnabled = !! values[ CONTROL_ENABLED_KEY ];
	// Until a gated section is on, only its enable toggle shows.
	const gatedSections = {
		override: [ OVERRIDE_ENABLED_KEY, overrideEnabled ],
		control: [ CONTROL_ENABLED_KEY, controlEnabled ],
	};

	// Fields are grouped by section server-side so the override and control
	// controls can sit under their own headings rather than trailing the
	// publisher profile.
	const renderFields = section =>
		( fields || [] )
			.filter( field => ( field.section || 'profile' ) === section )
			.filter( field => {
				const gate = gatedSections[ field.section || 'profile' ];
				return ! gate || gate[ 0 ] === field.key || gate[ 1 ];
			} )
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
				if ( 'number' === field.type ) {
					return (
						<TextControl
							key={ field.key }
							type="number"
							min={ 2 }
							max={ 20 }
							label={ field.label }
							help={ field.help }
							value={ values[ field.key ] ?? '' }
							onChange={ value => onSetValue( field.key, value ) }
							disabled={ inFlight }
							__next40pxDefaultSize
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
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					heading={ 2 }
					title={ __( 'Control Test', 'newspack-plugin' ) }
					description={ __(
						'Show a generic control ask on every Nth story to compare it against story-aware copy. The call to action stays the same. If the site-wide override is on, it takes precedence and the test pauses.',
						'newspack-plugin'
					) }
					noMargin
				/>
				<VStack spacing={ 6 }>
					{ renderFields( 'control' ) }
					<ControlPreview enabled={ controlEnabled } interval={ Number( values[ CONTROL_INTERVAL_KEY ] ) || 3 } />
				</VStack>
			</Grid>
		</WizardsTab>
	);
};

export default ContextualPromptsSettings;
