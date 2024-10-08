<?php
use MediaWiki\MediaWikiServices;

/**
 * @group Database
 * @coversNothing
 */
class StorageWikiPageTest extends MediaWikiLangTestCase {

	private $pages_to_delete;

	protected function setUp() : void {
		parent::setUp();
		$this->pages_to_delete = array();

		MediaWikiServices::getInstance()->getLinkCache()->clear(); # avoid cached redirect status, etc

		if ( PhpTags\Renderer::$needInitRuntime ) {
			MediaWikiServices::getInstance()->getHookContainer()->run( 'PhpTagsRuntimeFirstInit' );
			PhpTags\Hooks::loadData();
			PhpTags\Runtime::$loopsLimit = 1000;
			PhpTags\Renderer::$needInitRuntime = false;
		}
	}

	protected function tearDown() : void {
		$user = $this->getTestSysop()->getUser();
		$reason = 'testing done.';
		foreach ( $this->pages_to_delete as $p ) {
			/* @var $p WikiPage */

			try {
				if ( $p->exists() ) {
					if ( version_compare( MW_VERSION, '1.35', '<' ) ) {
						$p->doDeleteArticle( $reason );
					} else {
						$p->doDeleteArticleReal( $reason, $user );
					}
				}
			} catch ( MWException $ex ) {
				// fail silently
			}
		}
		parent::tearDown();
	}

	/**
	 * @param Title|string $title
	 * @param string|null $model
	 * @return WikiPage
	 */
	protected function newPage( $title ) {
		if ( is_string( $title ) ) {
			$title = Title::newFromText( $title );
		}

		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		} else {
			$page = WikiPage::factory( $title );
		}

		$this->pages_to_delete[] = $page;

