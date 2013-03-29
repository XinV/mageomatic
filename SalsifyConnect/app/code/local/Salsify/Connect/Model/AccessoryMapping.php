<?php

/**
 * For each relationship that originated in Salsify, this maintains the original
 * accessory category ID for lossless roundtrip updating.
 */
class Salsify_Connect_Model_AccessoryMapping
      extends Mage_Core_Model_Abstract
{
  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/accessorymapping');
  }

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


}
