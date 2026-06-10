import { Button, ColorIndicator } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { pencil, trash } from '@wordpress/icons';
import type { ColorStyle } from './types';

type StyleRowProps = {
	value: string;
	colorStyle: ColorStyle;
	onEdit: ( value: string ) => void;
	onRemove: ( value: string ) => void;
};

export const StyleRow = ( {
	value,
	colorStyle,
	onEdit,
	onRemove,
}: StyleRowProps ) => (
	<div className="flex items-center justify-between gap-3 border-b border-gray-200 px-3 py-2 last:border-b-0">
		<div className="min-w-0 flex-1 space-y-1">
			<div className="truncate text-sm font-medium">{ value }</div>

			<div className="flex items-center gap-2 text-xs text-gray-700">
				<span className="w-20 shrink-0">
					{ __( 'Text', 'newspack-profiles' ) }
				</span>
				<ColorIndicator
					colorValue={ colorStyle.textColor }
					className="shrink-0"
				/>
				<span className="font-mono text-xs text-gray-700">
					{ colorStyle.textColor }
				</span>
			</div>

			<div className="flex items-center gap-2 text-xs text-gray-700">
				<span className="w-20 shrink-0">
					{ __( 'Background', 'newspack-profiles' ) }
				</span>
				<ColorIndicator
					colorValue={ colorStyle.backgroundColor }
					className="shrink-0"
				/>
				<span className="font-mono text-xs text-gray-700">
					{ colorStyle.backgroundColor }
				</span>
			</div>
		</div>

		<div className="shrink-0 self-start flex items-center gap-1">
			<Button
				variant="tertiary"
				icon={ pencil }
				label={ __( 'Edit style', 'newspack-profiles' ) }
				size="small"
				onClick={ () => onEdit( value ) }
			/>
			<Button
				variant="tertiary"
				icon={ trash }
				label={ __( 'Remove style', 'newspack-profiles' ) }
				isDestructive
				size="small"
				onClick={ () => onRemove( value ) }
			/>
		</div>
	</div>
);
