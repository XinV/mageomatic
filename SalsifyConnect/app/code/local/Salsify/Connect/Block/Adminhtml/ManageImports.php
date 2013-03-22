<?php
class Salsify_Connect_Block_Adminhtml_ManageImports extends Mage_Adminhtml_Block_Widget_Grid_Container {

  private static function _log($msg) {
    Mage::log('Block_Adminhtml_ManageImports: ' . $msg, null, 'salsify.log', true);
  }

  public function __construct() {
    // The blockGroup must match the first half of how we call the block, and
    // controller matches the second half.
    $this->_blockGroup = 'salsify_connect';
    $this->_controller = 'adminhtml_manageimports';
    $this->_headerText = $this->__('Manage Salsify Imports');
     
    parent::__construct();
  }

}