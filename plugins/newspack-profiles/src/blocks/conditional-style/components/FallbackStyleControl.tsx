import { Button, ColorIndicator } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { pencil } from '@wordpress/icons';
import type { ColorStyle } from './types';

type FallbackStyleControlProps = {
	fallbackStyle: ColorStyle;
	onEdit: () => void;
};

export const FallbackStyleControl = ( {
	fallbackStyle,
	onEdit,
}: FallbackStyleControlProps ) => (
	<div className="mb-3 rounded border border-gray-200 px-3 py-2">
		<div className="mb-2 flex items-center justify-between">
			<p className="text-xs font-medium text-gray-700">
				{ __( 'Fallback style (no match)', 'newspack-profiles' ) }
			</p>
			<Button
				variant="tertiary"
				icon={ pencil }
				label={ __( 'Edit fallback style', 'newspack-profiles' ) }
				size="small"
				onClick={ onEdit }
			/>
		</div>

		<div className="mb-1.5 flex items-center gap-2 text-xs text-gray-700">
			<span className="w-20 shrink-0">
				{ __( 'Text', 'newspack-profiles' ) }
			</span>
			<ColorIndicator colorValue={ fallbackStyle.textColor } />
			<span className="font-mono text-xs text-gray-700">
				{ fallbackStyle.textColor }
			</span>
		</div>

		<div className="flex items-center gap-2 text-xs text-gray-700">
			<span className="w-20 shrink-0">
				{ __( 'Background', 'newspack-profiles' ) }
			</span>
			<ColorIndicator colorValue={ fallbackStyle.backgroundColor } />
			<span className="font-mono text-xs text-gray-700">
				{ fallbackStyle.backgroundColor }
			</span>
		</div>
	</div>
);
