/**
 * Internal dependencies.
 */
import SectionHeader from '../section-header';
import { useEmptyStateContext } from './context';
import type { EmptyStateHeaderProps } from './types';

const Header = ( { icon, title, description, heading, className }: EmptyStateHeaderProps ) => {
	const { size } = useEmptyStateContext();

	// Heading level is a document-outline concern, so the size only sets a default.
	const level = heading ?? ( size === 'small' ? 3 : 2 );

	return (
		<SectionHeader
			className={ className }
			icon={ icon }
			title={ title }
			description={ description }
			heading={ level }
			size={ size }
			pageHeader
			noMargin
		/>
	);
};

export default Header;
