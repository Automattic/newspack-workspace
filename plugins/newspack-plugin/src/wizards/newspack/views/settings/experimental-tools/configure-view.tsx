/**
 * Configure view for an experimental tool.
 * Replaces the tab content; the Settings nav tabs remain visible.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Fragment, useState, useEffect, useRef } from '@wordpress/element';
import { TextareaControl, TextControl, SelectControl, ToggleControl, Spinner, Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { Stack, Text } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../../wizards-tab';
import { CollapsibleGroup, Divider, Grid, SectionHeader, useConfirmDialog, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import type { SaveNotice, Tool, ToolField } from './types';

interface LogEntry {
	datetime: string;
	response_time: number;
	settings: {
		model: string;
		max_tokens: number;
		temperature: number;
	};
	prompt: string;
	response: string;
}

function LogsField( { field }: { field: ToolField } ) {
	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		if ( field.endpoint ) {
			apiFetch< LogEntry[] >( { path: field.endpoint } )
				.then( setLogs )
				.catch( () => setLogs( [] ) )
				.finally( () => setIsLoading( false ) );
		}
	}, [ field.endpoint ] );

	if ( isLoading ) {
		return <Spinner />;
	}

	if ( logs.length === 0 ) {
		return (
			<Text variant="body-md" render={ <p /> }>
				{ __( 'No requests logged yet.', 'newspack-plugin' ) }
			</Text>
		);
	}

	return (
		<CollapsibleGroup titleLevel={ 3 }>
			{ logs.map( ( log, index ) => (
				<CollapsibleGroup.Item
					key={ index }
					title={ sprintf(
						/* translators: 1: request date and time, 2: model name, 3: response time in seconds. */
						__( '%1$s · %2$s · %3$ss', 'newspack-plugin' ),
						new Date( log.datetime.replace( ' ', 'T' ) + 'Z' ).toLocaleString(),
						log.settings?.model ?? 'unknown',
						String( log.response_time )
					) }
				>
					<Stack direction="column" gap="lg">
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Prompt', 'newspack-plugin' ) }
							value={ log.prompt }
							readOnly
							onChange={ () => {} }
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Response', 'newspack-plugin' ) }
							value={ log.response }
							readOnly
							onChange={ () => {} }
						/>
					</Stack>
				</CollapsibleGroup.Item>
			) ) }
		</CollapsibleGroup>
	);
}

function FieldRenderer( {
	field,
	value,
	onChange,
	error,
}: {
	field: ToolField;
	value: string | number | boolean | undefined;
	onChange: ( val: string | boolean ) => void;
	error?: string;
} ) {
	const help = error ? <span style={ { color: '#cc1818' } }>{ error }</span> : field.help;

	switch ( field.type ) {
		case 'textarea':
			return <TextareaControl label={ field.label } help={ help } value={ String( value ?? '' ) } onChange={ onChange } />;
		case 'text':
			return <TextControl label={ field.label } help={ help } value={ String( value ?? '' ) } onChange={ onChange } />;
		case 'select':
			return (
				<SelectControl
					label={ field.label }
					help={ help }
					value={ String( value ?? '' ) }
					options={ field.options ?? [] }
					onChange={ onChange }
				/>
			);
		case 'toggle':
			return <ToggleControl label={ field.label } help={ help } checked={ !! value } onChange={ onChange } />;
		case 'display':
			return (
				<div className="experimental-tools__display-field">
					<strong>{ field.label }</strong>
					<span>{ String( field.value ?? '' ) }</span>
				</div>
			);
		default:
			return null;
	}
}

type Section = {
	key: string;
	title: string;
	backNav?: string;
	description?: React.ReactNode;
	content: React.ReactNode;
	isFullWidth?: boolean;
};

