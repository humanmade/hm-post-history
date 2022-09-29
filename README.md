# Post History

This widget allows visitors to easily diff posts against their earlier revisions, displaying diffs of HTML inline.

It should be plug and play: Just add the widget to a sidebar that will appear on a page or post. It will detect everything it needs. If you have more complex needs, the code is heavily documented and includes many filters to modify behavior. If the widget's design doesn't suit your purposes, its class is easily extendable, with methods for generating all of the necessary HTML.

## Assets

This plugin requires a small amount of JS and CSS in order to work properly, and these must be built on deployment. To build them, run `.build-script`. Unless you run this script on deployment, *the plugin will not work*.

## Release process

1. Merge all PRs to be included in the release into `main`
2. Open a PR to bump the version numbers in `package.json` and `hm-post-history.php` to the next appropriate version
3. Once the PR is merged, create a tag on `main` with the version number, e.g. `v1.4.0`
4. GitHub Actions should auto-build the frontend assets and reset that tag to push to the bundled code

Check the build output in the Actions tab to see whether it works or not.
