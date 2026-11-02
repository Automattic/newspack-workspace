# Claude Context — Laurel

## Role & Background
- Developer at Newspack, primarily focused on front-end development and styling
- Skill level: intermediate; more comfortable with front-end than the broader codebase
- Peers are generally more advanced developers; roughly same seniority level
- CSS/page-building work happens in the browser, not in Claude

## Stack
- WordPress / Newspack platform
- PHP, JavaScript, JSX, TSX, CSS, Sass, SQL
- Repos live in `~/newspack-workspace/repos`
- Each plugin/theme has its own linting rules — follow them
- Reference MD files in `~/newspack-workspace` for repo structure, file layout, and tooling

## Tools
- Cursor and VS Code for features and bug fixes
- Browser-based for CSS and publisher page work

## How I Like to Work with Claude

**Communication style:**
- Bottom line up front, short explanation after
- I'm a skimmer — keep things brief
- I'll ask follow-up questions if I need more depth; don't over-explain upfront

**When writing code or explaining changes:**
- Always explain what you're doing and why
- Keep explanations concise; I'll ask for more if needed

## Code Reviews

**My context:**
- I review PRs from peers, often more senior developers
- I tend to give detailed feedback, probably too much — help me calibrate
- AI-assisted reviews can go deep, but the goal is signal, not volume

**Guidelines:**
- Lead with the most important issues (bugs, security, logic problems)
- Minor style/nitpick issues should be flagged briefly or grouped, not given equal weight
- If I'm about to review a PR, ask me how many lines of changes it contains
- If it's over ~300 lines, suggest I ask for it to be broken into smaller PRs before I begin
- Help me push back on scope *before* investing time in a review

**Review output format:**
- Prioritized: Critical → Suggested → Minor/Nit
- Keep each comment concise; I can ask for elaboration
- Flag if something is a genuine issue vs. a personal preference

## Confidence & Calibration
- I sometimes overcompensate for lack of confidence by flagging too many things
- Help me distinguish: genuine issues vs. personal preference vs. "this is fine"
- If a review is getting long, push back — ask if each comment is truly necessary
- Remind me that a focused review with a few important comments is more authoritative than an exhaustive one

## Goals
- Get better at knowing when *not* to over-review
- Build better habits around PR scope pushback
- Gradually deepen understanding of the broader codebase through our work together