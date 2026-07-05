/**
 * Ads Settings Section.
 */

/**
 * WordPress dependencies
 */
import { Fragment, useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Grid, Notice, SelectControl, TextControl } from '../';
import './style.scss';

/**
 * A plugin setting's value, as returned by the settings API.
 */
export type PluginSettingValue = string | number | boolean | string[] | null;

/**
 * A single field of a plugin settings section.
 */
export interface PluginSettingField {
	/** The setting's key. Falsy for the section-info setting. */
	key?: string;
	/** The section the setting belongs to. */
	section?: string;
	/** The setting's type, e.g. 'boolean', 'int', 'string', 'password'. */
	type?: string;
	/** The setting's label. Holds the section title on the section-info setting. */
	description?: string;
	/** The setting's help text. Holds the section description on the section-info setting. */
	help?: string;
	/** The setting's value. */
	value?: PluginSettingValue;
	/** Options for settings rendered as a select control. */
	options?: { value: string; name?: string; label?: string }[] | null;
	/** Whether multiple values can be selected. */
	multiple?: boolean;
}

export type PluginSettingsSectionProps = {
	/** The section's key. */
	sectionKey: string;
	/** Whether the section is active. Null when the section does not support activation. */
	active?: boolean | null;
	/** The section's title. */
	title?: string;
	/** The section's description. */
	description?: string;
	/** The section's field settings. */
	fields: PluginSettingField[];
	/** Whether the section controls are disabled. */
	disabled?: boolean;
	/** Called with a setting key and its new value on any control change. */
	onChange: ( key: string | undefined, value: unknown ) => void;
	/** Called to persist the section, optionally with an explicit payload. */
	onUpdate: ( data?: Record< string, unknown > ) => void;
	/** Error to display in the section. */
	error?: { message?: string } | null;
	/** Whether the section header has a grey background. */
	hasGreyHeader?: boolean;
	/** ID for the section element. */
	id?: string;
};

const isSelectControl = ( setting: PluginSettingField ) => {
	return Array.isArray( setting.options ) && setting.options.length;
};
const getControlComponent = ( setting: PluginSettingField ): React.ElementType => {
	if ( isSelectControl( setting ) ) {
		return SelectControl;
	}
	switch ( setting.type ) {
		case 'checkbox':
		case 'boolean':
			return CheckboxControl;
		default:
			return TextControl;
	}
};
const getControlType = ( setting: PluginSettingField ) => {
	switch ( setting.type ) {
		case 'int':
		case 'integer':
		case 'float':
		case 'number':
			return 'number';
		case 'string':
		case 'text':
			return 'text';
		case 'password':
			return 'password';
		case 'boolean':
		case 'checkbox':
			return 'checkbox';
		default:
			return null;
	}
};

const SettingsSection = ( props: PluginSettingsSectionProps ) => {
	const [ saveDisabled, setSaveDisabled ] = useState( true );
	const { error, sectionKey, active, title, description, fields, disabled, onChange, onUpdate } = props;
	const getControlProps = ( setting: PluginSettingField ) => ( {
		disabled,
		name: `${ setting.section }_${ setting.key }`,
		type: getControlType( setting ),
		label: setting.description,
		help: setting.help || null,
		options:
			setting.options?.map( option => ( {
				value: option.value,
				label: option.name || option.label,
			} ) ) || null,
		value: setting.value,
		multiple: isSelectControl( setting ) && setting.multiple ? true : null,
		checked: setting.type === 'boolean' ? !! setting.value : null,
		onChange: ( value: unknown ) => {
			onChange( setting.key, value );
			setSaveDisabled( false );
		},
	} );
	const createFilter = ( name: string, defaultComponent: React.ReactNode = null ) => {
		return applyFilters( `newspack.settingSection.${ sectionKey }.${ name }`, defaultComponent, props ) as React.ReactNode;
	};
	let columns = 2;
	if ( fields.length % 3 === 0 ) {
		columns = 3;
	} else if ( fields.length === 1 ) {
		columns = 1;
	}
	return (
		<ActionCard
			id={ props.id }
			isMedium
			disabled={ disabled }
			title={ title }
			description={ description }
			toggleChecked={ active ?? undefined }
			hasGreyHeader={ active || null === active }
			toggleOnChange={ active !== null ? ( value?: boolean ) => onUpdate( { active: value } ) : undefined }
			actionContent={
				( active || null === active ) &&
				createFilter(
					'buttons',
					<Button
						variant="primary"
						disabled={ disabled || saveDisabled }
						onClick={ () => {
							onUpdate();
							setSaveDisabled( true );
						} }
					>
						{ __( 'Save Settings', 'newspack' ) }
					</Button>
				)
			}
		>
			{ ( active || active === null ) && (
				<Fragment>
					{ error?.message && <Notice noticeText={ error.message } isError /> }
					{ createFilter( 'beforeControls' ) }
					<Grid columns={ columns } gutter={ 32 }>
						{ fields.map( setting => {
							const Control = getControlComponent( setting ); // eslint-disable-line @wordpress/no-unused-vars-before-return, no-unused-vars
							return applyFilters(
								`newspack.settingsSection.${ sectionKey }.control`,
								<Control key={ setting.key } { ...getControlProps( setting ) } />,
								{ sectionKey, setting, onChange }
							) as React.ReactNode;
						} ) }
					</Grid>
				</Fragment>
			) }
		</ActionCard>
	);
};

export default SettingsSection;
