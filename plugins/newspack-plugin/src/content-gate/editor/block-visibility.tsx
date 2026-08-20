/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import {
	FormTokenField,
	PanelBody,
	PanelRow,
	TextControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import type { TokenItem } from '@wordpress/components/build-types/form-token-field/types.d.ts';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './block-visibility.scss';
import {
	formatAccessRuleOptionLabel,
	getAccessRuleOptionTokens,
	getAccessRuleTokenFieldMessages,
	getMissingOptionLabel,
	getUnlistedAccessRuleValuesNotice,
	hasUnlistedAccessRuleValues,
	isAccessRuleOptionInput,
	resolveAccessRuleOptionTokens,
} from '../access-rule-options';
import { getAccessRuleOptionSource } from '../access-rule-option-sources';
import OneTimePurchaseRuleControl from '../components/one-time-purchase-rule-control';

/**
 * Target block types that receive access control attributes.
 * Sourced from PHP (respects the newspack_content_gate_block_visibility_blocks filter)
 * with the default list as a fallback for environments where the script is loaded
 * before localisation runs.
 */
const TARGET_BLOCKS: string[] = window.newspackBlockVisibility?.target_blocks ?? [ 'core/group', 'core/stack', 'core/row' ];

/**
 * Register custom attributes on target block types.
 */
addFilter( 'blocks.registerBlockType', 'newspack-plugin/block-visibility/attributes', ( settings: BlockSettings, name: string ) => {
	if ( ! TARGET_BLOCKS.includes( name ) ) {
		return settings;
	}
	return {
		...settings,
		attributes: {
			...settings.attributes,
			newspackAccessControlVisibility: {
				type: 'string',
				default: 'visible',
			},
			newspackAccessControlMode: {
				type: 'string',
				default: 'gate',
			},
			newspackAccessControlGateIds: {
				type: 'array',
				default: [],
				items: { type: 'integer' },
			},
			newspackAccessControlRules: {
				type: 'object',
				default: {},
			},
		},
	};
} );

/**
 * Available access rules from localized data.
 */
const availableAccessRules: Record< string, AccessRuleConfig > = window.newspackBlockVisibility?.available_access_rules ?? {};

/**
 * Available gates from localized data.
 */
const availableGates: GateOption[] = window.newspackBlockVisibility?.available_gates ?? [];

/**
 * Whether any rules are currently active on the block.
 */
function hasActiveRules( rules: BlockVisibilityRules, mode: string, gateIds: number[] ): boolean {
	if ( 'gate' === mode ) {
		return gateIds.length > 0;
	}
	return !! rules?.registration?.active || !! rules?.custom_access?.active;
}

/** ToggleGroupControl for the two standard visibility options. */
const VisibilityControl = ( {
	label,
	help,
	value,
	onChange,
	disabled,
}: {
	label: string;
	help: string;
	value: string;
	onChange: ( value: string ) => void;
	disabled: boolean;
} ) => (
	<PanelRow>
		<ToggleGroupControl
			label={ label }
			help={ help }
			value={ value }
			onChange={ v => onChange( String( v ?? 'visible' ) ) }
			isBlock
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		>
			<ToggleGroupControlOption disabled={ disabled } value="visible" label={ __( 'Visible', 'newspack-plugin' ) } />
			<ToggleGroupControlOption disabled={ disabled } value="hidden" label={ __( 'Hidden', 'newspack-plugin' ) } />
		</ToggleGroupControl>
	</PanelRow>
);

/**
 * Gate selector: a FormTokenField that lets the editor link one or more gates.
 * A reader needs to satisfy any one of the selected gates' rules to match.
 */
const GateControls = ( { gateIds, onChange }: { gateIds: number[]; onChange: ( ids: number[] ) => void } ) => {
	// Gate titles are no more unique than product names, so tokens carry the gate ID for
	// the same reason the access-rule pickers do.
	const gateOptions = availableGates.map( g => ( { value: g.id, label: g.title } ) );

	return (
		<PanelRow>
			<FormTokenField
				label={ __( 'Gates', 'newspack-plugin' ) }
				value={ getAccessRuleOptionTokens( gateOptions, gateIds, getMissingOptionLabel( 'gate' ) ) }
				suggestions={ gateOptions.map( formatAccessRuleOptionLabel ) }
				onChange={ ( tokens: ( string | TokenItem )[] ) =>
					// The attribute is typed as integers, and a preserved value may come
					// back as the string the token carried, so coerce before storing.
					onChange( resolveAccessRuleOptionTokens( tokens, gateOptions, gateIds ).map( Number ) )
				}
				messages={ getAccessRuleTokenFieldMessages(
					__( 'Not a selectable gate. Pick one from the list, or type its ID.', 'newspack-plugin' )
				) }
				__experimentalValidateInput={ ( input: string ) => isAccessRuleOptionInput( input, gateOptions ) }
				__experimentalAutoSelectFirstMatch
				__experimentalExpandOnFocus
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</PanelRow>
	);
};

/**
 * Value control for a single access rule.
 * Renders FormTokenField for rules with options, TextControl for free-text rules.
 */
const AccessRuleValueControl = ( {
	slug,
	config,
	value,
	onChange,
}: {
	slug: string;
	config: AccessRuleConfig;
	value: ActiveRule[ 'value' ];
	onChange: ( value: ActiveRule[ 'value' ] ) => void;
} ) => {
	const staticOptions: AccessRuleOption[] = config.options ?? [];

	const [ options, setOptions ] = useState< AccessRuleOption[] >( staticOptions );

	useEffect( () => {
		const source = getAccessRuleOptionSource( slug );
		if ( ! source ) {
			return;
		}
		let cancelled = false;
		source()
			.then( fetched => {
				if ( ! cancelled ) {
					setOptions( fetched );
				}
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [ slug ] );

	if ( 'one_time_purchase' === slug ) {
		return <OneTimePurchaseRuleControl value={ value } onChange={ onChange } options={ options } productsLabel={ config.name } />;
	}

	if ( options.length > 0 ) {
		const selected = Array.isArray( value ) ? value : [];
		return (
			<>
				<FormTokenField
					label={ config.name }
					value={ getAccessRuleOptionTokens( options, selected, getMissingOptionLabel( slug ) ) }
					suggestions={ options.map( formatAccessRuleOptionLabel ) }
					onChange={ ( tokens: ( string | TokenItem )[] ) => onChange( resolveAccessRuleOptionTokens( tokens, options, selected ) ) }
					messages={ getAccessRuleTokenFieldMessages() }
					__experimentalValidateInput={ ( input: string ) => isAccessRuleOptionInput( input, options ) }
					__experimentalAutoSelectFirstMatch
					__experimentalExpandOnFocus
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ hasUnlistedAccessRuleValues( options, selected ) && (
					<p className="newspack-access-control-block-visibility-panel__notice">{ getUnlistedAccessRuleValuesNotice() }</p>
				) }
			</>
		);
	}

	return (
		<TextControl
			hideLabelFromVision
			label={ config.name }
			placeholder={ config.placeholder ?? '' }
			help={ __( 'Separate with commas.', 'newspack-plugin' ) }
			value={ typeof value === 'string' ? value : '' }
			onChange={ onChange as ( value: string ) => void }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
};

/** One toggle + value control per available access rule. */
const AccessRulesControls = ( { activeRules, onChange }: { activeRules: ActiveRule[]; onChange: ( rules: ActiveRule[] ) => void } ) => {
	const handleToggle = ( slug: string, defaultValue: ActiveRule[ 'value' ] ) => {
		const has = activeRules.some( r => r.slug === slug );
		if ( has ) {
			onChange( activeRules.filter( r => r.slug !== slug ) );
		} else {
			onChange( [ ...activeRules, { slug, value: defaultValue } ] );
		}
	};

	const handleValueChange = ( slug: string, value: ActiveRule[ 'value' ] ) => {
		onChange( activeRules.map( r => ( r.slug === slug ? { ...r, value } : r ) ) );
	};

	return (
		<>
			{ Object.entries( availableAccessRules ).map( ( [ slug, config ] ) => {
				const activeRule = activeRules.find( r => r.slug === slug );
				return (
					<PanelRow key={ slug }>
						<div>
							<ToggleControl
								label={ config.name }
								help={ config.description }
								checked={ !! activeRule }
								onChange={ () => handleToggle( slug, config.default ) }
							/>
							{ activeRule && ! config.is_boolean && (
								<AccessRuleValueControl
									slug={ slug }
									config={ config }
									value={ activeRule.value }
									onChange={ v => handleValueChange( slug, v ) }
								/>
							) }
						</div>
					</PanelRow>
				);
			} ) }
		</>
	);
};

/** Registration section: logged-in toggle + verification sub-toggle. */
const RegistrationControls = ( {
	registration,
	onChange,
}: {
	registration: RegistrationRule;
	onChange: ( registration: RegistrationRule ) => void;
} ) => (
	<>
		<PanelRow>
			<ToggleControl
				label={ __( 'Registered readers', 'newspack-plugin' ) }
				help={ __( 'Restrict to logged-in readers.', 'newspack-plugin' ) }
				checked={ !! registration.active }
				onChange={ active => onChange( { ...registration, active } ) }
			/>
		</PanelRow>
		<PanelRow>
			<ToggleControl
				label={ __( 'Require verification', 'newspack-plugin' ) }
				help={ __( 'Readers must verify their account to access.', 'newspack-plugin' ) }
				checked={ !! registration.require_verification }
				disabled={ ! registration.active }
				onChange={ require_verification => onChange( { ...registration, require_verification } ) }
			/>
		</PanelRow>
	</>
);

/**
 * Inspector panel for block access control.
 */
const BlockVisibilityPanel = ( { attributes, setAttributes }: BlockEditProps ) => {
	const rules: BlockVisibilityRules = attributes.newspackAccessControlRules ?? {};
	const visibility: string = attributes.newspackAccessControlVisibility ?? 'visible';
	const mode: string = attributes.newspackAccessControlMode ?? 'gate';
	const gateIds: number[] = attributes.newspackAccessControlGateIds ?? [];

	const registration: RegistrationRule = rules.registration ?? { active: false };
	const customAccess: CustomAccessRule = rules.custom_access ?? { active: false, access_rules: [] };
	// Flatten grouped OR rules for display: [[rule]] → [rule]
	const activeRules: ActiveRule[] = customAccess.access_rules.map( group => group[ 0 ] ).filter( Boolean );

	const rulesActive = hasActiveRules( rules, mode, gateIds );

	const updateRules = ( updates: Partial< BlockVisibilityRules > ) => {
		const newRules: BlockVisibilityRules = { ...rules, ...updates };
		const stillActive = hasActiveRules( newRules, mode, gateIds );
		setAttributes( {
			newspackAccessControlRules: newRules,
			// Reset visibility to 'visible' when all custom rules are cleared.
			...( ! stillActive ? { newspackAccessControlVisibility: 'visible' } : {} ),
		} );
	};

	const setRegistration = ( newRegistration: RegistrationRule ) => {
		updateRules( {
			registration: {
				...newRegistration,
				// Ensure require_verification is cleared when registration is turned off.
				require_verification: newRegistration.active ? newRegistration.require_verification : false,
			},
		} );
	};

	const setAccessRules = ( flatRules: ActiveRule[] ) => {
		const grouped: ActiveRule[][] = flatRules.map( rule => [ rule ] );
		updateRules( {
			custom_access: {
				...customAccess,
				active: grouped.length > 0,
				access_rules: grouped,
			},
		} );
	};

	return (
		<InspectorControls>
			<PanelBody
				className="newspack-access-control-block-visibility-panel"
				title={ __( 'Access Control', 'newspack-plugin' ) }
				initialOpen={ rulesActive }
			>
				{ /* Mode toggle: Gate (default) or Custom */ }
				<PanelRow>
					<ToggleGroupControl
						label={ __( 'Access mode', 'newspack-plugin' ) }
						help={ __( 'Control visibility of this block using gates or custom rules.', 'newspack-plugin' ) }
						value={ mode }
						onChange={ v => setAttributes( { newspackAccessControlMode: String( v ?? 'gate' ) } ) }
						isBlock
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="gate" label={ __( 'Gate', 'newspack-plugin' ) } />
						<ToggleGroupControlOption value="custom" label={ __( 'Custom', 'newspack-plugin' ) } />
					</ToggleGroupControl>
				</PanelRow>

				{ 'gate' === mode && (
					<GateControls
						gateIds={ gateIds }
						onChange={ ids => {
							setAttributes( {
								newspackAccessControlGateIds: ids,
								// Reset visibility when the last gate is removed.
								...( ids.length === 0 ? { newspackAccessControlVisibility: 'visible' } : {} ),
							} );
						} }
					/>
				) }

				{ 'custom' === mode && (
					<>
						{ /* Registration toggle */ }
						<RegistrationControls registration={ registration } onChange={ setRegistration } />

						{ /* Access rule toggles */ }
						<AccessRulesControls activeRules={ activeRules } onChange={ setAccessRules } />
					</>
				) }

				<VisibilityControl
					label={ __( 'Visibility', 'newspack-plugin' ) }
					help={
						'gate' === mode
							? __( 'Content visibility for readers who match any of the selected gates.', 'newspack-plugin' )
							: __( 'Content visibility for readers who match any of the selected rules.', 'newspack-plugin' )
					}
					value={ visibility }
					onChange={ ( v: string ) => setAttributes( { newspackAccessControlVisibility: v } ) }
					disabled={ ! rulesActive }
				/>
			</PanelBody>
		</InspectorControls>
	);
};

/**
 * Inject the Inspector panel into target block editors.
 */
addFilter(
	'editor.BlockEdit',
	'newspack-plugin/block-visibility/inspector',
	createHigherOrderComponent( BlockEdit => {
		const WithBlockVisibilityPanel = ( props: BlockEditProps ) => {
			if ( ! TARGET_BLOCKS.includes( props.name ) ) {
				return <BlockEdit { ...props } />;
			}
			return (
				<>
					<BlockEdit { ...props } />
					<BlockVisibilityPanel { ...props } />
				</>
			);
		};
		return WithBlockVisibilityPanel;
	}, 'withBlockVisibilityPanel' )
);
