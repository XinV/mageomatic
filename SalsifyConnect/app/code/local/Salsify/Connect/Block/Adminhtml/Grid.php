<?php
 
class Salsify_Connect_Block_Adminhtml_ManageImports_Grid extends Mage_Adminhtml_Block_Widget_Grid {
  
  public function __construct() {
    Mage::log("grid", null, 'salsify.log', true);

    parent::__construct();
    $this->setId('manageimportsGrid');
    $this->setDefaultSort('id');
    $this->setDefaultDir('DESC');
    $this->setSaveParametersInSession(true);
  }
 
  protected function _prepareCollection() {
    $collection = Mage::getModel('salsify_connect/importrun')->getCollection();
    $this->setCollection($collection);
    return parent::_prepareCollection();
  }
 
  protected function _prepareColumns() {
    $this->addColumn('id', array(
      'header'  => 'ID',
      'align'   =>'right',
      'width'   => '10px',
      'index'   => 'id',
    ));

    // FIXME add more details here
 
    // $this->addColumn('name', array(
    //   'header'  => Mage::helper('employee')->__('Name'),
    //   'align'   =>'left',
    //   'index'   => 'name',
    //   'width'   => '50px',
    // ));
 
      
    // $this->addColumn('content', array(
    //   'header'  => Mage::helper('employee')->__('Description'),
    //   'width'   => '150px',
    //   'index'   => 'content',
    // ));

    return parent::_prepareColumns();
  }
}