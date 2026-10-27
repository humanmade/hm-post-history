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

Themes opt in with `add_theme_support( 'hm-post-history-browser' )`. On a readable singular page the plugin inserts its history section above the article. The timeline replaces the old widget and its custom REST endpoint. Hosts can narrow the opt-in surface with `hm_post_history_should_show_browser` without changing API permissions.

The always-visible history control loads five revision records at a time through
Core REST pagination. **Load older versions** appends the next batch. The timeline
uses normal document scrolling; there is no nested scrollbar or collapsed mode.
The current page is the default comparison base. Pinning an older revision moves
it above the remaining entries; unpinning restores the current page as the base.

Selecting a revision highlights changes in the article. Comparisons run from older
to newer, regardless of which version is pinned. Returning to current restores the
original DOM nodes and their interactive state. Deep links use
`#history={revision-id}&base={revision-id}` and can load entries outside the first
batch. An ordinary page anchor is preserved until the reader selects a revision.
Identical current snapshots are omitted. Large comparisons fall back to a saved
preview; loading failures offer a retry.

Hosts can opt out of in-content controls with `hm_post_history_browser_in_content`
and render `Browser\markup( $id, $container )` in their sidebar, as Playbook does.
The optional container callback receives an escaped title, trusted control HTML
and the root class/data attributes. It should return an always-visible section
with those attributes. Without a callback the plugin supplies that same layout.
The host owns the surrounding presentation; the browser has one pagination and
interaction path.

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

Select a revision, pin a different base, use **Load older versions**, and unpin again. Confirm dates remain in order, the fixed base appears only once, and selecting current without a historical base restores the original article. Reload a deep link and retry after a temporary network failure. Verify keyboard focus, narrow layouts and reduced motion, then remove theme support to confirm the browser and its assets are no longer inserted.

### Compatibility and rollout

The browser UI is opt-in. The old widget, its assets and `/post-history/v1` endpoints are removed; update the Playbook theme alongside the plugin. History requests now use the post type's native Core REST endpoints. There is no database migration, publication backfill, custom capability grant or required theme release-policy filter.
