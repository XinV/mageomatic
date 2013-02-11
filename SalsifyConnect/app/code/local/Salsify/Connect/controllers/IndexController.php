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

    $config = Mage::getModel('salsify_connect/configuration');
    $config->setApiKey("yNoKZx9UabqqQ1m2c6K2");
    $config->setUrl("http://localhost:5000/");
    $config->save();

    echo '<br/>configuration created: ' . $config->getId();
  }

  public function exportAction() {
    echo '<br/>creating new export from Salsify...';

    $params = $this->getRequest()->getParams();
    $config_id = $params['config'];
    $config = Mage::getModel('salsify_connect/configuration');
    $config->load((int)$config_id);
    if (!$config->getId()) {
      throw new Exception("Cannot do an export without specifying a configuration");
    }
    echo var_dump((int)($config->getId()));
    echo '<br/><br/>';

    $model = Mage::getModel('salsify_connect/importrun');
    // $model->setStartTime(time());
    // $model->setConfig(1);
    $model->setFoo(69);
    // $model->set_status_preparing();

    // $export = $downloader->create_export();
    // $model->setToken($export->id);
    $model->setToken(1); // FIXME
    echo var_dump($model);
    echo '<br/><br/>';

    $model->save();
    echo var_dump($model);
    echo '<br/><br/>';

    echo '<br/>saved model: ' . $model->getId();

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