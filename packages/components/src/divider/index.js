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
