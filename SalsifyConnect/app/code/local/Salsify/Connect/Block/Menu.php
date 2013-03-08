<?php

// Block for rendering the Salsify admin menu
class Salsify_Connect_Block_Menu extends Mage_Core_Block_Template {

  public function _construct() {
    // TODO need our own PHTML file here
    $this->setTemplate('nofrills_helloworld.phtml');
    return parent::_construct();
  }

}
