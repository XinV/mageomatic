<?php

/**
 * Writes out Magento data to a Salsify format.
 */
class Salsify_Connect_Helper_Exporter
      extends Mage_Core_Helper_Abstract
{

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  // File that we're writing data out to. We do not have responsibility for
  // opening and closing this stream; that must be done by the calling class.
  private $_output_stream;


  // cached handle to the salsify helper
  private $_salsify;


  // cached attribute map from magento code -> salsify id
  private $_attribute_map;


  // list of codes to skip when exporting a product. mostly exists to surpress
  // overly chatting logs so that properties will only be talked about the first
  // time.
  private $_attribute_codes_to_skip;


  // cached mapping of magento ID to salsify ID
  private $_category_mapping;


  private function _init_skip_list() {
    $this->_attribute_codes_to_skip = Salsify_Connect_Model_AttributeMapping::getMagentoOwnedAttributeCodes();
    array_push($this->_attribute_codes_to_skip, Salsify_Connect_Model_AttributeMapping::getSalsifyProductIdAttributeCode());
  }


  private function _ensure_salsify_attributes() {
    Salsify_Connect_Model_AttributeMapping::createSalsifyIdAttributes();
  }


  // handy helper function that writes the given content out to the exporter's
  // output stream and adds a newline.
  private function _write($content) {
    fwrite($this->_output_stream, $content . "\n");
  }


  /**
   * Creates a COMPLETE export of Magento data to Salsify.
   *
   * @param $export_stream already opened output stream to which the export will
   *                       be written.
   */
  public function export($export_stream) {
    try {
      $this->_salsify = Mage::helper('salsify_connect');
      $this->_attribute_map = array();
      $this->_init_skip_list();
      $this->_ensure_salsify_attributes();
      $this->_category_mapping = array();

      $this->_output_stream = $export_stream;

      self::_log("starting to export Magento data into document");
      $this->_start_document();

      self::_log("writing header...");
      $this->_start_header();
      $this->_end_header();
      self::_log("done writing header.");

      self::_log("writing attributes...");
      $this->_start_attributes();
      $this->_write_attributes();
      $this->_end_attributes();
      self::_log("done writing attributes.");

      self::_log("writing attribute values...");
      $this->_start_attribute_values();
      $this->_write_attribute_values();
      $this->_end_attribute_values();
      self::_log("done writing attribute values.");

      self::_log("writing products...");
      $this->_start_products();
      $this->_write_products();
      $this->_end_products();
      self::_log("done writing products.");

      $this->_end_document();
      self::_log("done exporting Magento data into document");
    } catch (Exception $e) {
      self::_log("ERROR: could not complete export: " . $e->getMessage());
      throw $e;
    }
  }


  private function _start_document() {
    $this->_write('[');
  }

  private function _end_document() {
    $this->_write(']');
  }


  private function _start_header() {
    // makes this a little more readable
    $this->_write('{"header":{');
    $this->_write('"version":"2013-04"');
    $this->_write(',"scope":["all"]');
    $this->_write('}}');
  }

  private function _end_header() {
    // NOOP
  }


  // keeps track of whether we're talking about the first item in an array
  private $_first_item;

  private function _start_nonheader_section($name) {
    // because the header is guaranteed to have been first, it is safe to assume
    // a comma is needed here.
    $this->_write(',{"'.$name.'":[');
    $this->_first_item = true;
  }

  private function _end_nonheader_section() {
    $this->_write(']}');
  }

  private function _write_object($object) {
    if (!$this->_first_item) {
      $this->_write(',');
    }

    $json = json_encode($object);
    $this->_write($json);
    $this->_first_item = false;
  }


  private function _start_attributes() {
    $this->_start_nonheader_section('attributes');
  }

  private function _end_attributes() {
    $this->_end_nonheader_section();
  }

  private function _write_attributes() {
    $attributes = Salsify_Connect_Model_AttributeMapping::getProductAttributes();
    foreach ($attributes as $attribute) {
      $this->_write_attribute($attribute);
    }

    // need to do the accessory attributes separately because they don't exist
    // in magento as attributes, so we'd get errors trying to load them up and
    // examine their metadata.
    $accessory_attribute = Salsify_Connect_Model_AttributeMapping::getAccessoryAttribute();
    $this->_write_object($accessory_attribute);
  }

  private function _write_attribute($attribute) {
    $attribute_json = array();

    $code = $attribute['code'];
    // need to load the full model here. to this point it's only a small array
    // with some key items.
    $attribute = Salsify_Connect_Model_AttributeMapping::loadProductAttributeByMagentoCode($code);

    $id = Salsify_Connect_Model_AttributeMapping::getIdForCode($code);
    if (!$id) {
      self::_log("ERROR: could not load attribute for export. skipping: " . var_export($attribute,true));
      return null;
    }
    $attribute_json['salsify:id'] = $id;

    $this->_attribute_map[$code] = $id;

    $name = $attribute->getFrontendLabel();
    if (!$name) {
      $category_attribute_code = Salsify_Connect_Model_AttributeMapping::getCategoryAssignemntMagentoCode();
      // if we find any other special cases we should move this to the mapper
      if ($code === $category_attribute_code) {
        $name = 'Category';
      } else {
        $name = $id;
      }
    }
    $attribute_json['salsify:name'] = $name;

    $role = Salsify_Connect_Model_AttributeMapping::getRoleForMagentoCode($code);
    if ($role) {
      $attribute_json['salsify:role'] = $role;
    }

    $this->_write_object($attribute_json);
  }


  private function _start_attribute_values() {
    $this->_start_nonheader_section('attribute_values');
  }

  private function _end_attribute_values() {
    $this->_end_nonheader_section();
  }

  private function _write_attribute_values() {
    $categories = Mage::getModel('catalog/category')
                      ->getCollection();
    
    $default_category_attribute = $this->_salsify
                                       ->get_attribute_mapper()
                                       ->getCategoryAssignemntMagentoCode();

    foreach($categories as $category) {
      $category_attribute =  Mage::getResourceModel('catalog/category')
                                 ->getAttributeRawValue($category->getId(), 'salsify_attribute_id', 0);
      if (!$category_attribute) {
        $category_attribute = $default_category_attribute;
      }
      $this->_write_category($category, $category_attribute);
    }

    $accessory_attribute_values = Salsify_Connect_Model_AttributeMapping::getAccessoryAttributeValues();
    foreach ($accessory_attribute_values as $attrv) {
      $this->_write_object($attrv);
    }
  }

  private function _write_category($category, $category_attribute) {
    $category_json = array();

    $magento_id = $category->getId();
    // we're missing key data here (such as name) so need to load the whole
    // category
    $category = Mage::getModel('catalog/category')
                    ->load($magento_id);
    $level = intval($category->getLevel());
    
    if ($level === 0) {
      // global root. skip.
      return null;
    }

    if (!array_key_exists($magento_id, $this->_category_mapping)) {
      $this->_load_category_mapping($category);
    }
    $salsify_id = $this->_category_mapping[$magento_id];
    $category_json['salsify:id'] = $salsify_id;

    $name = $category->getName();
    $category_json['salsify:name'] = $name;

    // level is relative to the root. so these are local roots.
    if ($level > 1) {
      $parent_id = $category->getParentId();
      if (!array_key_exists($parent_id, $this->_category_mapping)) {
        $parent_category = Mage::getModel('catalog/category')
                               ->load($parent_id);
        $this->_load_category_mapping($parent_category);
      }
      $category_json['salsify:parent_id'] = $this->_category_mapping[$parent_id];
    }

    $category_json['salsify:attribute_id'] = $category_attribute;

    $this->_write_object($category_json);
  }

  private function _load_category_mapping($category) {
    $magento_id = $category->getId();
    $salsify_id = Mage::getResourceModel('catalog/category')
                      ->getAttributeRawValue($magento_id, 'salsify_category_id', 0);
    if (!$salsify_id) {
      // no salsify_id yet exists. need to ` one.
      $salsify_id = 'magento_' . $category->getPath();
      $category->setSalsifyCategoryId($salsify_id);
      $category->save();
    }

    $this->_category_mapping[$magento_id] = $salsify_id;
  }


  private function _start_products() {
    $this->_start_nonheader_section('products');
  }

  private function _end_products() {
    $this->_end_nonheader_section();
  }

  private function _write_products() {
    $products = Mage::getModel('catalog/product')
                    ->getCollection();
    foreach ($products as $product) {
      $this->_write_product($product);
    }
  }


  private function _write_product($product) {
    $product_json = array();

    $id = $product->getId();
    // need to load the full product model to have access to all of its
    // attribute values, gallery images, etc.
    $product = Mage::getModel('catalog/product')
                   ->load($id);

    $attributes = $product->getData();
    foreach ($attributes as $key => $value) {
      if (in_array($key, $this->_attribute_codes_to_skip)) {
        // skip quietly
        continue;
      } elseif (!$value) {
        self::_log("WARNING: value is null for key. skipping: " . var_export($key,true));
        array_push($this->_attribute_codes_to_skip, $key);
        continue;
      } elseif ($key === 'media_gallery') {
        // skip. we'll deal with this separately
      } elseif(array_key_exists($key, $this->_attribute_map)) {
        $salsify_id = $this->_attribute_map[$key];
        $product_json[$salsify_id] = $value;
      } else {
        self::_log("WARNING: no mapping for attribute with code. skipping: " . var_export($key,true));
      }
    }


    // write out category assignments
    $default_category_attribute = $this->_salsify
                                       ->get_attribute_mapper()
                                       ->getCategoryAssignemntMagentoCode();
    $category_collection = $product->getCategoryCollection();
    $salsify_categories_for_product = array();
    foreach ($category_collection as $category) {
      $catid = $category->getId();

      $category_attribute =  Mage::getResourceModel('catalog/category')
                                 ->getAttributeRawValue($catid, 'salsify_attribute_id', 0);
      if (!$category_attribute) {
        $category_attribute = $default_category_attribute;
      }
      
      if (!array_key_exists($catid, $this->_category_mapping)) {
        self::_log("WARNING: category assignment in product " . $id . " does not exist in mapping. cat id: " . $catid);
      } else {
        array_push($salsify_categories_for_product, $this->_category_mapping[$catid]);
      }
    }
    if (!empty($salsify_categories_for_product)) {
      $product_json[$category_attribute] = $salsify_categories_for_product;
    }

    
    $digital_assets = $this->_get_digital_assets_json($product);
    if (!empty($digital_assets)) {
      $product_json['salsify:digital_assets'] = $digital_assets;
    }


    $accessories = $this->_get_accessories_json($product);
    if (!empty($accessories)) {
      $product_json['salsify:relations'] = $accessories;
    }


    $this->_write_object($product_json);
  }


  // returns a nicely, Salsify JSON document-formatted version of the product's
  // digital assets.
  private function _get_digital_assets_json($product) {
    $digital_assets = array();

    $sku = $product->getSku();
    $image_mapper = Mage::getModel('salsify_connect/imagemapping');
    $gallery_images = $product->getMediaGalleryImages();

    foreach ($gallery_images as $image) {
      $da = array();
      $da["salsify:name"] = $image->getLabel();

      $url = $image->getUrl();
      $da["salsify:url"] = $url;

      $mapping = Salsify_Connect_Model_ImageMapping::get_mapping_by_sku_and_image($sku, $image);
      if ($mapping) {
        $da["salsify:id"] = $mapping->getSalsifyId();
        $da["salsify:source_url"] = $mapping->getSourceUrl();
      } else {
        $image_mapper->init_by_sku_and_image($sku, $image);
        $da["salsify:id"] = $image_mapper->getMagentoId();
      }

      // NOTE can't get this info from the $image object. it will have to be a
      //      combo of product and image. will tackle it when we deal with image
      //      role support in general.
      // "is_primary_image": "true"

      array_push($digital_assets, $da);
    }

    return $digital_assets;
  }


  // returns a nicely, Salsify JSON document-formatted version of the product's
  // accessory relationships.
  private function _get_accessories_json($product) {
    $accessories = array();

    $sku = $product->getSku();

    $id_attribute = Salsify_Connect_Model_AttributeMapping::getAttributeForAccessoryIds();
    $default_category = Salsify_Connect_Model_AttributeMapping::getDefaultAccessoryAttribute();

    $cross_sell_ids = $product->getCrossSellProductIds();
    $cross_sell_json = $this->_get_accessories_json_for_ids(
      $sku,
      $default_category,
      Salsify_Connect_Model_AccessoryMapping::CROSS_SELL,
      $id_attribute,
      $cross_sell_ids
    );
    $accessories = array_merge($accessories, $cross_sell_json);

    $up_sell_ids = $product->getUpSellProductIds();
    $up_sell_json = $this->_get_accessories_json_for_ids(
      $sku,
      $default_category,
      Salsify_Connect_Model_AccessoryMapping::UP_SELL,
      $id_attribute,
      $up_sell_ids
    );
    $accessories = array_merge($accessories, $up_sell_json);

    $related_ids = $product->getRelatedProductIds();
    $related_json = $this->_get_accessories_json_for_ids(
      $sku,
      $default_category,
      Salsify_Connect_Model_AccessoryMapping::RELATED_PRODUCT,
      $id_attribute,
      $related_ids
    );
    $accessories = array_merge($accessories, $related_json);

    return $accessories;
  }


  // default_category and relation_type are used if there already isn't a mapping
  // for the relation that has come from salsify.
  private function _get_accessories_json_for_ids(
    $sku,
    $default_category,
    $relation_type,
    $id_attribute,
    $related_product_ids
  ) {
    $accessories = array();
    if (!$related_product_ids || empty($related_product_ids)) {
      return $accessories;
    }

    // these collection selects will only bring back product skus by default,
    // which works for us here since that's all we need.
    $related_products = Mage::getModel('catalog/product')
                            ->getCollection()
                            ->addAttributeToFilter('entity_id',
                                                   array('in' => $related_product_ids));

    if ($related_products) {
      foreach ($related_products as $rp) {
        $target_sku = $rp->getSku();

        // a single relationship in Magento may have originated from several in
        // Salsify.
        $mappings = Salsify_Connect_Model_AccessoryMapping::getOrCreateMappings(
          $sku, $rp->getSku(), $default_category, $relation_type
        );
        foreach($mappings as $mapping) {
          array_push($accessories, array(
            $id_attribute => $target_sku,
            $mapping->getSalsifyCategoryId() => $mapping->getSalsifyCategoryValue(),
          ));
        }
      }
    }

    return $accessories;
  }
}