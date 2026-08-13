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
	/** Lets a control reference this badge, e.g. via aria-describedby. */
	id?: string;
};

/**
 * Badge component
 */
const Badge = ( { text, level = 'default', id }: BadgeProps ) => {
	const classes = classnames( 'newspack-badge', `is-${ level }` );
	return (
		<span className={ classes } id={ id }>
			{ text }
		</span>
	);
};

export default Badge;
