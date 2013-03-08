<?php

// Primary rendering block
class Salsify_Connect_Block_Adminhtml_Admin extends Mage_Core_Block_Template {

  public function _beforeToHtml() {
    $menu_block = $this->_layout
                       ->createBlock('salsify_connect/adminhtml_menu','sidebar');
    $this->setChild($menu_block, 'salsify-menu');

    // TODO get the other blocks to show up here
  }

}