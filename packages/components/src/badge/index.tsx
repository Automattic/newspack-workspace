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
};

/**
 * Badge component
 */
const Badge = ( { text, level = 'default' }: BadgeProps ) => {
	const classes = classnames( 'newspack-badge', `is-${ level }` );
	return <span className={ classes }>{ text }</span>;
};

export default Badge;
