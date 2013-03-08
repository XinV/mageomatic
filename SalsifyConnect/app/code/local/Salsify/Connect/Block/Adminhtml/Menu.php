<?php

// Block for rendering the Salsify admin menu
class Salsify_Connect_Block_Adminhtml_Menu extends Mage_Core_Block_Template {

  private static function _log($msg) {
    Mage::log('Block_Adminhtml_Menu: ' . $msg, null, 'salsify.log', true);
  }


  // All you need to add is the action!
  const BASE_ADMIN_URL = 'salsify/adminhtml_manageimports/';

  const INDEX_MENU_ID  = 'salsify_connect_menu/manage_imports';
  const CONFIG_MENU_ID = 'salsify_connect_menu/configuration';


  private $_menu_items;


  public function _construct() {
    // TODO: get the full list programatically instead of having it in both the
    //      XML *and* here
    // TODO: set the active one
    $this->_menu_items = array(
      array('title' => 'Menu Item 1', 'url' => '/'),
      array('title' => 'Menu Item 2', 'url' => '/')
    );

    $this->setTemplate('salsify/menu.phtml');
    return parent::_construct();
  }

 
  public function getMenuItems() {
    return $this->_menu_items;
  }

}