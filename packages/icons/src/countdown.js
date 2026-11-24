/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const countdown = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M3.75 7C3.75 6.30964 4.30964 5.75 5 5.75H19C19.6904 5.75 20.25 6.30964 20.25 7V17C20.25 17.6904 19.6904 18.25 19 18.25H5C4.30964 18.25 3.75 17.6904 3.75 17V7Z" />
		<Path fill="currentColor" stroke="none" d="M16.602 9.4H17.626V15H16.786L14.226 11.16V15H13.202V9.4H14.21L16.602 13.016V9.4ZM11.291 9.4H12.291L10.147 15.68H9.147L11.29 9.4H11.291ZM7.15 9.4H7.934V15H6.91V10.848C6.80429 10.9156 6.69325 10.9744 6.578 11.024C6.45891 11.076 6.33402 11.1136 6.206 11.136L6.118 10.272C6.25527 10.2381 6.38534 10.1799 6.502 10.1C6.643 10.01 6.774 9.903 6.894 9.78C7.014 9.655 7.099 9.528 7.15 9.4Z" />
	</SVG>
);

export default countdown;
