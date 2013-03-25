<?php

/**
 * Simply holds the grid that will display all the export runs.
 */
class Salsify_Connect_Block_Adminhtml_Manageexports extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    $this->_blockGroup = 'salsify_connect';
    $this->_controller = 'adminhtml_manageexports';
    $this->_headerText = $this->__('Manage Exports to Salsify');
    parent::__construct();

    $this->_addButtonLabel = 'Create New Import';

    // remove the Add New button
    $this->removeButton('add');
  }

}