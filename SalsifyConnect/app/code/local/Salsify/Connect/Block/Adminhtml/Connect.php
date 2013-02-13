<?php
class Salsify_Connect_Block_Adminhtml_Salsify_Connect extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    $this->_controller = 'adminhtml_salsify_connect';
    $this->_blockGroup = 'salsify_connect';
    $this->_headerText = Mage::helper('salsify_connect')->__('Salsify Connect Manager');
    $this->_addButtonLabel = Mage::helper('salsify_connect')->__('Add Import');
    parent::__construct();
  }

}