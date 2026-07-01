/**
 * RefreshMenu — the "Insights options" header kebab dropdown. Holds
 * "Refresh now", "Print / Save as PDF…" (NPPD-1661), and "Export JSON…".
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { DropdownMenu } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

export interface RefreshMenuProps {
	onRefresh: () => void;
	disabled: boolean;
	/** Trigger the per-tab PDF export (browser print). */
	onDownloadPdf: () => void;
	/** Disable the export while the tab is still loading its data. */
	downloadDisabled: boolean;
	/** Trigger the per-tab JSON export (Blob download). */
	onDownloadJson: () => void;
	/** Disable the JSON export while the tab is loading or has no data. */
	jsonDisabled: boolean;
}

const RefreshMenu = ( { onRefresh, disabled, onDownloadPdf, downloadDisabled, onDownloadJson, jsonDisabled }: RefreshMenuProps ) => (
	<DropdownMenu
		icon={ moreVertical }
		label={ __( 'Insights options', 'newspack-plugin' ) }
		className="newspack-insights__refresh-menu"
		controls={ [
			{
				title: __( 'Refresh now', 'newspack-plugin' ),
				onClick: onRefresh,
				isDisabled: disabled,
			},
			{
				title: __( 'Print / Save as PDF…', 'newspack-plugin' ),
				onClick: onDownloadPdf,
				isDisabled: downloadDisabled,
			},
			{
				title: __( 'Export JSON…', 'newspack-plugin' ),
				onClick: onDownloadJson,
				isDisabled: jsonDisabled,
			},
		] }
	/>
);

export default RefreshMenu;
