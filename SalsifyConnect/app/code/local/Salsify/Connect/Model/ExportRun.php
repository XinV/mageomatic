<?php

/**
 * Represents a single export run for Salsify. This provides all state
 * persistence functionality around a Salsify export.
 *
 * A bunch of information is kept in the database, while temporary data is
 * kept on the filesystem in var/salsify/.
 *
 * This can be executed as a background job (see perform()).
 */
class Salsify_Connect_Model_ExportRun extends Salsify_Connect_Model_SyncRun {

  private $_export_file;


  const STATUS_EXPORTING             = 1;
  const STATUS_EXPORTING_DONE        = 2;
  const STATUS_UPLOADING_TO_SALSIFY  = 3;
  const STATUS_UPLOAD_DONE           = 4;
  const STATUS_SALSIFY_LOADING       = 5;
  
  public function get_status_string() {
    switch ($this->getStatus()) {
      case self::STATUS_ERROR:
        return "Export failed";
      case self::STATUS_NOT_STARTED:
        return "Export not started";
      case self::STATUS_EXPORTING:
        return "Magento is preparing the data for Salsify.";
      case self::STATUS_EXPORTING_DONE:
        return "Export file for Salsify generated. Preparing to upload.";
      case self::STATUS_UPLOADING_TO_SALSIFY:
        return "Uploading data to Salsify.";
      case self::STATUS_DONE:
        return "Export to Salsify has been completed successfully.";
      default:
        $this->set_error("INTERNAL ERROR: unknown status: " . $this->getStatus());
    }
  }


  protected function _construct() {
    $this->_init('salsify_connect/exportrun');
    parent::_construct();
  }


  // called by DJWorker
  public function perform() {
    self::_log("background export job started.");

    if (!$this->getId()) {
      throw new Exception("ExportRun was never initialized prior to being run.");
    }

    // first export the data to a file
    self::_log("1) creating export document");
    $this->create_export_file();

    // next upload the document to Salsify and wait for it to complete
    self::_log("2) uploding export document to Salsify");
    $this->upload_to_salsify();

    // done.
    return true;
  }


  // creates the export document for Salsify.
  // major work is done by Exporter.
  private function create_export_file() {
    if ($this->getStatus() != self::STATUS_NOT_STARTED) {
      $this->set_error("cannot create an export file when the ExportRun is not new.");
    }

    // set the actual start time now.
    $this->_set_status(self::STATUS_EXPORTING);
    $this->_set_start_time();
    $this->save();

    try {
      $salsify = Mage::helper('salsify_connect');
      $this->_export_file = $salsify->export_data();
    } catch (Exception $e) {
      $this->set_error($e);
    }
    if (!$this->_export_file) {
      $this->set_error("error: no local export file generated by exporter. check salsify.log for details.");
    }

    $this->_set_status(self::STATUS_EXPORTING_DONE);
    $this->save();
  }


  // uploads the prepared export document to Salsify.
  // all the real heavy lifting is done by the SalsifyApi.
  private function upload_to_salsify() {
    if ($this->getStatus() !== self::STATUS_EXPORTING_DONE) {
      $this->set_error("cannot start uploading to Salsify until the file has been exported");
    }

    $this->_set_status(self::STATUS_UPLOADING_TO_SALSIFY);
    $this->save();

    $salsify_api = $this->_get_salsify_api();
    try {
      $success = $salsify_api->upload_product_data_to_salsify($this->_export_file);
    } catch (Exception $e) {
      $this->set_error($e);
    }
    if (!$success) {
      $this->set_error("export of file to Salsify failed: " . $file);
    }

    // note at this point the file has been uploaded to salsify AND loaded.

    $this->_set_status(self::STATUS_UPLOAD_DONE);
    $this->save();
  }
}