import { isRTL } from '@wordpress/i18n';
import { Icon, chevronLeft, chevronRight } from '@wordpress/icons';
import { Stack, Text } from '@wordpress/ui';

type ActionRowProps = {
	id: string;
	title: string;
	description: string;
	icon: JSX.Element;
};

/**
 * ARIA wiring for the element that renders an ActionRow, naming it by the row's title and describing it by its description.
 *
 * @param id The id passed to the ActionRow.
 */
export const actionRowLabels = ( id: string ) => ( {
	'aria-labelledby': `${ id }-title`,
	'aria-describedby': `${ id }-description`,
} );

export default function ActionRow( { id, title, description, icon }: ActionRowProps ) {
	return (
		<Stack align="center" gap="lg" render={ <span /> } className="setup-action-row__inner">
			<span className="setup-action-row__icon">
				<Icon icon={ icon } />
			</span>
			<Stack direction="column" gap="sm" className="setup-action-row__text" render={ <span /> }>
				<Text variant="heading-lg" className="setup-action-row__title" id={ `${ id }-title` }>
					{ title }
				</Text>
				<Text variant="body-md" className="setup-action-row__description setup-muted" id={ `${ id }-description` }>
					{ description }
				</Text>
			</Stack>
			<Icon icon={ isRTL() ? chevronLeft : chevronRight } className="setup-action-row__indicator" />
		</Stack>
	);
}
