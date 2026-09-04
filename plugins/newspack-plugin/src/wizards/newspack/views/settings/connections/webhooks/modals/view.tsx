/**
 * Settings Wizard: Connections > Webhooks > Modal > View
 */

/**
 * External dependencies
 */
import moment from 'moment';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { Icon, Notice } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Modal } from '../../../../../../../../packages/components/src';
import { getEndpointLabel, getRequestStatusIcon, hasEndpointErrors } from '../utils';

const View = ( { endpoint, setAction }: { endpoint: ModalComponentProps[ 'endpoint' ]; setAction: ModalComponentProps[ 'setAction' ] } ) => {
	return (
		<Modal title={ __( 'Latest Requests', 'newspack-plugin' ) } onRequestClose={ () => setAction( null, endpoint.id ) }>
			<Stack className="newspack-webhooks__requests-modal" direction="column" gap="xl">
				<p>
					{ sprintf(
						// translators: %s is the endpoint title (shortened URL).
						__( 'Most recent requests for %s', 'newspack-plugin' ),
						getEndpointLabel( endpoint )
					) }
				</p>
				{ endpoint.requests.length > 0 ? (
					<table className={ `newspack-webhooks__requests ${ hasEndpointErrors( endpoint ) ? 'has-error' : '' }` }>
						<thead>
							<tr>
								<th scope="col" />
								<th scope="col" colSpan={ 2 }>
									{ __( 'Action', 'newspack-plugin' ) }
								</th>
								{ hasEndpointErrors( endpoint ) && (
									<th scope="col" colSpan={ 2 }>
										{ __( 'Error', 'newspack-plugin' ) }
									</th>
								) }
							</tr>
						</thead>
						<tbody>
							{ endpoint.requests.map( request => (
								<tr key={ request.id }>
									<td className={ `status status--${ request.status }` }>
										<Icon icon={ getRequestStatusIcon( request.status ) } />
									</td>
									<td className="action-name">{ request.action_name }</td>
									<td className="scheduled">
										{ 'pending' === request.status
											? sprintf(
													// translators: %s is a human-readable time difference.
													__( 'sending in %s', 'newspack-plugin' ),
													moment( parseInt( request.scheduled ) * 1000 ).fromNow( true )
											  )
											: sprintf(
													// translators: %s is a human-readable time difference.
													__( 'processed %s', 'newspack-plugin' ),
													moment( parseInt( request.scheduled ) * 1000 ).fromNow()
											  ) }
									</td>
									{ hasEndpointErrors( endpoint ) && (
										<Fragment>
											<td className="error">
												{ request.errors && request.errors.length > 0 ? request.errors[ request.errors.length - 1 ] : '--' }
											</td>
											<td>
												<span className="error-count">
													{ sprintf(
														// translators: %s is the number of errors.
														__( 'Attempt #%s', 'newspack-plugin' ),
														String( request.errors.length )
													) }
												</span>
											</td>
										</Fragment>
									) }
								</tr>
							) ) }
						</tbody>
					</table>
				) : (
					<Notice status="info" isDismissible={ false } spokenMessage="">
						{ __( "This endpoint hasn't received any requests yet.", 'newspack-plugin' ) }
					</Notice>
				) }
			</Stack>
		</Modal>
	);
};

export default View;
