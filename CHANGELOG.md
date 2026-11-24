# Changelog

This file records the changes in each release of Vale.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 (unreleased)

### Added

- [#32](https://github.com/Decipher/vale/issues/32): Checks the text in the
  components of Canvas pages. A settings form at
  **Configuration > Content authoring > Canvas page prose checks** turns it on
  for every prop that Canvas treats as plain or rich prose, with a list of
  props to leave out. An error refuses publishing the page, and Canvas's
  review panel links it to its component. The content report, its CSV export
  and `vale:lint` name the component and prop. Canvas is optional, and
  nothing changes on a site without it.
- [#3638310](https://www.drupal.org/project/vale/issues/3638310): `vale:sync` downloads
  the styles that the site's `.vale.ini` names, with Vale's sync, into its
  `StylesPath`.
- [#63](https://github.com/Decipher/vale/issues/63): `vale:install` also
  installs Vale's browser build, `vale.wasm` and `wasm_exec.js`, from Vale
  3.26.0, at the binary's version and checked against the release's
  checksums. It goes in `libraries/vale` under the web root, or in the
  directory `$settings['vale_wasm']` names. The status report shows its
  version beside the binary's, and warns when they differ.
- [#64](https://github.com/Decipher/vale/issues/64): Checks each value in
  the language of its entity translation, with Vale 3.27.0 or later. The
  module sets `Lang` at the top of the configuration it passes to Vale, and
  a `.vale.ini` section that sets its own `Lang` still wins. English, "Not
  specified" and "Not applicable" values are checked as before.
  `vale:sync` also downloads the model for each of the site's languages. The
  status report lists the site's languages, and names those with no model
  and those with no spelling dictionary. With an older Vale, every value is
  checked in English and the status report says why.
- [#65](https://github.com/Decipher/vale/issues/65): Serves the site's
  Vale configuration to the browser build: the `.vale.ini` for each
  language at `/vale/config/{langcode}`, the styles as a zip at
  `/vale/styles`, and a language's model and dictionary as a zip at
  `/vale/models/{langcode}`. They need the **Use the Vale check endpoint**
  permission, and serve nothing outside the `.vale.ini` and `StylesPath`.
  The zips are cached until the styles change, and a browser keeps a zip
  it asked for with the styles' fingerprint. The status report and the
  field settings link to the styles zip, which the Vale browser extension
  imports as a package.
- [#33](https://github.com/Decipher/vale/issues/33): The README shows how to
  run Vale on Lagoon, Acquia Cloud Platform, Pantheon, Platform.sh and Upsun,
  with a script that installs a pinned Vale and its styles in a build with no
  database.

### Changed

- [#3639107](https://www.drupal.org/project/vale/issues/3639107): The commands run under
  core's `dr` and Drush 15, as plain Symfony Console commands. Drush 12, 13
  and 14 still run them as `drush vale:...`. Their names, arguments, exit
  codes and default fields are unchanged.
- The content report's table has two new columns, `component` and `prop`.
  Run the database updates after updating.

### Removed

- [#3639107](https://www.drupal.org/project/vale/issues/3639107): `--format` takes `table`,
  `json` or `csv` only. Drush's other formats, such as `yaml`, `tsv`, `list`
  and `var_export`, are gone, and so are Drush's other output options, such
  as `--field` and `--filter`.

## 1.0.1 (2026-10-31)

### Fixed

- [#3634055](https://www.drupal.org/project/vale/issues/3634055): In
  publish-only mode, a paragraph saved by itself, such as over JSON:API,
  could change a published page's text with an error in it. Paragraphs makes
  no new revision for such a save, so the page shows the change at once. That
  save is now refused like a publishing save.
- [#3634055](https://www.drupal.org/project/vale/issues/3634055): A bundle
  lint labelled a paragraph that only a newer draft holds as a "(previous
  revision)".

## 1.0.0 (2026-10-28)

### Added

- [#3632782](https://www.drupal.org/project/vale/issues/3632782): Drupal 12
  support, alongside Drupal 10.3 and 11.
- A development recipe that `make provision` applies. A fresh development
  site gets a Page type whose body is checked with Vale, with Vale installed
  and configured.

### Changed

- [#3632984](https://www.drupal.org/project/vale/issues/3632984): Each prose
  error starts with its field's label, such as `Body: Vale.Repetition: ...`,
  so the same alert in two fields is listed for each. JSON:API's 422 detail
  includes the label too.
- The workspace publish check names each field by its label as well.

### Fixed

- [#3632983](https://www.drupal.org/project/vale/issues/3632983):
  `drush vale:lint ENTITY_TYPE:BUNDLE` refuses a bundle with nothing checked
  by Vale, instead of printing an empty table that read as a pass.
- [#3633410](https://www.drupal.org/project/vale/issues/3633410): Under
  publish-only mode, an edit of published content inside a workspace saves
  as a draft. Publishing the workspace stops on its errors, in `advise` mode
  as well as `block`.
- Linting a paragraph bundle reports only paragraphs that a host still holds,
  in the revision and translations that host uses.
- Under publish-only mode, JSON:API's 422 detail for a refused publish is
  plain text, without HTML entities.
- Under publish-only mode, a paragraph's error that refuses a publish is no
  longer also listed as a warning.
- The content report drops a field's rows when Vale is turned off on it, and
  refreshes when a draft revision is deleted. Its pages sort by row as well,
  so none repeat or go missing.
- `drush vale:install` makes Vale executable before it replaces the old
  binary, so a failed install leaves the old one working.
- A Vale run that failed or timed out is not cached. A failed version or
  styles probe no longer counts for an hour, and a failing Vale runs once per
  value in a request.
- The check endpoint's limit of four checks at once covers Vale's version and
  styles probes, and a slow check no longer outlasts its slot.
- The status report says that unchecked text is refused under `fail_closed`,
  and is fully translatable.

## 1.0.0-beta1 (2026-10-21)

### Added

- Checks on save. A text field with **Check with Vale** on is checked on every
  save that validates: forms, JSON:API, REST and workspace publishing. An
  error stops the save and is marked on the field. Warnings and suggestions
  are shown to the author.
- Field settings for a minimum level and a level for each rule. A profile
  name lets `.vale.ini` sections match the field.
- Site settings in `settings.php`: `formats`, `default_extension`,
  `publish_only`, `fail_closed`, `workspace_mode`, `timeout`, `check_limit`
  and `check_window`.
- Publish-only mode, which follows Content Moderation.
- Paragraphs support. Opted-in fields on paragraphs are checked, and
  publishing a page checks its paragraphs.
- A workspace check before publishing, which can block the publish or list
  the alerts.
- `drush vale:lint`, `vale:check`, `vale:rules`, `vale:status` and
  `vale:install`.
- **Reports > Prose linting**, a report of the alerts in stored content, with
  a scan, filters, a CSV export and a **Check prose with Vale** bulk action.
- The check endpoint, `POST /vale/check`, with flood and concurrency limits.
- JSON:API errors that point at the field, and the other alerts in
  `meta.vale`.
- A status report entry with Vale's version and rule count.
