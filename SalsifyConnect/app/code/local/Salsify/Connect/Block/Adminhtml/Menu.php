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


  public function setActions($actions, $active_action_id) {
    foreach ($actions as $action) {
      $action_id = $action['action'];
      array_push($this->_menu_items,
        array(
          'title' => $action['label'],
          'url' => $this->_get_action_url($action_id),
          'active' => ($action_id === $active_action_id),
        )
      );
    }
  }

 
  public function getMenuItems() {
    return $this->_menu_items;
  }

}