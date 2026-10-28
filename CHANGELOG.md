# Changelog

This file records the changes in each release of Vale.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
