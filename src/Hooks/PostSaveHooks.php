<?php

namespace MediaWiki\Extension\DiscussionForum\Hooks;

use MediaWiki\Extension\DiscussionForum\Jobs\DTAnnotateJob;
use MediaWiki\Extension\DiscussionForum\Notifications\ForumSubscriptionFanout;
use MediaWiki\Extension\DiscussionForum\Util\ForumTitleResolver;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use WikiPage;

/**
 * PageSaveComplete handler: two responsibilities, sharing the same
 * "is this save on a forum topic page?" guard.
 *
 *   1. Schedule the DT annotation job (every save — creation or reply).
 *      Decoupled into Jobs/DTAnnotateJob so the Parsoid work happens
 *      asynchronously and doesn't block the save response.
 *
 *   2. On creations only, fan out the forum landing page's watchers
 *      onto the new topic page. Delegated to
 *      Notifications/ForumSubscriptionFanout so the watchlist logic
 *      is testable in isolation.
 *
 * The DT-auto-subscribe-timezone-bug workaround
 * (Hooks/DTAutoSubscribeHooks) is intentionally registered as a
 * separate PageSaveComplete handler in extension.json rather than
 * folded in here: it operates on a different namespace surface (any
 * forum topic save, but with extra DT-specific gating) and has its
 * own kill switch.
 */
class PostSaveHooks {
	/**
	 * Hook signature for MW 1.43+ PageSaveComplete (six parameters,
	 * EditResult last). The static callable form doesn't bind to a
	 * typed interface; matching the signature keeps the contract
	 * explicit.
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $user,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	): void {
		$title = $wikiPage->getTitle();
		if ( !ForumTitleResolver::isTopicPage( $title ) ) {
			return;
		}

		// Every save → re-annotate.
		MediaWikiServices::getInstance()->getJobQueueGroup()->push(
			new DTAnnotateJob( $title, [] )
		);

		// Creations only → fan out the landing page's watchers onto the
		// new topic. The "is this a creation?" check lives inside
		// fanoutOnCreation() so PostSaveHooks doesn't have to reach into
		// RevisionRecord guard logic that's already covered by tests on
		// the notification side.
		ForumSubscriptionFanout::fanoutOnCreation( $title, $user, $revisionRecord );
	}
}
