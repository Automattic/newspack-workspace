/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const emailError = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M20.25 11V7C20.25 6.30964 19.6904 5.75 19 5.75H5C4.30964 5.75 3.75 6.30964 3.75 7V17C3.75 17.6904 4.30964 18.25 5 18.25H17.5M3.75 8.3125L12 14.5L20.25 8.3125" />
		<Path d="M20.25 13V17M20.25 18.5V20" />
	</SVG>
);

export default emailError;
