/**
 * Divider
 */

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Divider component.
 *
 * @param {Object}        props                - Component props.
 * @param {string}        [props.alignment]    - Horizontal alignment of the divider.
 * @param {string}        [props.className]    - Additional class name.
 * @param {number|string} [props.marginBottom] - Bottom margin, in pixels when numeric.
 * @param {number|string} [props.marginTop]    - Top margin, in pixels when numeric.
 * @param {string}        [props.variant]      - Visual variant of the divider.
 * @return {JSX.Element} Divider component.
 */
const Divider = ( { alignment = 'none', className = undefined, marginBottom = 64, marginTop = 64, variant = 'default', ...otherProps } ) => {
	const classes = classNames(
		'newspack-divider',
		className,
		alignment && `newspack-divider--alignment-${ alignment }`,
		variant && `newspack-divider--variant-${ variant }`
	);

	const style = {
		'--divider-margin-bottom': typeof marginBottom === 'number' ? `${ marginBottom }px` : marginBottom,
		'--divider-margin-top': typeof marginTop === 'number' ? `${ marginTop }px` : marginTop,
	};

	return <hr className={ classes } style={ style } { ...otherProps } />;
};

export default Divider;
