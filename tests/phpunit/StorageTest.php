<?php
namespace PhpTags;

/**
 * @coversNothing
 */
class StorageTest extends \PHPUnit\Framework\TestCase {

	public function testRun_PHPTAGS_STORAGE_VERSION_constant() {
		if ( Renderer::$needInitRuntime ) {
			\MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->run( 'PhpTagsRuntimeFirstInit' );
			Hooks::loadData();
			Runtime::$loopsLimit = 1000;
			Renderer::$needInitRuntime = false;
		}

		$this->assertEquals (
				Runtime::runSource('echo PHPTAGS_STORAGE_VERSION;'),
				array(PHPTAGS_STORAGE_VERSION)
		);
	}

}
