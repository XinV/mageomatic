<?php

// Primary rendering block
class Salsify_Connect_Block_Adminhtml_Admin extends Mage_Core_Block_Template {


  public function _construct() {
    $this->setTemplate('salsify/admin.phtml');
    return parent::_construct();
  }


  public function _beforeToHtml() {
    $menu_block = $this->_layout
                       ->createBlock('salsify_connect/adminhtml_menu','salsify-menu');
    $this->setChild($menu_block, 'salsify-menu');
  }


  public function setContentBlock($blockname) {
    $block = $this->_layout
                  ->createBlock('salsify_connect/adminhtml_' . $blockname,'salsify-content');
    // $this->setChild($block, 'salsify-content');
  }

}