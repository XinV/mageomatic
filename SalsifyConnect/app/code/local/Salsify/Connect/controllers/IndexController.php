<?php
// FIXME remove this file completely once we know that the worker execution
//       kickoff works in the adminhtml version of it.

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'DJJob');
require_once('DJJob.php');


class Salsify_Connect_IndexController extends Mage_Core_Controller_Front_Action {

  public function indexAction() {
    echo 'usage:';
    echo '<br/>&nbsp;&nbsp;salsify/index/testload - loads a pre-saved test file. just for testing import.';
    echo '<br/>&nbsp;&nbsp;salsify/index/config?api_key=YOURKEY&salsify_url=YOURURL - creates a config for export usage.';
    echo '<br/>&nbsp;&nbsp;salsify/index/export?config=ID - kicks off an export using config ID 1.';
    echo '<br/>&nbsp;&nbsp;salsify/index/chexport?id=ID - checks the status of export with ID 1 and advances it if ready.';
  }

  public function testloadAction() {
    $salsify = Mage::helper('salsify_connect');

    echo '<br/>Loading file...';
    $salsify->load_data(BP.DS.'var'.DS.'salsify'.DS.'export.json');

    echo '<br/>Data loaded!';
  }

  public function configAction() {
    $params = $this->getRequest()->getParams();

    if (!array_key_exists('api_key', $params)) {
      throw new Exception("Must specify api_key to use for import.");
    }
    $api_key = $params['api_key'];

    if (!array_key_exists('salsify_url', $params)) {
      throw new Exception("Must specify salsify_url to use for import.");
    }
    $url = urldecode($params['salsify_url']);

    $config = Mage::getModel('salsify_connect/configuration');
    $config->setApiKey($api_key);
    $config->setUrl($url);
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

    // TODO use jquery to automatically check for updates so that the user
    //      doesn't have to refresh the screen.
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
  }

  public function workerAction() {
    // TODO add a one-time token or something like that to enable this to be
    //      called safely.
    $job = Mage::getModel('salsify_connect/importjob');
    $job->start_worker();
  }

}