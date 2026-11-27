/**
 * SplitSection
 */

/**
 * Internal dependencies
 */
import Content from './content';
import Header from './header';
import Root from './root';

export type { SplitSectionContentProps, SplitSectionHeaderProps, SplitSectionHeadingLevel, SplitSectionRootProps } from './types';

export const SplitSection = {
	Root,
	Header,
	Content,
};

Object.entries( SplitSection ).forEach( ( [ name, part ] ) => {
	( part as { displayName?: string } ).displayName = `SplitSection.${ name }`;
} );

export default SplitSection;
