<?php

namespace MediaWiki\Extension\DiscussionForum;

/**
 * Centralised string constants used across the extension's hooks, job, and
 * resources. Keeping them in one place makes the rename/migration story
 * (labki-platform vintage -> DiscussionForum) auditable and prevents drift
 * between hook-side property IDs and SMW-side query labels.
 */
final class Constants {
	/** Job name registered in extension.json's JobClasses. Picked up by the jobrunner. */
	public const JOB_ANNOTATE = 'discussionForumAnnotate';

	/** Stash key prefix used by DTAnnotateJob and TopicAnnotationHooks. */
	public const STASH_PREFIX = 'discussionforum-dt';

	/**
	 * SMW property internal IDs. Stored in the SMW DB; renaming forces
	 * rebuildData.php. Kept verbatim from the labki-platform vintage to
	 * avoid a rebuild on cutover.
	 */
	public const PROP_SUBJECT      = '___forum_subject';
	public const PROP_STARTER      = '___forum_starter';
	public const PROP_COMMENTS     = '___forum_comments';
	public const PROP_PARTICIPANTS = '___forum_participants';
	public const PROP_PARENT       = '___forum_parent';

	/**
	 * SMW property labels. These ARE user-visible in #ask queries, so they
	 * must stay stable across the rename (on-wiki templates query
	 * [[Has forum::...]] et al by label, not by internal ID).
	 */
	public const LABEL_SUBJECT      = 'Topic subject';
	public const LABEL_STARTER      = 'Topic starter';
	public const LABEL_COMMENTS     = 'Comment count';
	public const LABEL_PARTICIPANTS = 'Participant count';
	public const LABEL_PARENT       = 'Has forum';

	/** ResourceLoader module names declared in extension.json. */
	public const RL_STYLES = 'ext.discussionforum.styles';
	public const RL_SCRIPT = 'ext.discussionforum';

	/** Body class added by TopicPageHooks to forum topic pages. */
	public const HTML_CLASS_TOPIC = 'discussionforum-topic';

	/** Class on the breadcrumb back-link in #contentSub. Style hook. */
	public const HTML_CLASS_BACK = 'discussionforum-back';

	/** Config flag name, mirrored from extension.json's config block. */
	public const CONFIG_PATCH_TZ_BUG = 'DiscussionForumPatchAutoSubscribeTZBug';
}
