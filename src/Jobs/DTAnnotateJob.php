<?php

namespace MediaWiki\Extension\DiscussionForum\Jobs;

use Job;
use MediaWiki\Extension\DiscussionForum\Constants;
use MediaWiki\Extension\DiscussionTools\Hooks\HookUtils;
use MediaWiki\MediaWikiServices;
use RefreshLinksJob;

/**
 * Re-annotates a forum topic's DT-derived SMW properties after each save.
 *
 * Pipeline:
 *
 *   PageSaveComplete (PostSaveHooks)
 *     -> push DTAnnotateJob to the queue
 *
 *   jobrunner picks up DTAnnotateJob
 *     -> HookUtils::parseRevisionParsoidHtml() to get DT's CommentParser
 *        output on the saved revision's Parsoid HTML
 *     -> cache {count, people, starter} in MainObjectStash, keyed by
 *        (article id, revision id)
 *     -> run RefreshLinksJob inline so SMW re-parses the page
 *
 *   ParserAfterParse (TopicAnnotationHooks)
 *     -> reads the stash, contributes the 3 props through SMW's parser-
 *        data channel
 *
 *   SMW LinksUpdate stores the full bundle (title-derived + DT-derived)
 *   in one atomic write, gated by OPT_FORCED_UPDATE so SMW doesn't skip
 *   on the unchanged-revision-id check.
 *
 * Routing through the parser channel (vs. calling Store::updateData
 * directly) avoids data loss: any subsequent re-parse — manual purge,
 * parser-cache eviction, RefreshLinksJob from another extension — also
 * reads the stash and re-emits the same DT-derived values, so SMW's
 * stored data stays consistent for the lifetime of the revision.
 *
 * Counts are exactly what DT itself uses in its bell / subscription UI:
 * locale-correct (handles non-UTC $wgLocaltimezone, custom signatures,
 * transcluded comments, fully-localised month names).
 */
class DTAnnotateJob extends Job {
	public function __construct( $title, $params = [] ) {
		parent::__construct( Constants::JOB_ANNOTATE, $title, $params );
		// Two saves on the same topic can land back-to-back (OP saves,
		// then immediately replies). Deduplicate so the queue carries
		// at most one pending re-annotation per page.
		$this->removeDuplicates = true;
	}

	public function run() {
		$title = $this->getTitle();
		if ( !$title ) {
			return true;
		}

		$services = MediaWikiServices::getInstance();
		$rev = $services->getRevisionLookup()->getRevisionByTitle( $title );
		if ( !$rev ) {
			return true;
		}

		$status = HookUtils::parseRevisionParsoidHtml( $rev, __METHOD__ );
		if ( !$status->isOK() ) {
			// Parsoid resource-limit or similar transient failure — leave
			// the DT-derived properties absent for this revision; the
			// next save will retry.
			return true;
		}

		$threads = $status->getValue()->getThreads();
		if ( !$threads ) {
			// Empty topic (no H2, no comments yet) — nothing to annotate.
			return true;
		}
		$heading = $threads[0];

		$oldestReply = $heading->getOldestReply();
		$payload = [
			'count'   => (int)$heading->getCommentCount(),
			'people'  => count( $heading->getAuthorsBelow() ),
			'starter' => $oldestReply ? (string)$oldestReply->getAuthor() : '',
		];

		$stash = $services->getMainObjectStash();
		$key = $stash->makeKey(
			Constants::STASH_PREFIX,
			$title->getArticleID(),
			$rev->getId()
		);
		// TTL_MONTH covers normal parser-cache eviction windows; on the
		// rare cache miss past expiry, the next save (which always
		// re-enqueues this job) repopulates.
		$stash->set( $key, $payload, $stash::TTL_MONTH );

		// Force SMW to re-store the page with the fresh DT data flowing
		// through ParserAfterParse. RefreshLinksJob re-renders, which
		// runs the annotation hook (now with a cache hit) and SMW's
		// LinksUpdate.
		( new RefreshLinksJob( $title, [] ) )->run();

		return true;
	}
}
