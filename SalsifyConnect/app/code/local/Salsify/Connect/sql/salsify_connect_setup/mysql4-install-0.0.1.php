<?php
$installer = $this;
$installer->startSetup();

// Create the initial Salsify data
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

$table = $installer->getConnection()->newTable($installer->getTable(
  'salsify_connect/import_run'))
  ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    'primary' => true,
    'identity' => true,
    ), 'Salsify Connect Import ID')
  ->addColumn('token', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
    'nullable' => false,
    ), 'Salsify Connect Import Token (provided by Salsify Server)')
  ->addColumn('status', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => false,
    'nullable' => false,
    'primary'  => false,
    'identity' => false,
    ), 'Salsify Connect Import Run Status')
  ->addColumn('start_time', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
    'nullable' => false,
    ), 'Salsify Connect Import Run Start Time')
  // ->addColumn('end_time', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
  //   'nullable' => true,
  //   ), 'Salsify Connect Import Run End Time')
  ->addColumn('configuration_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
    'unsigned' => true,
    'nullable' => false,
    ), 'Salsify Connect Import ID')
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

$installer->endSetup();
