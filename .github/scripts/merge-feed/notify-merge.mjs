#!/usr/bin/env node
//
// Posts one Slack message per PR merged to main: a plain-English sentence
// saying what changed and for whom, plus a link to the PR. Run by the
// "Merge feed" workflow on every push to main.
//
// A push to main carries merges that are not PRs into main - release version
// bumps, `Merge branch 'release'`, and the hotfix commits a forward-port brings
// along. Those are skipped by asking GitHub which PR a commit is the merge
// commit of, and requiring that PR's base to be main.
//
// A failure never fails the job: the merge already happened, and a red run for
// a missed Slack post is noise. Errors become workflow warnings instead.
//
// Local testing: SLACK_DRY_RUN=1 prints each message instead of posting, and
// PR_NUMBERS=1153,1122 summarises those PRs instead of reading a push event.

import { readFileSync } from 'node:fs';
import Anthropic from '@anthropic-ai/sdk';

const REPO = process.env.GITHUB_REPOSITORY || 'Automattic/newspack-workspace';
const DRY_RUN = !! process.env.SLACK_DRY_RUN;
const MODEL = 'claude-opus-5-5';

// Bodies past this are mostly test steps and screenshots, which say nothing
// about the change's effect.
const MAX_BODY_CHARS = 12000;
const MAX_FILES_LISTED = 80;

// Conventional commit types, highest first, with the emoji that leads the
// Slack line. Only feat and fix get bright colours: they are what publishers
// notice, and the rest should read as background at a glance.
const TYPE_LADDER = [
	[ 'feat', '🚀' ],
	[ 'fix', '🐛' ],
	[ 'perf', '🔧' ],
	[ 'revert', '🔧' ],
	[ 'refactor', '🔧' ],
	[ 'docs', '🔧' ],
	[ 'test', '🔧' ],
	[ 'build', '⚙️' ],
	[ 'ci', '⚙️' ],
	[ 'chore', '🔧' ],
	[ 'style', '🔧' ],
];
const FALLBACK_TYPE = 'chore';

const SYSTEM_PROMPT = `You write one-line summaries of merged pull requests for a Slack channel that people across the Newspack team read: support, product, and engineering.

Write one short sentence: a present-tense verb ("Fixes", "Adds", "Stops", "Lets", "Speeds up") and the one change that matters most. Shorter is always better; the word limits below are ceilings you should rarely reach. Mention only the main change, never a second one joined with "and". Add a clause after a comma or "so" only when the sentence would otherwise not say who is affected; never add one for extra detail. Use words a non-engineer understands: no function, hook, class, file, or package names, no PR or ticket numbers. Never name a publisher or site.

You are told the change type:
- feat or fix: usually 6 to 12 words, 20 at most. These are the changes publishers and readers notice.
- anything else: usually 3 to 6 words, 10 at most. Name the kind of change and stop; leave out what it was about.

Examples:
- feat: Adds pre-migration checks for networked paid-access sites.
- feat: Lets publishers filter the Emails list.
- fix: Stops WordPress.com sites from creating duplicate image files.
- fix: Fixes "Former donors" prompts never showing to readers.
- fix: Tidies the Access Control gate cards.
- ci: Restores our automated code checks.
- chore: Corrects a code comment.
- docs: Updates guidance for AI coding assistants.

Reply with the sentence only.`;

// "feat(scope)!: subject" -> "feat". Case-insensitive because some PR titles
// use "CI:". A revert's subject is git's own `Revert "..."`.
function commitType( subject ) {
	if ( /^revert\b/i.test( subject ) ) {
		return 'revert';
	}
	const type = subject.match( /^(\w+)(\([^)]*\))?!?:/ )?.[ 1 ]?.toLowerCase();
	return TYPE_LADDER.some( ( [ name ] ) => name === type ) ? type : null;
}

// The highest type among the PR title and every commit on its branch, so a
// branch that mixes fix and feat commits reads as feat whatever its title says.
function highestType( subjects ) {
	const types = subjects.map( commitType );
	return TYPE_LADDER.find( ( [ name ] ) => types.includes( name ) )?.[ 0 ] ?? FALLBACK_TYPE;
}

function typeEmoji( type ) {
	return TYPE_LADDER.find( ( [ name ] ) => name === type )[ 1 ];
}

function warn( message ) {
	console.log( `::warning::[merge-feed] ${ message }` );
}

async function github( path ) {
	const response = await fetch( `https://api.github.com/repos/${ REPO }${ path }`, {
		headers: {
			Accept: 'application/vnd.github+json',
			Authorization: `Bearer ${ process.env.GH_TOKEN }`,
			'X-GitHub-Api-Version': '2022-11-28',
		},
	} );
	if ( ! response.ok ) {
		throw new Error( `GitHub ${ path } returned ${ response.status }` );
	}
	return response.json();
}

// The PR whose merge into main produced this commit, or null. A commit that
// reached main any other way (direct push, a forward-ported hotfix whose PR
// targeted release) has no such PR.
async function prMergedAs( sha ) {
	const pulls = await github( `/commits/${ sha }/pulls` );
	return pulls.find( pr => pr.base.ref === 'main' && pr.merge_commit_sha === sha ) || null;
}

// Merges that change nothing a reader of the feed would act on. Forward-ports
// re-land release commits that were each announced when they shipped.
function isNoise( pr ) {
	if ( pr.user.login === 'dependabot[bot]' ) {
		return 'Dependabot update';
	}
	if ( pr.head.ref.startsWith( 'chore/forward-port-' ) ) {
		return 'forward-port';
	}
	return false;
}

