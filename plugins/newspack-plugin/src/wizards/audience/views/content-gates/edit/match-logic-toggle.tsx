/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CardBody, ToggleControl } from '@wordpress/components';

interface MatchLogicToggleProps {
	value: 'all' | 'any';
	ruleCount: number;
	onChange: ( value: 'all' | 'any' ) => void;
}

export default function MatchLogicToggle( { value, ruleCount, onChange }: MatchLogicToggleProps ) {
	const disabled = ruleCount < 2;
	const checked = value === 'any';

	return (
		<CardBody size="small">
			<ToggleControl
				label={ __( 'Match any rule', 'newspack-plugin' ) }
				help={ __( 'Restrict content that matches any one rule, instead of requiring all of them.', 'newspack-plugin' ) }
				checked={ checked }
				disabled={ disabled }
				onChange={ () => onChange( checked ? 'all' : 'any' ) }
			/>
		</CardBody>
	);
}
