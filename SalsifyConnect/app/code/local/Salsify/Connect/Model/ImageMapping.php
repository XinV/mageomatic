<?php

/**
 * ImageMapping that keeps track of which local, Magento images correspond to
 * which images in the Salsify CDN. This enables us to avoid re-downloading
 * images that we already have, discovering new images in Salsify, etc.
 */
class Salsify_Connect_Model_ImageMapping extends Mage_Core_Model_Abstract {

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }

  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/imagemapping');
  }


  // returns a collection that includes all mappings for the given sku. usually
  // this is refined further.
  private static function _get_mappings_collection_for_sku($sku) {
    return Mage::getModel('salsify_connect/imagemapping')
               ->getCollection()
               ->addFieldToFilter('sku', array('eq' => $sku));
  }


  // we do this in several '_get_mapping*' methods, so it made sense to factor
  // it out.
  private static function _get_mapping_from_mappings($mappings) {
    $mapping = $mappings->getFirstItem();
    if (!$mapping || !$mapping->getId()) {
      return null;
    }
    return $mapping;
  }


  // returns the ImageMapping model instance if it exists for the given sku and
  // source (e.g. external) url, null otherwise.
  private static function _get_mapping_by_sku_and_url($sku, $url) {
    $mappings = self::_get_mappings_collection_for_sku($sku);
    $mappings = $mappings->addFieldToFilter('url', array('eq' => $url));
    return self::_get_mapping_from_mappings($mappings);
  }


  // returns the ImageMapping model instance if it exists for the given sku and
  // checksum.
  private static function _get_mapping_by_sku_and_checksum($sku, $checksum) {
    $mappings = self::_get_mappings_collection_for_sku($sku);
    $mappings = $mappings->addFieldToFilter('checksum', array('checksum' => $checksum));
    return self::_get_mapping_from_mappings($mappings);
  }


  // takes a sku and the image get getMediaGalleryImages and returns the mapping
  // for the image if it exists.
  public static function get_mapping_by_sku_and_image($sku, $image) {
    $url = $image->getUrl();
    $id = self::get_image_mapping_id_from_url($sku, $url);

    $mappings = self::_get_mappings_collection_for_sku($sku);
    $mappings = $mappings->addFieldToFilter('magento_id', array('eq' => $id));
    return self::_get_mapping_from_mappings($mappings);
  }


  // This is a pretty key function. It has to create unique local filename for
  // each digital asset.
  //
  // Ensures that the file doesn't already exist.
  private static function _get_local_filename_for_image($sku, $digital_asset) {
    $import_dir = Mage::getBaseDir('media') . DS . 'import/';
    $pathinfo = pathinfo($digital_asset['url']);
    $filename = $import_dir . $sku . '--' . $pathinfo['basename'];

    if (file_exists($filename)) {
      // this snould only really happen in development if there is something
      // that goes wrong right in the middle of an import that we then retry.
      try {
        unlink($filename);
      } catch (Exception $e) {
        self::_log("WARNING: file that cannot be removed already exists for product " . $sku . ' from ' . $url . ' at ' . $filename . ' so skipping');
        return null;
      }
    }

    return $filename;
  }


  // downloads the image locally so that it's available to add to a product's
  // image gallery.
  private static function _download_image_to_local($salsify, $url, $filename) {
    try {
      $salsify->download_file($url, $filename);
      self::_log('successfully downloaded image from ' . $url . ' to ' . $filename);
      return true;
    } catch (Exception $e) {
      self::_log("WARNING: could not download digital asset: " . $e->getMessage());

      // in case that there was only a partial download
      if (file_exists($filename)) {
        try {
          unlink($filename);
        } catch (Exception $e) {
          self::_log("WARNING: could not delete file after load failure: " . $filename);
        }
      }

      return false;
    }
  }


  // loads the set of digital assets into Magento.
  //
  // digital_assets format of $digital_assets is basically exactly what you
  // would see in a salsify import, except that the keys of the array are the
  // skus of the product to which the digital asset is associated.
  // e.g. something like (excuse the mixture of json & php syntaxes here):
  // ['12345'=>{"url":"https://salsify-development.s3.amazonaws.com/rgonzalez/uploads/digital_asset/asset/2/2087913-5311.jpg",
  //   "name":"2087913-5311.jpg","is_primary_image":"true"},...]
  //
  // Thanks: http://stackoverflow.com/questions/8456954/magento-programmatically-add-product-image
  //
  // TODO deal with image updates (e.g. get image from salsify, it changes in
  //      salsify, recognize and update the local image).
  //
  // TODO get images for different digital asset roles (thumbnail vs. image,
  //      etc.) from salsify.
  public static function load_digital_assets($digital_assets) {
    if (!$digital_assets || empty($digital_assets)) {
      self::_log("no digital assets passed in to process.");
      return null;
    }

    // our salsify data helper has the download capability.
    $salsify = Mage::helper('salsify_connect');

    foreach ($digital_assets as $sku => $das) {
      $id = Mage::getModel('catalog/product')
                ->loadByAttribute('sku', $sku)
                ->getId();
      // this second load is necessary to have access to the media gallery
      $product = Mage::getModel('catalog/product')
                     ->load($id);

      foreach ($das as $da) {
        $url = $da['url'];

        $existing_mapping = self::_get_mapping_by_sku_and_url($sku, $url);
        if ($existing_mapping) {
          // we already have that image. skipping...
          continue;
        }

        $filename = self::_get_local_filename_for_image($sku, $da);
        if (!$filename) {
          // very rare
          continue;
        }

        $success = self::_download_image_to_local($salsify, $url, $filename);
        if (!$success) {
          // download problem. bad URL or the like. error message already
          // logged elsewhere.
          continue;
        }

        // calculate this here since addImageToMediaGallery moves the file
        $checksum = md5_file($filenmae);
        if (!$checksum) {
          $checksum = null;
          self::_log("WARNING: could not calculate checksum on import for " . $filename . ". This could lead to duplicate images over time.");
        }

        // the URL may have changed with Salsify. this happens if, for example,
        // we go from Magento->Salsify->Magento.
        $existing_mapping = self::_get_mapping_by_sku_and_checksum($sku, $checksum);
        if ($existing_mapping) {
          // this means that the source URL has changed. let's update it and
          // then move on.
          $existing_mapping->setUrl($url);
          $existing_mapping->save();
          try {
            unlink($filename);
          } catch (Exception $e) {
            self::_log("WARNING: could not remove unnecessary image file: " . $filename);
          }
          continue;
        }

        $product->addImageToMediaGallery(
          $filename,
          array('image', 'small_image', 'thumbnail'),
          true,  // whether to move the file
          false  // true hides from product page
        );

        // this is a little terrible. addImageToMediaGallery doesn't return a
        // handle to the thing just created, but you can get a handle to the last
        // item in the gallery this way. definitely not thread safe...
        // thanks: http://stackoverflow.com/questions/7215105/magento-set-product-image-label-during-import
        $gallery = $product->getData('media_gallery');
        $last_image = array_pop($gallery['images']);

        if (array_key_exists('name', $da)) {
          $last_image['label'] = $da['name'];
        }

        // create the new ImageMapping. The only part of this that is not
        // straightforward is that there is no universal ID given to any image
        // in Magento. Here we're assigning the file, but THE FILE CAN CHANGE
        // so that it points to a cache rather than the file itself over time.
        // see this SO post for interesting workarounds:
        // http://stackoverflow.com/questions/9049088/how-to-compare-a-products-images-in-magento
        // In particular (just in case it goes away for some reason):
        // $image_name = substr($_image->getUrl(), strrpos($_image->getUrl(), '/') + 1);
        $image_mapping = Mage::getModel('salsify_connect/imagemapping');
        $image_mapping->setSku($sku);
        $image_mapping->setUrl($url);
        $image_mapping->setChecksum($checksum);
        $image_mapping->setMagentoId(self::get_image_mapping_id_from_url($sku, $last_image['file']));
        $image_mapping->save();

        // fix the gallery up
        array_push($gallery['images'], $last_image);
        $product->setData('media_gallery', $gallery);

        // need to save the product for any changes to the media gallery to
        // take effect.
        $product->save();
      }
    }
  }


  // see larger comment above
  // thanks http://stackoverflow.com/questions/9049088/how-to-compare-a-products-images-in-magento
  public static function get_image_mapping_id_from_url($sku, $url) {
    // sku ends up being redundant here, but worth keeping around just in case
    // magento decides to change how it's randomly-generated file names change
    return $sku . '---' . substr($url, strrpos($url, '/') + 1);
  }
}