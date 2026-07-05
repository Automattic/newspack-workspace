/**
 * WordPress dependencies
 */
import { sprintf, __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { Fragment, useEffect, useMemo } from '@wordpress/element';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';

function MembershipsGate() {
	const newspack_memberships_gate = window.newspack_memberships_gate || {};
	const { gate_plans, plans } = newspack_memberships_gate;
	const { createNotice } = useDispatch( 'core/notices' );
	useEffect( () => {
		if ( gate_plans && Object.keys( gate_plans ).length ) {
			createNotice(
				'info',
				sprintf(
					// translators: %s is the list of plans.
					__( "You're currently editing a gate for content restricted by: %s", 'newspack-plugin' ),
					Object.values( gate_plans ).join( ', ' )
				)
			);
		}
	}, [] );
	const plansToEdit = useMemo( () => {
		if ( ! plans?.length || ! gate_plans ) {
			return [];
		}
		const currentGatePlans = Object.keys( gate_plans ) || [];
		return plans.filter( plan => {
			return ! currentGatePlans.includes( plan.id.toString() );
		} );
	}, [ gate_plans, plans ] );

	if ( ! plans?.length ) {
		return null;
	}
	return (
		<PluginDocumentSettingPanel name="content-gate-plans" title={ __( 'WooCommerce Memberships', 'newspack-plugin' ) }>
			{ ! Object.keys( newspack_memberships_gate?.gate_plans ).length ? (
				<Fragment>
					<p>
						{ __(
							'This gate will be rendered for all membership plans. Manage custom gates for when the content is locked behind a specific plan:',
							'newspack-plugin'
						) }
					</p>
				</Fragment>
			) : (
				<Fragment>
					<p>
						{ sprintf(
							// translators: %s is the list of plans.
							__( 'This gate will be rendered for the following membership plans: %s', 'newspack-plugin' ),
							Object.values( newspack_memberships_gate.gate_plans ).join( ', ' )
						) }
					</p>
					<hr />
					<p
						dangerouslySetInnerHTML={ {
							__html: sprintf(
								// translators: %s is the link to the primary gate.
								__( 'Edit the <a href="%s">primary gate</a>, or:', 'newspack-plugin' ),
								newspack_memberships_gate.edit_gate_url
							),
						} }
					/>
				</Fragment>
			) }
			<ul>
				{ plansToEdit.map( plan => (
					<li key={ plan.id }>
						{ plan.name } (
						{ plan.gate_id !== false && (
							<Fragment>
								<strong>
									{ plan.gate_status === 'publish' ? __( 'published', 'newspack-plugin' ) : __( 'draft', 'newspack-plugin' ) }
								</strong>{ ' ' }
								-{ ' ' }
							</Fragment>
						) }
						<a href={ newspack_memberships_gate.edit_plan_gate_url + '&plan_id=' + plan.id }>
							{ plan.gate_id ? __( 'edit gate', 'newspack-plugin' ) : __( 'create gate', 'newspack-plugin' ) }
						</a>
						)
					</li>
				) ) }
			</ul>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'newspack-memberships-gate', {
	render: MembershipsGate,
	icon: null,
} );