		return $page;
	}

	/**
	 * @param string|Title|WikiPage $page
	 * @param string $text
	 * @param int $model
	 *
	 * @return WikiPage
	 */
	protected function createPage( $page, $text ) {
		if ( is_string( $page ) || $page instanceof Title ) {
			$page = $this->newPage( $page );
		}

		if ( $text instanceof Content ) {
			$content = $text;
		} else {
			$content = ContentHandler::makeContent( $text, $page->getTitle() );
		}
		$user = $this->getTestSysop()->getUser();
		$page->doUserEditContent( ContentHandler::makeContent( '', $page->getTitle() ), $user, "create empty page" );
		$page->doUserEditContent( $content, $user, "testing" );
		return $page;
	}

	public function test_template_StorageTag() {

		####### Create Template:StorageTag #######
		$text = '
<phptag>
$s = new Storage( ["tag"=>"text"] );
if( !isset( $argv[1] ) ) {
	break;
}
$s->setValues( [ "tag"=>$argv[1] ] );
</phptag>';

		$titleStorageTag = Title::newFromText( 'StorageTag', NS_TEMPLATE );
		$templateStorageTagId = $this->createPage( $titleStorageTag, $text, CONTENT_MODEL_WIKITEXT )->getId();

		# ------------------------
		$dbr = $this->db;
		$res = $dbr->select( PhpTagsStorage\Schema::TABLE_SCHEMA, '*', array('template_id' => $templateStorageTagId) );
		$n = $res->numRows();
		$res->free();

		$this->assertEquals( 1, $n, 'TABLE_SCHEMA should contain one record' );

		# ------------------------
		$this->assertTrue( $dbr->tableExists( PhpTagsStorage\Schema::TABLE_PREFIX . $templateStorageTagId ), 'Table for template data was not created' );

		####### Create Page1 (transclude one template StorageTag) #######
		$text = '{{StorageTag|It is TAG!}}';

		$page_1 = $this->createPage( "Page1", $text, CONTENT_MODEL_WIKITEXT );
		$page_1_ID = $page_1->getId();

		# ------------------------
		$template_table_name = PhpTagsStorage\Schema::TABLE_PREFIX . $templateStorageTagId;
		$field_1_DB_Name = PhpTagsStorage\Field::PREFIX . 1;
		$res = $dbr->select( $template_table_name, '*', array('page_id'=>$page_1_ID) );
		$n = $res->numRows();
		$this->assertEquals( 1, $n, 'template TABLE should contain one record for Page1' );

		$row = $res->fetchRow();
		$this->assertEquals( $row[$field_1_DB_Name], 'It is TAG!' );
		$res->free();

		####### Create Page2 (transclude three templates StorageTag) #######
		$text = '{{StorageTag|one}}{{StorageTag|two}}{{StorageTag|three}}';

		$pageID = $this->createPage( "Page2", $text, CONTENT_MODEL_WIKITEXT )->getId();

		# ------------------------
		$res = $dbr->select( $template_table_name, '*', array('page_id'=>$pageID) );
		$n = $res->numRows();
		$this->assertEquals( 3, $n, 'template TABLE should contain three record for Page2' );

		$row = $res->fetchRow();
		$this->assertEquals( $row[$field_1_DB_Name], 'one' );
		$row = $res->fetchRow();
		$this->assertEquals( $row[$field_1_DB_Name], 'two' );
		$row = $res->fetchRow();
		$this->assertEquals( $row[$field_1_DB_Name], 'three' );
		$res->free();

		####### Create Template:DumpTags #######
		$text = '
<phptag>
$title = isset( $argv[1] ) ? new WTitle( $argv[1] ) : null;
$templateTitle = new WTitle( "StorageTag", NS_TEMPLATE );
$pd = new PageData( $title, $templateTitle );
$values = $pd->getValues();
$rows = current( $values );
if ( $rows ) {
    $tags = array();
    foreach ( $rows as $tagRow ) {
        $tags[] = $tagRow["tag"];
    }
    echo "TAGS: ", implode( ", ", $tags ), ".\n";
} else {
	echo "There is no TAG\n";
}
</phptag>';

		$templateDumpTagsId = $this->createPage( "Template:DumpTags", $text, CONTENT_MODEL_WIKITEXT )->getId();

		####### Create Page 'Test template DumpTags' for Page2 data #######
		$text = '{{DumpTags|Page2}}';

		$page = $this->createPage( "Test template DumpTags", $text, CONTENT_MODEL_WIKITEXT );

		$options = ParserOptions::newFromAnon();
		$options->enableLimitReport( false );

		if ( method_exists( MediaWikiServices::class, 'getContentRenderer' ) ) {
			// MW 1.38+
			$output = MediaWikiServices::getInstance()->getContentRenderer()->getParserOutput( $page->getContent(), $page->getTitle(), null, $options );
		} else {
			$output = $page->getContent()->getParserOutput( $page->getTitle(), null, $options );
		}
		$this->assertEquals($output->getText( [ 'unwrap' => true ] ), "<p>TAGS: one, two, three.\n</p>", "Page 'Test template DumpTags'" );

		####### Move Template:StorageTag to Template:NewStorageTag and create redirect #######
//		var_dump( "-= MOVE TEMPLATE =-" );
		$titleNewStorageTag = Title::newFromText( "NewStorageTag", NS_TEMPLATE );
		$mp = $this->getServiceContainer()->getMovePageFactory()->newMovePage( $titleStorageTag, $titleNewStorageTag );
		$status = $mp->move( $this->getTestSysop()->getUser(), 'Test move Storage', true );
		$this->assertTrue( $status->isOK() );
		$this->assertEquals( $titleNewStorageTag->getArticleID(), $templateStorageTagId, 'ID should not change when moving the template' );
		$this->assertTrue( $dbr->tableExists( PhpTagsStorage\Schema::TABLE_PREFIX . $templateStorageTagId ), 'Template table was dropped, ID:' . $templateStorageTagId );

		# ------------------------
		$res = $dbr->select( $template_table_name, '*', array('page_id'=>$page_1_ID) );
		$n = $res->numRows();
		$this->assertEquals( 1, $n, 'template TABLE should contain one record for Page1 after template move' );
		$row = $res->fetchRow();
		$this->assertEquals( $row[$field_1_DB_Name], 'It is TAG!', 'After template move' );
		$res->free();

		####### Create Page 'Test template DumpTags after move template' for Page2 data #######
		$text = '{{DumpTags|Page2}}';

		$page = $this->createPage( "Test template DumpTags after move template", $text, CONTENT_MODEL_WIKITEXT );
		if ( method_exists( MediaWikiServices::class, 'getContentRenderer' ) ) {
			// MW 1.38+
			$output = MediaWikiServices::getInstance()->getContentRenderer()->getParserOutput( $page->getContent(), $page->getTitle(), null, $options );
		} else {
			$output = $page->getContent()->getParserOutput( $page->getTitle(), null, $options );
		}
		$this->assertEquals($output->getText( [ 'unwrap' => true ] ), "<p>TAGS: one, two, three.\n</p>", "Page 'Test template DumpTags after move template'" );

		####### Create Page3 (transclude redirect StorageTag) #######
		$text = '{{StorageTag|I use #redirect to template NewStorageTag}}';
		$pageID = $this->createPage( "Page3", $text, CONTENT_MODEL_WIKITEXT )->getId();
		$res = $dbr->select( $template_table_name, '*', array('page_id'=>$pageID) );
		$n = $res->numRows();
		$this->assertEquals( 1, $n, 'template TABLE should contain record when redirect is transcluded (Page3)' );
		$row = $res->fetchRow();
		$this->assertEquals( $row[$field_1_DB_Name], 'I use #redirect to template NewStorageTag' );
		$res->free();

		####### Test template DumpTags for redirect page #######
		if ( method_exists( MediaWikiServices::class, 'getContentHandlerFactory' ) ) {
			// MW 1.35+
			$wch = MediaWikiServices::getInstance()
				->getContentHandlerFactory()
				->getContentHandler( CONTENT_MODEL_WIKITEXT );
		} else {
			$wch = ContentHandler::getForModelID( CONTENT_MODEL_WIKITEXT );
		}
		$text = $wch->makeRedirectContent( Title::newFromText( "Page3" ) );
		$page = $this->createPage( "Redirect to Page3", $text, CONTENT_MODEL_WIKITEXT );

		$text = '{{DumpTags|Redirect to Page3}}';
		$page = $this->createPage( "Test DumpTags for redirect page", $text, CONTENT_MODEL_WIKITEXT );

		if ( method_exists( MediaWikiServices::class, 'getContentRenderer' ) ) {
			// MW 1.38+
			$output = MediaWikiServices::getInstance()->getContentRenderer()->getParserOutput( $page->getContent(), $page->getTitle(), null, $options );
		} else {
			$output = $page->getContent()->getParserOutput( $page->getTitle(), null, $options );
		}
		$this->assertEquals($output->getText( [ 'unwrap' => true ] ), "<p>TAGS: I use #redirect to template NewStorageTag.\n</p>" );

		####### Delete Page1 #######
//		echo "Test delete Page1 $page_1_ID\n";
		$reason = 'test delete page';
		if ( version_compare( MW_VERSION, '1.35', '<' ) ) {
			$page_1->doDeleteArticle( $reason );
		} else {
			$page_1->doDeleteArticleReal( $reason, $this->getTestSysop()->getUser() );
		}

		$res = $dbr->select( $template_table_name, '*', array('page_id'=>$page_1_ID) );
		$n = $res->numRows();
		$this->assertEquals( 0, $n, 'template TABLE should not contain a record for Page1 after the page deletion' );
		$res->free();

		####### Undelete Page1 #######
		$comment = 'restore Page1 after test delete';
		if ( version_compare( MW_VERSION, '1.38', '<' ) ) {
			$archive = new PageArchive( $page_1->getTitle() );

			if ( method_exists( $archive, 'undeleteAsUser' ) ) {
				$archive->undeleteAsUser( array(), $this->getTestSysop()->getUser(), $comment );
			} else {
				$archive->undelete( array(), $comment );
			}
		} else {
			$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $page_1->getTitle() );
			$this->getServiceContainer()->getUndeletePageFactory()
				->newUndeletePage( $page, $this->getTestSysop()->getAuthority() )
				->undeleteUnsafe( $comment );
		}

		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$page_1 = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $page_1->getTitle() );
		} else {
			$page_1 = WikiPage::factory( $page_1->getTitle() );
		}
		$page_1_ID = $page_1->getId();

		# ------------------------
		$template_table_name = PhpTagsStorage\Schema::TABLE_PREFIX . $templateStorageTagId;
		$field_1_DB_Name = PhpTagsStorage\Field::PREFIX . 1;
		$res = $dbr->select( $template_table_name, '*', array('page_id'=>$page_1_ID) );
		$n = $res->numRows();
		$this->assertEquals( 1, $n, 'template TABLE should contain one record for Page1 after undelete' );

		$row = $res->fetchRow();
		$this->assertEquals( $row[$field_1_DB_Name], 'It is TAG!', 'after undelete Page1' );
		$res->free();

		####### Delete Template:NewStorageTag #######
//		var_dump( "\$titleNewStorageTag " . $titleNewStorageTag->getArticleID() );
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$wikipage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $titleNewStorageTag );
		} else {
			$wikipage = WikiPage::factory( $titleNewStorageTag );
		}
		$reason = 'test delete template';
		if ( version_compare( MW_VERSION, '1.35', '<' ) ) {
			$wikipage->doDeleteArticle( $reason );
		} else {
			$wikipage->doDeleteArticleReal( $reason, $this->getTestSysop()->getUser() );
		}
//		var_dump( 'test delete template' );

		# ------------------------
		$res = $dbr->select( PhpTagsStorage\Schema::TABLE_SCHEMA, '*', array('template_id' => $templateStorageTagId) );
		$n = $res->numRows();
		$res->free();
		//$this->assertEquals( 0, $n, 'TABLE_SCHEMA should not contain a record after template delete, ID:' . $templateStorageTagId );

		# ------------------------
		$this->assertFalse( $dbr->tableExists( PhpTagsStorage\Schema::TABLE_PREFIX . $templateStorageTagId ), 'Table for template data was not deleted, ID:' . $templateStorageTagId );
		//$j = new RunJobs();
		//$j->execute();
	}

}
