/**
 * Card Form component.
 *
 * A card with an expandable inline form — title, description, optional badge,
 * and an actions slot in the header. When `isOpen` is true, children are
 * rendered in the card body and the header border is removed for a seamless look.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { useEffect, useRef, createElement } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import Badge, { BadgeLevel } from '../badge';
import Card from '../card';
import './style.scss';

type HeadingLevel = 1 | 2 | 3 | 4 | 5 | 6;

type CardFormProps = {
	title: string;
	description?: string;
	badge?: {
		text: string;
		level?: BadgeLevel;
	};
	/** JSX rendered in the header action area (buttons, etc.). */
	actions?: React.ReactNode;
	/** When true, children are shown and the header border is removed. */
	isOpen?: boolean;
	/** Called when the user presses Escape while the form is open. */
	onRequestClose?: () => void;
	/** Heading level for the title. Defaults to 3. */
	titleLevel?: HeadingLevel;
	className?: string;
	children?: React.ReactNode;
};

const CardForm = ( { title, description, badge, actions, isOpen = false, onRequestClose, titleLevel = 3, className, children }: CardFormProps ) => {
	const bodyRef = useRef< HTMLDivElement | null >( null );
	const previousActiveRef = useRef< HTMLElement | null >( null );
	const instanceId = useInstanceId( CardForm, 'newspack-card-form' );
	const titleId = `${ instanceId }__title`;
	const bodyId = `${ instanceId }__body`;

	// Scope Escape handling to the open form's body, so multiple open CardForms
	// don't all close on a single keypress, and callers can preventDefault from
	// inner controls (e.g. select menus) without tripping the close.
	useEffect( () => {
		if ( ! isOpen || ! onRequestClose ) {
			return;
		}
		const node = bodyRef.current;
		if ( ! node ) {
			return;
		}
		const handleKeyDown = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' && ! event.defaultPrevented ) {
				onRequestClose();
			}
		};
		node.addEventListener( 'keydown', handleKeyDown );
		return () => node.removeEventListener( 'keydown', handleKeyDown );
	}, [ isOpen, onRequestClose ] );

	// Move focus into the body on open and restore it to the trigger on close.
	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}
		const node = bodyRef.current;
		if ( node ) {
			previousActiveRef.current = ( node.ownerDocument?.activeElement ?? null ) as HTMLElement | null;
			const focusable = node.querySelector< HTMLElement >(
				'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			);
			( focusable ?? node ).focus();
		}
		return () => {
			previousActiveRef.current?.focus?.();
		};
	}, [ isOpen ] );

	const titleTag = `h${ titleLevel }`;

	return (
		<Card
			className={ classnames( 'newspack-card-form', className, {
				'newspack-card-form--open': isOpen,
			} ) }
			__experimentalCoreCard
			isSmall
			__experimentalCoreProps={ {
				hasHeaderBorder: ! isOpen,
				header: (
					<HStack justify="space-between" style={ { width: '100%' } }>
						<VStack spacing={ 0 } style={ { flex: 1, minWidth: 0 } }>
							{ createElement( titleTag, { id: titleId, className: 'newspack-card-form__title' }, title ) }
							{ description && <p className="newspack-card-form__description">{ description }</p> }
						</VStack>
						<HStack spacing={ 2 } expanded={ false }>
							{ badge && <Badge text={ badge.text } level={ badge.level ?? 'success' } /> }
							{ actions }
						</HStack>
					</HStack>
				),
			} }
		>
			{ isOpen && (
				<div ref={ bodyRef } id={ bodyId } role="region" aria-labelledby={ titleId } tabIndex={ -1 } className="newspack-card-form__body">
					{ children }
				</div>
			) }
		</Card>
	);
};

export default CardForm;
