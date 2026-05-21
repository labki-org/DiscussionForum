<?php

namespace MediaWiki\Extension\DiscussionForum\Hooks;

use MediaWiki\Extension\DiscussionForum\Constants;

/**
 * Registers DiscussionForum's five SMW properties.
 *
 * Adding a property here is a schema change for the SMW SQL store:
 * existing installs run `php extensions/SemanticMediaWiki/maintenance/setupStore.php`
 * once after deploy, then `rebuildData.php -n <ns>` to backfill saved
 * pages. For brand-new wikis the install path runs both as part of the
 * standard SMW upgrade flow.
 *
 * Internal IDs (`___forum_*`) and human labels (`Has forum`, etc.) are
 * kept verbatim from the labki-platform vintage to avoid a forced
 * rebuildData on cutover.
 */
class PropertyRegistrationHooks {
	/**
	 * SMW hook — not a typed MW core hook, so signature is documented by
	 * SMW upstream. The $propertyRegistry instance is a
	 * \SMW\PropertyRegistry; typed loosely here so static analysis doesn't
	 * hard-fail when SMW isn't installed (the hook never fires in that
	 * case anyway, since SMW is what dispatches it).
	 *
	 * @param mixed $propertyRegistry
	 * @return bool true to continue (SMW hook contract).
	 */
	public static function onInitProperties( $propertyRegistry ): bool {
		$propertyRegistry->registerProperty(
			Constants::PROP_SUBJECT, '_txt', Constants::LABEL_SUBJECT
		);
		$propertyRegistry->registerProperty(
			Constants::PROP_STARTER, '_wpg', Constants::LABEL_STARTER
		);
		$propertyRegistry->registerProperty(
			Constants::PROP_COMMENTS, '_num', Constants::LABEL_COMMENTS
		);
		$propertyRegistry->registerProperty(
			Constants::PROP_PARTICIPANTS, '_num', Constants::LABEL_PARTICIPANTS
		);
		// Has forum (_wpg, page-typed) — points each topic back at its
		// containing forum landing. Enables chained queries like
		// [[Has forum.Has parent forum::Forum:Home]] for cross-forum feeds.
		$propertyRegistry->registerProperty(
			Constants::PROP_PARENT, '_wpg', Constants::LABEL_PARENT
		);
		return true;
	}
}
