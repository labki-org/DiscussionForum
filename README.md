# DiscussionForum

MediaWiki extension that builds a forum-style discussion UX on top of
[DiscussionTools][dt] and Semantic MediaWiki.

[dt]: https://www.mediawiki.org/wiki/Extension:DiscussionTools

- **What it does:** turns a `Foo:` / `Foo_talk:` namespace pair into a forum,
  where each "topic" is a subpage of `Foo_talk:` with a
  `<UTC-timestamp>_<username>` slug. Topic pages render as DT discussion
  threads with forum-card styling. A drop-in `[data-discussionforum-new-post]`
  trigger on the `Foo:` landing page creates a new topic and routes the user
  to DT's new-topic widget.
- **Forum index:** five SMW properties (`Has forum`, `Topic subject`,
  `Topic starter`, `Comment count`, `Participant count`) populate per topic.
  Comment and participant counts come from DT's `CommentParser`, so they're
  locale-correct (custom signatures, localized timestamps, transcluded
  comments). The landing page renders its index via `#ask` against these
  properties.
- **Subscriptions:** clicking the Watch star on a forum landing page
  auto-watches every subsequent new topic in that forum (and, via the
  standard watchlist plumbing, every reply on those topics) — bell + email
  with `$wgEnotifWatchlist = true`.

## Installation

```php
wfLoadExtension( 'DiscussionForum' );
```

Requirements:

- MediaWiki ≥ 1.43
- [DiscussionTools][dt] (hard dependency — the annotate job calls
  `HookUtils::parseRevisionParsoidHtml()`)
- [Semantic MediaWiki](https://www.semantic-mediawiki.org/) (hard dependency —
  the forum-index properties live in SMW)
- [Echo](https://www.mediawiki.org/wiki/Extension:Echo) (soft dependency —
  required for bell notifications on auto-watched topics; without it the
  watchlist + email paths still work)

## Namespace setup (deployer-provided)

DiscussionForum is namespace-agnostic. It detects "forum topic pages" by
matching odd-namespace (talk-side) subpages with `/` in the title — that
selector works for any namespace pair the deployer registers. Typical
setup in `LocalSettings.user.php`:

```php
define( 'NS_FORUM',      4000 );
define( 'NS_FORUM_TALK', 4001 );

$wgExtraNamespaces[NS_FORUM]      = 'Forum';
$wgExtraNamespaces[NS_FORUM_TALK] = 'Forum_talk';

$wgNamespacesWithSubpages[NS_FORUM]      = true;
$wgNamespacesWithSubpages[NS_FORUM_TALK] = true;

$wgContentNamespaces[]                  = NS_FORUM;
$smwgNamespacesWithSemanticLinks[NS_FORUM]      = true;
$smwgNamespacesWithSemanticLinks[NS_FORUM_TALK] = true;
```

Multiple forum tiers (e.g. `Forum` 4000 + `Forum_admin` 4010) work without
any extension changes — register both pairs and apply your own
`$wgNamespacePermissionLockdown` to gate edits.

## Configuration

| Variable | Default | Purpose |
| :--- | :--- | :--- |
| `$wgDiscussionForumPatchAutoSubscribeTZBug` | `true` | Workaround for an upstream DT bug where `EventDispatcher::generateEventsFromItemSets()` compares a `$wgLocaltimezone`-parsed revision timestamp against a UTC signature timestamp, suppressing auto-subscribe on any wiki where the local timezone is not UTC. Set to `false` once a patched DT version is deployed. |

Recommended companion settings (not set by this extension):

```php
$wgEnotifWatchlist                                                = true;  // email leg
$wgDefaultUserOptions['discussiontools-autotopicsub']             = 1;     // auto-sub on reply
$wgDiscussionToolsAutoTopicSubEditor                              = 'any'; // any editor counts
```

## How the topic-page pipeline works

```
PageSaveComplete
  └─ schedule discussionForumAnnotate job
                     │
                     ▼
   Job::run()
     ├─ DT::HookUtils::parseRevisionParsoidHtml()
     ├─ stash { count, people, starter } keyed by rev id
     └─ run RefreshLinksJob inline
                     │
                     ▼
   ParserAfterParse  ─ reads stash, contributes 5 props through SMW's
                      parser-data channel
                     │
                     ▼
   SMW LinksUpdate   ─ atomically stores Has forum + Topic subject
                      (title-derived) + count/people/starter (DT-derived)
```

`ParserAfterParse` is also the channel that exposes the data to any
later re-parse (manual purge, parser-cache eviction, RefreshLinksJob
from another extension) — the same stash lookup reapplies the
DT-derived values, so SMW's stored data stays consistent for the
lifetime of the revision.

## CSS / wikitext API

| Hook | What it does |
| :--- | :--- |
| `.discussionforum-new-post-btn` (or `[data-discussionforum-new-post]`) | Click-handler trigger that opens DT's new-topic widget on the corresponding `_talk` namespace. Drop it anywhere on a forum landing page. |
| `.discussionforum-landing` | Wrapper class applied by the body-class hook to forum landing pages; style hook for `#ask`-rendered topic indexes. |

## Maintenance scripts

- `maintenance/backfillForumAnnotations.php` — iterates every odd-NS subpage
  and enqueues a `discussionForumAnnotate` job. Run once after deploying
  this extension on a wiki that already has forum topics created under the
  previous code path. Idempotent; re-running is harmless.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
