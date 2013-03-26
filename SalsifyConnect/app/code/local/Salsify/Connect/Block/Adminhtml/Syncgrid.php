<?php
/**
 * Abstract grid class for listing imports or exports.
 *
 * These grids are largely identical.
 */
abstract class Salsify_Connect_Block_Adminhtml_Syncgrid extends Mage_Adminhtml_Block_Widget_Grid {

  public function __construct() {
    parent::__construct();
    $this->setDefaultSort('start_time');
    $this->setDefaultDir('desc');
    $this->setSaveParametersInSession(true);
  }


  protected abstract function _getCollectionClass();


  protected function _prepareCollection() {
    $collection = Mage::getResourceModel($this->_getCollectionClass());
    $this->setCollection($collection);
    return parent::_prepareCollection();
  }


  protected function _prepareColumns() {
    $this->addColumn('id',
      array(
        'header'=> $this->__('ID'),
        'index' => 'id',
        'align' =>'right',
        'width' => '50px',
        'readonly' => true,
      )
    );

    $this->addColumn('status',
      array(
        'header'=> $this->__('Current Status'),
        'index' => 'status_message',
        'readonly' => true,
      )
    );

    $this->addColumn('start_time',
      array(
        'header'=> $this->__('Start Time'),
        'index' => 'start_time',
        'width' => '150px',
        'readonly' => true,
      )
    );

    $this->addColumn('end_time',
      array(
        'header'=> $this->__('End Time'),
        'index' => 'end_time',
        'width' => '150px',
        'readonly' => true,
      )
    );

    return parent::_prepareColumns();
  }
}