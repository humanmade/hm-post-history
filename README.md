# Post History

This widget allows visitors to easily diff posts against their earlier revisions, displaying diffs of HTML inline.

It should be plug and play: Just add the widget to a sidebar that will appear on a page or post. It will detect everything it needs. If you have more complex needs, the code is heavily documented and includes many filters to modify behavior. If the widget's design doesn't suit your purposes, its class is easily extendable, with methods for generating all of the necessary HTML.

## Release process

This plugin requires a small amount of JS and CSS in order to work properly, and these must be built on deployment. To ensure a tagged release includes these built assets, follow the process below for each release:

1. Merge all PRs to be included in the release into `main`
2. Open a PR to bump the version numbers in `package.json` and `hm-post-history.php` to the next appropriate version
3. Once the PR is merged, create a tag on `main` with the version number, e.g. `v1.4.0`
4. GitHub Actions should auto-build the frontend assets and reset that tag to push to the bundled code

Check the build output in the Actions tab to see whether it works or not.

### Development Builds

To test the plugin on a deployed instance via composer, register the repository as a VCS source, and set your `humanmade/hm-post-history` dependency to track the `dev-develop-built` branch source.

Merge PRs which require development testing into the `develop` branch, and they will be automatically built and pushed to that `develop-built` branch using a GitHub Action.

If code which is not intended for release ends up on `develop`, force-reset both `develop` and `develop-built` to match the latest `main`.

## Shared history browser

Themes opt in with `add_theme_support( 'hm-post-history-browser' )`. On a readable singular page the plugin inserts a native disclosure above the article. The shared browser replaces the legacy widget on supported pages. Saved widget settings and `/post-history/v1/revisions/{id}` remain available for compatibility and fallback. Hosts can narrow the opt-in surface with `hm_post_history_should_show_browser` without changing API permissions. Hosts can delegate their existing compatibility routes to the same implementation without adding another revision store or browser bundle.

The compact history control keeps the comparison base above a scrollable timeline. The current page is the default base; pinning an older revision replaces it in that fixed position and moves the current page to the top of the scrolling list. Rows show a date and author avatar, with the name available to assistive technology and on hover. Choosing a version highlights its changes against the current page by default. Pin a timeline entry to compare other selections against it; unpin to return to current-page comparisons. The pin is included in deep links as `base`. It loads 20 metadata entries at a time as the reader scrolls, using an ID cursor, then fetches only the selected content and comparison. Deep links can select versions outside the initially loaded list. URLs use `#history={native-revision-id}`. Older fragments containing `view` or `compare` remain accepted; comparison defaults to the current page unless `base` pins another version. Additions and removals always read chronologically from older to newer, regardless of which version is pinned. Identical current snapshots are omitted from the timeline, and opening history starts with the current page unless a historical deep link was supplied. Closing history restores the current article and removes the history fragment. In-flight responses cannot reopen or replace a closed history view. Historical previews and rendered HTML comparisons are shown in the article itself. Its original DOM nodes are retained and restored when history closes or the reader returns to the current page, preserving interactive state. Title changes are highlighted in the page heading. Selecting a revision displays a loading indicator and scrolls to its first visible change once ready, allowing for fixed page headers and reduced-motion preferences. The reading view shows the article’s inline changes without a raw attribute-change summary. Host themes can opt out of content insertion with `hm_post_history_browser_in_content` and render `Browser\markup( $id, true )` in their page sidebar, as Playbook does.

Hosts may pass a third, optional container renderer to `Browser\markup()`. It
receives an escaped title, trusted control HTML and the history root's class/data
attributes, and returns the complete markup. The root must remain a native
`details` element with those attributes so the browser can observe its `open`
state and native `toggle` events. Playbook uses this to share its existing sidebar
disclosure component. Omitting the callback retains the plugin's standalone
container and sidebar presentation. This changes no history permissions or data.

### Visibility and release evidence

All routes require a signed-in reader, site membership on multisite (or super administrator), `read_post` on the parent, feature support, and no outstanding password protection. `hm_post_history_can_read_post` can **further restrict** that access for a host's page policy. This does not grant publish, edit or restore capabilities.

