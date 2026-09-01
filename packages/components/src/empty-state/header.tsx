/**
 * WordPress dependencies.
 */
import { Icon } from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useEmptyStateContext } from './context';
import type { EmptyStateHeaderProps } from './types';

const Header = ( { icon, title, description, heading, className }: EmptyStateHeaderProps ) => {
	const { size } = useEmptyStateContext();
	const isSmall = size === 'small';

	// Heading level is a document-outline concern, so the size only sets a default.
	const level = heading ?? ( isSmall ? 3 : 2 );
	const HeadingTag = `h${ level }` as keyof JSX.IntrinsicElements;

	// Two stacks so each gap belongs to exactly one of them: the inner one spaces the
	// icon from the title, the outer one the title from the description.
	return (
		<Stack
			direction="column"
			align="center"
			gap="sm"
			className={ classnames( 'newspack-empty-state__header', isSmall && 'newspack-empty-state__header--small', className ) }
		>
			<Stack direction="column" align="center" gap={ isSmall ? 'md' : 'lg' }>
				{ icon && (
					<div className="newspack-empty-state__icon">
						<Icon icon={ icon } size={ isSmall ? 24 : 48 } />
					</div>
				) }
				<HeadingTag className="newspack-empty-state__title">{ title }</HeadingTag>
			</Stack>
			{ description && <p className="newspack-empty-state__description">{ description }</p> }
		</Stack>
	);
};

export default Header;
