# Changelog

This file records the changes in each release of Vale.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Changed

- Each prose error starts with the field's label, such as "Body:
  Vale.Repetition: ...", so the same alert in two fields shows for each. The
  message for text Vale could not check names its field too, and JSON:API's
  422 detail reads `body.0.value: Body: ...`
  ([#3632984](https://www.drupal.org/project/vale/issues/3632984)).

### Fixed

- Publish-only mode no longer refuses an edit of published content inside a
  workspace. The save drafts within the workspace, where nothing is live yet;
  the workspace publish check still stops on its errors. That check now stops
  on them even in `advise` mode, and on unchecked text while `fail_closed`,
  because under publish-only the workspace's publish is the publishing save.
  Content a workspace does not track, which saves straight to Live, keeps
  its publishing refusal.

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
