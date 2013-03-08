<?php

// Primary block for rendering the admin interface
class Salsify_Connect_Block_Adminhtml_Admin extends Mage_Core_Block_Template {

  private static function _log($msg) {
    Mage::log('Block_Adminhtml_Admin: ' . $msg, null, 'salsify.log', true);
  }


  public function _construct() {
    $this->setTemplate('salsify/admin.phtml');
    return parent::_construct();
  }


  public function _beforeToHtml() {
    $menu_block = new Salsify_Connect_Block_Adminhtml_Menu();
    $this->setChild('menu_left', $menu_block);
    $this->_addLeft($menu_block);
  }

}