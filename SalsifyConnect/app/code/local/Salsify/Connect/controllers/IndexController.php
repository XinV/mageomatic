<?php
class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {

  public function indexAction() {
    $this->loadLayout();
    $this->renderLayout();
  }

  public function testAction() {
    $downloader = Mage::helper('salsify_connect/downloader');
    $file = $downloader->download();
    echo 'Temp file for uploading: ' . $file;

    echo 'Getting helper...';
    $salsify = Mage::helper('salsify_connect');
    echo 'Loading file...';
    $salsify->load_data($file);
    echo 'Data loaded!';
  }

}