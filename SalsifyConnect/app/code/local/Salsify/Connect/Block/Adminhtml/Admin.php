<?php

// Primary rendering block
class Salsify_Connect_Block_Adminhtml_Admin extends Mage_Core_Block_Abstract {

  public function _construct() {
    $menu_block = $this->_layout
                       ->createBlock('salsify_connect/adminhtml_menu');
    $this->setChild($menu_block, 'salsify-menu');
    return parent::_construct();
  }

}