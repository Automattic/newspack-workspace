/**
 * The Price Schedule list. Editing happens in a drawer so the cells hold short
 * strings that never truncate.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useMemo, useEffect, useCallback, useId, useRef } from '@wordpress/element';
import {
	Button,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
// Not the Newspack wrapper: with-wizard-screen/style.scss gives `.newspack-dataviews`
// a -48px page bleed that hangs this embedded table past the form column.
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';
import { currencyDollar } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { ConfirmDialog, Grid, SectionHeader, TableCard } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
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
	calcTypes: PricingRulesCalcType[];
	currency: PricingRulesCurrency;
}

const REMOVED_NOTICE_ID = 'pricing-rule-price-removed';

/** A row back to the bare price, so the table's own columns never reach the parent. */
const toPrice = ( row: SchedulePriceRow ): SchedulePriceInput => ( {
	at: row.at,
	calc_type: row.calc_type,
	value: row.value,
	label: row.label,
} );

export default function SchedulePrices( { steps, onChange, publicize, calcTypes, currency }: SchedulePricesProps ) {
	// `editing` outlives a close so the drawer can play its exit with the content
	// it opened with; `isOpen` is what actually shuts it.
	const [ editing, setEditing ] = useState< Editing | null >( null );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ removing, setRemoving ] = useState< number | null >( null );
	const addRef = useRef< HTMLButtonElement >( null );
	const tableRef = useRef< HTMLDivElement >( null );
	const claimFocus = useRef< 'add' | number | null >( null );
	const titleId = useId();
	const { addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

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
				price: priceSummary( step, currency, calcTypes.find( c => c.value === step.calc_type )?.label ?? '' ),
			} ) ),
		[ ordered, currency, calcTypes ]
	);

	// Removal unmounts its trigger and a moved price leaves the drawer restoring
	// focus to the wrong row; a claim re-parks it, which the drawer yields to.
	useEffect( () => {
		const claimed = claimFocus.current;
		claimFocus.current = null;
		if ( 'add' === claimed ) {
			addRef.current?.focus();
		} else if ( typeof claimed === 'number' ) {
			tableRef.current?.querySelectorAll< HTMLButtonElement >( '.dataviews-title-field button' )[ claimed ]?.focus();
		}
	}, [ ordered ] );

	const fields: Field< SchedulePriceRow >[] = useMemo( () => {
		const list: Field< SchedulePriceRow >[] = [
			{
				id: 'cycles',
				label: __( 'Cycles', 'newspack-plugin' ),
				enableHiding: false,
				enableSorting: false,
				getValue: ( { item }: { item: SchedulePriceRow } ) => item.cycles.label,
				// `aria-label` rather than `label`, which would hang a tooltip off every row.
				render: ( { item }: { item: SchedulePriceRow } ) => (
					<Button variant="link" aria-label={ item.cycles.label } onClick={ () => open( toPrice( item ), Number( item.id ) ) }>
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
	}, [ publicize, open ] );

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

	const nextCycle = String( ordered.reduce( ( max, step ) => Math.max( max, Number( step.at ) || 0 ), 0 ) + 1 );

	const add = () => open( { at: nextCycle, calc_type: calcTypes[ 0 ]?.value ?? 'fixed_price', value: '', label: '' }, null );

	const commit = ( price: SchedulePriceInput ) => {
		const index = editing?.index ?? null;
		// An index past the end would leave `map` silently dropping the edit.
		const isReplace = null !== index && index < ordered.length;
		const list = isReplace ? ordered.map( ( step, i ) => ( i === index ? price : step ) ) : [ ...ordered, price ];
		const sorted = list.sort( byCycle );
		const landed = sorted.indexOf( price );
		if ( isReplace && landed !== index ) {
			claimFocus.current = landed;
		} else if ( ! isReplace && 0 === ordered.length ) {
			// The empty-state button that opened the drawer unmounts as the table
			// appears, so the drawer has nothing to return focus to.
			claimFocus.current = 'add';
		}
		onChange( sorted );
		setIsOpen( false );
	};

	const takenCycles = useMemo( () => ordered.filter( ( _, i ) => i !== editing?.index ).map( step => Number( step.at ) ), [ ordered, editing ] );

	const actions: Action< SchedulePriceRow >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				isPrimary: true,
				callback: ( items: SchedulePriceRow[] ) => open( toPrice( items[ 0 ] ), Number( items[ 0 ].id ) ),
			},
			{
				id: 'remove',
				label: __( 'Remove', 'newspack-plugin' ),
				// Every action being primary is what suppresses the kebab menu, and the
				// stylesheet colors the LAST row action red, so Remove stays last here.
				isPrimary: true,
				isDestructive: true,
				callback: ( items: SchedulePriceRow[] ) => setRemoving( Number( items[ 0 ].id ) ),
			},
		],
		[ open ]
	);

	const confirmRemove = () => {
		if ( null === removing ) {
			return;
		}
		claimFocus.current = 'add';
		onChange( ordered.filter( ( _, i ) => i !== removing ) );
		// Notices append without deduping, so a second removal would stack a toast
		// sharing the first one's React key.
		removeNotice( REMOVED_NOTICE_ID );
		addNotice( { id: REMOVED_NOTICE_ID, type: 'success', message: __( 'Price removed.', 'newspack-plugin' ) } );
		setRemoving( null );
	};

	// perPage and the reader-facing column follow the live values; in view state,
	// an effect would land them a paint late.
	const tableView = useMemo( () => ( { ...view, perPage, fields: fieldIds } ), [ view, perPage, fieldIds ] );

	const { data, paginationInfo } = useMemo( () => filterSortAndPaginate( rows, tableView, fields ), [ rows, tableView, fields ] );

	return (
		<>
			<TableCard
				title={ rows.length > 0 && __( 'Price Schedule', 'newspack-plugin' ) }
				titleId={ titleId }
				actions={
					rows.length > 0 && (
						<Button ref={ addRef } variant="secondary" size="compact" onClick={ add }>
							{ __( 'Add Price', 'newspack-plugin' ) }
						</Button>
					)
				}
			>
				{ rows.length === 0 ? (
					<div className="newspack-pricing-rules__schedule-empty">
						<Grid columns={ 4 } noMargin>
							<VStack start={ 2 } end={ 4 } spacing={ 6 }>
								<SectionHeader
									icon={ currencyDollar }
									title={ __( 'No prices yet', 'newspack-plugin' ) }
									description={ __(
										'Each price applies from its billing cycle until the next takes over. Add the first one to build the schedule.',
										'newspack-plugin'
									) }
									pageHeader
									size="small"
									noMargin
									heading={ 3 }
								/>
								<HStack justify="center">
									<Button ref={ addRef } variant="secondary" onClick={ add } __next40pxDefaultSize>
										{ __( 'Add Price', 'newspack-plugin' ) }
									</Button>
								</HStack>
							</VStack>
						</Grid>
					</div>
				) : (
					<div ref={ tableRef } className="newspack-pricing-rules__schedule-table" role="region" aria-labelledby={ titleId }>
						<DataViews
							data={ data }
							fields={ fields }
							view={ tableView }
							onChangeView={ setView }
							actions={ actions }
							paginationInfo={ paginationInfo }
							defaultLayouts={ { table: {} } }
							getItemId={ ( item: SchedulePriceRow ) => item.id }
						>
							<DataViews.Layout />
						</DataViews>
					</div>
				) }
			</TableCard>
			<ConfirmDialog
				isOpen={ null !== removing }
				isDestructive
				title={ __( 'Remove Price', 'newspack-plugin' ) }
				confirmButtonText={ __( 'Remove', 'newspack-plugin' ) }
				onConfirm={ confirmRemove }
				onCancel={ () => setRemoving( null ) }
			>
				{ __( 'Remove this price from the schedule? The change applies when you save the rule.', 'newspack-plugin' ) }
			</ConfirmDialog>
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
