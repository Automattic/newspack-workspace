/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Fragment, useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Newspack dependencies.
 */
import { Card, Notice, TextControl, SelectControl, Button, ProgressBar } from 'newspack-components';

/**
 * Internal dependencies.
 */
import type { ApiError, GamBidder, GamOrder } from './types';

const { lica_batch_size } = window.newspack_ads_bidding_gam;

interface CreateTypeRequestData {
	id?: number | null;
	batch?: number;
	fixing?: boolean | number;
}

interface OrderConfig {
	orderId: number | null;
	name: string;
	revenueShare: number | string;
	bidders: string[];
}

interface OrderProps {
	orderId?: number | null;
	defaultName?: string;
	onPending?: ( pending: boolean ) => void;
	onError?: ( err: ApiError ) => void | Promise< void >;
	onSuccess?: ( order?: GamOrder ) => void | Promise< void >;
	onUnrecoverable?: ( order: GamOrder, err: ApiError ) => void | Promise< void >;
	onCancel?: () => void;
	bidders?: Record< string, GamBidder >;
}

const Order = ( { orderId = null, defaultName = '', onPending = () => {}, onError, onSuccess, onUnrecoverable, onCancel, ...props }: OrderProps ) => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ bidders, setBidders ] = useState< Record< string, GamBidder > >( props.bidders || {} );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ order, setOrder ] = useState< GamOrder | null >( null );
	const [ step, setStep ] = useState( 0 );
	const [ totalBatches, setTotalBatches ] = useState( 1 );
	const [ totalSteps, setTotalSteps ] = useState( 4 );
	const [ isLastAttempt, setLastAttempt ] = useState( false );
	const [ config, setConfig ] = useState< OrderConfig >( {
		orderId,
		name: ! orderId ? defaultName : '',
		revenueShare: 0,
		bidders: [],
	} );

	const hasIssues = () => order?.order_id && ( ! order?.line_item_ids?.length || totalBatches > ( order?.lica_batch_count || 0 ) );

	const canSubmit = () =>
		hasIssues() ||
		! orderId ||
		parseInt( String( config.revenueShare ) ) !== parseInt( String( order?.revenue_share ) ) ||
		JSON.stringify( config.bidders ) !== JSON.stringify( order?.bidders );

	const buttonText = () => ( orderId ? __( 'Update Order', 'newspack-ads' ) : __( 'Create Order', 'newspack-ads' ) );

	const fetchLicaConfig = async ( id: number ) => await apiFetch< unknown[] >( { path: `/newspack-ads/v1/bidding/gam/lica_config?id=${ id }` } );

	const getStepName = () => {
		switch ( step ) {
			case 0:
				return '';
			case 1:
				return __( 'Creating Order…', 'newspack-ads' );
			case 2:
				return __( 'Creating Line Items…', 'newspack-ads' );
			default:
				return __( 'Associating Creatives…', 'newspack-ads' );
		}
	};

	useEffect( () => {
		const setBiddersOnOrderId = async () => {
			setInFlight( true );
			try {
				setBidders( await apiFetch< Record< string, GamBidder > >( { path: '/newspack-ads/v1/bidders' } ) );
			} catch ( err ) {
				setError( err as ApiError );
			}
			if ( orderId ) {
				// Fetch order.
				try {
					const data = await apiFetch< GamOrder >( {
						path: `/newspack-ads/v1/bidding/gam/order?id=${ orderId }`,
						method: 'GET',
					} );
					setConfig( {
						orderId: data.order_id ?? null,
						name: data.order_name ?? '',
						revenueShare: data.revenue_share ?? 0,
						bidders: data.bidders ?? [],
					} );
					setOrder( data );
				} catch ( err ) {
					setError( err as ApiError );
				}
				// Fetch LICA config.
				try {
					const licaConfig = await fetchLicaConfig( orderId );
					const batches = Math.ceil( licaConfig.length / lica_batch_size );
					setTotalBatches( batches );
					setTotalSteps( 3 + batches );
				} catch ( err ) {
					setError( err as ApiError );
				}
			} else {
				setConfig( {
					orderId: null,
					name: defaultName,
					revenueShare: 0,
					bidders: [],
				} );
			}
			setInFlight( false );
		};

		setBiddersOnOrderId();
	}, [ orderId ] );

	useEffect( () => {
		onPending( inFlight );
	}, [ inFlight ] );

	const createType = async ( type: string, requestData: CreateTypeRequestData = { batch: 0, fixing: false } ): Promise< GamOrder > => {
		return await apiFetch< GamOrder >( {
			path: '/newspack-ads/v1/bidding/gam/create',
			method: 'POST',
			data: {
				...requestData,
				id: config?.orderId || requestData.id || null,
				type,
				config: {
					order_name: config?.name,
					revenue_share: config?.revenueShare,
					bidders: config?.bidders,
				},
			},
		} );
	};

	const create = async ( fixing: boolean | number | undefined = false ) => {
		setError( null );
		setInFlight( true );
		let pendingOrder: GamOrder = { ...order };
		try {
			if ( ! pendingOrder || ! pendingOrder.order_id ) {
				setStep( 1 );
				pendingOrder = await createType( 'order', { fixing } );
				setOrder( pendingOrder );
				setConfig( { ...config, orderId: pendingOrder.order_id ?? null } );
			}
			if ( ! pendingOrder?.line_item_ids?.length ) {
				setStep( 2 );
				pendingOrder = await createType( 'line_items', { id: pendingOrder.order_id, fixing } );
				setOrder( pendingOrder );
			}
			const licaConfig = await fetchLicaConfig( pendingOrder.order_id as number );
			const batches = Math.ceil( licaConfig.length / lica_batch_size );
			setTotalBatches( batches );
			setTotalSteps( 3 + batches );
			const start = pendingOrder?.lica_batch_count || 0;
			if ( batches > start ) {
				for ( let i = start; i < batches; i++ ) {
					const batch = i + 1;
					setStep( 2 + batch );
					pendingOrder = await createType( 'creatives', {
						id: pendingOrder.order_id,
						batch,
						fixing,
					} );
					setOrder( pendingOrder );
				}
			}
			setStep( 3 + batches );
			if ( typeof onSuccess === 'function' ) {
				await onSuccess( pendingOrder );
			}
		} catch ( err ) {
			if ( orderId || isLastAttempt ) {
				// Unrecoverable error.
				setLastAttempt( false );
				setOrder( null );
				setConfig( { ...config, orderId: null } );
				if ( typeof onUnrecoverable === 'function' ) {
					await onUnrecoverable( pendingOrder, err as ApiError );
				}
			} else {
				// Make it fail unrecoverably if it fails on next attempt.
				if ( pendingOrder?.order_id ) {
					setLastAttempt( true );
				}
				setError( err as ApiError );
				if ( typeof onError === 'function' ) {
					await onError( err as ApiError );
				}
			}
		} finally {
			setStep( 0 );
			setInFlight( false );
		}
	};

	const update = async () => {
		setError( null );
		setInFlight( true );
		let data: GamOrder | undefined;
		try {
			data = await apiFetch< GamOrder >( {
				path: '/newspack-ads/v1/bidding/gam/order',
				method: 'PUT',
				data: {
					id: orderId,
					config: {
						revenue_share: config?.revenueShare,
						bidders: config?.bidders,
					},
				},
			} );
			setOrder( data );
		} catch ( err ) {
			setError( err as ApiError );
			if ( typeof onError === 'function' ) {
				await onError( err as ApiError );
			}
		} finally {
			if ( typeof onSuccess === 'function' ) {
				await onSuccess( data );
			}
			setInFlight( false );
		}
	};

	const stepName = getStepName();

	return (
		<Card noBorder>
			{ ! orderId && (
				<p>
					{ __(
						'Create the order and line items on your Google Ad Manager network according to the pre-defined price bucket settings.',
						'newspack-ads'
					) }
				</p>
			) }
			{ error && error.data?.status !== '404' && <Notice isError noticeText={ error.message } /> }
			<TextControl
				label={ __( 'Order name', 'newspack-ads' ) }
				// NOTE: pre-existing -- forwards `order.order_name` (a string) through `disabled`
				// when present, not just a boolean; `TextControl`'s `disabled` type is `boolean`,
				// hence the cast (kept to avoid changing which value gets passed).
				disabled={ ( inFlight || order?.order_name ) as boolean }
				value={ order?.order_name ? order.order_name : config.name }
				onChange={ value =>
					setConfig( {
						...config,
						name: value,
					} )
				}
			/>
			<TextControl
				type="number"
				min="0"
				max="100"
				label={ __( 'Bidder Revenue Share', 'newspack-ads' ) }
				help={ __(
					'This is agreed upon revenue share between you and the bid partner. Input the percentage that goes to the bidder, i.e. 20 for 20%.',
					'newspack-ads'
				) }
				disabled={ inFlight }
				value={ config.revenueShare }
				onChange={ value =>
					setConfig( {
						...config,
						revenueShare: value,
					} )
				}
			/>
			<SelectControl
				label={ __( 'Bidders', 'newspack-ads' ) }
				disabled={ inFlight }
				value={ config.bidders }
				help={ __(
					'Which bidders to include in this order. Select bidders that all have the same revenue share and make sure to not include the same bidder in more than one header bidding order.',
					'newspack-ads'
				) }
				options={ Object.keys( bidders ).map( bidderKey => ( {
					value: bidderKey,
					label: bidders[ bidderKey ].name,
				} ) ) }
				multiple
				onChange={ value =>
					setConfig( {
						...config,
						bidders: value,
					} )
				}
			/>
			{ ! inFlight && hasIssues() && <Notice isWarning noticeText={ __( "Order exists but it's misconfigured.", 'newspack-ads' ) } /> }
			{ step && stepName ? (
				<Fragment>
					<Notice isWarning noticeText={ __( 'This may take up to 15 minutes, please do not close the window.', 'newspack-ads' ) } />
					<ProgressBar completed={ step } total={ totalSteps } label={ stepName } />
				</Fragment>
			) : null }
			<Card buttonsCard noBorder className="justify-end">
				{ typeof onCancel === 'function' && (
					<Button
						isSecondary
						disabled={ inFlight }
						onClick={ () => {
							onCancel();
						} }
					>
						{ __( 'Cancel', 'newspack-ads' ) }
					</Button>
				) }
				<Button
					isPrimary
					disabled={ ! canSubmit() || ! config.name || inFlight }
					onClick={ () => {
						const fixing = hasIssues();
						if ( fixing || ! config.orderId ) {
							create( fixing );
						} else {
							update();
						}
					} }
				>
					{ hasIssues() ? __( 'Fix issues', 'newspack-ads' ) : buttonText() }
				</Button>
			</Card>
		</Card>
	);
};

export default Order;