A native revision is reader-visible only when its parent is currently published and it carries a matching `_hm_post_history_release` fingerprint. The plugin records this metadata on the native revision after an ordinary published save has completed and the revision matches the saved title, body, excerpt and explicitly tracked metadata. It uses `add_metadata`, because WordPress's `add_post_meta` redirects revision IDs to the parent. Evidence is never inferred from the parent's status when reading old revisions. Autosaves and imports (`WP_IMPORTING`) are excluded. Hosts can reject snapshots with `hm_post_history_is_release_candidate`, which receives the current boolean decision and the native `WP_Post` revision. This filter runs both before evidence is captured and when release evidence is read; returning true does not bypass fingerprint or access checks. An importer can suppress capture with `hm_post_history_capture_release`.

Existing revisions are neither deleted nor retroactively labelled as published. Editors deliberately granted `read_unpublished_post_history` in addition to `edit_post` can browse unknown old revisions. The timeline identifies authors through avatars without publication-status labels; those labels do not affect the server-side access policy. Readers may therefore see no past versions until real published saves occur. The plugin assigns no roles or capabilities automatically; a site Administrator role alone is not this grant (WordPress super administrators retain their inherent capability bypass). There is deliberately no automatic historical backfill. The host remains responsible for enforcing its actual publication workflow before content becomes the published page; this feature does not certify owner approval.

### Content and metadata

Previews render saved, sanitized core presentation markup, not current dynamic block callbacks or `the_content` filters. Hidden blocks, custom/editorial blocks, shortcodes (including enclosed text), forms/scripts and HTML comments are omitted. Comments/Notes and review tasks are never fetched. This prevents historical rendering from invoking integrations or revealing hidden fallback text. Unsupported blocks are identified in the preview rather than silently executed. Shared-block references cannot recover historical content from the referencing revision and are shown as unavailable. Hosts can exclude blocks already represented elsewhere on the page with `hm_post_history_hidden_blocks`, and apply static presentation changes with `hm_post_history_saved_html`; its output is sanitized again.

Inline comparison uses `htmldiff-js` on the two server-authorized, sanitized versions. The API also supplies a detailed comparison using WordPress's text diff, including titles, excerpts, core block text, link/image destinations and image alternative text. It reports earlier-to-later changes with both text labels and addition/removal styling. Layout-only block changes are not represented as a pixel comparison. Large comparisons are bounded; saved previews remain available.

To include a metadata field, the host must register it as natively revision-enabled and explicitly return a `key => human-readable label` mapping from `hm_post_history_meta_fields`. Non-revisioned ownership or permission data must never be reconstructed from today's value. Do not change the tracked-field policy casually: a changed fingerprint policy intentionally fails closed for incompatible older evidence. Release evidence is separate from the owner-review fingerprint and cannot be used as a review receipt.

### Assets and delivery

`npm ci && npm run build` compiles both the existing frontend entry and the new `browser.js`/`browser.css` entry into `assets/build/production-asset-manifest.json`. The existing tagged-release and `develop-built` workflows include these files. Deploy a built shared-plugin release through the network's Composer process before enabling the thin theme adapters. No local dependency path or development bundle belongs in the network lockfile.

The browser inherits the consumer theme's reading font. Its scoped `--history-*` tokens fall back to neutral surfaces, blue interactions and readable addition/removal colors; it does not reset global typography or introduce a new site design system. Both legacy routes use the same stricter server permission policy. The old UI and its assets are suppressed when the replacement browser is available; stored widget settings are preserved for fallback.

### Manual verification

On a supported theme, sign in as a reader and confirm only released revisions are listed. An editor also needs `read_unpublished_post_history` to inspect legacy or unreleased revisions. Check that direct requests for an inaccessible parent, another page’s revision, an autosave and an unavailable pinned base are rejected.

Select a revision, pin a different base, scroll to load older versions, and unpin again. Confirm dates remain in order, the fixed base appears only once, and selecting current without a historical base restores the original article. Reload a deep link, close the panel while a request is pending, and retry after a temporary network failure. Verify keyboard focus, narrow layouts and reduced motion, then remove theme support to check the preserved legacy widget fallback.

### Compatibility and rollout

The browser UI is opt-in, and the existing widget, endpoint response shape and `hm_post_history_default_per_page` filter remain available. This release is **not behavior-preserving for existing consumers**: the legacy endpoint now applies the same authenticated parent/revision policy, excludes unverified historical snapshots for ordinary readers, and sanitizes saved markup rather than running dynamic content filters. Non-public post types are not exposed; legacy requests are capped at 50 items. Review existing consumers and capability grants before deploying this as a network-wide dependency update. The UI opt-in alone does not isolate these API changes.
