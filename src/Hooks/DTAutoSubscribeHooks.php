<?php

namespace MediaWiki\Extension\DiscussionForum\Hooks;

use MediaWiki\Extension\DiscussionForum\Constants;
use MediaWiki\Extension\DiscussionForum\Util\ForumTitleResolver;
use MediaWiki\Extension\DiscussionTools\Hooks\HookUtils;
use MediaWiki\Extension\DiscussionTools\SubscriptionStore;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use WikiPage;

/**
 * Workaround for an upstream DiscussionTools bug that suppresses auto-
 * subscribe on any wiki where $wgLocaltimezone is not UTC.
 *
 * === The upstream bug ===
 *
 * In extensions/DiscussionTools/includes/Notifications/EventDispatcher.php,
 * generateEventsFromItemSets() runs an "archiving guard" that drops
 * comments more than 10 minutes older than the revision they appear in
 * (the rationale being that very old comments showing up in a new
 * revision usually mean someone is archiving an old thread, not posting
 * something the author would want subscribed to). The check is:
 *
 *     $revTimestamp = new DateTimeImmutable( $newRevRecord->getTimestamp() );
 *     $threshold    = $revTimestamp->sub( new DateInterval( 'PT10M' ) );
 *     if ( $newComment->getTimestamp() <= $threshold ) { continue; }
 *
 * $newRevRecord->getTimestamp() returns MediaWiki's 14-digit TS_MW
 * format (YYYYMMDDHHMMSS), which has no timezone designator. PHP's
 * DateTimeImmutable parses unsuffixed timestamps as wall-clock time in
 * the default timezone — which MediaWiki has already set to
 * $wgLocaltimezone. So on (say) America/Los_Angeles (PDT, UTC-7), a
 * revision saved at 05:27:41 UTC becomes 12:27:41 UTC after the
 * tz-shift, and every comment in the revision (signature timestamp
 * parsed correctly in UTC) looks ~7 hours older than the threshold.
 * The guard fires on every save, and addAutoSubscription() is never
 * reached. STATE_AUTOSUBSCRIBED rows stop being written.
 *
 * === The workaround ===
 *
 * On every PageSaveComplete for a forum topic page, replicate the
 * subscription-write path DT would have taken: check the same gating
 * conditions ($wgDiscussionToolsAutoTopicSubEditor === 'any',
 * shouldAddAutoSubscription()), parse the revision via DT's own
 * Parsoid pipeline, and call SubscriptionStore::addAutoSubscriptionForUser()
 * directly. The call is idempotent on existing subscriptions, so it
 * coexists safely with DT's deferred path on the day the upstream
 * patch lands and starts firing again.
 *
 * Scope is intentionally narrow — forum topic pages only, gated by
 * isTopicPage(). Any other talk-NS save (regular Talk:Foo pages,
 * project talk, etc.) keeps the upstream behaviour, broken or not.
 * If DT's bug affects more than forum subpages on a given wiki, the
 * fix should land upstream rather than be re-implemented per consumer.
 *
 * === Kill switch ===
 *
 * Gated by $wgDiscussionForumPatchAutoSubscribeTZBug (default true).
 * Set to false once a patched DT version is in the deployed image;
 * the upstream path will then handle subscriptions as designed and
 * this hook becomes a no-op even on forum saves.
 */
class DTAutoSubscribeHooks {
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

		$services = MediaWikiServices::getInstance();

		// Kill switch — let deployers turn this off when upstream DT
		// ships the fix.
		$dfConfig = $services->getConfigFactory()->makeConfig( 'DiscussionForum' );
		if ( !$dfConfig->get( Constants::CONFIG_PATCH_TZ_BUG ) ) {
			return;
		}

		// Match DT's own gate for "any editor counts" — when the deployer
		// has restricted auto-subscribe to specific editors (e.g. only DT's
		// reply widget, not the source editor), respect that. Reusing
		// DT's config key avoids drifting from upstream semantics.
		$dtConfig = $services->getConfigFactory()->makeConfig( 'discussiontools' );
		if ( $dtConfig->get( 'DiscussionToolsAutoTopicSubEditor' ) !== 'any' ) {
			return;
		}

		// Title needs to be a full Title (not LinkTarget / PageIdentity)
		// because DT's HookUtils takes Title. WikiPage::getTitle() already
		// returns a Title.
		if ( !( $title instanceof Title ) ) {
			return;
		}

		// shouldAddAutoSubscription bundles the "is this user eligible"
		// checks: not on their own user talk page, not a bot, DT is
		// available on the title, and the user's autotopicsub pref is on.
		// Skipping this would mean we subscribe users who DT itself would
		// have skipped (bots, users with the pref off).
		if ( !HookUtils::shouldAddAutoSubscription( $user, $title ) ) {
			return;
		}

		// Parse the revision via the same Parsoid pipeline DTAnnotateJob
		// uses. parseRevisionParsoidHtml is DT's documented entry point;
		// failures here are transient (resource limit, etc.) and the next
		// save will retry.
		$status = HookUtils::parseRevisionParsoidHtml( $revisionRecord, __METHOD__ );
		if ( !$status->isOK() ) {
			return;
		}
		$threads = $status->getValue()->getThreads();
		if ( !$threads ) {
			return;
		}
		$heading = $threads[0];

		// Verify the saving user actually has a comment in the thread.
		// Otherwise an admin doing a typo fix to someone else's post
		// would be silently subscribed — DT's own flow only subscribes
		// authors of newly-added comments, and matching that semantic
		// keeps the workaround from being more aggressive than the
		// upstream behaviour we're emulating.
		//
		// DT (since some 1.4x bump — confirmed on 1.44) returns
		// getAuthorsBelow() as an array of
		//   [ 'username' => string, 'displayNames' => string[] ]
		// entries (see ContentThreadItem::calculateThreadSummary in DT).
		// Strict in_array against a plain string username would always
		// return false against the assoc-array form, silently
		// suppressing every subscribe write — exactly the bug we hit
		// on miniscope.org's production DT.
		$authors = array_column( $heading->getAuthorsBelow(), 'username' );
		if ( !in_array( $user->getName(), $authors, true ) ) {
			return;
		}

		/** @var SubscriptionStore $subscriptionStore */
		$subscriptionStore = $services->getService( 'DiscussionTools.SubscriptionStore' );
		$wrote = $subscriptionStore->addAutoSubscriptionForUser(
			$user,
			$title,
			$heading->getName()
		);

		// Log on actual write only — addAutoSubscriptionForUser returns
		// false when a row already exists (subscribed or auto-subscribed),
		// and that's the normal steady-state for every save after the
		// first. Logging only the writes keeps the channel signal-y for
		// confirming the workaround is firing on a wiki where the bug
		// would otherwise suppress all subscriptions.
		if ( $wrote ) {
			LoggerFactory::getInstance( 'DiscussionForum' )->info(
				'auto-subscribed {user} to forum topic {title} (workaround for DT tz bug)',
				[
					'user'     => $user->getName(),
					'title'    => $title->getPrefixedText(),
					'itemName' => $heading->getName(),
				]
			);
		}
	}
}
