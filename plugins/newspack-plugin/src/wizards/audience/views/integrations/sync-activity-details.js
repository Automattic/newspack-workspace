/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Spinner, Notice, Button } from '@wordpress/components';
import { Badge, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { API_BASE, PUSH_LOG_OPERATION_LABELS, formatTimestamp } from './constants';
import { getAttemptLabel, getErrorKindLabel, getRetryNote, getStatusDisplay } from './push-log-utils';

// The flag push reached the provider; the list cleanup after it did not, so
// the fields it sent did arrive. A benign push instead found no contact to
// change, so cleanup failing the same way left nothing delivered.
const DELIVERED_ERROR_CODES = [ 'flag_cleanup_failed' ];

/**
 * The heading over the fields. A push that did not reach the provider never
 * changed anything there, so its fields are what did not arrive.
 *
 * @param {Object}      entry      The push log entry.
 * @param {Object|null} comparedTo The push it was compared with.
 * @return {string} The heading.
 */
function getFieldsHeading( entry, comparedTo ) {
	const delivered = entry.status === 'success' || ( DELIVERED_ERROR_CODES.includes( entry.error_code ) && entry.error_class !== 'benign' );
	if ( ! delivered ) {
		return __( 'Not delivered', 'newspack-plugin' );
	}
	if ( ! comparedTo ) {
		return __( 'Fields sent', 'newspack-plugin' );
	}
	/* translators: %s: date and time of the reader's previous successful push. */
	return sprintf( __( 'Changed since %s', 'newspack-plugin' ), formatTimestamp( comparedTo.updated_at ) );
}

/**
 * The heading over what the provider said. A row that did not end in success
 * is an error regardless of what it is classified as; only on a success does
 * a benign classification mean the row never failed.
 *
 * @param {Object} entry The push log entry.
 * @return {string} The heading.
 */
function getErrorHeading( entry ) {
	if ( entry.status !== 'success' ) {
		return __( 'Error', 'newspack-plugin' );
	}
	return entry.error_class === 'benign' ? __( 'Provider response', 'newspack-plugin' ) : __( 'Earlier attempts failed with', 'newspack-plugin' );
}

/**
 * What to say about a push with no fields to show. Only a hard delete really
 * sends none; anything else with none is a payload the log could not read
 * back, and calling that a deletion would misname it.
 *
 * @param {Object} entry The push log entry.
 * @return {string} The message.
 */
function getEmptyFieldsMessage( entry ) {
	if ( entry.operation !== 'delete' ) {
		return __( 'No fields were recorded for this push.', 'newspack-plugin' );
	}
	return entry.status === 'success'
		? __( 'The contact was deleted. No data was sent.', 'newspack-plugin' )
		: __( 'The deletion did not reach the provider. A deletion sends no data.', 'newspack-plugin' );
}

// A field the push left out is not a field it cleared, and a field sent empty
// is not a field missing: the three read differently.
const formatAfter = value => {
	if ( value === null || value === undefined ) {
		return __( 'Not sent', 'newspack-plugin' );
	}
	return value === '' ? __( '(empty)', 'newspack-plugin' ) : value;
};

const formatBefore = value => {
	if ( value === null || value === undefined ) {
		return '—';
	}
	return value === '' ? __( '(empty)', 'newspack-plugin' ) : value;
};

const FieldsTable = ( { fields, showBefore, labelledBy } ) => (
	<table className="newspack-integration-log-details__fields" aria-labelledby={ labelledBy }>
		<thead>
			<tr>
				<th scope="col">{ __( 'Field', 'newspack-plugin' ) }</th>
				{ showBefore && <th scope="col">{ __( 'Before', 'newspack-plugin' ) }</th> }
				<th scope="col">{ showBefore ? __( 'After', 'newspack-plugin' ) : __( 'Value', 'newspack-plugin' ) }</th>
			</tr>
		</thead>
		<tbody>
			{ fields.map( field => (
				<tr key={ field.key } className={ field.volatile ? 'newspack-integration-log-details__field--volatile' : undefined }>
					<th scope="row">{ field.label }</th>
					{ showBefore && <td>{ formatBefore( field.before ) }</td> }
					<td>{ formatAfter( field.after ) }</td>
				</tr>
			) ) }
		</tbody>
	</table>
);

/**
 * One push log entry: what was sent, how it went, and which fields changed
 * since the reader's previous successful push.
 *
 * @param {Object} props               Props.
 * @param {string} props.integrationId The integration the entry belongs to.
 * @param {number} props.entryId       The entry.
 */
export const SyncActivityDetails = ( { integrationId, entryId } ) => {
	const [ data, setData ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ showAll, setShowAll ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );
		setError( null );

		apiFetch( { path: `${ API_BASE }/${ integrationId }/push-log/${ entryId }` } )
			.then( response => {
				if ( ! cancelled ) {
					setData( response );
				}
			} )
			.catch( err => {
				if ( cancelled ) {
					return;
				}
				setError(
					err?.data?.status === 404
						? __( 'This entry no longer exists.', 'newspack-plugin' )
						: __( 'Failed to load the entry.', 'newspack-plugin' )
				);
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ integrationId, entryId ] );

	if ( isLoading ) {
		return (
			<div className="newspack-integration-log-details--loading">
				<Spinner />
			</div>
		);
	}

	if ( error ) {
		// Raised by the mount GET, so it can render as the drawer opens:
		// assertive would cut off the title being announced.
		return (
			<Notice status="error" isDismissible={ false } politeness="polite">
				{ error }
			</Notice>
		);
	}

	if ( ! data || ! data.entry ) {
		return null;
	}

	const { entry, compared_to: comparedTo, fields } = data;
	const status = getStatusDisplay( entry );
	const attemptLabel = getAttemptLabel( entry );
	const retryNote = getRetryNote( entry );
	const hasError = Boolean( entry.error_code || entry.error_message );
	const changedFields = fields.filter( field => field.changed );
	// With nothing to compare against there is no "changed" subset to open on.
	const listsEverything = ! comparedTo || showAll;
	// The toggle only has something to reveal while some field is held back.
	const canShowMore = Boolean( comparedTo ) && changedFields.length < fields.length;
	const fieldsHeadingId = `newspack-integration-log-details__fields-heading-${ entryId }`;

	return (
		<Stack direction="column" gap="xl">
			<div className="newspack-integration-log-details__header">
				<h3>{ entry.email }</h3>
				<Badge intent={ status.intent }>{ status.label }</Badge>
			</div>

			<dl className="newspack-integration-log-details__meta">
				<dt>{ __( 'Operation', 'newspack-plugin' ) }</dt>
				<dd>{ PUSH_LOG_OPERATION_LABELS[ entry.operation ] || entry.operation }</dd>

				<dt>{ __( 'Trigger', 'newspack-plugin' ) }</dt>
				<dd>{ entry.context || '—' }</dd>

				<dt>{ __( 'First attempt', 'newspack-plugin' ) }</dt>
				<dd>{ formatTimestamp( entry.created_at ) }</dd>

				<dt>{ __( 'Last activity', 'newspack-plugin' ) }</dt>
				<dd>{ formatTimestamp( entry.updated_at ) }</dd>

				{ attemptLabel && (
					<>
						<dt>{ __( 'Retries', 'newspack-plugin' ) }</dt>
						<dd>{ attemptLabel }</dd>
					</>
				) }

				{ entry.repeat_count > 0 && (
					<>
						<dt>{ __( 'Repeats', 'newspack-plugin' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: %d: how many later pushes sent the same data. */
								_n( 'Sent %d more time unchanged', 'Sent %d more times unchanged', entry.repeat_count, 'newspack-plugin' ),
								entry.repeat_count
							) }
						</dd>
					</>
				) }

				{ entry.retry?.is_pending && entry.retry.scheduled_at && (
					<>
						<dt>{ __( 'Next retry', 'newspack-plugin' ) }</dt>
						<dd>{ formatTimestamp( entry.retry.scheduled_at ) }</dd>
					</>
				) }

				{ retryNote && (
					<>
						<dt>{ __( 'Retry', 'newspack-plugin' ) }</dt>
						<dd>{ retryNote }</dd>
					</>
				) }
			</dl>

			{ hasError && (
				<section className="newspack-integration-log-details__section">
					<h4>{ getErrorHeading( entry ) }</h4>
					<dl className="newspack-integration-log-details__meta">
						{ entry.error_class && (
							<>
								<dt>{ __( 'Type', 'newspack-plugin' ) }</dt>
								<dd>{ getErrorKindLabel( entry ) }</dd>
							</>
						) }
						{ entry.error_code && (
							<>
								<dt>{ __( 'Code', 'newspack-plugin' ) }</dt>
								<dd>
									<code>{ entry.error_code }</code>
								</dd>
							</>
						) }
						{ entry.error_message && (
							<>
								<dt>{ __( 'Message', 'newspack-plugin' ) }</dt>
								<dd>{ entry.error_message }</dd>
							</>
						) }
					</dl>
				</section>
			) }

			<section className="newspack-integration-log-details__section">
				{ fields.length === 0 ? (
					<p>{ getEmptyFieldsMessage( entry ) }</p>
				) : (
					<>
						<h4 id={ fieldsHeadingId }>{ getFieldsHeading( entry, comparedTo ) }</h4>
						{ ! listsEverything && changedFields.length === 0 && <p>{ __( 'No fields changed.', 'newspack-plugin' ) }</p> }
						{ ( listsEverything || changedFields.length > 0 ) && (
							<FieldsTable
								fields={ listsEverything ? fields : changedFields }
								showBefore={ Boolean( comparedTo ) }
								labelledBy={ fieldsHeadingId }
							/>
						) }
						{ canShowMore && (
							<Button variant="link" onClick={ () => setShowAll( ! showAll ) }>
								{ showAll
									? __( 'Show changed fields only', 'newspack-plugin' )
									: sprintf(
											/* translators: %d: number of fields in the push. */
											_n( 'Show all %d field sent', 'Show all %d fields sent', fields.length, 'newspack-plugin' ),
											fields.length
									  ) }
							</Button>
						) }
					</>
				) }
			</section>
		</Stack>
	);
};
