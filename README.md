# Post History

Browse native WordPress revisions in a frontend timeline, pin a comparison base, and view changes inline in the article. Requires WordPress 6.4 or later. Themes enable the browser with `add_theme_support( 'hm-post-history-browser' )`.

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

Themes opt in with `add_theme_support( 'hm-post-history-browser' )`. On a readable singular page the plugin inserts a native disclosure above the article. The timeline replaces the old widget and its custom REST endpoint. Hosts can narrow the opt-in surface with `hm_post_history_should_show_browser` without changing API permissions.

The compact history control keeps the comparison base above a scrollable timeline. The current page is the default base; pinning an older revision replaces it in that fixed position and moves the current page to the top of the scrolling list. Rows show a date and author avatar, with the name available to assistive technology and on hover. Choosing a version highlights its changes against the current page by default. Pin a timeline entry to compare other selections against it; unpin to return to current-page comparisons. The pin is included in deep links as `base`. It loads 20 metadata entries at a time as the reader scrolls, using Core REST pagination, then fetches only the selected content and comparison. Deep links can select versions outside the initially loaded list. URLs use `#history={native-revision-id}`. Older fragments containing `view` or `compare` remain accepted; comparison defaults to the current page unless `base` pins another version. Additions and removals always read chronologically from older to newer, regardless of which version is pinned. Identical current snapshots are omitted from the timeline, and opening history starts with the current page unless a historical deep link was supplied. Closing history restores the current article and removes the history fragment. In-flight responses cannot reopen or replace a closed history view. Historical previews and rendered HTML comparisons are shown in the article itself. Its original DOM nodes are retained and restored when history closes or the reader returns to the current page, preserving interactive state. Title changes are highlighted in the page heading. Selecting a revision displays a loading indicator and scrolls to its first visible change once ready, allowing for fixed page headers and reduced-motion preferences. The reading view shows the article’s inline changes without a raw attribute-change summary. Host themes can opt out of content insertion with `hm_post_history_browser_in_content` and render `Browser\markup( $id, $container )` in their page sidebar, as Playbook does.

Hosts may pass an optional container renderer to `Browser\markup()`. It
receives an escaped title, trusted control HTML and the history root's class/data
attributes, and returns the complete markup. The root must remain a native
`details` element with those attributes so the browser can observe its `open`
state and native `toggle` events. Playbook uses this to share its existing sidebar
disclosure component. Omitting the callback uses the plugin's plain native disclosure. Sidebar
presentation belongs to the theme's shared widget component. This changes no history permissions or data.

### Access and saved content

History uses WordPress Core's revision permissions: the signed-in user must be able to edit the parent post. Core authorizes timeline and revision requests. The browser uses `wp.apiFetch` for REST URLs and authentication, and visibility uses the post type’s registered revisions controller. The plugin does not add a separate membership, password or host-filter access policy. Ordinary readers are not granted revision access merely because the parent is published.

The browser reads the current post and revisions through their existing Core REST endpoints. Core supplies validation, ordering, pagination headers and revision-parent checks. The `hm_history` additional REST field supplies formatted dates, author names/avatars and a current-content match flag. Requests use `_fields` to avoid rendering article bodies while loading the timeline. Autosaves are omitted from the browser, while Core's own endpoints retain their normal behavior. There are no new version endpoints, revision writes, publication markers or capability grants. The browser uses Core’s `revisions` support and registered REST controller directly; it has no separate post-type support flag.

Previews use Core's `content.rendered`, including the normal `the_content` filters, block rendering and shortcodes. Dynamic and shared blocks use their current registered implementations and referenced content; they cannot reconstruct historical external state. Hosts can exclude sidebar-only blocks with `hm_post_history_hidden_blocks`. Browser responses remove invisible HTML comments and pass through `wp_kses_post()` before insertion. Core content filters already supply the theme’s normal heading anchors. There is no separate shortcode parser or static-block allowlist.

Inline comparison uses the plugin's existing `htmldiff-js` dependency on the two authorized, sanitized versions. Core's text-diff renderer produces a separate table rather than a diff within the rendered article, so the browser retains the existing HTML differ. No second, unused server-side diff is generated. Title changes are included; raw image/link attribute summaries and additional metadata comparisons are not produced. Large comparisons fall back to a saved preview.

### Assets and delivery

`npm ci && npm run build` compiles `browser.js` and `browser.css` into `assets/build/production-asset-manifest.json`. The existing tagged-release and `develop-built` workflows include these files. Deploy a built shared-plugin release through the network's Composer process before enabling the thin theme adapters. No local dependency path or development bundle belongs in the network lockfile.

The browser inherits the consumer theme's reading font. Its scoped `--history-*` tokens fall back to neutral surfaces, blue interactions and readable addition/removal colors; it does not reset global typography or introduce a new site design system. The old widget, client bundle and custom endpoints are removed.

### Manual verification

On a supported theme, sign in as an editor of the page and confirm existing revisions are listed without a new capability or publication marker. Sign in as a subscriber and confirm Core denies revision access. Check that direct requests for an inaccessible parent, another page’s revision, an autosave and an unavailable pinned base cannot be displayed.

Select a revision, pin a different base, scroll to load older versions, and unpin again. Confirm dates remain in order, the fixed base appears only once, and selecting current without a historical base restores the original article. Reload a deep link, close the panel while a request is pending, and retry after a temporary network failure. Verify keyboard focus, narrow layouts and reduced motion, then remove theme support to confirm the browser and its assets are no longer inserted.

### Compatibility and rollout

The browser UI is opt-in. The old widget, its assets and `/post-history/v1` endpoints are removed; update the Playbook theme alongside the plugin. History requests now use the post type's native Core REST endpoints. There is no database migration, publication backfill, custom capability grant or required theme release-policy filter.
