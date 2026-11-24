/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const ballotBox = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M4.75 12.25V18C4.75 18.6904 5.30964 19.25 6 19.25H18C18.6904 19.25 19.25 18.6904 19.25 18V12.25H4.75ZM4.75 15.75H19.25M8.25 12.25V6C8.25 5.30964 8.80964 4.75 9.5 4.75H14.5C15.1904 4.75 15.75 5.30964 15.75 6V12.25M10.25 8.25L11.5 9.5L13.75 7.25" />
	</SVG>
);

export default ballotBox;
