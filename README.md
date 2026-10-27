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

### Access and saved content

History follows WordPress Core's revision permission: the signed-in user must be able to edit the parent post. Site membership on multisite (or super administrator), parent read access, feature support and password protection are also checked. `hm_post_history_can_read_post` may further restrict access. The same checks apply to the timeline and the legacy endpoint. Ordinary readers are not granted access to drafts merely because the parent is published.

The browser reads existing native revisions through `wp_get_post_revisions()`. It does not create revisions, attach publication markers, classify past saves as releases, or grant capabilities. Autosaves are omitted. Direct selection and pinned comparisons validate that each revision belongs to the requested parent. The small query filter adds ID-cursor pagination and excludes autosaves before the page limit is applied; Core supplies revision retrieval and ordering.

Previews render saved, sanitized core presentation markup without executing dynamic block callbacks, shortcodes or `the_content` filters. Hidden/custom/editorial blocks, forms/scripts and HTML comments are omitted. Shared-block references cannot recover historical content and are shown as unavailable. Hosts can exclude blocks already represented elsewhere with `hm_post_history_hidden_blocks` and apply static presentation changes with `hm_post_history_saved_html`; the result is sanitized again. This is a reading view of saved article content, not a reconstruction of the entire historical page layout.

Inline comparison uses the plugin's existing `htmldiff-js` dependency on the two authorized, sanitized versions. Core's text-diff renderer produces a separate table rather than a diff within the rendered article, so the browser retains the existing HTML differ. No second, unused server-side diff is generated. Title changes are included; raw image/link attribute summaries and additional metadata comparisons are not produced. Large comparisons fall back to a saved preview.

### Assets and delivery

`npm ci && npm run build` compiles both the existing frontend entry and the new `browser.js`/`browser.css` entry into `assets/build/production-asset-manifest.json`. The existing tagged-release and `develop-built` workflows include these files. Deploy a built shared-plugin release through the network's Composer process before enabling the thin theme adapters. No local dependency path or development bundle belongs in the network lockfile.

The browser inherits the consumer theme's reading font. Its scoped `--history-*` tokens fall back to neutral surfaces, blue interactions and readable addition/removal colors; it does not reset global typography or introduce a new site design system. Both interfaces use the same Core-aligned revision permission policy. The old UI and its assets are suppressed when the replacement browser is available; stored widget settings are preserved for fallback.

### Manual verification

On a supported theme, sign in as an editor of the page and confirm existing revisions are listed without a new capability or publication marker. Sign in as a subscriber and confirm both APIs deny access. Check that direct requests for an inaccessible parent, another page’s revision, an autosave and an unavailable pinned base are rejected.

Select a revision, pin a different base, scroll to load older versions, and unpin again. Confirm dates remain in order, the fixed base appears only once, and selecting current without a historical base restores the original article. Reload a deep link, close the panel while a request is pending, and retry after a temporary network failure. Verify keyboard focus, narrow layouts and reduced motion, then remove theme support to check the preserved legacy widget fallback.

### Compatibility and rollout

The browser UI is opt-in. Existing widget settings, the legacy endpoint response shape and `hm_post_history_default_per_page` remain available. The legacy endpoint now requires Core's `edit_post` permission and sanitizes saved markup rather than executing dynamic content filters; requests are capped at 50 entries. Review consumers that previously exposed history to ordinary readers before a network-wide update. Opting out of the new UI does not restore the old, broader endpoint permissions. There is no database migration, publication backfill, custom capability grant or required theme release-policy filter.
