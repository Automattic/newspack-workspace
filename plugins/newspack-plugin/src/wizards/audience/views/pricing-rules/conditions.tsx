/**
 * Eligibility conditions editor. Renders each condition matcher from the REST
 * vocab by its `field_type`: boolean → toggle; datetime → an Anytime / Rule
 * publish date / Custom selector (the "subscriptions started on/after" cohort gate,
 * defaulting to the rule's publish date). New matchers the engine registers appear
 * automatically — no change here.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	TextControl,
	SelectControl,
	ToggleControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { AutocompleteTokenField } from '../../../../../packages/components/src';
import { tsToLocalInput, localInputToTs } from './datetime';
import { isConditionVisible, type PricingPath } from './recipes';

type ConditionsMap = { [ id: string ]: boolean | number | number[] | null };
type DateMode = 'none' | 'publish' | 'custom';

// A stored datetime within this many seconds of the rule's publish date reads back
// as the "Rule publish date" preset (absorbs new-rule selection-vs-save drift).
const PUBLISH_TOLERANCE = 120;

/**
 * The cohort-gate datetime control: Anytime / Rule publish date / Custom. The stored
 * value stays a plain UTC timestamp (or null); "Rule publish date" resolves to the
 * rule's publish timestamp — or, for a brand-new rule, now.
 */
function DatetimeCondition( {
	matcher,
	value,
	publishedAt,
	isNew,
	onChange,
}: {
	matcher: PricingRuleConditionVocab;
	value: number | null;
	publishedAt: number | null;
	isNew: boolean;
	onChange: ( v: number | null ) => void;
} ) {
	const derive = (): DateMode => {
		if ( ! value ) {
			return isNew ? 'publish' : 'none';
		}
		if ( publishedAt && Math.abs( value - publishedAt ) <= PUBLISH_TOLERANCE ) {
			return 'publish';
		}
		return 'custom';
	};
	const [ mode, setMode ] = useState< DateMode >( derive );
	const [ customTs, setCustomTs ] = useState< number | null >( derive() === 'custom' ? value : null );

	const resolvePublish = () => publishedAt ?? Math.floor( Date.now() / 1000 );

	// Apply the new-rule default (Rule publish date) to the parent on mount, so a
	// rule left at the default saves with the cohort gate set.
	useEffect( () => {
		if ( 'publish' === mode && ! value ) {
			onChange( resolvePublish() );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const choose = ( next: string ) => {
		const m = next as DateMode;
		setMode( m );
		if ( 'none' === m ) {
			onChange( null );
		} else if ( 'publish' === m ) {
			onChange( resolvePublish() );
		} else {
			onChange( customTs );
		}
	};

	const changeCustom = ( s: string ) => {
		const ts = localInputToTs( s );
		setCustomTs( ts );
		onChange( ts );
	};

	return (
		<VStack spacing={ 2 }>
			<SelectControl
				label={ matcher.label }
				help={ matcher.help }
				value={ mode }
				options={ [
					{ label: __( 'Anytime', 'newspack-plugin' ), value: 'none' },
					{ label: __( 'Rule publish date', 'newspack-plugin' ), value: 'publish' },
					{ label: __( 'Custom', 'newspack-plugin' ), value: 'custom' },
				] }
				onChange={ choose }
				__next40pxDefaultSize
			/>
			{ 'custom' === mode && (
				<TextControl
					label={ matcher.label }
					hideLabelFromVision
					type="datetime-local"
					value={ tsToLocalInput( customTs ) }
					onChange={ changeCustom }
					__next40pxDefaultSize
				/>
			) }
		</VStack>
	);
}

function SegmentCondition( {
	matcher,
	value,
	onChange,
}: {
	matcher: PricingRuleConditionVocab;
	value: number[];
	onChange: ( v: number[] ) => void;
} ) {
	const options = matcher.options || [];
	const fetchSuggestions = ( search: string ) =>
		Promise.resolve( options.filter( o => o.label.toLowerCase().includes( ( search || '' ).toLowerCase() ) ) );
	const fetchSavedInfo = ( ids: number[] ) => Promise.resolve( options.filter( o => ids.includes( o.value ) ) );
	return (
		<AutocompleteTokenField
			tokens={ value }
			onChange={ onChange }
			fetchSuggestions={ fetchSuggestions }
			fetchSavedInfo={ fetchSavedInfo }
			label={ matcher.label }
			help={ matcher.help }
			placeholder={ __( 'Search segments…', 'newspack-plugin' ) }
			__next40pxDefaultSize
		/>
	);
}

interface ConditionsProps {
	vocab: PricingRuleConditionVocab[];
	value: ConditionsMap;
	publishedAt: number | null;
	isNew: boolean;
	onChange: ( next: ConditionsMap ) => void;
	path: string;
}

export default function Conditions( { vocab, value, publishedAt, isNew, onChange, path }: ConditionsProps ) {
	if ( ! vocab?.length ) {
		return null;
	}

	const setOne = ( id: string, v: boolean | number | number[] | null ) => onChange( { ...value, [ id ]: v } );

	// Under a named path the recipe owns the lifecycle matcher (hidden); show only
	// the editable segmentation conditions. Custom shows the full set.
	const visible = vocab.filter( m => isConditionVisible( ( path || 'custom' ) as PricingPath, m.field_type ) );
	if ( ! visible.length ) {
		return null;
	}

	// Boolean (toggle) conditions always render last, below the richer datetime
	// controls. Array.sort is stable, so order within each group is preserved.
	const ordered = [ ...visible ].sort( ( a, b ) => ( 'boolean' === a.field_type ? 1 : 0 ) - ( 'boolean' === b.field_type ? 1 : 0 ) );

	return (
		<VStack spacing={ 4 }>
			{ ordered.map( matcher => {
				if ( 'datetime' === matcher.field_type ) {
					const ts = typeof value[ matcher.id ] === 'number' ? ( value[ matcher.id ] as number ) : null;
					return (
						<DatetimeCondition
							key={ matcher.id }
							matcher={ matcher }
							value={ ts }
							publishedAt={ publishedAt }
							isNew={ isNew }
							onChange={ v => setOne( matcher.id, v ) }
						/>
					);
				}
				if ( 'select' === matcher.field_type ) {
					const selected = Array.isArray( value[ matcher.id ] ) ? ( value[ matcher.id ] as number[] ) : [];
					return (
						<SegmentCondition
							key={ matcher.id }
							matcher={ matcher }
							value={ selected }
							onChange={ v => setOne( matcher.id, v.length ? v : null ) }
						/>
					);
				}
				// Boolean toggle for 'boolean' and as the graceful default for any
				// unknown field type.
				return (
					<ToggleControl
						key={ matcher.id }
						label={ matcher.label }
						help={ matcher.help }
						checked={ Boolean( value[ matcher.id ] ) }
						onChange={ v => setOne( matcher.id, v ) }
						__nextHasNoMarginBottom
					/>
				);
			} ) }
		</VStack>
	);
}
