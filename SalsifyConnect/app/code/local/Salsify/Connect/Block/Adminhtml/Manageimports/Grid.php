<?php
/*
 * Grid for displaying all the import runs that have happened.
 */
class Salsify_Connect_Block_Adminhtml_Manageimports_Grid extends Mage_Adminhtml_Block_Widget_Grid {

  public function __construct() {
    parent::__construct();
     
    // Set some defaults for our grid
    $this->setDefaultSort('id');
    $this->setId('salsify_connect_manageimports_grid');
    $this->setDefaultDir('desc');
    $this->setSaveParametersInSession(true);
  }

  protected function _getCollectionClass() {
    // This is the model we are using for the grid
    return 'salsify_connect/importrun_collection';
  }

  protected function _prepareCollection() {
    // Get and set our collection for the grid
    $collection = Mage::getResourceModel($this->_getCollectionClass());
    $this->setCollection($collection);

    return parent::_prepareCollection();
  }

  protected function _prepareColumns() {
    // Add the columns that should appear in the grid
    $this->addColumn('id',
      array(
        'header'=> $this->__('ID'),
        'align' =>'right',
        'width' => '50px',
        'index' => 'id'
      )
    );

    $this->addColumn('status',
      array(
        'header'=> $this->__('Status'),
        'index' => 'status'
      )
    );

    $this->addColumn('start_time',
      array(
        'header'=> $this->__('Start Time'),
        'index' => 'start_time'
      )
    );

    return parent::_prepareColumns();
  }

  // I don't think this is required if we're not showing the 'add' button
  // public function getRowUrl($row) {
  //   // This is where our row data will link to
  //   return $this->getUrl('*/*/edit', array('id' => $row->getId()));
  // }
}