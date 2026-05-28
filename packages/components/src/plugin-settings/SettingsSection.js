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

const isSelectControl = setting => {
	return Array.isArray( setting.options ) && setting.options.length;
};
const getControlComponent = setting => {
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
const getControlType = setting => {
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

const SettingsSection = props => {
	const [ saveDisabled, setSaveDisabled ] = useState( true );
	const { error, sectionKey, active, title, description, fields, disabled, onChange, onUpdate } = props;
	const getControlProps = setting => ( {
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
		onChange: value => {
			onChange( setting.key, value );
			setSaveDisabled( false );
		},
	} );
	const createFilter = ( name, defaultComponent = null ) => {
		return applyFilters( `newspack.settingSection.${ sectionKey }.${ name }`, defaultComponent, props );
	};
	let columns = 2;
	if ( fields.length % 3 === 0 ) {
		columns = 3;
	} else if ( fields.length === 1 ) {
		columns = 1;
	}
	return (
		<ActionCard
			id={ props.id ?? null }
			isMedium
			disabled={ disabled }
			title={ title }
			description={ description }
			toggleChecked={ active }
			hasGreyHeader={ active || null === active }
			toggleOnChange={ active !== null ? value => onUpdate( { active: value } ) : null }
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
							);
						} ) }
					</Grid>
				</Fragment>
			) }
		</ActionCard>
	);
};

export default SettingsSection;
