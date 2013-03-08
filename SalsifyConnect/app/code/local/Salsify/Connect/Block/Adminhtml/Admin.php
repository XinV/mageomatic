<?php

// Primary rendering block
class Salsify_Connect_Block_Adminhtml_Admin extends Mage_Core_Block_Abstract {

  public function _beforeToHtml() {
    $layout = Mage::getSingleton('core/layout');
    $menu_block = $layout->createBlock('salsify_connect/adminhtml_menu','sidebar');
    $this->setChild($menu_block, 'salsify-menu');
  }

}