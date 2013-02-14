<?php
class Salsify_Connect_Block_Adminhtml_SalsifyConnect extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    Mage::log("block", null, 'salsify.log', true);

    $this->_controller = 'adminhtml_salsifyconnect';
    $this->_blockGroup = 'salsifyconnect';
    $this->_headerText = Mage::helper('salsifyconnect')->__('Salsify Connect Manager');
    $this->_addButtonLabel = Mage::helper('salsifyconnect')->__('Add Import');
    parent::__construct();
  }

}