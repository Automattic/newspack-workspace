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
 * Every prop beyond the documented ones is forwarded to the `hr` element.
 *
 * @param {import('react').ComponentPropsWithoutRef<'hr'> & {
 *   alignment?: string,
 *   marginBottom?: number|string,
 *   marginTop?: number|string,
 *   variant?: string,
 * }} props - Component props: the documented options plus any `hr` attribute.
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
