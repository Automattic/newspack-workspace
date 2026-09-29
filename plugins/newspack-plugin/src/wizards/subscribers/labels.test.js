/**
 * Group-label copy. Both the noun and the sentence around it come from PHP, so the
 * spoken count never reads in a different language from the heading beside it.
 */

// labels.js reads window at import time, so each payload needs its own registry.
const loadLabels = ( config = { groupLabel: '', groupLabelPlural: '' } ) => {
	window.newspackSubscribers = config;
	let labels;
	jest.isolateModules( () => {
		labels = require( './labels' );
	} );
	return labels;
};

describe( 'group labels', () => {
	it( 'inflects the default noun on a site with no override', () => {
		const { groupCountLabel } = loadLabels();

		expect( groupCountLabel( 14 ) ).toBe( '14 Groups' );
		expect( groupCountLabel( 1 ) ).toBe( '1 Group' );
	} );

	it( 'interpolates the publisher’s own noun instead', () => {
		const { groupCountLabel } = loadLabels( { groupLabel: 'Team', groupLabelPlural: 'Teams' } );

		expect( groupCountLabel( 14 ) ).toBe( '14 Teams' );
		expect( groupCountLabel( 1 ) ).toBe( '1 Team' );
	} );

	it( 'speaks the same noun the heading shows when only the singular is set', () => {
		const { GROUP_LABEL, GROUP_LABEL_PLURAL, groupCountLabel } = loadLabels( { groupLabel: 'Team', groupLabelPlural: '' } );

		expect( groupCountLabel( 1 ) ).toBe( `1 ${ GROUP_LABEL }` );
		expect( groupCountLabel( 14 ) ).toBe( `14 ${ GROUP_LABEL_PLURAL }` );
	} );

	it( 'speaks the same noun the heading shows when only the plural is set', () => {
		const { GROUP_LABEL, GROUP_LABEL_PLURAL, groupCountLabel } = loadLabels( { groupLabel: '', groupLabelPlural: 'Teams' } );

		expect( GROUP_LABEL_PLURAL ).toBe( 'Teams' );
		expect( groupCountLabel( 1 ) ).toBe( `1 ${ GROUP_LABEL }` );
		expect( groupCountLabel( 14 ) ).toBe( `14 ${ GROUP_LABEL_PLURAL }` );
	} );

	it( 'supplies the default nouns when the payload carries none', () => {
		const { GROUP_LABEL, GROUP_LABEL_PLURAL } = loadLabels();

		expect( GROUP_LABEL ).toBe( 'Group' );
		expect( GROUP_LABEL_PLURAL ).toBe( 'Groups' );
	} );

	it( 'speaks the localized default and phrasing PHP resolved when there is no override', () => {
		const { GROUP_LABEL, GROUP_LABEL_PLURAL, groupCountLabel } = loadLabels( {
			groupLabel: '',
			groupLabelPlural: '',
			groupLabelDefault: 'Gruppe',
			groupLabelDefaultPlural: 'Gruppen',
			groupPhrases: { count: '%1$s %2$s insgesamt' },
		} );

		expect( GROUP_LABEL ).toBe( 'Gruppe' );
		expect( GROUP_LABEL_PLURAL ).toBe( 'Gruppen' );
		expect( groupCountLabel( 14 ) ).toBe( '14 Gruppen insgesamt' );
		expect( groupCountLabel( 1 ) ).toBe( '1 Gruppe insgesamt' );
	} );

	it( 'keeps the publisher’s override inside the localized phrasing', () => {
		const { GROUP_LABEL, GROUP_LABEL_PLURAL, groupCountLabel } = loadLabels( {
			groupLabel: 'Team',
			groupLabelPlural: 'Teams',
			groupLabelDefault: 'Gruppe',
			groupLabelDefaultPlural: 'Gruppen',
			groupPhrases: { count: '%1$s %2$s insgesamt' },
		} );

		expect( GROUP_LABEL ).toBe( 'Team' );
		expect( GROUP_LABEL_PLURAL ).toBe( 'Teams' );
		expect( groupCountLabel( 14 ) ).toBe( '14 Teams insgesamt' );
	} );

	// Every sentence that wraps the noun takes its template from PHP, not just the count.
	it( 'takes the role, failure and link phrasing from PHP too', () => {
		const { groupRoleLabel, groupLoadFailedLabel, groupViewLabel } = loadLabels( {
			groupLabel: '',
			groupLabelPlural: '',
			groupLabelDefault: 'Gruppe',
			groupLabelDefaultPlural: 'Gruppen',
			groupPhrases: {
				role: '%s-Rolle',
				loadFailed: '%1$s konnten nicht geladen werden: %2$s',
				view: '%1$s ansehen: %2$s',
			},
		} );

		expect( groupRoleLabel() ).toBe( 'Gruppe-Rolle' );
		expect( groupLoadFailedLabel( 'Zeitüberschreitung' ) ).toBe( 'Gruppen konnten nicht geladen werden: Zeitüberschreitung' );
		expect( groupViewLabel( 'Familienabo' ) ).toBe( 'Gruppe ansehen: Familienabo' );
	} );

	it( 'falls back to the English source when the payload carries no phrasing', () => {
		const { groupRoleLabel, groupLoadFailedLabel, groupViewLabel } = loadLabels();

		expect( groupRoleLabel() ).toBe( 'Group role' );
		expect( groupLoadFailedLabel( 'timed out' ) ).toBe( 'Could not load Groups: timed out' );
		expect( groupViewLabel( 'Family plan' ) ).toBe( 'View Group: Family plan' );
	} );
} );
