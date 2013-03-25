<?php
/*
 * Grid for displaying all the export runs that have happened.
 *
 * TODO not DRY. we should have exports and imports both inherit from a single
 *      grid superclass.
 */
class Salsify_Connect_Block_Adminhtml_Manageexports_Grid extends Mage_Adminhtml_Block_Widget_Grid {

  public function __construct() {
    parent::__construct();
    $this->setDefaultSort('start_time');
    $this->setId('salsify_connect_manageexports_grid');
    $this->setDefaultDir('desc');
    $this->setSaveParametersInSession(true);
  }

  protected function _getCollectionClass() {
    return 'salsify_connect/exportrun_collection';
  }

  protected function _prepareCollection() {
    $collection = Mage::getResourceModel($this->_getCollectionClass());
    $this->setCollection($collection);
    return parent::_prepareCollection();
  }

  protected function _prepareColumns() {
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