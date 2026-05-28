/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalHeading as Heading,
	SelectControl,
	Notice,
	Button,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies.
 */
import LocalBudgetsControl from './local-budgets-control';

export default function PullStory( { items, closeModal, onActionPerformed } ) {
	const isBulk = items.length > 1;

	const [ statusOnPublish, setStatusOnPublish ] = useState( 'draft' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ errors, setErrors ] = useState( [] );
	const [ budget, setBudget ] = useState( '' );

	const { fetchStory } = useDispatch( 'newspack-story-budget' );

	const headingRef = useRef( null );

	useEffect( () => {
		if ( errors.length > 0 ) {
			headingRef.current?.scrollIntoView( {
				behavior: 'smooth',
				block: 'center',
			} );
		}
	}, [ errors ] );

	const pullStory = async ( storyId ) => {
		const payload = await apiFetch( {
			isStoryBudget: true,
			fullPath: `newspack-network/v1/content-distribution/pull/${ storyId }`,
			method: 'POST',
			data: { status_on_publish: statusOnPublish },
		} );
		const res = await apiFetch( {
			path: 'newspack-network/v1/content-distribution/insert',
			method: 'POST',
			data: { payload },
		} );
		// Set the budget for the story.
		await apiFetch( {
			path: `newspack-story-budget/v1/stories/${ res.post_id }/budgets`,
			method: 'POST',
			data: { value: [ budget ] },
		} );
		// Refetch story so distribution side-effects are reflected in the UI.
		fetchStory( storyId );
	};

	const handleSubmit = ( ev ) => {
		ev.preventDefault();
		setErrors( [] );
		setIsLoading( true );
		const promises = [];
		for ( const item of items ) {
			promises.push( pullStory( item.id, statusOnPublish ) );
		}
		Promise.all( promises )
			.then( () => {
				closeModal();
				onActionPerformed?.( items );
			} )
			.catch( ( error ) => {
				setErrors( [ ...errors, error.message ] );
			} )
			.finally( () => {
				setIsLoading( false );
			} );
	};

	const statusOnPublishOptions = [
		{
			label: __( 'Draft', 'newspack-network' ),
			value: 'draft',
		},
		{
			label: __( 'Pending', 'newspack-network' ),
			value: 'pending',
		},
		{
			label: __( 'Published', 'newspack-network' ),
			value: 'publish',
		},
	];

	return (
		<form onSubmit={ handleSubmit }>
			<VStack spacing={ 4 }>
				<Heading level={ 3 } ref={ headingRef }>
					{ isBulk
						? __( 'Pull Stories', 'newspack-network' )
						: sprintf(
								// translators: %s is the story title.
								__( 'Pull “%s”', 'newspack-network' ),
								items[ 0 ].name
						  ) }
				</Heading>

				{ errors.length > 0 && (
					<Notice status="error" isDismissible={ false }>
						{ errors.join( ', ' ) }
					</Notice>
				) }

				{ items.length > 1 && (
					<div>
						<p>
							{ __(
								'The following stories will be pulled:',
								'newspack-network'
							) }
						</p>
						<ul>
							{ items.map( ( item ) => (
								<li key={ item.id }>{ item.name }</li>
							) ) }
						</ul>
					</div>
				) }

				<LocalBudgetsControl
					value={ budget }
					onChange={ setBudget }
					disabled={ isLoading }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>

				<SelectControl
					label={ __( 'Status on publish', 'newspack-network' ) }
					value={ statusOnPublish }
					options={ statusOnPublishOptions }
					onChange={ setStatusOnPublish }
					disabled={ isLoading }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>

				<HStack expanded direction="row-reverse" justify="end">
					<Button
						variant="primary"
						disabled={ isLoading || ! budget }
						isBusy={ isLoading }
						type="submit"
					>
						{ items.length === 1
							? __( 'Pull story', 'newspack-network' )
							: sprintf(
									// translators: %d is the number of stories.
									__( 'Pull %d stories', 'newspack-network' ),
									items.length
							  ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ closeModal }
						disabled={ isLoading }
					>
						{ __( 'Cancel', 'newspack-network' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
}
