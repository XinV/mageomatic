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

    // for internationalization
    $this->_headerText = Mage::helper('salsify_connect')
                             ->__('Manage Imports from Salsify');

    // create the new button. evidently 'new_button' could be anything. doesn't
    // show up or anything
    $createImportUrl = $this->getUrl('*/*/createimport');
    $createWorkerUrl = $this->getUrl('*/*/createworker');
    $this->_addButton('new_button', array(
      'label'   => Mage::helper('salsify_connect')->__('Create New Import'),
      'onclick' => "salsify.connect.createExport('" .
                            $createImportUrl ."','" . $createWorkerUrl . "');",
      'class'   => 'add new_import_button',
    ));

    parent::__construct();

    // remove the original Add New button
    $this->removeButton('add');
  }

}