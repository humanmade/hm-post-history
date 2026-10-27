# Development conventions

- Import external PHP classes and namespaces with `use` declarations. Import external namespaced functions with `use function`, and constants with `use const`, when referenced. Use those imports in code, type declarations, PHPDoc and callback names rather than repeating fully qualified names. Global WordPress functions can use their normal unqualified names.
- Reuse WordPress Core revision storage, retrieval and permissions. Keep the plugin focused on browsing and comparing existing revisions; do not introduce a separate publication or release-tracking mechanism.
- Preserve the existing `htmldiff-js` implementation for comparisons in the rendered article. Do not generate a second diff representation unless the interface needs it.
