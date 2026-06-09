/**
 * ComparisonToggle
 *
 * "Compare to previous period" checkbox. Uses @wordpress/components
 * CheckboxControl per Newspack admin convention (built-in label
 * association, focus ring, and a11y semantics — the previous raw
 * <input type="checkbox"> had no styled focus state).
 *
 * Component owns no state — caller wires it to useComparisonMode.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

export interface ComparisonToggleProps {
	enabled: boolean;
	onChange: ( v: boolean ) => void;
	className?: string;
}

const ComparisonToggle = ( { enabled, onChange, className }: ComparisonToggleProps ) => (
	<div className={ className ?? 'newspack-insights__comparison-toggle' }>
		<CheckboxControl
			__nextHasNoMarginBottom
			label={ __( 'Compare to previous period', 'newspack-plugin' ) }
			checked={ enabled }
			onChange={ onChange }
		/>
	</div>
);

export default ComparisonToggle;
