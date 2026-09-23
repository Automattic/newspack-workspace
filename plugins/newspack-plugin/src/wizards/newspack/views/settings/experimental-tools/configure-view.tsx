/**
 * Configure view for an experimental tool.
 * Replaces the tab content; the Settings nav tabs remain visible.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
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
						log.settings?.model ?? __( 'Unknown model', 'newspack-plugin' ),
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
	inputRef,
}: {
	field: ToolField;
	value: string | number | boolean | undefined;
	onChange: ( val: string | boolean ) => void;
	error?: string;
	inputRef?: React.Ref< HTMLInputElement | HTMLTextAreaElement >;
} ) {
	const help = error ? <span style={ { color: '#cc1818' } }>{ error }</span> : field.help;

	switch ( field.type ) {
		case 'textarea':
			return (
				<TextareaControl
					__nextHasNoMarginBottom
					ref={ inputRef as React.Ref< HTMLTextAreaElement > }
					aria-invalid={ !! error }
					label={ field.label }
					help={ help }
					value={ String( value ?? '' ) }
					onChange={ onChange }
				/>
			);
		case 'text':
			return (
				<TextControl
					__nextHasNoMarginBottom
					ref={ inputRef as React.Ref< HTMLInputElement > }
					aria-invalid={ !! error }
					label={ field.label }
					help={ help }
					value={ String( value ?? '' ) }
					onChange={ onChange }
				/>
			);
		case 'select':
			return (
				<SelectControl
					__nextHasNoMarginBottom
					label={ field.label }
					help={ help }
					value={ String( value ?? '' ) }
					options={ field.options ?? [] }
					onChange={ onChange }
				/>
			);
		case 'toggle':
			return <ToggleControl __nextHasNoMarginBottom label={ field.label } help={ help } checked={ !! value } onChange={ onChange } />;
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
	errorNotice,
	tabUrl,
	onSave,
	onDisable,
}: {
	tool: Tool;
	isFetching?: boolean;
	errorNotice?: React.ReactNode;
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
	const pendingSave = useRef< { submitted: Record< string, string | boolean >; baseline: Record< string, string | boolean > } | null >( null );
	// Undo runs from a snackbar long after the render that created it, so the baseline is read at call time.
	const baseline = useRef( initialValues );
	baseline.current = initialValues;
	const save = ( fields: Record< string, string | boolean >, notice?: SaveNotice ) => {
		pendingSave.current = { submitted: fields, baseline: baseline.current };
		return onSave( fields, notice );
	};
	// Saving hands back the stored values, which become the new baseline. A field edited after the save started, such as
	// between Restore to Default and its Undo, is neither the submitted nor the prior value, and keeps the edit.
	useEffect( () => {
		const pending = pendingSave.current;
		pendingSave.current = null;
		setValues( current =>
			pending
				? Object.fromEntries(
						Object.entries( initialValues ).map( ( [ key, stored ] ) => {
							const untouched = current[ key ] === pending.submitted[ key ] || current[ key ] === pending.baseline[ key ];
							return [ key, untouched ? stored : current[ key ] ];
						} )
				  )
				: initialValues
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tool ] );
	const isDirty = JSON.stringify( values ) !== JSON.stringify( initialValues );

	const fieldsWithDefault = editableFields.filter( ( field: ToolField ) => field.default !== undefined );
	const isDefault = fieldsWithDefault.every( ( field: ToolField ) => {
		const value = String( initialValues[ field.key ] ?? '' );
		if ( field.validation && value.trim() !== '' ) {
			return Number( value ) === Number( field.default );
		}
		return value === field.default;
	} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const restoreDefaults = () => {
		const previous = initialValues;
		const defaults = Object.fromEntries( fieldsWithDefault.map( ( field: ToolField ) => [ field.key, field.default ?? '' ] ) );
		const restored = { ...previous, ...defaults };
		setValues( restored );
		setErrors( {} );
		save( restored, {
			message: __( 'Restored to default.', 'newspack-plugin' ),
			actions: [
				{
					label: __( 'Undo', 'newspack-plugin' ),
					onClick: () => save( previous, { message: __( 'Restore undone.', 'newspack-plugin' ) } ).catch( () => undefined ),
				},
			],
		} ).catch( () => undefined );
	};

	const [ failedSaveCount, setFailedSaveCount ] = useState( 0 );
	const fieldRefs = useRef< Record< string, HTMLInputElement | HTMLTextAreaElement | null > >( {} );

	// Save sits in the page header, far from the fields, so a failed check moves focus to the first field it rejected.
	useEffect( () => {
		if ( ! failedSaveCount ) {
			return;
		}
		const firstInvalid = editableFields.find( ( field: ToolField ) => errors[ field.key ] );
		if ( firstInvalid ) {
			fieldRefs.current[ firstInvalid.key ]?.focus();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ failedSaveCount ] );

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

	// Failures surface through `errorNotice`, so the rejection has no second consumer here.
	const handleSave = () => {
		if ( validate() ) {
			save( values ).catch( () => undefined );
		} else {
			setFailedSaveCount( count => count + 1 );
		}
	};

	// Disabling leaves this screen once it succeeds, so the guard stands down before the request, not after it.
	const [ isDisabling, setIsDisabling ] = useState( false );
	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( { when: isDirty && ! isFetching && ! isDisabling } );

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

	const disable = () => {
		setIsDisabling( true );
		onDisable().catch( () => setIsDisabling( false ) );
	};
	// The header keeps whichever callbacks it was handed, so publishing these
	// directly would pin the state of the render that published them.
	const actionHandlers = useRef( { handleSave, disable, restoreDefaults } );
	actionHandlers.current = { handleSave, disable, restoreDefaults };

	useEffect( () => {
		setHeaderData( {
			sectionName: tool.label,
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
								action: () => requestDisable( () => actionHandlers.current.disable() ),
								disabled: isFetching,
							},
					  ] ),
			],
		} );
	}, [
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
				_n(
					'%1$s was used %2$s time in the last 30 days. Powered by %3$s.',
					'%1$s was used %2$s times in the last 30 days. Powered by %3$s.',
					tool.usage_count,
					'newspack-plugin'
				),
				tool.label,
				String( tool.usage_count ),
				tool.llm
		  )
		: sprintf(
				/* translators: 1: tool name, 2: usage count. */
				_n(
					'%1$s was used %2$s time in the last 30 days.',
					'%1$s was used %2$s times in the last 30 days.',
					tool.usage_count,
					'newspack-plugin'
				),
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
							inputRef={ element => {
								fieldRefs.current[ field.key ] = element;
							} }
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
			{ errorNotice }
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
