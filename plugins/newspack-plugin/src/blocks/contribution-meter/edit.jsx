/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder, Spinner } from '@wordpress/components';
import { useEffect, useState, useMemo } from '@wordpress/element';
import { error as warning } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import InspectorPanel from './components/InspectorPanel';
import LinearMeter from './components/LinearMeter';
import CircularMeter from './components/CircularMeter';
import { getDefaultStartDate } from './utils/helpers';

/**
 * Build CSS class names for the block wrapper.
 *
 * @param {string} style     Meter style.
 * @param {string} thickness Thickness size.
 * @return {string} Combined class names.
 */
const buildClassNames = ( style, thickness ) => {
	return [ `contribution-meter--${ style }`, `contribution-meter--thickness-${ thickness }`, 'newspack-ui', 'newspack-ui__font--s' ].join( ' ' );
};

/**
 * Edit component for the Contribution Meter block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {Element} Edit component.
 */
const PREVIEW_AMOUNT_RAISED = 1500;

const Edit = ( { attributes, setAttributes } ) => {
	const {
		className,
		goalAmount,
		startDate,
		endDate,
		progressBarColor,
		thickness,
		showGoal,
		showAmountRaised,
		showPercentage,
		previewMode = false,
	} = attributes;

	// Extract meter style from className attribute (is-style-circular or default to linear).
	const meterStyle = className && className.includes( 'is-style-circular' ) ? 'circular' : 'linear';

	const [ contributionData, setContributionData ] = useState( previewMode ? { amountRaised: PREVIEW_AMOUNT_RAISED } : null );
	const [ isLoading, setIsLoading ] = useState( previewMode ? false : true );
	const [ error, setError ] = useState( null );

	// Set default start date if not set.
	useEffect( () => {
		if ( ! startDate ) {
			setAttributes( { startDate: getDefaultStartDate() } );
		}
	}, [ startDate, setAttributes ] );

	// Fetch contribution data when startDate changes.
	useEffect( () => {
		if ( previewMode ) {
			return;
		}

		if ( ! startDate ) {
			setIsLoading( false );
			return;
		}

		setIsLoading( true );
		setError( null );

		apiFetch( {
			path: '/newspack/v1/contribution-meter',
			method: 'POST',
			data: {
				startDate,
				...( endDate && { endDate } ),
			},
		} )
			.then( data => {
				setContributionData( data );
				setIsLoading( false );
			} )
			.catch( err => {
				setError( err.message || __( 'Failed to load contribution data.', 'newspack-plugin' ) );
				setIsLoading( false );
			} );
	}, [ startDate, endDate, previewMode ] );

	// Get values and calculate percentage.
	const amountRaised = contributionData?.amountRaised || 0;
	const percentage = useMemo( () => {
		if ( ! goalAmount || goalAmount <= 0 ) {
			return 0;
		}
		const percentageRaw = ( amountRaised / goalAmount ) * 100;
		return Math.floor( percentageRaw * 10 ) / 10;
	}, [ amountRaised, goalAmount ] );

	// Build CSS class names.
	const blockClassName = useMemo( () => buildClassNames( meterStyle, thickness ), [ meterStyle, thickness ] );

	const blockProps = useBlockProps( { className: blockClassName } );

	// Shared meter props.
	const meterProps = {
		amountRaised,
		goal: goalAmount,
		percentage,
		showGoal,
		showAmountRaised,
		showPercentage,
		progressBarColor,
		thickness,
	};

	return (
		<>
			<InspectorPanel attributes={ attributes } setAttributes={ setAttributes } />

			<div { ...blockProps }>
				{ isLoading && (
					<Placeholder className="contribution-meter-loading">
						<Spinner />
						{ __( 'Loading contribution data…', 'newspack-plugin' ) }
					</Placeholder>
				) }

				{ ! isLoading && error && (
					<Placeholder
						icon={ warning }
						label={ __( 'Error', 'newspack-plugin' ) }
						instructions={ error }
						className="contribution-meter-error"
					/>
				) }

				{ ! isLoading && ! error && (
					<>
						{ meterStyle === 'linear' && <LinearMeter { ...meterProps } /> }
						{ meterStyle === 'circular' && <CircularMeter { ...meterProps } /> }
					</>
				) }
			</div>
		</>
	);
};

export default Edit;
