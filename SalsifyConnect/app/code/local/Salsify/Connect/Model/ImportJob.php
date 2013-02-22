<?php

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'DJJob');
require_once('DJJob.php');

/**
 * Not to be confused with Import Run, which represents the whole import.
 * This Job is executed by DJWorker to do some parts of import asynchronously.
 */
class Salsify_Connect_Model_ImportJob extends Mage_Core_Model_Abstract {

  public function setup_delayed_jobs() {
    $config  = Mage::getConfig()->getResourceConnectionConfig("default_setup");
    DJJob::configure("mysql:host=" . $config->host . ";dbname=" . $config->dbname . ";port=" . $config->port,
                     array('mysql_user' => $config->username, 'mysql_pass' => $config->password));
  }

  public function start_worker() {
    $this->setup_delayed_jobs();

    $options = array();
    $options['queue'] = 'salsify';
    $options['count'] = 1; // this worker will quit after doing one job
    $worker = new DJWorker($options);
    $worker->start();
  }

  public function enqueue() {
    $this->setup_delayed_jobs();
    DJJob::enqueue($this, 'salsify');
  }

  public function perform() {
    $this->log("ImportJob: background import job started.");

    $url = $this->getUrl();
    $filename = $this->getFilename();
    $import_run_id = $this->getImportRunId();

    if (!($url && $filename && $import_run_id)) {
      throw new Exception("Must set url, filename, and import run id for import job.");
    }

    $import = Mage::getModel('salsify_connect/importrun');
    $import->load($import_run_id);
    if (!$import->getId()) {
      throw new Exception("Import run id given does not refer to a valid import run: " . $import_run_id);
    }

    try {
      // fetch data from salsify
      $import->set_download_started();
      $filename = $this->_download($url, $filename);
      $import->set_download_complete();

      // parse file and load data into Magento
      $this->_load_data($filename);
      $import->set_loading_complete();

      // download and load digital assets
      // FIXME need to complete loading digital assets
      // $this->_load_digital_assets();
      $import->set_loading_digital_assets_complete();
    } catch (Exception $e) {
      $import->set_error($e);
    }
  }

  private function _download($url, $filename) {
    $this->log("ImportJob: starting download to: ".$filename);

    $file = null;
    try {
      $ch = curl_init($url);
      // FIXME change to wb?
      $file = fopen($filename, "w");
      curl_setopt($ch, CURLOPT_FILE, $file);
      curl_setopt($ch, CURLOPT_HEADER, 0);
      curl_exec($ch);
      curl_close($ch);
      fclose($file);
    } catch (Exception $e) {
      if ($file) { fclose($file); }
      unlink($filenmae);
      throw $e;
    }

     $this->log("ImportJob: download successful. Local file: ".$filename);
    return $filename;
  }

  private function _load_data($filename) {
    $this->log("ImportJob: starting data load from: ".$filename);

    $salsify = Mage::helper('salsify_connect');
    $salsify->load_data($filename);

    $this->log("ImportJob: load successful. Local file: ".$filename);
  }

  // FIXME factor into a log helper
  private function log($msg) {
    Mage::log($msg, null, 'salsify.log', true);
  }

}