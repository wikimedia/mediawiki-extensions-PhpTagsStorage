<?php
namespace PhpTagsStorage;
use MediaWiki\MediaWikiServices;

/**
 *
 */
class SchemaUpdate extends \DataUpdate {

	private $templateID;
	private $fields;
	private $fDropTable;
	private $schemaLoadedRows;
	private $schemaTemplates;

	function __construct( $templateID, $fields, $fDropTable, &$loadedRows, &$templates ) {
		parent::__construct();

		$this->templateID = $templateID;
		$this->fields = $fields;
		$this->fDropTable = $fDropTable;
		$this->schemaLoadedRows =& $loadedRows;
		$this->schemaTemplates =& $templates;
	}

	public function doUpdate() {
		wfDebugLog( 'PhpTags Storage', __METHOD__ );
		$templateID = $this->templateID;
		if ( method_exists( MediaWikiServices::class, 'getConnectionProvider' ) ) {
			// MW 1.42+
			$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		} else {
			$dbw = wfGetDB( DB_PRIMARY );
		}

		if ( $this->fDropTable ) {
			try {
				wfDebugLog( 'PhpTags Storage', __METHOD__ . " DROP TABLE $templateID");
				$dbw->dropTable( Schema::TABLE_PREFIX . $templateID );
			} catch ( Exception $e ) {
				throw new MWException( "Caught exception ($e) while trying to drop PhpTags Storage table. "
				. "Please make sure that your database user account has the DROP permission." );
			}
		}

		if ( $this->fields === false ) {
			wfDebugLog( 'PhpTags Storage', __METHOD__ . " DELETE TABLE_SCHEMA $templateID");
			$dbw->delete( Schema::TABLE_SCHEMA, array('template_id'=>$templateID) );
			$this->schemaLoadedRows[$templateID] = true;
			$this->schemaTemplates[$templateID] = true;
			return;
		}

		# create table for template's data
		$tableName = $dbw->tableName( Schema::TABLE_PREFIX . $templateID );
		$fields = array(); // for update table Schema::TABLE_SCHEMA
		$createSQL = "CREATE TABLE $tableName ( page_id int NOT NULL, row_id int NOT NULL";
		foreach ( $this->fields as $f ) {
			$createSQL .= $f->toCreateSQL();
			$fields[$f->getName()] = $f->toSchema();
		}
		$createSQL .= ')';
		try {
			wfDebugLog( 'PhpTags Storage', __METHOD__ . " CREATE TABLE $templateID");
			$dbw->query( $createSQL );
			$dbw->query( "CREATE UNIQUE INDEX page_row_id ON $tableName ( page_id, row_id )" );
		} catch ( Exception $e ) {
			throw new MWException( "Caught exception ($e) while trying to create PhpTags Storage table. "
				. "Please make sure that your database user account has the CREATE permission." );
		}

		# update table Schema::TABLE_SCHEMA
		$schema = \FormatJson::encode( $fields );
		wfDebugLog( 'PhpTags Storage', __METHOD__ . " REPLACE TABLE_SCHEMA $templateID");
		$dbw->replace( Schema::TABLE_SCHEMA, array(array('template_id')), array('template_id'=>$templateID, 'table_schema'=>$schema) );
		$this->schemaTemplates[$templateID] = null;
		$this->schemaLoadedRows[$templateID] = $schema;
	}

}
