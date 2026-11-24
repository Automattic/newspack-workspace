/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const emailPremium = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M20.25 12V7C20.25 6.30964 19.6904 5.75 19 5.75H5C4.30964 5.75 3.75 6.30964 3.75 7V17C3.75 17.6904 4.30964 18.25 5 18.25H13.5M20.25 8.5L12 14.5L3.75 8.5" />
		<Path d="M21 16.35C21 15.75 20.28 14.95 19 14.95M19 14.95C16.504 14.95 16.449 17.75 19 17.75C21.551 17.75 21.496 20.55 19 20.55M19 14.95L19 14.15M17 19.15C17 19.75 17.72 20.55 19 20.55M19 20.55V21.35" strokeLinecap="round" />
	</SVG>
);

export default emailPremium;
