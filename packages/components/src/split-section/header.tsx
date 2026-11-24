/**
 * WordPress dependencies
 */
import { Stack } from '@wordpress/ui';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import SectionHeader from '../section-header';
import type { SplitSectionHeaderProps } from './types';

export default function Header( { title, description, heading = 2, id, backNav, className, children, ...props }: SplitSectionHeaderProps ) {
	return (
		<Stack { ...props } direction="column" gap="xl" className={ classnames( 'newspack-split-section__header', className ) }>
			<SectionHeader heading={ heading } noMargin fullWidthText title={ title } description={ description } id={ id } backNav={ backNav } />
			{ children }
		</Stack>
	);
}
