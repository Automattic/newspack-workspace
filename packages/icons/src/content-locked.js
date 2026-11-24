/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const contentLocked = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M17.25 8V6C17.25 5.30964 16.6904 4.75 16 4.75H6C5.30964 4.75 4.75 5.30964 4.75 6V18C4.75 18.6904 5.30964 19.25 6 19.25H10.6M7.5 8.25H14.15M7.5 11.75H11.75M7.5 15.25H10.5M14 14V12C14 10.8954 14.8954 10 16 10C17.1046 10 18 10.8954 18 12V14" />
		<Path
			fill="currentColor"
			stroke="none"
			d="M13 14H19C19.5523 14 20 14.4477 20 15V19C20 19.5523 19.5523 20 19 20H13C12.4477 20 12 19.5523 12 19V15C12 14.4477 12.4477 14 13 14Z"
		/>
	</SVG>
);

export default contentLocked;
