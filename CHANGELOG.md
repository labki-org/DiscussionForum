# Changelog

All notable changes to DiscussionForum will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **`DTAutoSubscribeHooks` now handles the DT 1.44+ author shape.** Upstream
  DiscussionTools changed `ContentThreadItem::getAuthorsBelow()` to return
  an array of `[ 'username' => string, 'displayNames' => string[] ]`
  associative arrays (was previously a flat `string[]` of usernames). The
  workaround's `in_array( $user->getName(), $authors, true )` author-gate
  was strict-comparing a string against an associative array on the new
  shape and always returning false, suppressing every subscribe write. The
  comparison now extracts `username` defensively and accepts both shapes,
  so the workaround keeps working across the DT version bump.
- **DTAnnotateJob no longer runs `RefreshLinksJob` inline.** The vintage
  did `( new RefreshLinksJob( $title, [] ) )->run()` from within the job's
  `run()` method to immediately re-parse the page so SMW would pick up the
  freshly-stashed DT bundle. Inline execution collides with the outer
  `JobRunner`'s transaction round (`DBTransactionError: transaction round
  'MediaWiki\Extension\DiscussionForum\Jobs\DTAnnotateJob::run' already
  started`). On production miniscope.org the resulting exception path
  left rows half-claimed in the `job` table — `job_token` set but
  `job_token_timestamp` NULL — and upstream MW's
  `recycleAndDeleteStaleJobs` doesn't match NULL timestamps, so the
  stuck rows jammed the queue forever. The job now pushes the
  `RefreshLinksJob` to the queue instead; it runs in its own round on
  the next tick, ack-and-release works normally, and SMW still picks up
  the DT bundle through `ParserAfterParse` reading the stash.

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
