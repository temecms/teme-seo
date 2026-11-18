# Working on Teme SEO

Teme SEO is a first-party plugin kept in Teme's repository. It is built only on what `teme/contracts` offers every plugin: it never names a class in Teme's own App namespace, and Teme refuses to enable it if it does. Where it needs something core doesn't offer, write the wall in [FINDINGS.md](FINDINGS.md) instead of changing core from here.

## Rules this plugin keeps

- Teme's [code standards](../../docs/guides/developers/start/code-standards.md) and its own [coding standards](../../docs/architecture/coding-standards.md): no file headers, strict types, a one-to-three-sentence docblock on every class, a docblock on every public method, array shapes in PHPDoc.
- `@since` appears only on what other packages build on, such as a public hook and its value classes. Teme SEO has none yet, because of wall 10 in FINDINGS.md. When one comes, `@since` names Teme SEO's version, never Teme's.
- Every word people read goes through `__()`, in American English, with the English sentence as the key. Translations live in `lang/`, one JSON file per language Teme ships.
- Never learn from another SEO plugin's code. Teme SEO is written from its own description, in Teme's words and design system.
- Every write to Teme's content goes through the content door (`ContentWriter`) as the person doing the work. Teme SEO's own tables are read and written in `src/Storage` only.

## Tests and analysis

Tests live in `tests/`, use Teme's testing kit, and run against PostgreSQL in the Plugins test suite. Each file is a test class on `Support/SeoTestCase`, not a list of Pest closures: the plugins' analysis reads the tests too, and it takes `$this` in a Pest closure for Pest's own `TestCall` (wall 12 in FINDINGS.md).

```sh
vendor/bin/pest --testsuite=Plugins
vendor/bin/phpstan analyse -c phpstan-plugins.neon --no-progress
```

`composer check:plugins` runs both, and the plugins workflow runs them on GitHub when the plugin changes.

## Versions

The version in `composer.json` and `teme-plugin.json` is Teme SEO's own. Raise both together, and add the change to [CHANGELOG.md](CHANGELOG.md). Raise `requires.teme` when the plugin starts using a contract from a newer Teme.
