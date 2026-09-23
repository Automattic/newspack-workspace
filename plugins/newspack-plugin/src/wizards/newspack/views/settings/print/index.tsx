/**
 * Newspack > Settings > Print
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, Notice, SelectControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../../wizards-tab';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';
import EmptyState from '../../../../../../packages/components/src/empty-state';
import { Button, Divider, Grid, SectionHeader, Waiting, useConfirmDialog, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { print } from '../../../../../../packages/icons';

const PLATFORM_OPTIONS: { label: string; value: IndesignPlatform }[] = [
	{ label: __( 'Windows (ASCII-WIN)', 'newspack-plugin' ), value: 'win' },
	{ label: __( 'Mac (ASCII-MAC)', 'newspack-plugin' ), value: 'mac' },
];

type PrintSettings = Pick< PrintData, 'indesign_platform' | 'indesign_post_types' | 'indesign_exclude_captions' >;

const toSettings = ( data: PrintData ): PrintSettings => ( {
	indesign_platform: data.indesign_platform,
	indesign_post_types: data.indesign_post_types,
	indesign_exclude_captions: data.indesign_exclude_captions,
} );

// Post-type order carries no meaning, so re-checking a box must not read as a change.
const comparable = ( value: PrintSettings ) => JSON.stringify( { ...value, indesign_post_types: [ ...value.indesign_post_types ].sort() } );

function Print() {
	const { apiData, hasLoaded, isFetching, apiFetchToggle, errorMessage, resetError } = useWizardApiFetchToggle< PrintData >( {
		path: '/newspack/v1/wizard/newspack-settings/print',
		apiNamespace: 'newspack-settings/print',
		data: {
			module_enabled_print: false,
			indesign_platform: 'win',
			indesign_post_types: [ 'post' ],
			available_post_types: [],
			indesign_exclude_captions: false,
		},
	} );
	const { setHeaderData, addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const isEnabled = apiData.module_enabled_print;

	const [ settings, setSettings ] = useState< PrintSettings >( toSettings( apiData ) );
	useEffect( () => {
		setSettings( toSettings( apiData ) );
	}, [ apiData ] );

	const isDirty = comparable( settings ) !== comparable( toSettings( apiData ) );

	// Failures reach the publisher through `errorMessage`, so the rejection the
	// API layer re-throws has no second consumer here.
	const setModuleEnabled = ( value: boolean ) => {
		resetError();
		return apiFetchToggle( { module_enabled_print: value }, true ).catch( () => undefined );
	};

	const saveSettings = () => {
		resetError();
		return apiFetchToggle( { module_enabled_print: true, ...settings }, true )
			.then( () => {
				removeNotice( 'print-saved' );
				addNotice( {
					id: 'print-saved',
					type: 'success',
					message: __( 'Settings saved.', 'newspack-plugin' ),
				} );
			} )
			.catch( () => undefined );
	};

	const togglePostType = ( slug: string, checked: boolean ) =>
		setSettings( current => ( {
			...current,
			indesign_post_types: checked ? [ ...current.indesign_post_types, slug ] : current.indesign_post_types.filter( type => type !== slug ),
		} ) );

	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( { when: isDirty && ! isFetching } );
	const { confirmDialog: disableDialog, requestConfirm: requestDisable } = useConfirmDialog( {
		title: __( 'Disable InDesign export?', 'newspack-plugin' ),
		confirmButtonText: __( 'Disable', 'newspack-plugin' ),
		message: isDirty
			? __(
					'The export actions will no longer appear on post lists. Your saved settings are kept, but unsaved changes will be lost.',
					'newspack-plugin'
			  )
			: __( 'The export actions will no longer appear on post lists. Your settings are kept.', 'newspack-plugin' ),
	} );

	// Each swap unmounts whatever held focus, which would otherwise strand the
	// keyboard user at the top of the document.
	const bodyRef = useRef< HTMLDivElement >( null );
	const focusedFor = useRef< boolean | null >( null );
	useEffect( () => {
		if ( ! hasLoaded ) {
			return;
		}
		if ( focusedFor.current === null || focusedFor.current === isEnabled ) {
			focusedFor.current = isEnabled;
			return;
		}
		focusedFor.current = isEnabled;
		bodyRef.current?.focus();
	}, [ hasLoaded, isEnabled ] );

	// The header keeps whichever callbacks it was handed, so publishing these
	// directly would pin the state of the render that published them.
	const actionHandlers = useRef( { saveSettings, setModuleEnabled } );
	actionHandlers.current = { saveSettings, setModuleEnabled };

	useEffect( () => {
		if ( ! isEnabled ) {
			setHeaderData( { actions: [] } );
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => actionHandlers.current.saveSettings(),
					disabled: isFetching || ! isDirty,
				},
				{
					type: 'more',
					label: __( 'Disable', 'newspack-plugin' ),
					/* translators: must contain the menu item's visible label, "Disable" (WCAG 2.5.3, Label in Name). */
					ariaLabel: __( 'Disable InDesign export', 'newspack-plugin' ),
					action: () => requestDisable( () => actionHandlers.current.setModuleEnabled( false ) ),
					disabled: isFetching,
				},
			],
		} );
	}, [ isEnabled, isDirty, isFetching, requestDisable, setHeaderData ] );

	if ( ! hasLoaded ) {
		return (
			<WizardsTab>
				{ navBlockDialog }
				<Waiting isCenter />
			</WizardsTab>
		);
	}

	const errorNotice = errorMessage && (
		<Notice status="error" isDismissible={ false } politeness="polite">
			{ errorMessage }
		</Notice>
	);

	if ( ! isEnabled ) {
		return (
			<WizardsTab
				ref={ bodyRef }
				tabIndex={ -1 }
				role="group"
				aria-label={ __( 'Adobe InDesign export', 'newspack-plugin' ) }
				isFetching={ isFetching }
			>
				{ navBlockDialog }
				{ errorNotice }
				<EmptyState.Root>
					<EmptyState.Header
						icon={ print }
						title={ __( 'Export articles to Adobe InDesign', 'newspack-plugin' ) }
						description={ __( 'Let editors export article content in Adobe InDesign Tagged Text format.', 'newspack-plugin' ) }
					/>
					<EmptyState.Actions>
						<Button
							variant="primary"
							accessibleWhenDisabled
							loading={ isFetching }
							disabled={ isFetching }
							onClick={ () => setModuleEnabled( true ) }
						>
							{ __( 'Enable', 'newspack-plugin' ) }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			</WizardsTab>
		);
	}

	return (
		<WizardsTab
			ref={ bodyRef }
			tabIndex={ -1 }
			role="group"
			aria-label={ __( 'Adobe InDesign export settings', 'newspack-plugin' ) }
			isFetching={ isFetching }
		>
			{ navBlockDialog }
			{ disableDialog }
			{ errorNotice }
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Header Platform', 'newspack-plugin' ) }
					description={ __(
						'Exports declare their format on the first line and end every line to match. Windows places correctly in most InDesign installs; if placed files show tags as literal text, switch to Mac.',
						'newspack-plugin'
					) }
				/>
				<Stack direction="column" gap="xl">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Platform', 'newspack-plugin' ) }
						value={ settings.indesign_platform }
						disabled={ isFetching }
						options={ PLATFORM_OPTIONS }
						onChange={ ( value: IndesignPlatform ) => setSettings( current => ( { ...current, indesign_platform: value } ) ) }
					/>
				</Stack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Available Post Types', 'newspack-plugin' ) }
					description={ __(
						'Choose which post types show the "Export as Adobe InDesign" bulk and row actions on their admin list screens.',
						'newspack-plugin'
					) }
				/>
				<Stack direction="column" gap="xl">
					<Stack direction="column" gap="xs">
						{ apiData.available_post_types.map( option => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ option.value }
								label={ option.label }
								checked={ settings.indesign_post_types.includes( option.value ) }
								disabled={ isFetching }
								onChange={ ( checked: boolean ) => togglePostType( option.value, checked ) }
							/>
						) ) }
					</Stack>
					{ settings.indesign_post_types.length === 0 && (
						<Notice status="warning" isDismissible={ false } spokenMessage="">
							{ __(
								'No post types are selected. The "Export as Adobe InDesign" actions will not appear anywhere until you select at least one.',
								'newspack-plugin'
							) }
						</Notice>
					) }
				</Stack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Photo Captions and Credits', 'newspack-plugin' ) }
					description={ __(
						'Photo captions and credits are appended to the end of each export. Enable this to leave them out.',
						'newspack-plugin'
					) }
				/>
				<Stack direction="column" gap="xl">
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Exclude photo captions and credits', 'newspack-plugin' ) }
						checked={ settings.indesign_exclude_captions }
						disabled={ isFetching }
						onChange={ ( checked: boolean ) => setSettings( current => ( { ...current, indesign_exclude_captions: checked } ) ) }
					/>
				</Stack>
			</Grid>
		</WizardsTab>
	);
}

export default Print;
