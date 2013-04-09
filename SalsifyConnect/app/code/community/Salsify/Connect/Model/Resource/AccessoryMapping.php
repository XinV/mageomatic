<?php
class Salsify_Connect_Model_Resource_AccessoryMapping
      extends Mage_Core_Model_Resource_Db_Abstract
{
  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/accessory_mapping', 'id');
  }
}