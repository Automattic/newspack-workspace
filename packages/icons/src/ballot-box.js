/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const ballotBox = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M8.25 9.25H6C5.30964 9.25 4.75 9.80964 4.75 10.5V18C4.75 18.6904 5.30964 19.25 6 19.25H18C18.6904 19.25 19.25 18.6904 19.25 18V10.5C19.25 9.80964 18.6904 9.25 18 9.25H15.75M6.75 12.75H17.25M8.25 12.75V6C8.25 5.30964 8.80964 4.75 9.5 4.75H14.5C15.1904 4.75 15.75 5.30964 15.75 6V12.75M10.25 8.5L11.5 9.75L13.75 7.5" />
	</SVG>
);

export default ballotBox;
