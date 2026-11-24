/**
 * WordPress dependencies
 */
import { SVG, Circle, Path } from '@wordpress/primitives';

const broadcast = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M12 12.75V19.25M9.87868 7.62868C8.70711 8.80025 8.70711 10.6997 9.87868 11.8713M14.1213 7.62868C15.2929 8.80025 15.2929 10.6997 14.1213 11.8713M7.75736 5.50736C5.41421 7.85051 5.41421 11.6495 7.75736 13.9926M16.2426 5.50736C18.5858 7.85051 18.5858 11.6495 16.2426 13.9926" />
		<Circle cx="12" cy="9.75" r="1.25" fill="currentColor" stroke="none" />
	</SVG>
);

export default broadcast;
