<?php

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
//
// TODO: add unique pairs (e.g. each code/ID pair should only show up once)
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/attribute_mapping'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Attribute Mapping ID')
  ->addColumn('code', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'Magento attribute code')
  ->addColumn('salsify_id', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'Salsify attribute ID')
  ->setComment('Salsify_Connect salsify_connect/attribute_mapping table');
$installer->getConnection()->createTable($table);


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
    'unsigned' => true,
    'nullable' => false,
    ), 'ID of product related to the image in Magento')
  ->addColumn('magento_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    ), 'ID of image in Magento')
  ->addColumn('url', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => true,
    'default'  => NULL,
    ), 'Source URL of the image')
  ->setComment('Salsify_Connect salsify_connect/image_mapping table');
$installer->getConnection()->createTable($table);


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
    ->setComment('Salsify_Connect salsify_connect/' . $table_id . ' entity table');

  return $table;
}

$table = stub_import_export_table($installer, 'import_run', 'Import');
$installer->getConnection()->createTable($table);

$table = stub_import_export_table($installer, 'export_run', 'Export');
$installer->getConnection()->createTable($table);


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
