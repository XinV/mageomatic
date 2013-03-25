<?php

/**
 * Not to be confused with Import Run, which represents the whole import.
 * This Job is executed by DJWorker to do some parts of import asynchronously.
 */
class Salsify_Connect_Model_ImportJob extends Mage_Core_Model_Abstract {

  public function enqueue() {
    $salsify = Mage::helper('salsify_connect');
    $salsify->enqueue_job($this);
    return $this;
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
      $importer = $this->_import_data($filename);
      $import->set_loading_complete();

      // download and load digital assets
      $digital_assets = $this->load_digital_assets($importer->get_digital_assets());
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
  // TODO images coming from salsify should have an external ID or something to
  //      uniquely identify them between runs. what if someone updates the name
  //      and then we do another import?
  //
  // TODO get images for different digital asset roles (thumbnail vs. image,
  //      etc.) from salsify.
  public function load_digital_assets($digital_assets) {
    if (!$digital_assets || empty($digital_assets)) {
      $this->_log("no digital assets passed into process.");
      return null;
    }

    foreach ($digital_assets as $sku => $das) {
      $id = Mage::getModel('catalog/product')
                ->loadByAttribute('sku', $sku)
                ->getId();
      // stupid, but this is necessary to have access to the media gallery...
      $product = Mage::getModel('catalog/product')
                     ->load($id);

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


          // TODO once we get a unique identifier from Salsify, we need to
          //      create a new attribute for the image gallery to keep track
          //      of whether or not we've imported the product already.
          //
          // TODO should see the code that's currently in the controller on how
          //      to cycle through a product's images to check this.


          // http://docs.magentocommerce.com/Mage_Catalog/Mage_Catalog_Model_Product.html#addImageToMediaGallery
          // TODO the second argument should be set specifically to thumbnail or
          //      small_image if that data comes from Salsify. also we should
          //      only set 'image' for those that have 'profile_image' set to true
          $product->addImageToMediaGallery($filename, array('image'), true, false);

          if (array_key_exists('name', $da)) {
            // this is terrible. thanks:
            // http://stackoverflow.com/questions/7215105/magento-set-product-image-label-during-import
            $gallery = $product->getData('media_gallery');
            $last_image = array_pop($gallery['images']);
            $last_image['label'] = $da['name'];
            array_push($gallery['images'], $last_image);
            $product->setData('media_gallery', $gallery);
          }

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

    $this->_log("download successful. Local file: " . $filename);
    return $filename;
  }

  private function _import_data($filename) {
    $this->_log("starting data load from: " . $filename);

    $salsify = Mage::helper('salsify_connect');
    $salsify->import_data($filename);

    $this->_log("load successful. Local file: " . $filename);

    // This is clearly a weird return value here. Starting to feel like weird
    // spagetti code, and only exists because we need somewhere to temporarily
    // store digital assets during a load...
    return $salsify->get_importer();
  }

  // TODO factor into a log helper
  private function _log($msg) {
    Mage::log('ImportJob: ' . $msg, null, 'salsify.log', true);
  }

}