<?php
namespace PhpTagsStorage;
use MediaWiki\MediaWikiServices;

/**
 *
 */
class PageDataUpdate extends \DataUpdate {

	private $pageID;
	private $templateID;
	private $rows;

	function __construct( $pageID, $templateID, $rows ) {
		parent::__construct();

		$this->pageID = $pageID;
		$this->templateID = $templateID;
		$this->rows = $rows;
	}

	public function doUpdate() {
		wfDebugLog( 'PhpTags Storage', __METHOD__ );

		if ( method_exists( MediaWikiServices::class, 'getConnectionProvider' ) ) {
			// MW 1.42+
			$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		} else {
			$dbw = wfGetDB( DB_PRIMARY );
		}
		wfDebugLog( 'PhpTags Storage', __METHOD__ . ' DELETE ' . $this->templateID . ' WHERE page_id=' . $this->pageID );
		$dbw->delete( Schema::TABLE_PREFIX . $this->templateID, array('page_id'=>$this->pageID) );
		unset( \PhpTagsObjects\PageData::$cache[$this->pageID] );

		if ( $this->rows === false ) {
			return;
		}
		$a = array();
		$pageID = $this->pageID;
		foreach ( $this->rows as $rowID => $value ) {
			$value['page_id'] = $pageID;
			$value['row_id'] = $rowID;
			$a[] = $value;
		}
		wfDebugLog( 'PhpTags Storage', __METHOD__ . ' INSERT ' . $this->templateID . ' VALUES ' . print_r( $a, true ) );
		$dbw->insert( Schema::TABLE_PREFIX . $this->templateID, $a );
	}

}
