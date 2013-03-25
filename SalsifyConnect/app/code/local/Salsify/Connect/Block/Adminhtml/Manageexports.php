<?php

/**
 * Simply holds the grid that will display all the export runs.
 */
class Salsify_Connect_Block_Adminhtml_Manageexports extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    $this->_blockGroup = 'salsify_connect';
    $this->_controller = 'adminhtml_manageexports';
    $this->_headerText = $this->__('Manage Exports to Salsify');

    
    // $this->_addButtonLabel = Mage::helper('salsify_connect')
    //                              ->__('Create new Export');
    $this->_addButton('new_button', array(
      'label'   => Mage::helper('salsify_connect')->__('Create New Export'),
      'onclick' => "setLocation('".$this->getUrl('*/*/index')."')"
    ));

    parent::__construct();

    // remove the original
    $this->removeButton('add');
  }

}