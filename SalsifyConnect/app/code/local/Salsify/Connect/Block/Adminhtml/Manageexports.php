<?php

/**
 * Simply holds the grid that will display all the export runs.
 */
class Salsify_Connect_Block_Adminhtml_Manageexports extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    // module name
    $this->_blockGroup = 'salsify_connect';

    // is actually the path to your block class (NOT YOUR CONTROLLER)
    $this->_controller = 'adminhtml_manageexports';

    $this->_headerText = $this->__('Manage Salsify Exports');

    parent::__construct();

    // FIXME need to have an action for this
    // remove the Add New button
    $this->removeButton('add');
  }

}