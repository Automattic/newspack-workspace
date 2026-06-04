/**
 * TabNavigation
 *
 * Horizontal tab bar for the Insights wizard. Visibility per tab is
 * driven by props (computed at boot from feature detection — stubbed
 * all-on for now). Active tab highlighting per design spec.
 *
 * Implements the WAI-ARIA tabs pattern: roving tabindex (only the
 * active tab is in the tab order; others use tabIndex={-1}) and
 * arrow / Home / End keyboard navigation between tabs.
 *
 * Component owns no state — caller passes activeTab and onTabChange.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useRef } from '@wordpress/element';

/**
 * External dependencies
 */
import classnames from 'classnames';

export type TabKey = 'audience' | 'engagement' | 'conversion' | 'gates' | 'prompts' | 'subscribers' | 'donors' | 'advertising';

export interface TabDef {
	key: TabKey;
	label: string;
}

export const ALL_TABS: TabDef[] = [
	{ key: 'audience', label: __( 'Audience', 'newspack-plugin' ) },
	{ key: 'engagement', label: __( 'Engagement', 'newspack-plugin' ) },
	{ key: 'conversion', label: __( 'Conversion Journey', 'newspack-plugin' ) },
	{ key: 'gates', label: __( 'Gates', 'newspack-plugin' ) },
	{ key: 'prompts', label: __( 'Prompts', 'newspack-plugin' ) },
	{ key: 'subscribers', label: __( 'Subscribers', 'newspack-plugin' ) },
	{ key: 'donors', label: __( 'Donors', 'newspack-plugin' ) },
	{ key: 'advertising', label: __( 'Advertising', 'newspack-plugin' ) },
];

export type TabVisibility = Record< TabKey, boolean >;

export interface TabNavigationProps {
	activeTab: TabKey;
	visibility: TabVisibility;
	onTabChange: ( tab: TabKey ) => void;
	className?: string;
}

const TabNavigation = ( { activeTab, visibility, onTabChange, className }: TabNavigationProps ) => {
	const visibleTabs = ALL_TABS.filter( t => visibility[ t.key ] );
	const tabsRef = useRef< Record< TabKey, HTMLButtonElement | null > >( {} as Record< TabKey, HTMLButtonElement | null > );

	const focusTab = useCallback(
		( key: TabKey ) => {
			tabsRef.current[ key ]?.focus();
			onTabChange( key );
		},
		[ onTabChange ]
	);

	const handleKeyDown = useCallback(
		( e: React.KeyboardEvent< HTMLButtonElement >, index: number ) => {
			if ( visibleTabs.length === 0 ) {
				return;
			}
			let nextIndex: number | null = null;
			switch ( e.key ) {
				case 'ArrowRight':
					nextIndex = ( index + 1 ) % visibleTabs.length;
					break;
				case 'ArrowLeft':
					nextIndex = ( index - 1 + visibleTabs.length ) % visibleTabs.length;
					break;
				case 'Home':
					nextIndex = 0;
					break;
				case 'End':
					nextIndex = visibleTabs.length - 1;
					break;
				default:
					return;
			}
			e.preventDefault();
			focusTab( visibleTabs[ nextIndex ].key );
		},
		[ visibleTabs, focusTab ]
	);

	return (
		<div
			className={ classnames( 'newspack-insights__tabs', className ) }
			role="tablist"
			aria-label={ __( 'Insights sections', 'newspack-plugin' ) }
		>
			{ visibleTabs.map( ( tab, index ) => {
				const isActive = tab.key === activeTab;
				return (
					<button
						key={ tab.key }
						ref={ el => {
							tabsRef.current[ tab.key ] = el;
						} }
						type="button"
						role="tab"
						aria-selected={ isActive }
						aria-controls={ `newspack-insights-panel-${ tab.key }` }
						id={ `newspack-insights-tab-${ tab.key }` }
						tabIndex={ isActive ? 0 : -1 }
						className={ classnames( 'newspack-insights__tab', isActive && 'is-active' ) }
						onClick={ () => onTabChange( tab.key ) }
						onKeyDown={ e => handleKeyDown( e, index ) }
					>
						{ tab.label }
					</button>
				);
			} ) }
		</div>
	);
};

export default TabNavigation;
