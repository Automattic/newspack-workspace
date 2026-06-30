/**
 * WordPress dependencies
 */
import { BlockControls } from '@wordpress/block-editor';
import { Button, Popover, SearchControl, ToolbarButton } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { insert, registerFormatType, remove, useAnchor } from '@wordpress/rich-text';
import { mergeTags } from 'newspack-icons';

/**
 * Internal dependencies
 */
import { TRIGGER, getLabel, getLegacyTrigger, useMergeTagItems } from './utils';

const FORMAT_NAME = 'newspack-newsletters/merge-tag';
// `registerFormatType` rejects a `tagName: 'span'` + `className: null` registration because `core/underline` already claims bare `<span>`. The class never lands in markup (the format is never applied) — it exists purely to win that validation check.
const FORMAT_SETTINGS = {
	tagName: 'span',
	className: 'newspack-newsletters-merge-tag-noop',
};

const CaretAnchoredPicker = ( { contentRef, value, onSelect, onClose } ) => {
	const anchor = useAnchor( {
		editableContentElement: contentRef?.current,
		value,
		settings: FORMAT_SETTINGS,
	} );
	return <MergeTagPicker anchor={ anchor } onSelect={ onSelect } onClose={ onClose } />;
};

const MergeTagPicker = ( { anchor, onSelect, onClose } ) => {
	const [ search, setSearch ] = useState( '' );
	const items = useMergeTagItems( search );
	const containerRef = useRef();
	const dialogLabel = sprintf(
		/* translators: %s: ESP-native singular noun (e.g. "merge tag" or "personalization tag"). */
		__( 'Insert %s', 'newspack-newsletters' ),
		getLabel()
	);
	const searchLabel = sprintf(
		/* translators: %s: ESP-native singular noun (e.g. "merge tag" or "personalization tag"). */
		__( 'Search %s', 'newspack-newsletters' ),
		getLabel()
	);

	// Roving focus across the search input + option buttons via Arrow keys.
	useEffect( () => {
		const container = containerRef.current;
		if ( ! container ) {
			return;
		}
		const handleKeyDown = event => {
			if ( event.key !== 'ArrowDown' && event.key !== 'ArrowUp' ) {
				return;
			}
			// Scope to the search input + option buttons, skipping the SearchControl's internal clear-button.
			const focusables = Array.from(
				container.querySelectorAll( '.components-search-control input, .newspack-newsletters-merge-tags-picker__option' )
			);
			if ( focusables.length < 2 ) {
				return;
			}
			const current = focusables.indexOf( container.ownerDocument.activeElement );
			if ( current === -1 ) {
				return;
			}
			const direction = event.key === 'ArrowDown' ? 1 : -1;
			const next = ( current + direction + focusables.length ) % focusables.length;
			focusables[ next ].focus();
			event.preventDefault();
		};
		container.addEventListener( 'keydown', handleKeyDown );
		return () => container.removeEventListener( 'keydown', handleKeyDown );
	}, [] );

	return (
		<Popover
			anchor={ anchor }
			className="newspack-newsletters-merge-tags-picker__popover"
			placement="bottom-start"
			offset={ 13 }
			focusOnMount="firstElement"
			onClose={ onClose }
			onFocusOutside={ onClose }
		>
			<div ref={ containerRef } className="newspack-newsletters-merge-tags-picker" role="dialog" aria-label={ dialogLabel }>
				<SearchControl __nextHasNoMarginBottom value={ search } onChange={ setSearch } label={ searchLabel } placeholder={ searchLabel } />
				{ items.length === 0 ? (
					<p className="newspack-newsletters-merge-tags-picker__empty">{ __( 'No matches.', 'newspack-newsletters' ) }</p>
				) : (
					<ul className="newspack-newsletters-merge-tags-picker__list">
						{ items.map( item => (
							<li key={ item.key }>
								<Button className="newspack-newsletters-merge-tags-picker__option" onClick={ () => onSelect( item.value.tag ) }>
									{ item.label }
								</Button>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</Popover>
	);
};

const MergeTagEdit = ( { value, onChange, contentRef } ) => {
	const [ isOpen, setOpen ] = useState( false );
	const [ anchorMode, setAnchorMode ] = useState( 'caret' );
	const [ buttonRef, setButtonRef ] = useState();
	// Snapshot the value at open time so the caret survives the popover stealing focus. Intermediate edits while open aren't expected — `onFocusOutside` closes the popover the moment focus returns to the editor.
	const valueRef = useRef( value );
	const prevTextRef = useRef( value.text );

	useEffect( () => {
		const { text, start } = value;
		const prevText = prevTextRef.current;
		prevTextRef.current = text;
		// Verify exactly one character was just inserted at caret-1 (rules out paste, multi-char edits, and pre-existing `{}` further along).
		if ( text.length !== prevText.length + 1 || start < 2 ) {
			return;
		}
		if ( text.slice( 0, start - 1 ) + text.slice( start ) !== prevText ) {
			return;
		}
		const legacy = getLegacyTrigger();
		const triggers = legacy ? [ TRIGGER, legacy ] : [ TRIGGER ];
		const matched = triggers.find( t => text.slice( start - t.length, start ) === t );
		if ( matched ) {
			const stripped = remove( value, start - matched.length, start );
			valueRef.current = stripped;
			prevTextRef.current = stripped.text;
			onChange( stripped );
			setAnchorMode( 'caret' );
			setOpen( true );
		}
	}, [ value, onChange ] );

	const toggleFromToolbar = () => {
		if ( isOpen ) {
			setOpen( false );
			return;
		}
		valueRef.current = value;
		setAnchorMode( 'toolbar' );
		setOpen( true );
	};

	// Keep popover focus when clicking the open button so onFocusOutside doesn't pre-close it before the toggle handler fires.
	const onToolbarMouseDown = event => {
		if ( isOpen ) {
			event.preventDefault();
		}
	};

	const handleSelect = tag => {
		setOpen( false );
		onChange( insert( valueRef.current, tag ) );
	};

	const label = sprintf(
		/* translators: %s: ESP-native singular noun (e.g. "merge tag" or "personalization tag"). */
		__( 'Insert %s', 'newspack-newsletters' ),
		getLabel()
	);

	const closePicker = () => setOpen( false );

	return (
		<>
			<BlockControls group="inline">
				<ToolbarButton
					ref={ setButtonRef }
					icon={ mergeTags }
					label={ label }
					onMouseDown={ onToolbarMouseDown }
					onClick={ toggleFromToolbar }
					aria-expanded={ isOpen }
					aria-haspopup="dialog"
				/>
			</BlockControls>
			{ isOpen && anchorMode === 'toolbar' && <MergeTagPicker anchor={ buttonRef } onSelect={ handleSelect } onClose={ closePicker } /> }
			{ isOpen && anchorMode === 'caret' && (
				<CaretAnchoredPicker contentRef={ contentRef } value={ value } onSelect={ handleSelect } onClose={ closePicker } />
			) }
		</>
	);
};

export default () => {
	registerFormatType( FORMAT_NAME, {
		title: sprintf(
			/* translators: %s: ESP-native singular noun (e.g. "merge tag" or "personalization tag"). */
			__( 'Insert %s', 'newspack-newsletters' ),
			getLabel()
		),
		edit: MergeTagEdit,
		...FORMAT_SETTINGS,
	} );
};
