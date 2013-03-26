<?php
/*
 * Grid for displaying all the import runs that have happened.
 */
class Salsify_Connect_Block_Adminhtml_Manageimports_Grid extends Salsify_Connect_Block_Adminhtml_Syncgrid {

  public function __construct() {
    parent::__construct();
    $this->setId('salsify_connect_manageimports_grid');
  }

  protected function _getCollectionClass() {
    return 'salsify_connect/importrun_collection';
  }
}