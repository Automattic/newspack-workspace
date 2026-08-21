/**
 * Internal dependencies
 */
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

export type BadgeLevel = 'default' | 'info' | 'success' | 'warning' | 'error';

type BadgeProps = {
	text: string;
	level?: BadgeLevel;
} & Omit< React.ComponentPropsWithoutRef< 'span' >, 'children' | 'dangerouslySetInnerHTML' >;

/**
 * Badge component
 */
const Badge = ( { text, level = 'default', className, ...props }: BadgeProps ) => {
	const classes = classnames( 'newspack-badge', `is-${ level }`, className );
	return (
		<span { ...props } className={ classes }>
			{ text }
		</span>
	);
};

export default Badge;
