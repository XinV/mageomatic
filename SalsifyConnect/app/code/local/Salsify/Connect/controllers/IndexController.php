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

  public function createAction(){
    echo '<br/>getting loader';
    $loader = Mage::helper('salsify_connect/loader');
    echo '<br/>creating attribute';
    $attribute = $loader->_create_attribute_if_needed("Rob Attribute of Awesomeness", 'text');
    echo '<br/>' . var_dump($attribute);
  }

}