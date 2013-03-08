<?php

// Block to provide a form to configure the Salsify connection.
class Salsify_Connect_Block_Adminhtml_Config extends Mage_Core_Block_Template {

  public function _construct() {
    $this->setTemplate('salsify/config.phtml');
    return parent::_construct();
  }


}