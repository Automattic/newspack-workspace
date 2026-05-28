/**
 * Inline configure view for an experimental tool.
 * Replaces the tab content; the Settings nav tabs remain visible.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { TextareaControl, TextControl, SelectControl, ToggleControl, Spinner } from '@wordpress/components';
import { Icon, chevronLeft, chevronDown, chevronUp } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { Button } from '../../../../../../packages/components/src';
import type { Tool, ToolField } from './types';

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
	const [ expandedIndex, setExpandedIndex ] = useState< number | null >( null );

	useEffect( () => {
		if ( field.endpoint ) {
			apiFetch< LogEntry[] >( { path: field.endpoint } )
				.then( setLogs )
				.catch( () => setLogs( [] ) )
				.finally( () => setIsLoading( false ) );
		}
	}, [ field.endpoint ] );

	if ( isLoading ) {
		return (
			<div className="experimental-tools__logs-field">
				<strong>{ field.label }</strong>
				<Spinner />
			</div>
		);
	}

	if ( logs.length === 0 ) {
		return (
			<div className="experimental-tools__logs-field">
				<strong>{ field.label }</strong>
				<p className="experimental-tools__logs-empty">{ __( 'No requests logged yet.', 'newspack-plugin' ) }</p>
			</div>
		);
	}

	return (
		<div className="experimental-tools__logs-field">
			<strong>{ field.label }</strong>
			{ field.help && <p className="experimental-tools__logs-help">{ field.help }</p> }
			<div className="experimental-tools__logs-list">
				{ logs.map( ( log, index ) => {
					const isExpanded = expandedIndex === index;
					const date = new Date( log.datetime.replace( ' ', 'T' ) + 'Z' );
					const formattedDate = date.toLocaleString();
					return (
						<div key={ index } className="experimental-tools__log-entry">
							<button
								type="button"
								className="experimental-tools__log-header"
								onClick={ () => setExpandedIndex( isExpanded ? null : index ) }
								aria-expanded={ isExpanded }
							>
								<span className="experimental-tools__log-date">{ formattedDate }</span>
								<span className="experimental-tools__log-meta">
									{ sprintf(
										/* translators: 1: model name, 2: response time in seconds. */
										__( '%1$s · %2$ss', 'newspack-plugin' ),
										log.settings?.model ?? 'unknown',
										log.response_time
									) }
								</span>
								<Icon icon={ isExpanded ? chevronUp : chevronDown } />
							</button>
							{ isExpanded && (
								<div className="experimental-tools__log-details">
									<div className="experimental-tools__log-section">
										<strong>{ __( 'Prompt', 'newspack-plugin' ) }</strong>
										<pre>{ log.prompt }</pre>
									</div>
									<div className="experimental-tools__log-section">
										<strong>{ __( 'Response', 'newspack-plugin' ) }</strong>
										<pre>{ log.response }</pre>
									</div>
								</div>
							) }
						</div>
					);
				} ) }
			</div>
		</div>
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
	const stringValue = String( value ?? '' );
	const hasDefault = field.default !== undefined && field.default !== '';
	const isModified = hasDefault && stringValue !== field.default;

	const handleRestore = () => {
		if (
			// eslint-disable-next-line no-alert
			window.confirm(
				__( 'Are you sure you want to restore this field to its default value? Your current customizations will be lost.', 'newspack-plugin' )
			)
		) {
			onChange( field.default ?? '' );
		}
	};

	const restoreButton = hasDefault ? (
		<Button variant="link" className="experimental-tools__restore-default" onClick={ handleRestore } disabled={ ! isModified }>
			{ __( 'Restore to default', 'newspack-plugin' ) }
		</Button>
	) : null;

	const getHelp = () => {
		if ( error ) {
			return <span style={ { color: '#cc1818' } }>{ error }</span>;
		}
		if ( restoreButton && field.help ) {
			return (
				<span className="experimental-tools__help-with-restore">
					{ field.help } { restoreButton }
				</span>
			);
		}
		return field.help;
	};

	const help = error ? <span style={ { color: '#cc1818' } }>{ error }</span> : field.help;

	switch ( field.type ) {
		case 'textarea':
			return <TextareaControl label={ field.label } help={ getHelp() } value={ stringValue } onChange={ onChange } />;
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

export default function ConfigureView( {
	tool,
	isFetching,
	onSave,
	onBack,
}: {
	tool: Tool;
	isFetching?: boolean;
	onSave: ( fields: Record< string, string | boolean > ) => void;
	onBack: () => void;
} ) {
	const editableFields = tool.fields.filter( ( f: ToolField ) => f.type !== 'display' && f.type !== 'logs' );
	const displayFields = tool.fields.filter( ( f: ToolField ) => f.type === 'display' );
	const logsFields = tool.fields.filter( ( f: ToolField ) => f.type === 'logs' );

	const initialValues: Record< string, string | boolean > = {};
	editableFields.forEach( ( field: ToolField ) => {
		initialValues[ field.key ] = ( field.value as string | boolean ) ?? field.default ?? '';
	} );
	const [ values, setValues ] = useState< Record< string, string | boolean > >( initialValues );

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

	const handleSave = () => {
		if ( validate() ) {
			onSave( values );
		}
	};

	return (
		<form
			className={ `newspack-wizard__sections experimental-tools__configure${ isFetching ? ' is-fetching' : '' }` }
			onSubmit={ ( e: React.FormEvent ) => {
				e.preventDefault();
				handleSave();
			} }
		>
			<div className="experimental-tools__configure-header">
				<Button icon={ chevronLeft } label={ __( 'Back', 'newspack-plugin' ) } onClick={ onBack } isLink />
				<h1>{ tool.label }</h1>
			</div>
			<p className="newspack-wizard__sections__description">{ tool.description }</p>

			<div className="experimental-tools__configure-fields">
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
			</div>

			<Button variant="primary" type="submit" disabled={ isFetching }>
				{ __( 'Save', 'newspack-plugin' ) }
			</Button>

			{ logsFields.map( ( field: ToolField ) => (
				<LogsField key={ field.key } field={ field } />
			) ) }

			<p className="experimental-tools__usage-note">
				{ tool.llm
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
					  ) }
			</p>
		</form>
	);
}
