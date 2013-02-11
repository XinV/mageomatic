<?php

/**
 * Helper class that takes care of downloading a data dump from Salsify to a
 * local Magento directory.
 *
 * TODO clean out the temp directory every so often (maybe once it has, say, 3
 *      files in it? could make that configurable).
 */
class Salsify_Connect_Helper_Downloader extends Mage_Core_Helper_Abstract {

  private $_base_url;
  private $_api_token;

  public function set_base_url($baseurl) {
    $this->_base_url = $baseurl;
  }

  public function set_api_token($apitoken) {
    $this->_api_token = $apitoken;
  }


  public function create_export() {
    if (!$this->_base_url || !$this->_api_token) {
      throw new Exception("Base URL and API token must be set to create a new export.");
    }
    $url = $this->get_create_export_url();
    $req = new HttpRequest($url, HTTP_METH_POST);
    $mes = $req->send();
    return json_decode($mes->getBody());
  }

  private function get_create_export_url() {
    return $this->_base_url . '/api/exports?format=json&auth_token=' . $this->_api_token;
  }

  /**
   * Returns the path to the locally downloaded file.
   */
  public function download() {
    $file = $this->_get_temp_file('json');

    // FIXME need to download from salsify :)
    // For now, we're mimicking the upload...
    copy($this->_get_temp_directory() . DS . 'products.json', $file);

    return $file;
  }

  /**
   * Returns the name of a temp file that does not exist and so can be used for
   * storing data.
   */
  private function _get_temp_file($extension) {
    $dir = $this->_get_temp_directory();
    $file = $dir . DS . 'data-' . date('Y-m-d') . '-' . round(microtime(true)) . '.' . $extension;
    return $file;
  }

  /**
   * Ensures that the Salsify temp directory exists in var/
   */
  private function _get_temp_directory() {
    // thanks http://stackoverflow.com/questions/8708718/whats-the-best-place-to-put-additional-non-xml-files-within-the-module-file-str/8709462#8709462
    $dir = Mage::getBaseDir('var') . DS . 'salsify';
    if (!file_exists($dir)) {
      mkdir($dir);
      chmod($dir, 0777);
    } elseif (!is_dir($dir)) {
      throw new Exception($dir . " already exists and is not a directory. Cannot proceed.");
    }
    return $dir;
  }

}