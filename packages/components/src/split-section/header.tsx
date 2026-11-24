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

export default function Header( { title, description, heading = 2, id, className, children }: SplitSectionHeaderProps ) {
	return (
		<Stack direction="column" gap="lg" className={ classnames( 'newspack-split-section__header', className ) }>
			<SectionHeader heading={ heading } noMargin title={ title } description={ description } id={ id } />
			{ children }
		</Stack>
	);
}
