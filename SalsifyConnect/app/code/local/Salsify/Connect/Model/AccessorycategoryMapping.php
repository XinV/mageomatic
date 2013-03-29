<?php

/**
 * FIXME
 */
class Salsify_Connect_Model_AccessorycategoryMapping
      extends Mage_Core_Model_Abstract
{

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }

  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/accessorycategorymapping');
  }

}
