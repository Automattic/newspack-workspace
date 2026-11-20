/**
 * WordPress dependencies.
 */
import { Button, CardDivider } from '@wordpress/components';
import { Fragment, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ContentRule from './content-rule';
import ContentRuleControl from './content-rule-control';
import UnavailableRule from './unavailable-rule';
import { getContentRuleConfig } from '../utils';

const availableContentRules = window.newspackAudienceContentGates.available_content_rules || {};

interface ContentRulesProps {
	rules: GateContentRule[];
	onChange: ( rules: GateContentRule[] ) => void;
	isNewsletter?: boolean;
	// A row placed after the post type and taxonomy rules, ahead of the include-only ones.
	archiveRow?: React.ReactNode;
}

export default function ContentRules( { rules, onChange, isNewsletter = false, archiveRow }: ContentRulesProps ) {
	const handleToggle = useCallback(
		( slug: string ) => {
			const hasRule = rules.find( r => r.slug === slug );
			if ( hasRule ) {
				onChange( rules.filter( r => r.slug !== slug ) );
			} else {
				onChange( [ ...rules, { slug, value: availableContentRules[ slug ].default } ] );
			}
		},
		[ rules, onChange ]
	);

	const handleChange = useCallback(
		( slug: string ) => ( v: GateContentRuleValue ) => {
			onChange( rules.map( r => ( r.slug === slug ? { ...r, value: v } : r ) ) );
		},
		[ onChange, rules ]
	);

	const handleChangeExclusion = useCallback(
		( slug: string ) => ( e: boolean ) => {
			onChange( rules.map( r => ( r.slug === slug ? { ...r, exclusion: e } : r ) ) );
		},
		[ onChange, rules ]
	);

	const handleRemove = useCallback( ( slug: string ) => onChange( rules.filter( r => r.slug !== slug ) ), [ rules, onChange ] );

	// The newsletter rule is offered only while the ESP answers for its lists, but it is
	// never an "Other rule": the newsletter wizard owns it and it has nothing to show here.
	const otherRules = rules.filter( r => 'newsletters' !== r.slug && ! availableContentRules[ r.slug ] );

	const rows: { key: string; node: React.ReactNode }[] = Object.keys( availableContentRules ).map( slug => ( {
		key: slug,
		node: (
			<ContentRule
				config={ availableContentRules[ slug ] }
				enabled={ rules.map( r => r.slug ).includes( slug ) }
				rule={ rules.find( r => r.slug === slug ) }
				slug={ slug }
				onChange={ handleChange( slug ) }
				onChangeExclusion={ handleChangeExclusion( slug ) }
				onToggle={ handleToggle }
				isNewsletter={ isNewsletter }
			/>
		),
	} ) );
	if ( archiveRow ) {
		const firstIncludeOnly = rows.findIndex( row => availableContentRules[ row.key ].include_only );
		rows.splice( firstIncludeOnly === -1 ? rows.length : firstIncludeOnly, 0, { key: 'archive_rules', node: archiveRow } );
	}

	return (
		<>
			{ rows.map( ( row, index ) => (
				<Fragment key={ row.key }>
					{ row.node }
					{ index < rows.length - 1 && <CardDivider /> }
				</Fragment>
			) ) }
			{ otherRules.length > 0 && (
				<>
					<CardDivider />
					<div className="newspack-content-gates__other-rules">
						<h4>{ __( 'Other rules', 'newspack-plugin' ) }</h4>
						<p className="description">
							{ __( "These rules were set up outside this screen. They still apply, but can't be edited here.", 'newspack-plugin' ) }
						</p>
						{ otherRules.map( rule => {
							const config = getContentRuleConfig( rule.slug );
							return (
								<div key={ rule.slug }>
									{ config && ! config.unavailable ? (
										<ContentRuleControl
											slug={ rule.slug }
											value={ rule.value }
											exclusion={ rule.exclusion }
											onChange={ () => {} }
											isStatic
										/>
									) : (
										<UnavailableRule slug={ rule.slug } value={ rule.value } />
									) }
									<Button
										variant="link"
										isDestructive
										label={ sprintf(
											// translators: %s: content rule name.
											__( 'Remove %s rule', 'newspack-plugin' ),
											config?.name || rule.slug
										) }
										onClick={ () => handleRemove( rule.slug ) }
									>
										{ __( 'Remove', 'newspack-plugin' ) }
									</Button>
								</div>
							);
						} ) }
					</div>
				</>
			) }
		</>
	);
}
