<?php

// Block for rendering the Salsify admin menu
class Salsify_Connect_Block_Adminhtml_Menu extends Mage_Core_Block_Template {

  private static function _log($msg) {
    Mage::log('Block_Adminhtml_Menu: ' . $msg, null, 'salsify.log', true);
  }


  public function _construct() {
    $this->setTemplate('salsify/menu.phtml');
    return parent::_construct();
  }

}