<?php

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'DJJob');
require_once('DJJob.php');

class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {

  public function indexAction() {
    // FIXME remember to remove the connection_configuration.phtml and local.xml
    // when moving to the admin interface.
    // $this->loadLayout();
    // $this->renderLayout();

    echo 'usage:';
    echo '<br/>&nbsp;&nbsp;salsify/index/testload - loads a pre-saved test file. just for testing import.';
    echo '<br/>&nbsp;&nbsp;salsify/index/config - creates a config for export usage.';
    echo '<br/>&nbsp;&nbsp;salsify/index/export/config/1 - kicks off an export using config ID 1.';
    echo '<br/>&nbsp;&nbsp;salsify/index/chexport/id/1 - checks the status of export with ID 1 and advances it if ready.';
  }

  public function testloadAction() {
    $salsify = Mage::helper('salsify_connect');

    echo '<br/>Loading file...';
    $salsify->load_data(BP.DS.'var'.DS.'salsify'.DS.'export.json');

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
    if (!array_key_exists('config', $params)) {
      throw new Exception("Must specify configuration ID to use for import.");
    }
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
    echo "Current status: " . $import->get_status_string();

    if (!$import->is_done()) {
      echo "<br/><br/>Attempting next stage...";
      if($import->is_waiting_on_salsify()) {
        $advanced = $import->start_download_if_ready();
        if (!$advanced) {
          echo '<br/>Still waiting on Salsify.';
        } else {
          echo '<br/>Download is ready. Enqueued background job to complete import.';
          $this->sneaky_worker_thread_start();
        }
      } elseif ($import->is_waiting_on_worker()) {
        echo '<br/>Still waiting on background worker to pick up the job.';
        $this->sneaky_worker_thread_start();
      }
    }
  }

  private function sneaky_worker_thread_start() {
    echo '<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>';
    echo '<script language="javascript">$.get("/salsify/index/worker");</script>';

    // echo '<br/>Visit <a target="_blank" href="/salsify/index/worker">here</a> to create a worker.';
    // echo '<br/>Note that you can close that new window after a second or so without waiting for it to return.';
    // TODO the crontab approach was not working. There needs to be antoher way
    // to create a worker.
  }

  public function workerAction() {
    // TODO add a one-time token or something like that to enable this to be
    //      called safely.
    $job = Mage::getModel('salsify_connect/importjob');
    $job->start_worker();
  }

}