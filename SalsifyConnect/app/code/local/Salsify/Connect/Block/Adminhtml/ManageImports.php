<?php
class Salsify_Connect_Block_Adminhtml_ManageImports extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    Mage::log("block", null, 'salsify.log', true);

    $this->_controller = 'adminhtml_manageimports';
    $this->_blockGroup = 'manageimports';
    $this->_headerText = Mage::helper('manageimports')->__('Salsify Connect Manager');
    $this->_addButtonLabel = Mage::helper('manageimports')->__('Add Import');
    parent::__construct();
  }

}