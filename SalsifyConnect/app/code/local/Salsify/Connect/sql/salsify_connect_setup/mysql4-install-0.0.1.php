<?php

// NOTE: to UNDO this:
// DELETE from core_resource where code = 'salsify_connect_setup'; drop table salsify_connect_import_run; drop table salsify_connect_configuration; drop table jobs;

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


// Create table for Salsify imports. Keeps a record of every import pulled into
// Magento from Salsify.
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/import_run'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Import ID')
  ->addColumn('token', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => true,
    'default'  => NULL,
    ), 'Salsify Connect Import Token (provided by Salsify Server)')
  ->addColumn('status', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => false,
    'nullable' => false,
    'primary'  => false,
    'identity' => false,
  ), 'Salsify Connect Import Run Status')
  ->addColumn('start_time', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
    'nullable' => false,
    ), 'Salsify Connect Import Run Start Time')
  // ->addColumn('end_time', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
  //   'nullable' => true,
  //   ), 'Salsify Connect Import Run End Time')
  ->addColumn('configuration_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary'  => false,
    'identity' => false,
    ), 'Config Used for this Import Run')
  ->addForeignKey(
          $installer->getFkName('salsify_connect/import_run', 'configuration_id',
                                'salsify_connect/configuration', 'id'),
          'configuration_id',
          $installer->getTable('salsify_connect/configuration'),
          'id',
          Varien_Db_Ddl_Table::ACTION_NO_ACTION,
          Varien_Db_Ddl_Table::ACTION_NO_ACTION)
  ->setComment('Salsify_Connect salsify_connect/import_run entity table');
$installer->getConnection()->createTable($table);


// Create export table for Salsify imports. Keeps a record of every export that
// pushes data from Magento Salsify.
$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/export_run'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Export ID')
  // FIXME is this even used?
  ->addColumn('token', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => true,
    'default'  => NULL,
    ), 'Salsify Connect Export Token (provided by Salsify Server)')
  ->addColumn('status', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => false,
    'nullable' => false,
  ), 'Salsify Connect Export Run Status')
  ->addColumn('start_time', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
    'nullable' => true,
    ), 'Salsify Connect Export Run Start Time')
  ->addColumn('end_time', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
    'nullable' => true,
    ), 'Salsify Connect Export Run End Time')
  ->setComment('Salsify_Connect salsify_connect/export_run entity table');
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
