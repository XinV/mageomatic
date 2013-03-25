<?php

/**
 * Not to be confused with ExportRun, which represents the whole export.
 *
 * This Job is executed by DJWorker to do the entire export asynchronously.
 */
class Salsify_Connect_Model_ExportJob extends Mage_Core_Model_Abstract {

  private static function _log($msg) {
    Mage::log('ExportJob: ' . $msg, null, 'salsify.log', true);
  }


  // causes this job to be enqueued in the DJ database
  public function enqueue() {
    $salsify = Mage::helper('salsify_connect');
    $salsify->enqueue_job($this);
    return $this;
  }


  // called by DJWorker
  public function perform() {
    self::_log("background export job started.");

    $export_run_id = $this->getExportRunId();
    $export = Mage::getModel('salsify_connect/exportrun');
    $export->load($export_run_id);
    if (!$export->getId()) {
      throw new Exception("Export run id given does not refer to a valid export run: " . $export_run_id);
    }

    // first let's export the data to a file
    $export->create_export_file();

    // next upload the document to Salsify
    $export->upload_to_salsify();

    // finally wait for salsify to complete
    $export->wait_for_salsify_to_complete();

    // done.
  }

}