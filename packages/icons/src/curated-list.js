/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const curatedList = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M4.625 3.75H16.375A0.875 0.875 0 0 1 17.25 4.625V16.375A0.875 0.875 0 0 1 16.375 17.25H4.625A0.875 0.875 0 0 1 3.75 16.375V4.625A0.875 0.875 0 0 1 4.625 3.75ZM21 8V19A2 2 0 0 1 19 21H6" />
		<Path fill="currentColor" stroke="none" d="M5.5 7.5H7.5V9.5H5.5V7.5ZM5.5 13.5H7.5V11.5H5.5V13.5ZM8.5 9.5H15.5V7.5H8.5V9.5ZM8.5 13.5H15.5V11.5H8.5V13.5Z" />
	</SVG>
);

export default curatedList;
