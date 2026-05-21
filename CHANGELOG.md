# Changelog

All notable changes to DiscussionForum will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial extraction from `labki-platform`'s inline forum block (commit history
  for the migrated code lives in `labki-org/labki-platform`, primarily under
  `mediawiki/extensions.platform.php` through PR #78).
- Forum-style topic pages on top of DiscussionTools: talk-namespace subpages
  with `<UTC-timestamp>_<username>` slug, routed to DT's new-topic widget by a
  drop-in `.discussionforum-new-post-btn` (or `[data-discussionforum-new-post]`)
  trigger element.
- Five SMW properties (`Has forum`, `Topic subject`, `Topic starter`,
  `Comment count`, `Participant count`) populated per topic so a forum landing
  page can render a topic index via `#ask`. Internal IDs (`___forum_*`) and
  human labels are unchanged from the labki-platform vintage to keep existing
  on-wiki templates working.
- `discussionForumAnnotate` job: parses Parsoid HTML via DT after each save,
  stashes the count/participant/starter bundle keyed by revision id, and
  triggers a `RefreshLinksJob` so SMW re-stores the page with the
  DT-derived data flowing through `ParserAfterParse`.
- Forum subscription fanout: copies the forum landing page's watchers onto
  each newly-created topic page (port of `labki-platform` PR #74), so the
  Watch star on a forum landing produces a real "subscribe to this forum"
  experience via the standard watchlist plumbing.
- Workaround for upstream DiscussionTools auto-subscribe bug (T-undecided):
  when `$wgLocaltimezone !== 'UTC'`, DT's archiving-guard in
  `EventDispatcher::generateEventsFromItemSets()` suppresses
  `dt-subscribed-new-comment` events on every save. DiscussionForum writes
  the missing `discussiontools_subscription` row directly for forum-namespace
  saves. Gated by `$wgDiscussionForumPatchAutoSubscribeTZBug` (default on);
  set to `false` once a patched DT version is deployed.
- `maintenance/backfillForumAnnotations.php`: one-shot script that enqueues
  the annotate job for every existing forum topic, so deployments don't
  strand content saved before the extension was loaded.

### Renamed (breaking) from the labki-platform vintage
- CSS class hook `labki-forum-new-post-btn` → `discussionforum-new-post-btn`.
- Data attribute `data-labki-forum-new-post` → `data-discussionforum-new-post`.
- Landing wrapper class `.labki-forum-landing` → `.discussionforum-landing`.
- ResourceLoader modules `ext.labki.forum{,.styles}` → `ext.discussionforum{,.styles}`.
- Job name `labkiForumDTAnnotate` → `discussionForumAnnotate`. Drain the old
  queue before cutover deploys: `php maintenance/run.php runJobs.php --type labkiForumDTAnnotate`.

### Unchanged from the labki-platform vintage
- SMW property internal IDs (`___forum_*`) — no `rebuildData.php` needed.
- SMW property labels (`Has forum`, `Topic subject`, …) — existing user-wiki
  `#ask` queries against these labels keep working.
