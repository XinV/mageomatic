<?php
class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {

  public function indexAction() {
    $this->loadLayout();
    $this->renderLayout();
  }

  public function testAction() {
    $downloader = Mage::helper('salsify_connect/downloader');
    $file = $downloader->download();
    echo '<br/>Temp file for uploading: ' . $file;

    echo '<br/>Getting helper...';
    $salsify = Mage::helper('salsify_connect');
    echo '<br/>Loading file...';
    $salsify->load_data($file);
    echo '<br/>Data loaded!';
  }

}