<?php

namespace MediaWiki\Extension\DiscussionForum\Hooks;

use MediaWiki\Extension\DiscussionForum\Jobs\DTAnnotateJob;
use MediaWiki\Extension\DiscussionForum\Util\ForumTitleResolver;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use WikiPage;

/**
 * PageSaveComplete handler: schedules the DT annotation job for any
 * forum topic save (creation OR reply).
 *
 * The subscription-fanout sibling — copying forum-landing watchers onto
 * newly-created topics (port of labki-platform PR #74) — runs from a
 * separate hook registration in extension.json so the two behaviours
 * are independent: failure of one doesn't block the other, and CI
 * coverage can target each in isolation.
 */
class PostSaveHooks {
	/**
	 * Hook signature for MW 1.43+ PageSaveComplete (six parameters,
	 * EditResult last). The $editResult is unused here but kept so the
	 * signature matches the hook contract; the static callable form
	 * doesn't bind to a typed interface.
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $user,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	): void {
		if ( !ForumTitleResolver::isTopicPage( $wikiPage->getTitle() ) ) {
			return;
		}
		MediaWikiServices::getInstance()->getJobQueueGroup()->push(
			new DTAnnotateJob( $wikiPage->getTitle(), [] )
		);
	}
}
