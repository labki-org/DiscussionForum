<?php

namespace MediaWiki\Extension\DiscussionForum\Hooks;

use MediaWiki\Extension\DiscussionForum\Constants;
use MediaWiki\Extension\DiscussionForum\Util\ForumTitleResolver;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\ParserData;
use SMW\Services\ServicesFactory;
use SMWDIBlob;
use SMWDINumber;

/**
 * ParserAfterParse handler: populates forum metadata via the parser channel.
 *
 *   - Has forum         (title-derived, always set)
 *   - Topic subject     (first H2, also mirrored into DISPLAYTITLE)
 *   - Comment count     (from the DT stash populated by DTAnnotateJob)
 *   - Participant count (ditto)
 *   - Topic starter     (ditto)
 *
 * === Why ParserAfterParse, not ContentAlterParserOutput ===
 * SMW's ParserAfterTidy reads page properties (e.g. displaytitle) before
 * the Content layer fires; ParserAfterParse runs earlier in the parser
 * pipeline and gets the values into ParserOutput in time for SMW.
 *
 * === Why a cache + RefreshLinksJob for the DT-derived bundle ===
 * DT's CommentParser needs final Parsoid HTML, which isn't available at
 * this hook. The annotate job does the Parsoid render after save, caches
 * the result, and triggers a re-parse. This hook then becomes a single
 * channel for all forum properties — any future re-parse (manual purge,
 * parser-cache eviction) reads the same cache and re-emits the same
 * DT-derived values, so SMW's stored data stays consistent regardless
 * of which path retriggered the parse.
 *
 * === Caveats / known limits ===
 * 1. Counts include the OP — a fresh topic with no replies reads as
 *    "1 comment, 1 participant", matching Discourse-style conventions.
 *    If a strict "replies" count is wanted, subtract 1 in the consuming
 *    template.
 * 2. Brief window between save and DT job completion where the page
 *    has Has forum + Topic subject but no DT-derived counts. Resolves
 *    on the next jobrunner cycle (~seconds in production with the
 *    bundled jobrunner sidecar). The forum index shows the topic as
 *    "0 replies" during this window.
 * 3. If Parsoid fails (resource limit, etc.) the DT-derived bundle is
 *    skipped silently for that revision; the next save retries.
 */
class TopicAnnotationHooks {
	/**
	 * @param Parser $parser
	 * @param string &$text  half-parsed wikitext; unused here (we work
	 *   on ParserOutput directly) but kept by-reference to match the
	 *   hook signature.
	 * @param mixed $stripState
	 */
	public static function onParserAfterParse( Parser $parser, &$text, $stripState ): void {
		$title = $parser->getTitle();
		if ( !ForumTitleResolver::isTopicPage( $title ) ) {
			return;
		}
		$parserOutput = $parser->getOutput();

		// SMW's DisplayTitle annotator hijacks the sortkey to the displaytitle
		// when no explicit defaultsort is set, breaking LIKE-pattern queries
		// like [[~*Miniscopes/*]]. Pin defaultsort unconditionally on forum
		// topic pages so subpage queries always resolve, regardless of whether
		// an H2 has been added yet.
		$parserOutput->setPageProperty( 'defaultsort', $title->getPrefixedText() );

		$sections = $parserOutput->getSections();
		$subject = $sections ? trim( strip_tags( $sections[0]['line'] ?? '' ) ) : '';
		if ( $subject !== '' ) {
			$parserOutput->setDisplayTitle( $subject );
		}

		$parserData = ServicesFactory::getInstance()->newParserData( $title, $parserOutput );
		$semanticData = $parserData->getSemanticData();

		// Has forum: the topic's containing forum landing page. Always
		// annotated — derivable from the title alone, no content gate.
		$forumLanding = ForumTitleResolver::getLandingPage( $title );
		if ( $forumLanding ) {
			$semanticData->addPropertyObjectValue(
				new DIProperty( Constants::PROP_PARENT ),
				DIWikiPage::newFromTitle( $forumLanding )
			);
		}

		if ( $subject !== '' ) {
			$semanticData->addPropertyObjectValue(
				new DIProperty( Constants::PROP_SUBJECT ),
				new SMWDIBlob( $subject )
			);
		}

		// DT-derived bundle: read whatever DTAnnotateJob stashed for
		// this revision. Absent on freshly-saved revs before the job
		// has run; present afterward (and on every subsequent re-parse).
		$rev = $parser->getRevisionRecordObject();
		if ( $rev ) {
			$stash = MediaWikiServices::getInstance()->getMainObjectStash();
			$dt = $stash->get( $stash->makeKey(
				Constants::STASH_PREFIX,
				$title->getArticleID(),
				$rev->getId()
			) );
			if ( is_array( $dt ) && ( $dt['count'] ?? 0 ) > 0 ) {
				$semanticData->addPropertyObjectValue(
					new DIProperty( Constants::PROP_COMMENTS ),
					new SMWDINumber( (int)$dt['count'] )
				);
				$semanticData->addPropertyObjectValue(
					new DIProperty( Constants::PROP_PARTICIPANTS ),
					new SMWDINumber( (int)$dt['people'] )
				);
				if ( ( $dt['starter'] ?? '' ) !== '' ) {
					$starterTitle = Title::makeTitleSafe( NS_USER, $dt['starter'] );
					if ( $starterTitle ) {
						$semanticData->addPropertyObjectValue(
							new DIProperty( Constants::PROP_STARTER ),
							DIWikiPage::newFromTitle( $starterTitle )
						);
					}
				}
				// Force SMW to re-run the data update even though the
				// revision ID hasn't changed. SMW's LinksUpdateComplete
				// handler skips when the stored associatedRev matches the
				// page's latestRev (DataUpdater::isSkippable in SMW's
				// source). The initial save for this revision already
				// stored a title-derived snapshot (DT-bundle absent,
				// stash hadn't been populated yet) — without this flag
				// the RefreshLinksJob from DTAnnotateJob would silently
				// skip and the DT-derived props would never land in the
				// store. Only set when we actually have DT data to
				// contribute, so we don't pay the cost on every reparse.
				$parserOutput->setExtensionData(
					ParserData::OPT_FORCED_UPDATE,
					true
				);
			}
		}
		$parserData->pushSemanticDataToParserOutput();
	}
}
