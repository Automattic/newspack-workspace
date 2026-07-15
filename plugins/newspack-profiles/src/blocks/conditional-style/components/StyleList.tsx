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
			<div className="wp-block-newspack-profiles-conditional-style__empty">
				{ __(
					'No value-specific styles yet. The fallback style will be used unless you add a matching value style.',
					'newspack-profiles'
				) }
			</div>
		);
	}

	return (
		<div className="wp-block-newspack-profiles-conditional-style__list">
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
