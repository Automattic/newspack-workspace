/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SVG, Path } from '@wordpress/primitives';

/**
 * Internal dependencies
 */
import { AVATAR_LEFT_TEMPLATE, AVATAR_RIGHT_TEMPLATE, CENTERED_TEMPLATE, COMPACT_TEMPLATE } from './templates';

const ICON_COLOR = colors[ 'primary-400' ];

// Variation icons: abstract layout representations.
// Circle represents the avatar, rectangles represent text lines.

const iconAvatarLeft = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
		<Path d="M31 33H17V32H31V33ZM39 30H17V29H39V30ZM37 27H17V26H37V27ZM41 24H17V23H41V24ZM11 15C13.2091 15 15 16.7909 15 19C15 21.2091 13.2091 23 11 23C8.79086 23 7 21.2091 7 19C7 16.7909 8.79086 15 11 15ZM37 21H17V20H37V21ZM41 18H17V15H41V18Z" />
	</SVG>
);

const iconAvatarRight = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
		<Path d="M21 33H7V32H21V33ZM29 30H7V29H29V30ZM27 27H7V26H27V27ZM31 24H7V23H31V24ZM37 15C39.2091 15 41 16.7909 41 19C41 21.2091 39.2091 23 37 23C34.7909 23 33 21.2091 33 19C33 16.7909 34.7909 15 37 15ZM27 21H7V20H27V21ZM31 18H7V15H31V18Z" />
	</SVG>
);

const iconCentered = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
		<Path d="M36 38H12V37H36V38ZM40 35H8V34H40V35ZM39 32H9V31H39V32ZM41 29H7V28H41V29ZM39 26H9V25H39V26ZM41 23H7V20H41V23ZM24 10C26.2091 10 27.9999 11.791 28 14C28 16.2091 26.2091 18 24 18C21.7909 18 20 16.2091 20 14C20.0001 11.791 21.7909 10 24 10Z" />
	</SVG>
);

const iconCompact = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
		<Path d="M31 33H7V32H31V33ZM39 30H7V29H39V30ZM37 27H7V26H37V27ZM41 24H7V23H41V24ZM37 21H7V20H37V21ZM41 18H7V15H41V18Z" />
	</SVG>
);

const variations = [
	{
		name: 'avatar-left',
		title: __( 'Avatar left', 'newspack-blocks' ),
		description: __( 'Avatar on the left, content on the right.', 'newspack-blocks' ),
		icon: { src: iconAvatarLeft, foreground: ICON_COLOR },
		attributes: { variation: 'avatar-left' },
		innerBlocks: AVATAR_LEFT_TEMPLATE,
		scope: [ 'block' ],
		isDefault: true,
	},
	{
		name: 'avatar-right',
		title: __( 'Avatar right', 'newspack-blocks' ),
		description: __( 'Content on the left, avatar on the right.', 'newspack-blocks' ),
		icon: { src: iconAvatarRight, foreground: ICON_COLOR },
		attributes: { variation: 'avatar-right' },
		innerBlocks: AVATAR_RIGHT_TEMPLATE,
		scope: [ 'block' ],
	},
	{
		name: 'centered',
		title: __( 'Centered', 'newspack-blocks' ),
		description: __( 'Large centered avatar with center-aligned text.', 'newspack-blocks' ),
		icon: { src: iconCentered, foreground: ICON_COLOR },
		attributes: { variation: 'centered' },
		innerBlocks: CENTERED_TEMPLATE,
		scope: [ 'block' ],
	},
	{
		name: 'compact',
		title: __( 'Compact', 'newspack-blocks' ),
		description: __( 'No avatar, vertical stack.', 'newspack-blocks' ),
		icon: { src: iconCompact, foreground: ICON_COLOR },
		attributes: { variation: 'compact' },
		innerBlocks: COMPACT_TEMPLATE,
		scope: [ 'block' ],
	},
];

export default variations;