function cleanBody( body ) {
	const text = ( body || '' )
		.replace( /<!--[\s\S]*?-->/g, '' ) // Template instructions.
		.replace( /!\[[^\]]*\]\([^)]*\)/g, '' ) // Screenshots.
		.replace( /<img[^>]*>/g, '' )
		.trim();
	return text.length > MAX_BODY_CHARS ? `${ text.slice( 0, MAX_BODY_CHARS ) }\n[truncated]` : text;
}

async function summarise( client, pr, type ) {
	const files = await github( `/pulls/${ pr.number }/files?per_page=100` );
	const fileList = files
		.slice( 0, MAX_FILES_LISTED )
		.map( file => `${ file.filename } (+${ file.additions } -${ file.deletions })` )
		.join( '\n' );
	const moreFiles = files.length > MAX_FILES_LISTED ? `\n...and ${ files.length - MAX_FILES_LISTED } more` : '';

	const response = await client.beta.messages.create( {
		model: MODEL,
		max_tokens: 2000,
		output_config: { effort: 'low' },
		// A classifier decline on an ordinary PR would otherwise drop it from the
		// feed; this retries on the model Anthropic recommends for that category.
		betas: [ 'server-side-fallback-2026-07-01' ],
		fallbacks: 'default',
		system: SYSTEM_PROMPT,
		messages: [
			{
				role: 'user',
				content: `Type: ${ type }\n\nTitle: ${ pr.title }\n\nDescription:\n${ cleanBody( pr.body ) }\n\nChanged files:\n${ fileList }${ moreFiles }`,
			},
		],
	} );

	if ( response.stop_reason === 'refusal' ) {
		throw new Error( `model declined (${ response.stop_details?.category ?? 'no category' })` );
	}
	const text = response.content
		.filter( block => block.type === 'text' )
		.map( block => block.text )
		.join( ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
	if ( ! text ) {
		throw new Error( `empty response (stop_reason: ${ response.stop_reason })` );
	}
	return text;
}

// Slack reads <...> as links and mentions, so model output is escaped before
// it goes in: a PR body cannot make the bot ping @channel.
function slackEscape( text ) {
	return text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
}

async function post( text ) {
	if ( DRY_RUN ) {
		console.log( `[merge-feed] DRY RUN: ${ text }` );
		return;
	}
	const response = await fetch( 'https://slack.com/api/chat.postMessage', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json; charset=utf-8',
			Authorization: `Bearer ${ process.env.SLACK_AUTH_TOKEN }`,
		},
		body: JSON.stringify( {
			channel: process.env.SLACK_MERGE_FEED_CHANNEL_ID,
			text,
			unfurl_links: false,
		} ),
	} );
	const result = await response.json();
	if ( ! result.ok ) {
		throw new Error( `Slack returned ${ result.error }` );
	}
	console.log( `[merge-feed] Posted: ${ text }` );
}

async function pullRequestsToPost() {
	if ( process.env.PR_NUMBERS ) {
		return Promise.all(
			process.env.PR_NUMBERS.split( ',' ).map( number => github( `/pulls/${ number.trim() }` ) )
		);
	}
	const event = JSON.parse( readFileSync( process.env.GITHUB_EVENT_PATH, 'utf8' ) );
	const prs = [];
	for ( const commit of event.commits || [] ) {
		let pr;
		try {
			pr = await prMergedAs( commit.id );
		} catch ( error ) {
			// One failed lookup must not drop the other merges in the same push.
			warn( `${ commit.id.slice( 0, 9 ) }: could not find its PR: ${ error.message }` );
			continue;
		}
		if ( ! pr ) {
			console.log( `[merge-feed] ${ commit.id.slice( 0, 9 ) } is not a PR merge into main. Skipping.` );
			continue;
		}
		prs.push( pr );
	}
	return prs;
}

async function main() {
	if ( ! DRY_RUN && ( ! process.env.SLACK_AUTH_TOKEN || ! process.env.SLACK_MERGE_FEED_CHANNEL_ID ) ) {
		console.log( '[merge-feed] No Slack token and/or channel. Skipping.' );
		return;
	}
	const client = new Anthropic();

	for ( const pr of await pullRequestsToPost() ) {
		const noise = isNoise( pr );
		if ( noise ) {
			console.log( `[merge-feed] #${ pr.number } is a ${ noise }. Skipping.` );
			continue;
		}

		let subjects = [ pr.title ];
		try {
			const commits = await github( `/pulls/${ pr.number }/commits?per_page=100` );
			subjects = subjects.concat( commits.map( commit => commit.commit.message.split( '\n' )[ 0 ] ) );
		} catch ( error ) {
			warn( `#${ pr.number }: typing from the title alone: ${ error.message }` );
		}
		const type = highestType( subjects );

		let summary;
		try {
			summary = await summarise( client, pr, type );
		} catch ( error ) {
			// The title still tells the channel something merged; a gap in the
			// feed tells it nothing.
			warn( `#${ pr.number }: no summary, posting the title instead: ${ error.message }` );
			summary = pr.title;
		}

		try {
			await post( `${ typeEmoji( type ) } ${ slackEscape( summary ) } <${ pr.html_url }|#${ pr.number }>` );
		} catch ( error ) {
			warn( `#${ pr.number }: ${ error.message }` );
		}
	}
}

main().catch( error => warn( `unexpected error: ${ error.message }` ) );
