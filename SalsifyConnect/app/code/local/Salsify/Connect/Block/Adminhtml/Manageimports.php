<?php

/**
 * Simply holds the grid that will display all the import runs.
 */
class Salsify_Connect_Block_Adminhtml_Manageimports extends Mage_Adminhtml_Block_Widget_Grid_Container {

  public function __construct() {
    // module name
    $this->_blockGroup = 'salsify_connect';

    // is actually the path to your block class (NOT YOUR CONTROLLER)
    $this->_controller = 'adminhtml_manageimports';

    $this->_headerText = $this->__('Manage Salsify Imports');

    parent::__construct();

    // remove the Add New button
    $this->removeButton('add');
  }

}