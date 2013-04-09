<?php

// OPTIMIZE more table indexes

// NOTE: to UNDO this install:
// DROP each of the tables created
// DELETE from core_resource where code = 'salsify_connect_setup';

// improves readability
$installer = $this;
$installer->startSetup();


// Create the initial Salsify data. Some notes on the table name here.
// The following call to getTable('salsify_connect/configuration') will lookup
// the resource for salsify_connect in confix.xml and look for a corresponding
// entity called configuration. The table name in the XML is
// salsify_connect_configuration, so this is what is created.
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/configuration'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Configuration ID')
  ->addColumn('api_key', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false
    ), 'Salsify API Key')
  ->addColumn('url', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false
    ), 'Salsify Base URL')
  ->setComment('Salsify_Connect salsify_connect/configuration entity table');
$installer->getConnection()->createTable($table);


// unfortunately you can't have attributes about attributes, so we needed to
// create this table to recall the mapping between attribute codes and salsify
// IDs for attributes for both imports and exports.
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/attribute_mapping'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Attribute Mapping ID')
  ->addColumn('code', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, array(
    'nullable' => false,
    ), 'Magento attribute code')
  ->addColumn('salsify_id', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'Salsify attribute ID')
  ->setComment('Salsify_Connect salsify_connect/attribute_mapping table');
$installer->getConnection()->createTable($table);
// MySQL doesn't support this on varchar lengths > 255, including text
// $installer->run("
//   ALTER TABLE salsify_connect_attribute_mapping
//   ADD UNIQUE index(code, salsify_id);
// ");


// in order to avoid re-importing the same images over and over again, we need
// to store metadata about an image's source somewhere.
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/image_mapping'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Image Mapping ID')
  ->addColumn('sku', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'ID of product related to the image in Magento')
  ->addColumn('magento_id', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'Magento ID for the image')
  ->addColumn('salsify_id', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'Salsify ID for the image')
  ->addColumn('url', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => true,
    'default'  => NULL,
    ), 'URL of the image')
  ->addColumn('source_url', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    'default'  => NULL,
    ), 'Source URL of the image (Saved for Salsify)')
  ->addColumn('checksum', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => true,
    'default'  => NULL,
    ), 'MD5 checksum of the image file')
  ->setComment('Salsify_Connect salsify_connect/image_mapping table');
$installer->getConnection()->createTable($table);


// this table contains the mappings between category labels in Salsify and
// cross-sells, up-sells, and product relations in salsify.
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/accessorycategory_mapping'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Accessory Category Mapping ID')
  ->addColumn('salsify_category_id', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'ID of Accessory Category in Salsify')
  ->addColumn('salsify_category_value', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'ID of Accessory Category Value in Salsify')
  ->addColumn('magento_relation_type', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
    'unsigned' => true,
    'nullable' => false,
    ), 'Product Relation Type in Magento (enum)');
$installer->getConnection()->createTable($table);


// for specific product relationships (sku-to-sku) this keeps track of the
// salsify category ID for the relation if it was originally imported from
// salsify.
//
// NOTE this would be totally unnecessary if all product relations are being
//      managed in Salsify, or if we simply aren't moving them back and forth
//      over the wire.
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/accessory_mapping'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Accessory Mapping ID')
  ->addColumn('salsify_category_id', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'ID of Accessory Category in Salsify')
  ->addColumn('salsify_category_value', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'ID of Accessory Category Value in Salsify')
  ->addColumn('magento_relation_type', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
    'unsigned' => true,
    'nullable' => false,
    ), 'Product Relation Type in Magento (enum)')
  // 64 is the length of a sku in Magento's internal tables
  ->addColumn('trigger_sku', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, array(
    'nullable' => false,
    ), 'Trigger Product SKU')
  ->addColumn('target_sku', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, array(
    'nullable' => false,
    ), 'Target Product SKU');
$installer->getConnection()->createTable($table);
// primarly for export
// tried to create an index:
//   CREATE INDEX accessory_mapping_by_category_and_skus
//   ON salsify_connect_accessory_mapping(salsify_category_value, trigger_sku, target_sku);
// but couldn't. mysql won't index text fields of longer than varchar > 255...
// If this becomes important we could always index the checksum of the external
// ID just for lookup purposes.
$installer->run("
  CREATE INDEX salsify_connect_accessory_mapping_by_skus
  ON salsify_connect_accessory_mapping(trigger_sku, target_sku, magento_relation_type);
");


// currently the import_run and export_run tables are nearly identical, and this
// is required by their superclass model SyncRun. this creates the basic raw
// table reuqired by SyncRun and shared by ImportRun and ExportRun.
function stub_import_export_table($installer, $table_id, $label) {
  $table = $installer->getConnection()->newTable($installer->getTable(
    'salsify_connect/' . $table_id))
    ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
      'unsigned' => true,
      'nullable' => false,
      'primary' => true,
      'identity' => true,
      ), 'Salsify Connect ' . $label . ' ID')
    ->addColumn('token', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
      'nullable' => true,
      'default'  => NULL,
      ), 'Salsify Connect ' . $label . ' Token (provided by Salsify Server)')
    ->addColumn('status', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
      'nullable' => false,
    ), 'Salsify Connect ' . $label . ' Run Status')
    ->addColumn('status_message', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
      'nullable' => true,
      'default'  => NULL,
      ), 'Salsify Status Message')
    ->addColumn('start_time', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
      'nullable' => true,
      'default'  => NULL,
      ), 'Salsify Connect ' . $label . ' Run Start Time')
    ->addColumn('end_time', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
      'nullable' => true,
      'default'  => NULL,
      ), 'Salsify Connect ' . $label . ' Run End Time')
    ->addIndex($installer->getIdxName(
      $installer->getTable('salsify_connect/' . $table_id),
      array('id'),
      array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_INDEX)
      ),
    ->setComment('Salsify_Connect salsify_connect/' . $table_id . ' entity table');

  return $table;
}

$table = stub_import_export_table($installer, 'import_run', 'Import');
// add extra columns here
$installer->getConnection()->createTable($table);
// $installer->run("
//   CREATE INDEX salsify_connect_import_run_by_id
//   ON salsify_connect_import_run(id);
// ");

$table = stub_import_export_table($installer, 'export_run', 'Export');
// add extra columns here
$installer->getConnection()->createTable($table);
// $installer->run("
//   CREATE INDEX salsify_connect_export_run_by_id
//   ON salsify_connect_export_run(id);
// ");


// NOTE: this is taken almost directly from the DJJob/jobs.sql
// I clearly didn't bother to use the newer syntax as above...and I'm not at
// all convinced that it's worth it, since Magento seems pretty unhappy using
// any database other than MySQL right now.
$installer->run("
  CREATE TABLE IF NOT EXISTS `jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `handler` TEXT NOT NULL,
  `queue` VARCHAR(255) NOT NULL DEFAULT 'default',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `run_at` DATETIME NULL,
  `locked_at` DATETIME NULL,
  `locked_by` VARCHAR(255) NULL,
  `failed_at` DATETIME NULL,
  `error` TEXT NULL,
  `created_at` DATETIME NOT NULL
  ) ENGINE = INNODB;
");


$installer->endSetup();