export default function ConfigureView( {
	tool,
	isFetching,
	tabLabel,
	tabUrl,
	onSave,
	onDisable,
}: {
	tool: Tool;
	isFetching?: boolean;
	tabLabel: string;
	tabUrl: string;
	onSave: ( fields: Record< string, string | boolean >, notice?: SaveNotice ) => Promise< unknown >;
	onDisable: () => Promise< unknown >;
} ) {
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const editableFields = tool.fields.filter( ( f: ToolField ) => f.type !== 'display' && f.type !== 'logs' );
	const displayFields = tool.fields.filter( ( f: ToolField ) => f.type === 'display' );
	const logsFields = tool.fields.filter( ( f: ToolField ) => f.type === 'logs' );

	const initialValues: Record< string, string | boolean > = {};
	editableFields.forEach( ( field: ToolField ) => {
		initialValues[ field.key ] = ( field.value as string | boolean ) ?? field.default ?? '';
	} );
	const [ values, setValues ] = useState< Record< string, string | boolean > >( initialValues );
	// Saving hands back the stored values, which become the new baseline.
	useEffect( () => {
		setValues( initialValues );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tool ] );
	const isDirty = JSON.stringify( values ) !== JSON.stringify( initialValues );

	const fieldsWithDefault = editableFields.filter( ( field: ToolField ) => field.default !== undefined );
	const isDefault = fieldsWithDefault.every( ( field: ToolField ) => String( values[ field.key ] ?? '' ) === field.default );
	const restoreDefaults = () => {
		const previous = initialValues;
		const defaults = Object.fromEntries( fieldsWithDefault.map( ( field: ToolField ) => [ field.key, field.default ?? '' ] ) );
		onSave(
			{ ...previous, ...defaults },
			{
				message: __( 'Restored to default.', 'newspack-plugin' ),
				actions: [
					{
						label: __( 'Undo', 'newspack-plugin' ),
						onClick: () => onSave( previous, { message: __( 'Restore undone.', 'newspack-plugin' ) } ).catch( () => undefined ),
					},
				],
			}
		).catch( () => undefined );
	};

	const [ errors, setErrors ] = useState< Record< string, string > >( {} );

	const handleChange = ( key: string, value: string | boolean ) => {
		setValues( prev => ( { ...prev, [ key ]: value } ) );
		setErrors( prev => {
			const next = { ...prev };
			delete next[ key ];
			return next;
		} );
	};

	const validate = (): boolean => {
		const newErrors: Record< string, string > = {};
		editableFields.forEach( ( field: ToolField ) => {
			const val = values[ field.key ];
			if ( ( field.validation === 'float' || field.validation === 'integer' ) && typeof val === 'string' && val !== '' ) {
				const num = Number( val );
				if ( isNaN( num ) || val.trim() === '' || ( field.validation === 'integer' && ! /^\d+$/.test( val ) ) ) {
					newErrors[ field.key ] =
						field.validation === 'integer'
							? __( 'Must be a whole number.', 'newspack-plugin' )
							: __( 'Must be a number.', 'newspack-plugin' );
				} else if ( field.min !== undefined && num < field.min ) {
					/* translators: %s: minimum allowed value. */
					newErrors[ field.key ] = sprintf( __( 'Minimum value is %s.', 'newspack-plugin' ), String( field.min ) );
				} else if ( field.max !== undefined && num > field.max ) {
					/* translators: %s: maximum allowed value. */
					newErrors[ field.key ] = sprintf( __( 'Maximum value is %s.', 'newspack-plugin' ), String( field.max ) );
				}
			}
		} );
		setErrors( newErrors );
		return Object.keys( newErrors ).length === 0;
	};

	// Failures surface through the wizard's error handling, so the rejection has no second consumer here.
	const handleSave = () => {
		if ( validate() ) {
			onSave( values ).catch( () => undefined );
		}
	};

	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( { when: isDirty && ! isFetching } );

	const { confirmDialog: disableDialog, requestConfirm: requestDisable } = useConfirmDialog( {
		/* translators: %s: tool name. */
		title: sprintf( __( 'Disable %s?', 'newspack-plugin' ), tool.label ),
		confirmButtonText: __( 'Disable', 'newspack-plugin' ),
		message: isDirty
			? __( 'Your saved settings are kept, but unsaved changes will be lost. You can enable the tool again at any time.', 'newspack-plugin' )
			: __( 'Your settings are kept. You can enable the tool again at any time.', 'newspack-plugin' ),
	} );

	const { confirmDialog: restoreDialog, requestConfirm: requestRestore } = useConfirmDialog( {
		title: __( 'Restore to Default?', 'newspack-plugin' ),
		confirmButtonText: __( 'Restore', 'newspack-plugin' ),
		message: isDirty
			? __( 'Your customizations are replaced with the defaults and saved. Other unsaved changes will be lost.', 'newspack-plugin' )
			: __( 'Your customizations are replaced with the defaults and saved.', 'newspack-plugin' ),
	} );

	// The header keeps whichever callbacks it was handed, so publishing these
	// directly would pin the state of the render that published them.
	const actionHandlers = useRef( { handleSave, onDisable, restoreDefaults } );
	actionHandlers.current = { handleSave, onDisable, restoreDefaults };

	useEffect( () => {
		setHeaderData( {
			sectionName: [ { label: tabLabel, url: tabUrl }, { label: tool.label } ],
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => actionHandlers.current.handleSave(),
					disabled: isFetching || ! isDirty,
				},
				...( fieldsWithDefault.length
					? [
							{
								type: 'more',
								label: __( 'Restore to Default', 'newspack-plugin' ),
								action: () => requestRestore( () => actionHandlers.current.restoreDefaults() ),
								disabled: isFetching || isDefault,
							},
					  ]
					: [] ),
				...( tool.constant_active
					? []
					: [
							{
								type: 'more',
								label: __( 'Disable', 'newspack-plugin' ),
								/* translators: %s: tool name. Must contain the menu item's visible label, "Disable" (WCAG 2.5.3, Label in Name). */
								ariaLabel: sprintf( __( 'Disable %s', 'newspack-plugin' ), tool.label ),
								action: () => requestDisable( () => actionHandlers.current.onDisable().catch( () => undefined ) ),
								disabled: isFetching,
							},
					  ] ),
			],
		} );
	}, [
		tabLabel,
		tabUrl,
		tool.label,
		tool.constant_active,
		isDirty,
		isFetching,
		isDefault,
		fieldsWithDefault.length,
		requestDisable,
		requestRestore,
		setHeaderData,
	] );

	const usageNote = tool.llm
		? sprintf(
				/* translators: 1: tool name, 2: usage count, 3: LLM model name. */
				__( '%1$s was used %2$s times in the last 30 days. Powered by %3$s.', 'newspack-plugin' ),
				tool.label,
				String( tool.usage_count ),
				tool.llm
		  )
		: sprintf(
				/* translators: 1: tool name, 2: usage count. */
				__( '%1$s was used %2$s times in the last 30 days.', 'newspack-plugin' ),
				tool.label,
				String( tool.usage_count )
		  );

	const sections: Section[] = [
		{
			key: 'settings',
			title: tool.label,
			backNav: tabUrl,
			description: tool.location_hint ? (
				<>
					{ tool.description } { tool.location_hint }
				</>
			) : (
				tool.description
			),
			content: (
				<>
					{ editableFields.map( ( field: ToolField ) => (
						<FieldRenderer
							key={ field.key }
							field={ field }
							value={ values[ field.key ] }
							onChange={ ( val: string | boolean ) => handleChange( field.key, val ) }
							error={ errors[ field.key ] }
						/>
					) ) }
					{ displayFields.map( ( field: ToolField ) => (
						<FieldRenderer key={ field.key } field={ field } value={ field.value } onChange={ () => {} } />
					) ) }
				</>
			),
		},
		...logsFields.map( ( field: ToolField ) => ( {
			key: field.key,
			title: field.label,
			description: field.help,
			content: <LogsField field={ field } />,
			isFullWidth: true,
		} ) ),
	];

	return (
		<WizardsTab isFetching={ isFetching }>
			{ navBlockDialog }
			{ disableDialog }
			{ restoreDialog }
			<Notice status="info" isDismissible={ false } spokenMessage="" className="experimental-tools__usage">
				{ usageNote }
			</Notice>
			{ sections.map( ( section, index ) => (
				<Fragment key={ section.key }>
					{ index > 0 && <Divider alignment="full-width" variant="tertiary" /> }
					{ section.isFullWidth ? (
						<Stack direction="column" gap="xl">
							<SectionHeader noMargin heading={ 2 } title={ section.title } description={ section.description } />
							{ section.content }
						</Stack>
					) : (
						<Grid columns={ 2 } gutter={ 32 } noMargin>
							<SectionHeader
								noMargin
								fullWidthText
								heading={ 2 }
								backNav={ section.backNav }
								title={ section.title }
								description={ section.description }
							/>
							<Stack direction="column" gap="xl">
								{ section.content }
							</Stack>
						</Grid>
					) }
				</Fragment>
			) ) }
		</WizardsTab>
	);
}
