<?php
namespace PhpTagsStorage;
use MediaWiki\MediaWikiServices;

/**
 *
 */
class PageTemplatesUpdate extends \DataUpdate {

	private $pageID;
	private $templates;
	private $schemaPageTemplates;

	function __construct( $pageID, $templates, &$pageTemplates ) {
		parent::__construct();

		$this->pageID = $pageID;
		$this->templates = $templates;
		$this->schemaPageTemplates =& $pageTemplates;
	}

	public function doUpdate() {
		wfDebugLog( 'PhpTags Storage', __METHOD__ );

		if ( method_exists( MediaWikiServices::class, 'getConnectionProvider' ) ) {
			// MW 1.42+
			$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		} else {
			$dbw = wfGetDB( DB_PRIMARY );
		}
		$templates = $this->templates;

		if ( $templates ) {
			wfDebugLog( 'PhpTags Storage', __METHOD__ . " REPLACE pageTemplates WHERE pageID is " . $this->pageID );
			$dbw->replace(
					Schema::TABLE_PAGE_TEMPLATES,
					array(array('page_id')),
					array('page_id' => $this->pageID, 'templates' => \FormatJson::encode( $templates ))
				);
			$this->schemaPageTemplates[$this->pageID] = $templates;
			return;
		}

		wfDebugLog( 'PhpTags Storage', __METHOD__ . " DELETE pageTemplates WHERE pageID is " . $this->pageID );
		$dbw->delete( Schema::TABLE_PAGE_TEMPLATES, array('page_id'=>$this->pageID) );
		$this->schemaPageTemplates[$this->pageID] = null;
	}

}
