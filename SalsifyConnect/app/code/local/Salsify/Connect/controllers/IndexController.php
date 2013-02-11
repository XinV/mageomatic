<?php
class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {

  public function indexAction() {
    // FIXME remember to remove the connection_configuration.phtml and local.xml
    // when moving to the admin interface.
    // $this->loadLayout();
    // $this->renderLayout();

    echo 'usage:';
    echo '&nbsp;nbsp;salsify/index/testload - loads a pre-saved test file. just for testing import.';
    echo '&nbsp;nbsp;salsify/index/config - creates a config for export usage.';
    echo '&nbsp;nbsp;salsify/index/export/config/1 - kicks off an export using config ID 1.';
    echo '&nbsp;nbsp;salsify/index/chexport/id/1 - checks the status of export with ID 1 and advances it if ready.';
  }

  public function testloadAction() {
    $downloader = Mage::helper('salsify_connect/downloader');
    $file = $downloader->download();
    echo '<br/>Temp file for uploading: ' . $file;

    echo '<br/>Getting helper...';
    $salsify = Mage::helper('salsify_connect');

    echo '<br/>Loading file...';
    $salsify->load_data($file);

    echo '<br/>Data loaded!';
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
    $model = Mage::getModel('salsify_connect/importrun');
    $model->setConfigurationId($config_id);
    $model->save();
    $model->start_import();

    echo '<br/>created. go to salsify/index/chexport/id/'.($model->getId()).' to check the status';
  }

  public function chexportAction() {
    $params = $this->getRequest()->getParams();
    $import_id = $params['id'];
    $import = Mage::getModel('salsify_connect/importrun');
    $import->load((int)$import_id);
    if (!$import->getId()) {
      throw new Exception("Must specify a valid import ID.");
    }
    echo "Status: " . $import->get_status_string();
  }

}