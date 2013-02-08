<?php
class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {

  public function indexAction() {
    $this->loadLayout();
    $this->renderLayout();
  }

  public function testAction() {
    $downloader = Mage::helper('salsify_connect/downloader');
    $file = $downloader->download();
    echo '\nTemp file for uploading: ' . $file;

    echo '\nGetting helper...';
    $salsify = Mage::helper('salsify_connect');
    echo '\nLoading file...';
    $salsify->load_data($file);
    echo '\nData loaded!';
  }

}