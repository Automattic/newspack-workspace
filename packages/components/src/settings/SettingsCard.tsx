/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { Grid, ActionCard } from '../';

type SettingsCardProps = {
	children?: React.ReactNode;
	/** Additional CSS class name. */
	className?: string;
	/** Number of grid columns. */
	columns?: number;
	/** Grid gutter size. */
	gutter?: number;
	/** Whether to render the card without a border. */
	noBorder?: boolean;
	/** Grid row gap size. */
	rowGap?: number;
} & Omit< React.ComponentProps< typeof ActionCard >, 'children' | 'className' | 'notificationLevel' | 'noBorder' >;

const SettingsCard = ( { children, className, columns = 3, gutter = 32, noBorder, rowGap, ...props }: SettingsCardProps ) => {
	const classes = classnames( 'newspack-settings__card', noBorder && 'newspack-settings__no-border', className );

	return (
		<ActionCard { ...props } className={ classes } notificationLevel="info" noBorder={ noBorder }>
			<Grid columns={ columns } gutter={ gutter } rowGap={ rowGap }>
				{ children }
			</Grid>
		</ActionCard>
	);
};

export default SettingsCard;
