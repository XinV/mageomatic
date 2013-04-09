<?php
/**
 * Grid for displaying all the export runs that have happened.
 */
class Salsify_Connect_Block_Adminhtml_Manageexports_Grid
      extends Salsify_Connect_Block_Adminhtml_Syncgrid
{

  public function __construct() {
    parent::__construct();
    $this->setId('salsify_connect_manageexports_grid');
  }

  protected function _getCollectionClass() {
    return 'salsify_connect/exportrun_collection';
  }
}