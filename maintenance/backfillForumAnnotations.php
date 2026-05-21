<?php
/**
 * Enqueue a discussionForumAnnotate job for every existing forum topic
 * page. Run once after deploying this extension on a wiki that already
 * has forum topics created under the previous code path
 * (labki-platform's inline mediawiki/extensions.platform.php block, where
 * the PageSaveComplete hook was registered under a different name).
 *
 * Why this is needed: PageSaveComplete only fires on subsequent saves.
 * Topics saved before DiscussionForum was loaded never had an annotation
 * job enqueued, so their DT-derived SMW properties (Comment count,
 * Participant count, Topic starter) stay absent until each topic gets
 * re-saved. On a wiki with N existing topics, that means N manual
 * null-edits — this script does it in one pass.
 *
 * Idempotent: re-running on a wiki where annotations are already
 * present just re-enqueues the jobs and re-stores the same values.
 *
 * Usage (from the MW install root):
 *
 *   php maintenance/run.php extensions/DiscussionForum/maintenance/backfillForumAnnotations.php
 *   php maintenance/run.php extensions/DiscussionForum/maintenance/backfillForumAnnotations.php --dry-run
 *   php maintenance/run.php extensions/DiscussionForum/maintenance/backfillForumAnnotations.php --namespace 4001 --namespace 4011
 */

if ( getenv( 'MW_INSTALL_PATH' ) !== false ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\Extension\DiscussionForum\Jobs\DTAnnotateJob;
use MediaWiki\Extension\DiscussionForum\Util\ForumTitleResolver;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class BackfillForumAnnotations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'DiscussionForum' );
		$this->addDescription(
			'Enqueue discussionForumAnnotate jobs for every existing forum topic page. '
			. 'Run once after extension install on wikis with pre-existing forum content.'
		);
		$this->addOption(
			'namespace',
			'Restrict to a specific talk-side namespace (numeric). Repeatable. '
			. 'If omitted, all odd namespaces with subpage support are scanned.',
			false, true, false, true
		);
		$this->addOption(
			'dry-run',
			'List pages that would be enqueued without actually pushing to the queue.',
			false, false
		);
		$this->setBatchSize( 200 );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		// Detect the set of namespaces to scan. Either user-specified
		// via --namespace, or every odd namespace that the deployer has
		// enabled subpages on. The odd-namespace constraint catches
		// every talk-side namespace pair (Talk, Forum_talk,
		// Forum_admin_talk, etc.) without us having to enumerate the
		// deployer's specific config.
		$nsRestrict = $this->getOption( 'namespace' );
		if ( $nsRestrict ) {
			$namespaces = array_map( 'intval', (array)$nsRestrict );
		} else {
			global $wgNamespacesWithSubpages;
			$namespaces = [];
			foreach ( $wgNamespacesWithSubpages as $ns => $enabled ) {
				if ( $enabled && ( (int)$ns % 2 ) === 1 ) {
					$namespaces[] = (int)$ns;
				}
			}
		}
		if ( !$namespaces ) {
			$this->output( "No matching namespaces found.\n" );
			return;
		}
		$this->output( 'Scanning namespaces: ' . implode( ', ', $namespaces ) . "\n" );

		$dryRun = $this->hasOption( 'dry-run' );
		$jobQueueGroup = $services->getJobQueueGroup();

		$total = 0;
		$enqueued = 0;
		foreach ( $namespaces as $ns ) {
			$last = '';
			while ( true ) {
				$rows = $dbr->newSelectQueryBuilder()
					->select( [ 'page_id', 'page_namespace', 'page_title' ] )
					->from( 'page' )
					->where( [ 'page_namespace' => $ns ] )
					->andWhere( $dbr->expr( 'page_title', '>', $last ) )
					->orderBy( 'page_title' )
					->limit( $this->getBatchSize() )
					->caller( __METHOD__ )
					->fetchResultSet();
				if ( !$rows->numRows() ) {
					break;
				}
				foreach ( $rows as $row ) {
					$last = $row->page_title;
					$total++;
					// Re-use the same selector as PostSaveHooks so the
					// backfill set is exactly the set of pages that get
					// annotated on save. The DB-level page_title >= '%/%'
					// LIKE doesn't compose with the rest of the query
					// efficiently, so it's cheaper to over-fetch and
					// filter in PHP.
					$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
					if ( !ForumTitleResolver::isTopicPage( $title ) ) {
						continue;
					}
					if ( $dryRun ) {
						$this->output( "would enqueue: " . $title->getPrefixedText() . "\n" );
					} else {
						$jobQueueGroup->push( new DTAnnotateJob( $title, [] ) );
					}
					$enqueued++;
				}
				$this->waitForReplication();
			}
		}

		$verb = $dryRun ? 'would enqueue' : 'enqueued';
		$this->output( sprintf(
			"Done. Scanned %d pages, %s %d annotation jobs.\n",
			$total, $verb, $enqueued
		) );
		if ( !$dryRun && $enqueued > 0 ) {
			$this->output(
				"Run the jobrunner (or `php maintenance/run.php runJobs.php "
				. "--type discussionForumAnnotate`) to drain the queue.\n"
			);
		}
	}
}

$maintClass = BackfillForumAnnotations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
