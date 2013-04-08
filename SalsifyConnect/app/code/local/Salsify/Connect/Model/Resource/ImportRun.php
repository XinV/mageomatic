<?php
class Salsify_Connect_Model_Resource_ImportRun
      extends Mage_Core_Model_Resource_Db_Abstract
{
  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/import_run', 'id');
  }
}