/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const ballotBox = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M8 8.75H5.5A1.5 1.5 0 0 0 4 10.25V18.25A1.5 1.5 0 0 0 5.5 19.75H18.5A1.5 1.5 0 0 0 20 18.25V10.25A1.5 1.5 0 0 0 18.5 8.75H16M4 15.75H20M6.25 12.25H17.75M8 12.25V5.75A1.5 1.5 0 0 1 9.5 4.25H14.5A1.5 1.5 0 0 1 16 5.75V12.25M9.97 7.72L11.5 9.25L14.03 6.72" />
	</SVG>
);

export default ballotBox;
