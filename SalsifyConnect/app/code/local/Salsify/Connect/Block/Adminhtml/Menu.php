<?php

// Block for rendering the Salsify admin menu
class Salsify_Connect_Block_Adminhtml_Menu extends Mage_Core_Block_Template {

  private static function _log($msg) {
    Mage::log('Block_Adminhtml_Menu: ' . $msg, null, 'salsify.log', true);
  }

  public function _construct() {
    self::_log("RENDERING ME BY GOD!");

    // FIXME HERE
    $this->setTemplate('salsify/menu.phtml');
    return parent::_construct();
  }

}