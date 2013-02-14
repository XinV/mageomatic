<?php
class Salsify_Connect_Block_Adminhtml_ManageImports extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    Mage::log("block", null, 'salsify.log', true);

    $this->_controller = 'adminhtml_manageimports';
    $this->_blockGroup = 'salsify';
    $this->_headerText = Mage::helper('salsify')->__('Salsify Connect Manager');
    $this->_addButtonLabel = Mage::helper('salsify')->__('Add Import');
    parent::__construct();
  }

}