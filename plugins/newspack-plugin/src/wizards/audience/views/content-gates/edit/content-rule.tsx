/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { CardBody, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import ContentRuleControl from './content-rule-control';

export default function ContentRule( {
	config,
	enabled,
	onToggle = () => {},
	rule,
	slug,
	onChange,
	onChangeExclusion,
	isNewsletter = false,
}: GateContentRuleProps ) {
	return (
		<CardBody size="small">
			{ ! isNewsletter && (
				<ToggleControl label={ config.name } help={ config.description } checked={ enabled } onChange={ () => onToggle( slug ) } />
			) }
			{ ( isNewsletter || enabled ) && (
				<ContentRuleControl
					slug={ slug }
					exclusion={ rule?.exclusion ?? false }
					value={ rule?.value ?? config.default }
					onChange={ onChange }
					onChangeExclusion={ onChangeExclusion }
				/>
			) }
		</CardBody>
	);
}
