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

  public function createAction() {
    echo '<br/>getting loader';
    $loader = Mage::helper('salsify_connect/loader');
    echo '<br/>creating attribute';
    $attribute = $loader->_create_attribute_if_needed("Rob Attribute of Awesomeness", 'text');
    echo '<br/>';
    echo var_dump($attribute);
  }

  public function exportAction() {
    echo '<br/>creating new export from Salsify...';

    $url = "http://localhost:5000/";
    $key = "yNoKZx9UabqqQ1m2c6K2";
    $downloader = Mage::helper('salsify_connect/downloader');
    $downloader.set_api_token($key);
    $downloader.set_base_url($url);

    echo '<br/>created. go to salsify/index/chexport to check the status';
  }

  public function chexportAction() {
    echo '<br/>checking export status.';
    echo '<br/>';
    echo var_dump(http_request(HTTP_METH_GET, 'http://www.google.com/'));
  }

}