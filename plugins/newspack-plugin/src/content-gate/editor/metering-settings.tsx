/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, SelectControl, TextControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';

/** The gate CPT meta consumed by this panel. */
type MeteringMeta = {
	metering: boolean;
	metering_anonymous_count: number | string;
	metering_registered_count: number | string;
	metering_period: 'day' | 'week' | 'month';
};

function MeteringSettings() {
	const { meta } = useSelect( select => {
		// The editor selectors are untyped for string-keyed stores; assert at the store boundary.
		const { getEditedPostAttribute } = select( 'core/editor' ) as {
			getEditedPostAttribute: ( attribute: string ) => unknown;
		};
		return {
			meta: getEditedPostAttribute( 'meta' ) as MeteringMeta,
		};
	} );
	const { editPost } = useDispatch( 'core/editor' );
	return (
		<PluginDocumentSettingPanel
			name="content-gate-metering-panel"
			className="newspack-content-gate-panel"
			title={ __( 'Metering', 'newspack-plugin' ) }
		>
			<CheckboxControl
				label={ __( 'Enable metering', 'newspack-plugin' ) }
				checked={ meta.metering }
				onChange={ value => editPost( { meta: { metering: value } } ) }
				help={ __( 'Implement metering to configure access to restricted content before showing the gate.', 'newspack-plugin' ) }
			/>
			{ meta.metering && (
				<>
					<TextControl
						type="number"
						min="0"
						value={ meta.metering_anonymous_count }
						label={ __( 'Available views for anonymous readers', 'newspack-plugin' ) }
						onChange={ value => editPost( { meta: { metering_anonymous_count: value } } ) }
						help={ __(
							'Number of times an anonymous reader can view gated content. If set to 0, anonymous readers will always render the gate.',
							'newspack-plugin'
						) }
					/>
					<TextControl
						type="number"
						min="0"
						value={ meta.metering_registered_count }
						label={ __( 'Available views for registered readers', 'newspack-plugin' ) }
						onChange={ value => editPost( { meta: { metering_registered_count: value } } ) }
						help={ __(
							'Number of times a registered reader can view gated content. If set to 0, registered readers without membership plan will always render the gate.',
							'newspack-plugin'
						) }
					/>
					<SelectControl
						label={ __( 'Time period', 'newspack-plugin' ) }
						value={ meta.metering_period }
						options={ [
							{ value: 'day', label: __( 'Day', 'newspack-plugin' ) },
							{ value: 'week', label: __( 'Week', 'newspack-plugin' ) },
							{ value: 'month', label: __( 'Month', 'newspack-plugin' ) },
						] }
						onChange={ value => editPost( { meta: { metering_period: value } } ) }
						help={ __(
							'The time period during which the metering views will be counted. For example, if the metering period is set to a week, the metering views will be reset every week.',
							'newspack-plugin'
						) }
					/>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'newspack-content-gate-metering', {
	render: MeteringSettings,
	// An explicit falsy icon overrides registerPlugin's default plugins icon via object spread.
	icon: undefined,
} );
