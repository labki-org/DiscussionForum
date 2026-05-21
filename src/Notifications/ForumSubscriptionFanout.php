<?php

namespace MediaWiki\Extension\DiscussionForum\Notifications;

use MediaWiki\Extension\DiscussionForum\Util\ForumTitleResolver;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

/**
 * Watchlist fanout: when a new topic page is created under a forum
 * landing, copy the landing page's watchers onto the new topic page.
 *
 * Closes the long-standing gap where clicking the Watch star on a
 * Forum landing page does nothing useful: posts under that forum live
 * in a different namespace (the talk-side subpages), so MediaWiki's
 * native watchlist has no way to follow them.
 *
 * From the moment a watcher is added to a topic page, MediaWiki's
 * existing watchlist plumbing handles the rest: the topic page (and
 * every subsequent reply on it) shows up in Special:Watchlist, its
 * RSS feed, the Echo bell, and — with $wgEnotifWatchlist = true —
 * emails.
 *
 * === Why piggyback on the watchlist instead of building an Echo event ===
 *
 * The auto-watch path is ~50 lines including comments; a custom Echo
 * event with a "Subscribe to this forum" button + subscriber storage +
 * opt-in UI would be several hundred. The Watch star is also already
 * a user-discoverable affordance and integrates with every existing
 * piece of watchlist UX (Special:Watchlist filters, RSS, email digest
 * preferences). For the volume a typical labki-platform deployment
 * sees (handfuls of posts per workshop), a distinct bell category
 * isn't worth its weight.
 *
 * === Scope and limits ===
 *
 * - **Leaf-only by design.** Walks one subpage segment up; watching
 *   `Forum:Home` does NOT auto-watch posts in `Forum:Hardware`.
 *   Mirrors DT's per-page subscription model. Users wanting cross-tree
 *   coverage watch each leaf.
 * - **No backfill.** Pre-existing topics aren't retroactively added
 *   to existing watchers' lists; only topics created after deploy
 *   get the auto-watch. Acceptable for forums still warming up.
 *   For one-shot backfills, see maintenance/backfillForumAnnotations.php
 *   (added in Block 5 of the porting plan) which exists for a different
 *   reason (DT-derived SMW props) but could grow a `--fanout` flag if
 *   needed.
 * - **OP self-notify off by default.** MediaWiki's "Email me also for
 *   edits made by me" preference defaults off; the poster is added
 *   to their own post's watchlist (so they see replies) but doesn't
 *   email themselves about the create.
 * - **Create-event email timing caveat.** PageSaveComplete fires
 *   before MediaWiki's deferred EmailNotification job queries the
 *   watcher set, so the first revision (the topic creation) emails
 *   landing-page watchers, not just later replies. Empirically true;
 *   flagged here in case a future MW upgrade reorders the deferred-
 *   update queue.
 *
 * === Implementation choices worth flagging ===
 *
 * - **Creation detection via parent revision id**, not EDIT_NEW flag.
 *   More reliable across every caller path (API, maintenance scripts,
 *   etc.) — only the very first revision of a page has a null/zero
 *   parent.
 * - **WatchedItemStore::addWatchBatchForUser** instead of
 *   WatchlistManager::addWatch. The latter does an `editmywatchlist`
 *   permission check on the performer; we don't want to re-gate per
 *   fanout because the landing-page watcher already opted in by
 *   clicking the Watch star.
 * - **Direct `watchlist` table query** rather than a service method
 *   to enumerate watchers. WatchedItemStore exposes `countWatchers`
 *   but not a public "all watchers as a UserIdentity[]" for arbitrary
 *   targets; the table query with explicit `->caller(__METHOD__)` is
 *   what the rest of MW core does in similar lookups.
 */
class ForumSubscriptionFanout {
	/**
	 * @param Title $topicTitle  freshly-saved topic page
	 * @param UserIdentity $poster  user who just saved
	 * @param RevisionRecord $revisionRecord
	 * @return int Number of watchers fanned out (0 if not a creation or
	 *   the landing page has no watchers).
	 */
	public static function fanoutOnCreation(
		Title $topicTitle,
		UserIdentity $poster,
		RevisionRecord $revisionRecord
	): int {
		// Creations only — replies / edits don't re-fan out. Checking the
		// parent revision id (0 / null on the first revision of a brand-
		// new page) is more reliable than relying on EDIT_NEW being set
		// by every caller path.
		$parentId = $revisionRecord->getParentId();
		if ( $parentId !== null && $parentId !== 0 ) {
			return 0;
		}

		// Same derivation as Has forum: getBaseTitle strips just the
		// last subpage segment (the post's slug), getSubjectPage flips
		// the talk namespace to its subject pair.
		$landing = ForumTitleResolver::getLandingPage( $topicTitle );
		if ( !$landing || !$landing->exists() ) {
			return 0;
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$userIds = $dbr->newSelectQueryBuilder()
			->select( 'wl_user' )
			->from( 'watchlist' )
			->where( [
				'wl_namespace' => $landing->getNamespace(),
				'wl_title'     => $landing->getDBkey(),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$userIds ) {
			return 0;
		}

		$userFactory = $services->getUserFactory();
		$watchedItemStore = $services->getWatchedItemStore();
		$fanned = 0;
		foreach ( $userIds as $uid ) {
			$watcher = $userFactory->newFromId( (int)$uid );
			$watchedItemStore->addWatchBatchForUser( $watcher, [ $topicTitle ] );
			$fanned++;
		}
		return $fanned;
	}
}
