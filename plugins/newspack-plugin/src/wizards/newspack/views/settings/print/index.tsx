/**
 * Newspack > Settings > Print
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, Notice, SelectControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import WizardsActionCard from '../../../../wizards-action-card';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';

const PLATFORM_OPTIONS: { label: string; value: IndesignPlatform }[] = [
	{ label: __( 'Auto-detect (per export)', 'newspack-plugin' ), value: 'auto' },
	{ label: __( 'Mac', 'newspack-plugin' ), value: 'mac' },
	{ label: __( 'Windows', 'newspack-plugin' ), value: 'win' },
];

// Coalesce a rapid series of post-type checkbox clicks into a single save.
const POST_TYPES_SAVE_DEBOUNCE_MS = 500;

function Print() {
	const { description, apiData, isFetching, actionText, apiFetchToggle, errorMessage } = useWizardApiFetchToggle< PrintData >( {
		path: '/newspack/v1/wizard/newspack-settings/print',
		apiNamespace: 'newspack-settings/print',
		data: {
			module_enabled_print: false,
			indesign_platform: 'auto',
			indesign_post_types: [ 'post' ],
			available_post_types: [],
			indesign_exclude_captions: false,
		},
		description: __( 'Allows editors to export article content in Adobe InDesign Tagged Text format.', 'newspack-plugin' ),
	} );

	// Latest server-acknowledged settings, readable from timers and promise
	// callbacks without closing over a render-time snapshot.
	const apiDataRef = useRef( apiData );
	useEffect( () => {
		apiDataRef.current = apiData;
	}, [ apiData ] );

	// Optimistic mirror of the post-type selection so a checkbox flips on click
	// instead of waiting for the round trip. Kept in sync with the server value
	// on load and after every successful save.
	const [ selectedPostTypes, setSelectedPostTypes ] = useState< string[] >( apiData.indesign_post_types );
	useEffect( () => {
		setSelectedPostTypes( apiData.indesign_post_types );
	}, [ apiData.indesign_post_types ] );

	const saveTimer = useRef< ReturnType< typeof setTimeout > | undefined >();
	const pendingSave = useRef< ( () => void ) | undefined >();
	useEffect(
		() => () => {
			if ( saveTimer.current ) {
				clearTimeout( saveTimer.current );
			}
			// Flush rather than drop a pending save, so a change made within the
			// debounce window of leaving the view still persists.
			pendingSave.current?.();
		},
		[]
	);

	/**
	 * Persist a change. The payload carries only the module flag (which the
	 * endpoint requires) plus the explicitly changed fields — never a full
	 * snapshot — so a save can't write back other settings from a stale render
	 * or timer closure. The module flag is read at send time for the same
	 * reason. The response (always the full settings object) re-syncs state.
	 */
	const save = ( overrides: Partial< PrintData > ) =>
		apiFetchToggle( { module_enabled_print: apiDataRef.current.module_enabled_print, ...overrides }, true );

	const togglePostType = ( slug: string, checked: boolean ) => {
		const next = new Set( selectedPostTypes );
		if ( checked ) {
			next.add( slug );
		} else {
			next.delete( slug );
		}
		const nextPostTypes = Array.from( next );
		// Reflect the click immediately, then debounce the save. The API layer
		// dedupes concurrent requests to the same path, so firing one request per
		// click would drop all but the first and revert the boxes to that first
		// response. Debouncing sends a single request carrying the final selection.
		setSelectedPostTypes( nextPostTypes );
		if ( saveTimer.current ) {
			clearTimeout( saveTimer.current );
		}
		pendingSave.current = () => {
			pendingSave.current = undefined;
			// A failed save leaves `apiData` untouched, so the optimistic mirror
			// would keep showing a selection that never persisted — snap it back
			// to the last server-acknowledged value.
			save( { indesign_post_types: nextPostTypes } ).catch( () => setSelectedPostTypes( apiDataRef.current.indesign_post_types ) );
		};
		saveTimer.current = setTimeout( () => pendingSave.current?.(), POST_TYPES_SAVE_DEBOUNCE_MS );
	};

	return (
		<WizardsTab title={ __( 'Adobe InDesign', 'newspack-plugin' ) }>
			<WizardSection>
				<WizardsActionCard
					title={ __( 'Enable InDesign Export', 'newspack-plugin' ) }
					description={ description }
					disabled={ isFetching }
					actionText={ actionText }
					error={ errorMessage }
					toggleChecked={ apiData.module_enabled_print }
					toggleOnChange={ ( value: boolean ) => save( { module_enabled_print: value } ) }
				/>
			</WizardSection>
			{ apiData.module_enabled_print && (
				<>
					<WizardSection
						title={ __( 'Header platform', 'newspack-plugin' ) }
						description={ __(
							'InDesign requires the export file to declare its host platform on the first line. Choose "Auto-detect" to match the operating system of whoever clicks Export, or pick a specific platform if your team always lays out on the same OS.',
							'newspack-plugin'
						) }
					>
						<SelectControl
							label={ __( 'Platform', 'newspack-plugin' ) }
							value={ apiData.indesign_platform }
							disabled={ isFetching }
							options={ PLATFORM_OPTIONS }
							onChange={ ( value: IndesignPlatform ) => save( { indesign_platform: value } ) }
						/>
					</WizardSection>
					<WizardSection
						title={ __( 'Available post types', 'newspack-plugin' ) }
						description={ __(
							'Choose which post types show the "Export as Adobe InDesign" bulk and row actions on their admin list screens.',
							'newspack-plugin'
						) }
					>
						{ apiData.available_post_types.map( option => (
							<CheckboxControl
								key={ option.value }
								label={ option.label }
								checked={ selectedPostTypes.includes( option.value ) }
								disabled={ isFetching }
								onChange={ ( checked: boolean ) => togglePostType( option.value, checked ) }
							/>
						) ) }
						{ selectedPostTypes.length === 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'No post types are selected. The "Export as Adobe InDesign" actions will not appear anywhere until you select at least one.',
									'newspack-plugin'
								) }
							</Notice>
						) }
					</WizardSection>
					<WizardSection
						title={ __( 'Photo captions', 'newspack-plugin' ) }
						description={ __(
							'Photo captions are appended to the end of each export. Enable this to leave them out — photo credits are still included.',
							'newspack-plugin'
						) }
					>
						<CheckboxControl
							label={ __( 'Exclude photo captions', 'newspack-plugin' ) }
							checked={ apiData.indesign_exclude_captions }
							disabled={ isFetching }
							onChange={ ( checked: boolean ) => save( { indesign_exclude_captions: checked } ) }
						/>
					</WizardSection>
				</>
			) }
		</WizardsTab>
	);
}

export default Print;
