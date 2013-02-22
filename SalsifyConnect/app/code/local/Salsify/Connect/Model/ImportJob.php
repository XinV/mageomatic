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
    $this->_log("background import job started.");

    $url = $this->getUrl();
    $filename = $this->getFilename();
    $import_run_id = $this->getImportRunId();

    $this->_log("downloading salsify data from " . $url . " to " . $filename . " for import run " . $import_run_id);

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
      $digital_assets = $this->load_digital_assets($import->get_digital_assets());
      $import->set_loading_digital_assets_complete($digital_assets);
    } catch (Exception $e) {
      $import->set_error($e);
    }
  }


  // note: format of $digital_assets is basically exactly what you would expect
  // in a salsify import, except that the keys of the array are the skus of the
  // product to which the digital asset is associated.
  // e.g. something like (excuse the mixture of json & php syntaxes here):
  // ['12345'=>{"url":"https://salsify-development.s3.amazonaws.com/rgonzalez/uploads/digital_asset/asset/2/2087913-5311.jpg",
  //   "name":"2087913-5311.jpg","is_primary_image":"true"},...]
  //
  // Thanks http://stackoverflow.com/questions/8456954/magento-programmatically-add-product-image
  //
  // FIXME images coming from salsify should have an external ID or something to
  //       uniquely identify them between runs. what if someone updates the name
  //       and then we do another import?
  //
  // TODO get images for different digital asset roles (thumbnail vs. image,
  //      etc.) from salsify.
  public function load_digital_assets($digital_assets) {
    if (!$digital_assets || empty($digital_assets)) {
      $this->_log("no digital assets passed into process.");
      return null;
    }

    foreach ($digital_assets as $sku => $das) {
      $product = Mage::getModel('catalog/product')
                     ->loadByAttribute('sku', $sku);

      foreach ($das as $da) {
        $url = $da['url'];
        $filename = $this->_get_local_filename($sku, $da);
        try {
          if (file_exists($filename)) {
            $this->_log('local file already exists for product ' . $sku . ' from ' . $url);
          } else {
            $this->_download($url, $filename);
            $this->_log('successfully downloaded image for ' . $sku . ' from ' . $url . ' to ' . $filename);
          }

          // TODO figure out if the media gallery already includes the thing
          // $gallery_data = $product->getData('media_gallery');
          // $this->_log("GALLERY: " . var_export($gallery_data, true));
          // foreach ($gallery_data['images'] as $image) {
          //   $this->_log("IMAGE: " . var_export($image, true));
          // }
          $this->_log("PRODUCT: " .var_export($product, true));
          // $gallery_data = $product->getMediaGallery();
          // $this->_log("GALLERY: " . var_export($gallery_data, true));
          // foreach ($gallery_data['images'] as $image) {
          //   $this->_log("IMAGE: " . var_export($image, true));
          //   // if ($gallery->getBackend()->getImage($product, $image['file'])) {
          //   //   $gallery->getBackend()->removeImage($product, $image['file']);
          //   // }
          // }


          // http://docs.magentocommerce.com/Mage_Catalog/Mage_Catalog_Model_Product.html#addImageToMediaGallery
          $product->addImageToMediaGallery($filename, null, false, false);
          $product->save();
        } catch (Exception $e) {
          $this->_log("ERROR: could not load digital asset. skipping: " . $e->getMessage());
          $this->_log("       " . var_export($da, true));
          if (file_exists($filename)) {
            try {
              unlink($filename);
            } catch (Exception $f) {
              $this->_log("WARNING: could not delete file after load failure: " . $filename);
            }
          }
        }
      }
    }
  }


  // This is a pretty key function. It has to create unique local filename for
  // each digital asset.
  private function _get_local_filename($sku, $digital_asset) {
    $import_dir = Mage::getBaseDir('media') . DS . 'import/';
    $pathinfo = pathinfo($digital_asset['url']);
    return $import_dir . $sku . '--' . $pathinfo['basename'];
  }


  private function _download($url, $filename) {
    $this->_log("starting download from " . $url . " to: " . $filename);

    $file = null;
    try {
      $ch = curl_init($url);
      $file = fopen($filename, "wb");
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

    $this->_log("download successful. Local file: ".$filename);
    return $filename;
  }

  private function _load_data($filename) {
    $this->_log("starting data load from: ".$filename);

    $salsify = Mage::helper('salsify_connect');
    $salsify->load_data($filename);

    $this->_log("load successful. Local file: ".$filename);
  }

  // TODO factor into a log helper
  private function _log($msg) {
    Mage::log('ImportJob: ' . $msg, null, 'salsify.log', true);
  }

}