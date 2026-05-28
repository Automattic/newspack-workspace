/**
 * Box Contrast
 *
 * can be used to dynamically assign black or white color/background-color based on a hex color.
 * Black/white can be assigned to either text or background-color.
 */

/**
 * Dependencies
 */
import { getContrast } from '../utils/color';

/**
 * Box Contrast component
 *
 * @return JSX.Element
 */
const BoxContrast = ( {
	hexColor,
	isInverted = false,
	children,
	...props
}: {
	children: string | JSX.Element;
	hexColor: string;
	isInverted?: boolean;
	className?: string;
} ) => {
	let contrastColor;
	if ( hexColor === '#f0f0f0' ) {
		contrastColor = '#1e1e1e';
	} else {
		contrastColor = getContrast( hexColor );
	}
	const style = isInverted ? { color: hexColor, backgoundColor: contrastColor } : { backgroundColor: hexColor, color: contrastColor };

	return (
		<div { ...props } style={ style }>
			{ children }
		</div>
	);
};

export default BoxContrast;
