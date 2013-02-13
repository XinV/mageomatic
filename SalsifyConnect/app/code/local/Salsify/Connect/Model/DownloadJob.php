<?php

class Salsify_Connect_Model_DownloadJob extends Jowens_JobQueue_Model_Job_Abstract {

  public function perform() {
    Mage::log(
      "DownloadJob#perform: " . $this->getName(),
      null, 
      'salsify.log',
      true
    );

    $url = $this->getUrl();
    $filename = $this->getFilename();
    $import_run_id = $this->getImportRunId();

    Mage::log(
      "DownloadJob#perform: (url, filename, import_run_id) = (".$url.",".$filename.",".$import_run_id.")",
      null, 
      'salsify.log',
      true
    );

    if (!($url && $filename && $import_run_id)) {
      throw new Exception("Must set url, filename, and import run id for download job.");
    }

    $import = Mage::getModel('salsify_connect/importrun');
    $import->load($import_run_id);
    if (!$import->getId()) {
      throw new Exception("Import run id given does not refer to a valid import run: " . $import_run_id);
    }

    $import->set_download_started();
    try {
      $filename = $this->_download($url, $filename);
    } catch (Exception $e) {
      $import->set_error($e);
    }
    $import->set_download_complete($filename);
  }

  private function _download($url, $filename) {
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
    return $filename;
  }

}