/**
 * The Price Schedule list: one row per price, none of them editable in place.
 * Editing moves to a drawer so the cells hold short strings, which is what lets
 * four columns of controls become three columns of text in the form's column.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useMemo, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
// Not the Newspack wrapper: with-wizard-screen/style.scss gives `.newspack-dataviews`
// a -48px page bleed that hangs this embedded table past the form column.
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { Divider } from '../../../../../packages/components/src';
import { byCycle, cycleRange, priceSummary } from './schedule-format';
import SchedulePriceDrawer from './schedule-price-drawer';

interface SchedulePriceRow extends SchedulePriceInput {
	id: string;
	cycles: { display: string; label: string };
	price: string;
}

interface Editing {
	price: SchedulePriceInput;
	index: number | null;
}

interface SchedulePricesProps {
	steps: SchedulePriceInput[];
	onChange: ( steps: SchedulePriceInput[] ) => void;
	publicize: boolean;
	calcTypes: { value: string; label: string }[];
	currency: PricingRulesCurrency;
}

export default function SchedulePrices( { steps, onChange, publicize, calcTypes, currency }: SchedulePricesProps ) {
	// `editing` outlives a close so the drawer can play its exit with the content
	// it opened with; `isOpen` is what actually shuts it.
	const [ editing, setEditing ] = useState< Editing | null >( null );
	const [ isOpen, setIsOpen ] = useState( false );

	// A rule saved before this redesign can hold its prices in any order, and every
	// cell reads the price after it, so nothing below may index the prop directly.
	const ordered = useMemo( () => [ ...steps ].sort( byCycle ), [ steps ] );

	const open = useCallback( ( price: SchedulePriceInput, index: number | null ) => {
		setEditing( { price, index } );
		setIsOpen( true );
	}, [] );

	const rows: SchedulePriceRow[] = useMemo(
		() =>
			ordered.map( ( step, i ) => ( {
				...step,
				id: String( i ),
				cycles: cycleRange( Number( step.at ), ordered[ i + 1 ] ? Number( ordered[ i + 1 ].at ) : null ),
				price: priceSummary( step, currency ),
			} ) ),
		[ ordered, currency ]
	);

	const fields: Field< SchedulePriceRow >[] = useMemo( () => {
		const list: Field< SchedulePriceRow >[] = [
			{
				id: 'cycles',
				label: __( 'Cycles', 'newspack-plugin' ),
				enableHiding: false,
				enableSorting: false,
				getValue: ( { item }: { item: SchedulePriceRow } ) => item.cycles.label,
				// A real control, not a styled cell: it is what makes the first column read
				// as a way in, alongside the row's Edit button. `aria-label` rather than
				// `label`, which would hang a tooltip off every row.
				render: ( { item }: { item: SchedulePriceRow } ) => (
					<Button variant="link" aria-label={ item.cycles.label } onClick={ () => open( ordered[ Number( item.id ) ], Number( item.id ) ) }>
						{ item.cycles.display }
					</Button>
				),
			},
			{
				id: 'price',
				label: __( 'Price', 'newspack-plugin' ),
				enableHiding: false,
				enableSorting: false,
				getValue: ( { item }: { item: SchedulePriceRow } ) => item.price,
			},
		];
		if ( publicize ) {
			list.push( {
				id: 'label',
				label: __( 'Name shown to reader', 'newspack-plugin' ),
				enableHiding: false,
				enableSorting: false,
				getValue: ( { item }: { item: SchedulePriceRow } ) => item.label,
			} );
		}
		return list;
	}, [ publicize, ordered, open ] );

	const fieldIds = useMemo( () => ( publicize ? [ 'price', 'label' ] : [ 'price' ] ), [ publicize ] );
	const perPage = Math.max( rows.length, 1 );

	const [ view, setView ] = useState< View >( () => ( {
		type: 'table',
		page: 1,
		search: '',
		filters: [],
		layout: { density: 'compact', enableMoving: false },
		titleField: 'cycles',
		fields: fieldIds,
	} ) );

	// The reader-facing column appears and vanishes with the disclosure toggle.
	useEffect( () => {
		setView( prev => ( prev.fields?.join( '|' ) === fieldIds.join( '|' ) ? prev : { ...prev, fields: fieldIds } ) );
	}, [ fieldIds ] );

	const nextCycle = String( ordered.reduce( ( max, step ) => Math.max( max, Number( step.at ) || 0 ), 0 ) + 1 );

	const add = () => open( { at: nextCycle, calc_type: calcTypes[ 0 ]?.value ?? 'fixed_price', value: '', label: '' }, null );

	const commit = ( price: SchedulePriceInput ) => {
		const index = editing?.index ?? null;
		const list = null === index ? [ ...ordered, price ] : ordered.map( ( step, i ) => ( i === index ? price : step ) );
		onChange( [ ...list ].sort( byCycle ) );
		setIsOpen( false );
	};

	const takenCycles = useMemo( () => ordered.filter( ( _, i ) => i !== editing?.index ).map( step => Number( step.at ) ), [ ordered, editing ] );

	const actions: Action< SchedulePriceRow >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				isPrimary: true,
				callback: ( items: SchedulePriceRow[] ) => {
					const index = Number( items[ 0 ].id );
					open( ordered[ index ], index );
				},
			},
			{
				id: 'remove',
				label: __( 'Remove', 'newspack-plugin' ),
				isDestructive: true,
				// Nothing is destroyed until the rule saves, so this asks for no confirmation.
				callback: ( items: SchedulePriceRow[] ) => onChange( ordered.filter( ( _, i ) => i !== Number( items[ 0 ].id ) ) ),
			},
		],
		[ ordered, onChange, open ]
	);

	// The page holds every price, so `perPage` follows the live count rather than
	// living in view state, where an effect would land it a paint late.
	const tableView = useMemo( () => ( { ...view, perPage } ), [ view, perPage ] );

	const { data, paginationInfo } = useMemo( () => filterSortAndPaginate( rows, tableView, fields ), [ rows, tableView, fields ] );

	return (
		<>
			{ /* One child of the section's stack, so the button sits with its table. */ }
			<VStack spacing={ 0 }>
				<div className="newspack-pricing-rules__schedule-table">
					<DataViews
						data={ data }
						fields={ fields }
						view={ tableView }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ paginationInfo }
						defaultLayouts={ { table: {} } }
						getItemId={ ( item: SchedulePriceRow ) => item.id }
						empty={ <p>{ __( 'No prices yet. Add one to get started.', 'newspack-plugin' ) }</p> }
					>
						<DataViews.Layout />
					</DataViews>
				</div>
				{ /* Divider margins are raw px, not a step scale. */ }
				<Divider variant="tertiary" marginTop={ 0 } marginBottom={ 8 } />
				<div>
					<Button variant="secondary" onClick={ add }>
						{ __( 'Add Price', 'newspack-plugin' ) }
					</Button>
				</div>
			</VStack>
			{ editing && (
				<SchedulePriceDrawer
					isOpen={ isOpen }
					price={ editing.price }
					isNew={ null === editing.index }
					takenCycles={ takenCycles }
					publicize={ publicize }
					calcTypes={ calcTypes }
					currency={ currency }
					onSave={ commit }
					onClose={ () => setIsOpen( false ) }
				/>
			) }
		</>
	);
}
