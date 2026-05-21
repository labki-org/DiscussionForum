<?php

namespace MediaWiki\Extension\DiscussionForum\Util;

use MediaWiki\Title\Title;

/**
 * Two-purpose utility:
 *   - identify forum topic pages (talk-NS subpage with "/" in the dbkey)
 *   - derive the forum landing page from a topic page (base title flipped
 *     to its subject namespace)
 *
 * Centralised here because the same selector and derivation appear in
 * PostSaveHooks, TopicAnnotationHooks, TopicPageHooks, the subscription
 * fanout, and the DT auto-subscribe workaround. Drift between those
 * copies would silently fragment which pages get annotated vs subscribed
 * vs styled, so it's worth a single source of truth.
 */
final class ForumTitleResolver {
	/**
	 * @param Title|null $title
	 * @return bool True if the title looks like a forum topic page:
	 *   talk-side namespace (odd numeric ID) AND its dbkey contains "/"
	 *   (i.e. is a subpage). Namespace-agnostic — works for any pair the
	 *   deployer registers (Forum/Forum_talk, Forum_admin/Forum_admin_talk).
	 */
	public static function isTopicPage( ?Title $title ): bool {
		if ( !$title ) {
			return false;
		}
		if ( ( $title->getNamespace() % 2 ) !== 1 ) {
			return false;
		}
		return strpos( $title->getDBkey(), '/' ) !== false;
	}

	/**
	 * Derive the forum landing page from a topic page.
	 *
	 *   Forum_talk:Bar/<slug>          -> Forum:Bar
	 *   Forum_talk:Bar/Sub/<slug>      -> Forum:Bar/Sub
	 *   Forum_admin_talk:Foo/<slug>    -> Forum_admin:Foo
	 *
	 * @return Title|null Null if the topic title has no base (defensive —
	 *   non-subpages don't pass isTopicPage() either).
	 */
	public static function getLandingPage( Title $topicTitle ): ?Title {
		$talkBase = $topicTitle->getBaseTitle();
		if ( !$talkBase ) {
			return null;
		}
		return $talkBase->getSubjectPage();
	}

	/**
	 * Forum landing page derived from the ROOT title (top of the subpage
	 * tree), used for the topic-page breadcrumb. For deeply nested
	 * topics this differs from getLandingPage(): on
	 * Forum_talk:Bar/Sub/<slug>, the landing is Forum:Bar/Sub but the
	 * breadcrumb root is Forum:Bar.
	 */
	public static function getRootLandingPage( Title $topicTitle ): Title {
		return $topicTitle->getRootTitle()->getSubjectPage();
	}
}
