<?php

// Primary rendering block
class Salsify_Connect_Block_Adminhtml_Admin extends Mage_Core_Block_Abstract {

  private static function _log($msg) {
    Mage::log('Block_Adminhtml_Admin: ' . $msg, null, 'salsify.log', true);
  }

  public function _construct() {
    self::_log("THERE");

    // $menu_block = $this->_layout
    //                    ->createBlock('salsify_connect/adminhtml_menu','sidebar');
    // $this->setChild($menu_block, 'salsify-menu');
    return parent::_construct();
  }

  public function _beforeToHtml() {
    self::_log("HERE");

    $menu_block = $this->_layout
                       ->createBlock('salsify_connect/adminhtml_menu','sidebar');
    $this->setChild($menu_block, 'salsify-menu');
    // return parent::_construct();
  }

}