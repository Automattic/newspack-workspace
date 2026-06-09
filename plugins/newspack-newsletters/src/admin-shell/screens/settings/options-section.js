import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	TextControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function OptionsSection( { title, options, schema, activeProvider, pendingValues, onChange, onSave, isDirty, isSaving, disabled } ) {
	const heading = title || __( 'Newsletter options', 'newspack-newsletters' );

	const resolveValue = key => {
		if ( pendingValues && Object.prototype.hasOwnProperty.call( pendingValues, key ) ) {
			return pendingValues[ key ];
		}
		return options?.[ key ];
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ heading }</h2>
				<Button variant="primary" onClick={ onSave } disabled={ ! isDirty || isSaving } isBusy={ isSaving }>
					{ __( 'Save', 'newspack-newsletters' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>
					{ ( schema || [] ).map( field => {
						if ( field.provider && field.provider !== activeProvider ) {
							return null;
						}
						const value = resolveValue( field.key );
						if ( field.type === 'checkbox' ) {
							return (
								<CheckboxControl
									key={ field.key }
									label={ field.label }
									checked={ !! value }
									onChange={ next => onChange( field.key, next ) }
									disabled={ disabled }
									__nextHasNoMarginBottom
								/>
							);
						}
						return (
							<TextControl
								key={ field.key }
								label={ field.label }
								value={ value ?? '' }
								placeholder={ field.placeholder || '' }
								help={
									field.help && field.help_url ? (
										<a href={ field.help_url } target="_blank" rel="noreferrer noopener">
											{ field.help }
										</a>
									) : (
										field.help || ''
									)
								}
								onChange={ next => onChange( field.key, next ) }
								disabled={ disabled }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						);
					} ) }
				</VStack>
			</CardBody>
		</Card>
	);
}
