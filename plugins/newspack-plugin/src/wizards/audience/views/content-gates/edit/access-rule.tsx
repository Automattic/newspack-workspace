/**
 * Access Gate component.
 */

/**
 * WordPress dependencies.
 */
import { CardBody, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import AccessRuleControl from './access-rule-control';

export default function AccessRule( { config, enabled, onToggle = () => {}, rule, slug, onChange }: GateAccessRuleProps ) {
	return (
		<CardBody size="small">
			<ToggleControl label={ config.name } help={ config.description } checked={ enabled } onChange={ () => onToggle( slug ) } />
			{ enabled && <AccessRuleControl slug={ slug } value={ rule?.value ?? config.default } onChange={ onChange } /> }
		</CardBody>
	);
}
