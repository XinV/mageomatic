<?php

class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {
  public function indexAction() {
    $this->loadLayout();
 
    $block = $this->getLayout()->createBlock('core/text', 'green-block')->setText('<h1>Green Acorn Web Design</h1>');
    $this->_addContent($block);
 
    $this->_setActiveMenu('green_menu')->renderLayout();
  }
}