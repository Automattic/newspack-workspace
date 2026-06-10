import { __ } from '@wordpress/i18n';
import { StyleRow } from './StyleRow';
import type { Styles } from './types';
import { normalizeColorStyle } from './utils';

type StyleListProps = {
	styles: Styles;
	onEdit: ( value: string ) => void;
	onRemove: ( value: string ) => void;
};

export const StyleList = ( { styles, onEdit, onRemove }: StyleListProps ) => {
	const colorEntries = Object.entries( styles );

	if ( colorEntries.length === 0 ) {
		return (
			<div className="mb-3 rounded border border-dashed border-gray-300 px-3 py-2 text-xs text-gray-600">
				{ __(
					'No value-specific styles yet. The fallback style will be used unless you add a matching value style.',
					'newspack-profiles'
				) }
			</div>
		);
	}

	return (
		<div className="mb-3 overflow-hidden rounded border border-gray-200">
			{ colorEntries.map( ( [ value, colorValue ] ) => (
				<StyleRow
					key={ value }
					value={ value }
					colorStyle={ normalizeColorStyle( colorValue ) }
					onEdit={ onEdit }
					onRemove={ onRemove }
				/>
			) ) }
		</div>
	);
};
