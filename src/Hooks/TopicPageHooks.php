<?php

namespace MediaWiki\Extension\DiscussionForum\Hooks;

use MediaWiki\Extension\DiscussionForum\Constants;
use MediaWiki\Extension\DiscussionForum\Util\ForumTitleResolver;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Skin;

/**
 * BeforePageDisplay handler: attaches ResourceLoader modules to every
 * page (so the new-post button works wherever it's dropped — typically
 * on subject-namespace forum landing pages, which are NOT topic pages
 * themselves), and adds the body class + breadcrumb subtitle to topic
 * pages.
 *
 * The body class .discussionforum-topic is added server-side via
 * OutputPage::addHtmlClasses so the CSS matches before first paint —
 * no inline <script> needed for the discriminator. The breadcrumb
 * back-link lives in #contentSub via OutputPage::addSubtitle, above
 * the topic card frame.
 */
class TopicPageHooks {
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$out->addModuleStyles( [ Constants::RL_STYLES ] );
		$out->addModules( [ Constants::RL_SCRIPT ] );

		$title = $out->getTitle();
		if ( !ForumTitleResolver::isTopicPage( $title ) ) {
			return;
		}
		$out->addHtmlClasses( Constants::HTML_CLASS_TOPIC );

		// Breadcrumb back to the forum landing page. The root-title
		// derivation (not base-title) collapses nested subpages so the
		// breadcrumb always points at the top of the forum tree, not an
		// intermediate sub-forum.
		$parent = ForumTitleResolver::getRootLandingPage( $title );
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		$out->addSubtitle( $linkRenderer->makeLink(
			$parent,
			'← ' . $parent->getText(),
			[ 'class' => Constants::HTML_CLASS_BACK ]
		) );
	}
}
