/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const iframe = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M5 3.75H19A1.25 1.25 0 0 1 20.25 5V19A1.25 1.25 0 0 1 19 20.25H5A1.25 1.25 0 0 1 3.75 19V5A1.25 1.25 0 0 1 5 3.75ZM10 9L7 12L10.05 15.3M14 9L17 12L14.05 15.3" />
		<Path fill="currentColor" stroke="none" d="M4 4H9.643L4 10.364Z" />
	</SVG>
);

export default iframe;
