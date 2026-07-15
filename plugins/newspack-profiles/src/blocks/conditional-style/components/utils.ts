import type { ColorStyle } from './types';

export const DEFAULT_TEXT_COLOR = '#000000';
export const DEFAULT_BACKGROUND_COLOR = '#f3f4f6';

export const normalizeColorStyle = (
	colorValue: ColorStyle | undefined
): ColorStyle => {
	if ( typeof colorValue === 'undefined' ) {
		return {
			textColor: DEFAULT_TEXT_COLOR,
			backgroundColor: DEFAULT_BACKGROUND_COLOR,
		};
	}

	return {
		textColor: colorValue.textColor || DEFAULT_TEXT_COLOR,
		backgroundColor: colorValue.backgroundColor || DEFAULT_BACKGROUND_COLOR,
	};
};

export const getColorValue = ( value: unknown, fallbackColor: string ) => {
	if ( typeof value === 'string' ) {
		return value;
	}

	if ( typeof value === 'object' && value !== null ) {
		const colorObject = value as Record< string, unknown >;

		if ( typeof colorObject.hex === 'string' ) {
			return colorObject.hex;
		}
	}

	return fallbackColor;
};
