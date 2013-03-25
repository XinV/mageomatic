<?php

// Block for rendering the Salsify admin menu
class Salsify_Connect_Block_Adminhtml_Menu extends Mage_Core_Block_Template {

  private static function _log($msg) {
    Mage::log('Block_Adminhtml_Menu: ' . $msg, null, 'salsify.log', true);
  }


  private $_menu_items;


  public function _construct() {
    $this->_menu_items = array();

    $this->setTemplate('salsify/menu.phtml');
    return parent::_construct();
  }


  private function _get_action_url($action) {
    return Mage::helper("adminhtml")
               ->getUrl('*/*/' . $action);
  }


  public function setActions($actions) {
    // TODO get the full list programatically instead of having it in both the
    //      XML *and* here
    // TODO set the active one
    foreach ($actions as $action) {
      array_push($this->_menu_items,
                 array('title' => $action['label'],
                       'url' => $this->_get_action_url($action['action'])
                      )
                );
    }
  }

 
  public function getMenuItems() {
    return $this->_menu_items;
  }

}