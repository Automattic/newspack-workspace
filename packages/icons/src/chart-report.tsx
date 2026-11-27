/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const chartReport = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M4.75 6C4.75 5.30964 5.30964 4.75 6 4.75H18C18.6904 4.75 19.25 5.30964 19.25 6V15C19.25 15.6904 18.6904 16.25 18 16.25H6C5.30964 16.25 4.75 15.6904 4.75 15V6Z" />
		<Path d="M10.5 16.25L8.75 19.25M13.5 16.25L15.25 19.25" strokeLinecap="round" />
		<Path d="M8.75 13V9.75M12 13V8M15.251 13V11" />
	</SVG>
);

export default chartReport;
