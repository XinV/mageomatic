<?php
class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {

  public function indexAction() {
    $this->loadLayout();
    $this->renderLayout();
  }

  public function testAction() {
    $downloader = Mage::helper('salsify_connect/downloader')
    echo 'Magento root: ' . $downloader->download();
  }

}