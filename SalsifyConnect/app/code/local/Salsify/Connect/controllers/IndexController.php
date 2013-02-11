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

  public function configAction() {
    echo '<br/>creating export configuration.';
  }

  public function exportAction() {
    echo '<br/>creating new export from Salsify...';

    // FIXME should get this from a configuration
    $url = "http://localhost:5000/";
    $key = "yNoKZx9UabqqQ1m2c6K2";
    $downloader = Mage::helper('salsify_connect/downloader');
    $downloader->set_api_token($key);
    $downloader->set_base_url($url);

    echo '<br/>creating export...';
    $model = Mage::getModel('salsify_connect/importrun');
    try {
      echo '<br/>1';
      $model->setStartTime(new DateTime('now'));
      echo '<br/>2';
      $export = $downloader->create_export();
      echo '<br/>3';
      $model->setToken($export['id']);
      echo '<br/>4';
      $model->save();
      echo '<br/>5';
    } catch (Exception $e) {
      var_dump($e);
    }

    echo '<br/>saved model: '. $model->getId();

    echo '<br/>created. go to salsify/index/chexport to check the status';
  }

  public function chexportAction() {
    echo '<br/>checking export status...';

    $url = "http://localhost:5000/";
    $key = "yNoKZx9UabqqQ1m2c6K2";
    $downloader = Mage::helper('salsify_connect/downloader');
    $downloader->set_api_token($key);
    $downloader->set_base_url($url);

    echo '<br/>first export:';
    $export = $downloader->get_export(1);
    echo var_dump($export);
    echo '<br/>second export:';
    $export = $downloader->get_export(2);
    echo var_dump($export);
  }

}