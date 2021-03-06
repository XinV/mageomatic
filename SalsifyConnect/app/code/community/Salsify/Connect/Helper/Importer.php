<?php
require_once BP.DS.'lib'.DS.'salsify'.DS.'JsonStreamingParser'.DS.'Listener.php';

/**
 * Parses the Salsify JSON data format and loads the resulting data into
 * Magento, creating the necessary attributes, categories, and whatever else is
 * needed.
 *
 * Currently this works as append-only. For example, if an image or property
 * is deleted in Salsify and not present in the JSON (or even a full product),
 * this will NOT delete the value in Magento. It will replace old values with
 * new values, and insert new values, but there is currently no capability to
 * support delete.
 *
 * The major reason for this is that there are so many attributes in Magento
 * that are controlled by Magento (see AttributeMapping for more details on that
 * specifically). The real nub here is that some of those attributes require
 * different values using Magento's ImportExport API than are stored internally,
 * so one cannot simply copy them into an array and then re-import them during
 * a replace operation.
 *
 * NOTE this assumes that the accessory category hierarchy and product category
 *      hierarchy(s) are necessarily distinct.
 */
class Salsify_Connect_Helper_Importer
      extends Mage_Core_Helper_Abstract
      implements JsonStreamingParser_Listener
{

  private static function _log($msg) {
    Mage::log("Salsify_Connect_Helper_Importer" . ': ' . $msg, null, 'salsify.log', true);
  }


  // For types of attributes. In Magento's EAV struction attributes of products,
  // categories, customers, etc., are stored in different EAV tables.
  const CATEGORY = 1;
  const PRODUCT  = 2;

  // Current keys and values that we're building up. We have to do it this way
  // vs. just having a current object stack because php deals with arrays as
  // pass-by-value.
  private $_key_stack;
  private $_value_stack;
  private $_type_stack; // since php doesn't have a separate hash
  const ARRAY_TYPE  = 1;
  const OBJECT_TYPE = 2;


  // cached attributes
  private $_attributes;

  // current attribute
  private $_attribute;

  // cached from AttributeMapper for convenience
  private $_salsify_id_category_attribute_code;
  private $_salsify_attribute_id_code;
  private $_salsify_id_product_attribute_code;

  // list of attribute IDs for relationships. one reason for keeping
  // these around is to ignore them when loading products, since no product
  // will ever be assigned to one of these. another is to be able to load the
  // accessory mappings for the full round-trip story.
  private $_relationship_attributes;

  // holds the target product attribute
  private $_target_product_attribute;


  // category hierarchy.
  // _categories[attribute_id][salsify_category_id] = category
  private $_categories;

  // current category that we're building up from parsing
  private $_category;


  // Current product batch that has been read in.
  // NOTE: currently we're not batch loading anything because we want to bulk
  //       load relations, and if a product that is the target of a relation
  //       isn't in a batch the relation will fail to load.
  const BATCH_SIZE = 1000;
  private $_batch;
  private $_batch_accessories;

  // current product that we're building up from parsing
  private $_product;


  // hash of all digital assets. we're not actually going to load the digital
  // assets during parsing (even though the bulk import API supports it), since
  // that requires that all images be downloaded locally. so instead what we're
  // going to do is save the assets and make them available in an accessor to
  // whatever is using the importer.
  private $_digital_assets;


  // keep track of nesting level during parsing. this is handy to know whether
  // the object you're leaving is nested, etc.
  private $_nesting_level;
  const HEADER_NESTING_LEVEL  = 2;
  const ITEM_NESTING_LEVEL = 4;

  // keeps track of current parsing state.
  private $_in_attributes;
  private $_in_attribute_values;
  private $_in_products;


  /**
   * Returns digital assets seen during import.
   * 
   * Format of digital assets is an array of sku => array(array(url,name)).
   */
  public function get_digital_assets() {
    return $this->_digital_assets;
  }


  public function start_document() {
    self::_log("Starting document load.");

    $this->_key_stack = array();
    $this->_value_stack = array();
    $this->_type_stack = array();

    $this->_nesting_level = 0;
    $this->_in_attributes = false;
    $this->_in_attribute_values = false;
    $this->_in_products = false;

    $this->_target_product_attribute = Salsify_Connect_Model_AttributeMapping::getAttributeForAccessoryIds();
  }


  public function end_document() {
    self::_log("Finished parsing document. Flushing final product data and reindexing.");

    $this->_flush_batch();
    $this->_reindex();

    self::_log("Finished parsing, loading, and reindexing. Only digital assets remain, and left as an exercise to the caller.");
  }


  // update all indexes in Magento. it doesn't really pay to be picky about this
  // since most indexes update almost instantly, and the ones that don't we have
  // to update anyway.
  private function _reindex() {
    self::_log("Rebuilding all indexes.");

    $processCollection = Mage::getSingleton('index/indexer')
                             ->getProcessesCollection(); 
    
    foreach($processCollection as $process) {
      $process->reindexEverything();
    }
  }


  public function start_object() {
    $this->_nesting_level++;

    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_start_attribute();
      } elseif ($this->_in_attribute_values) {
        $this->_start_category();
      } elseif ($this->_in_products) {
        $this->_start_product();
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_start_nested_thing(self::OBJECT_TYPE);
    }
  }


  public function end_object() {
    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_end_attribute();
      } elseif ($this->_in_attribute_values) {
        $this->_end_category();
      } elseif ($this->_in_products) {
        $this->_end_product();
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_end_nested_thing();
    } elseif ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
        if ($this->_in_attribute_values) {
          $this->_import_categories();
        }

        $this->_in_attributes = false;
        $this->_in_attribute_values = false;
        $this->_in_products = false;
    }

    $this->_nesting_level--;
  }


  private function _start_attribute() {
    $this->_attribute = array();
    if ($this->_in_attributes) {
      $this->_attribute['type'] = self::PRODUCT;
    } elseif ($this->_in_attribute_values) {
      $this->_attribute['type'] = self::CATEGORY;
    } else {
      self::_log("ERROR: _start_attribute when not in attributes or attribute values");
    }
  }

  private function _end_attribute() {
    $role = $this->_get_attribute_role($this->_attribute);
    if ($role === "relation_type") {
      array_push($this->_relationship_attributes, $this->_attribute['salsify:id']);
    }

    // NOTE: if the attribute turns out to be a category--other than a product
    //       accessory category, which we can't tell at this point in the
    //       import--it will be deleted from Magento during category loading.
    $this->_get_or_create_attribute($this->_attribute);

    unset($this->_attribute);
  }


  private function _start_category() {
    $this->_category = array();
  }

  private function _end_category() {
    if (!array_key_exists('salsify:attribute_id', $this->_category)) {
      self::_log("ERROR: no salsify:attribute_id specified for category, so skipping: " . var_export($this->_category, true));
    } elseif (!array_key_exists('salsify:id', $this->_category)) {
      self::_log("ERROR: no id specified for category, so skipping: " . var_export($this->_category, true));
    } else {
      $attribute_id = $this->_category['salsify:attribute_id'];
      $id = $this->_category['salsify:id'];

      if (!array_key_exists('salsify:name', $this->_category)) {
        self::_log("WARNING: salsify:name not given for category. using ID as name: " . var_export($this->_category, true));
        $this->_category['salsify:name'] = $id;
      }

      $this->_categories[$attribute_id][$id] = $this->_category;
    }
    unset($this->_category);
  }


  private function _start_product() {
    $this->_product = array();
  }

  private function _end_product() {
    $prepared_products = $this->_prepare_product($this->_product);
    if ($prepared_products) {
      $this->_batch = array_merge($this->_batch, $prepared_products);
    }
    unset($this->_product);

    // Unfortunately this does not work with accessory relationships (see
    // note above by _batch declaration). The Magento ImportExport system does
    // do batching of its own within an import, so the major downside to not
    // being able to contribute ourselves is that we're going to be holding the
    // ENTIRE import in memory. If that becomes a problem it might be easier to
    // temporarily write out the relations somewhere else and then do this
    // batching, which would work.
    // if (count($this->_batch) > self::BATCH_SIZE) {
    //   $this->_flush_batch();
    // }
  }

  // prepares the product that we've loaded from salsify for Magento. this means
  // returning an array of "products" that match what Magento's bulk import API
  // requires.
  //
  // The way that the Magento Import API handles multi-valued assignments is to
  // have the second values as new array items just after the main element (the
  // analogy is a new row in a CSV with only a single value for the column
  // filled out).
  //
  // Note: the only multi-valued assignments we currently deal with here are
  //       product relationships. Multi-valued properties are squished into a
  //       single value.
  private function _prepare_product($product) {
    if (!array_key_exists('sku', $product)) {
      self::_log("ERROR: product must have a SKU and does not: " . var_export($product, true));
      return null;
    }

    // SKU can only be 64 characters in Magento. We fail for now on this...
    $sku = $product['sku'];
    if (strlen($sku) > 64) {
      self::_log("ERROR: product SKU must be at most 64 characters long. Skipping product: " . $sku);
      return null;
    }

    $prepped_product = array();
    $extra_product_values = array();

    foreach ($product as $key => $value) {
      if ($key === 'salsify:relations') {
        // process accessory relationships for the product
        $accessory_skus = $this->_prepare_product_accessories($product['sku'], $value);
        if (!empty($accessory_skus)) {
          $product['_links_crosssell_sku'] = array_pop($accessory_skus);
          foreach ($accessory_skus as $accessory_sku) {
            array_push($extra_product_values,
                       $this->_row_for_extra_product_value('_links_crosssell_sku',
                                                           $accessory_sku));
          }
        }
        unset($product['salsify:relations']);
      } elseif ($key === 'salsify:digital_assets') {
        $this->_digital_assets[$product['sku']] = $value;
        unset($product['salsify:digital_assets']);
      } elseif ($key === '_category') {
        // support for multiple category assignments
        $categories = $product[$key];
        $product[$key] = array_shift($categories);
        foreach ($categories as $category) {
          array_push($extra_product_values,
                     $this->_row_for_extra_product_value($key, $category));
        }
      } elseif (is_array($value)) {
        // multi-valued thing. wish we could do better, but see this for why not:
        // https://github.com/avstudnitz/AvS_FastSimpleImport/issues/9
        $product[$key] = implode(', ', $value);
        
        // Tried this, but only the first one actually shows up. Magento doesn't
        // seem to support multi-valued attriutes for a product.
        //
        // $product[$key] = array_pop($value);
        // foreach ($value as $v) {
        //   array_push($extra_product_values,
        //              $this->_row_for_extra_product_value($key, $v));
        // }
      }
    }

    $product = $this->_prepare_product_add_required_values($product);

    // add the Salsify ID for good measure, even though it is mapped to the sku.
    $product[$this->_salsify_id_product_attribute_code] = $product['sku'];

    array_push($prepped_product, $product);
    if (!empty($extra_product_values)) {
      $prepped_product = array_merge($prepped_product, $extra_product_values);
    }

    return $prepped_product;
  }


  // see https://github.com/avstudnitz/AvS_FastSimpleImport/issues/30
  // for why this is necessary. it may be unnecessary for future versions of
  // AvS_FastSimpleImport that shield us from these problems.
  private function _row_for_extra_product_value($key, $value) {
    return array($key             => $value,
                 'sku'            => null,
                 '_type'          => null,
                 '_attribute_set' => null);
  }


  // accessories is as per the salsify import format.
  private function _prepare_product_accessories($trigger_sku, $accessories) {
    $accessory_skus = array();
    foreach ($accessories as $accessory) {
      // need to figure out the category and value
      foreach($accessory as $key => $value) {
        if ($key === $this->_target_product_attribute) {
          $target_sku = $value;
        } elseif (in_array($key, $this->_relationship_attributes)) {
          $category = $key;
          $category_value = $value;
        }
      }

      array_push($accessory_skus, $target_sku);

      array_push($this->_batch_accessories, array(
        'salsify_category_id'    => $category,
        'salsify_category_value' => $category_value,
        'trigger_sku'            => $trigger_sku,
        'target_sku'             => $target_sku,
      ));
    }

    return $accessory_skus;
  }


  // cached
  private $_required_attributes;
  private function _get_required_attributes() {
    if (!$this->_required_attributes) {
      $this->_required_attributes = Salsify_Connect_Model_AttributeMapping::getRequiredProductAttributesWithDefaults();
    }
    return $this->_required_attributes;
  }


  // Magento requires that products that are imported in bulk through its
  // ImportExport API have values for all required properties. There are some
  // that come with the system by default.
  //
  // If the product already exists then we copy over the values from it so that
  // we don't accidentally overwrite a Magento value with some Mageomatic
  // default.
  private function _prepare_product_add_required_values($product) {
    $existing_product = Mage::getModel('catalog/product')
                            ->loadByAttribute('sku', $product['sku']);
    if (!$existing_product || !$existing_product->getId()) {
      $existing_product = null;
    } else {
      // need to do this to load ALL attribute values for the product
      $existing_product = Mage::getModel('catalog/product')
                              ->load($existing_product->getId());
    }

    $required_attributes = $this->_get_required_attributes();
    foreach ($required_attributes as $code => $default) {
      // default is to go with what's coming from Salsify, so that Salsify
      // overrules what is happening here.
      if (!array_key_exists($code, $product)) {
        if ($existing_product) {
          $product[$code] = $existing_product->getData($code);
        } else {
          $product[$code] = $default;
        }
      }
    }


    // note that the product may have values other than the ones above, but they
    // are not overridden since we're using append. for the required attributes
    // we have to have them in the import array otherwise the importexport API
    // craps out on us looking for them.


    return $product;
  }


  public function start_array() {
    $this->_nesting_level++;
    
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_start_nested_thing(self::ARRAY_TYPE);
    }
  }


  public function end_array() {
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_end_nested_thing();
    }

    $this->_nesting_level--;
  }


  private function _start_nested_thing($type) {
    array_push($this->_value_stack, array());
    array_push($this->_type_stack, $type);
  }


  private function _end_nested_thing() {
    $value = array_pop($this->_value_stack);
    $type = array_pop($this->_type_stack);

    if (empty($this->_value_stack)) {
      // at the root of an object, whether product, attribute, category, etc.

      $key = array_pop($this->_key_stack);
      
      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_attribute_values) {
        self::_log("ERROR: in a nested object in attribute_values, but shouldn't be: " . var_export($this->_category, true));
        self::_log("ERROR: nested thing for above error: " . var_export($value, true));
      } elseif ($this->_in_products) {
        if (array_key_exists($key, $this->_attributes)) {
          $code = $this->_get_attribute_code($this->_attributes[$key]);
          $this->_product[$code] = $value;
        } elseif ($key === 'salsify:relations') {
          $this->_product[$key] = $value;
        } elseif ($key === 'salsify:digital_assets') {
          $this->_product[$key] = $value;
        } elseif (array_key_exists($key, $this->_categories)) {
          // multiple categories
          foreach ($value as $catid) {
            $this->_add_category_to_product($key, $catid);
          }
        } else {
          self::_log("ERROR: product has key of undeclared attribute. skipping attribute: " . $key);
        }
      }
    } else {
      // within a nested object of some kind
      $this->_add_nested_value($value);
    }
  }

  // nice helper method that adds the given value to the top of the nested
  // stack of objects, whether that nested thing be an array or object (which,
  // in both cases, is a PHP array).
  private function _add_nested_value($value) {
    // unbelievable how PHP doesn't have array_peek...
    $parent_value = array_pop($this->_value_stack);
    $parent_type = array_pop($this->_type_stack);
    if ($parent_type === self::ARRAY_TYPE) {
      array_push($parent_value, $value);
    } elseif ($parent_type === self::OBJECT_TYPE) {
      $key = array_pop($this->_key_stack);
      $parent_value[$key] = $value;
    }
    array_push($this->_value_stack, $parent_value);
    array_push($this->_type_stack, $parent_type);
  }


  // Key will always be a string
  public function key($key) {
    array_push($this->_key_stack, $key);

    if ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
      if ($key === 'attributes') {
        // starting to parse attribute section of import document

        self::_log("Starting to parse attributes.");
        $this->_in_attributes = true;
        $this->_attributes = array();
        $this->_relationship_attributes = array();

        // create the attributes to store the salsify ID for all object types.
        $this->_create_salsify_id_attributes_if_needed();

      } elseif ($key === 'attribute_values') {
        // starting to parse attribute_values (e.g. categories) section of
        // import document

        self::_log("Starting to parse categories (attribute_values).");
        $this->_in_attribute_values = true;
        $this->_categories = array();

      } elseif ($key === 'products') {
        // starting to parse products section of import document

        self::_log("Starting to parse products.");
        $this->_in_products = true;
        $this->_batch = array();
        $this->_batch_accessories = array();
        $this->_digital_assets = array();
      }
    }
  }


  // Note that value may be a string, integer, boolean, array, etc.
  public function value($value) {
    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      $key = array_pop($this->_key_stack);

      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_attribute_values) {
        $this->_category[$key] = $value;
      } elseif ($this->_in_products) {
        if (array_key_exists($key, $this->_categories)) {
          $this->_add_category_to_product($key, $value);
        } elseif (array_key_exists($key, $this->_attributes)) {
          $attribute = $this->_attributes[$key];
          $code = $this->_get_attribute_code($attribute);

          // make sure to skip attributes that are owned by Magento
          if (!Salsify_Connect_Model_AttributeMapping::isAttributeMagentoOwned($code)) {
            $value = Salsify_Connect_Model_AttributeMapping::castValueByBackendType($value, $attribute['__backend_type']);
            $this->_product[$code] = $value;
          }
        } else {
          self::_log('WARNING: skipping unrecognized attribute id on product: ' . $key);
        }
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_add_nested_value($value);
    }
  }


  private function _add_category_to_product($cat_attr, $catid) {
    // this will always be a single category assignment
    if (array_key_exists($catid, $this->_categories[$cat_attr])) {
      $category = $this->_categories[$cat_attr][$catid];
      if (!array_key_exists('_category', $this->_product)) {
        $this->_product['_category'] = array();
      }
      array_push($this->_product['_category'], $this->_get_category_path($category));
    } else {
      self::_log("WARNING: product category assignment to unknown category. Skipping: " . $key . '=' . $value);
    }
  }


  private function _flush_batch() {
    if (empty($this->_batch)) {
      self::_log("Connecting to an empty Salsify. Skipping batch.");
      return null;
    }


    // first save the products in the bulk API
    self::_log("Flushing product batch of size: " . count($this->_batch));
    try {
      Mage::getSingleton('fastsimpleimport/import')
          ->setBehavior(Mage_ImportExport_Model_Import::BEHAVIOR_APPEND)
          ->setContinueAfterErrors(true)
          ->processProductImport($this->_batch);

      // for PHP GC
      unset($this->_batch);
      $this->_batch = array();
    } catch (Exception $e) {
      $error_msg = 'ERROR: could not flush batch: ' . $e->getMessage();
      self::_log($error_msg);
      self::_log('BACKTRACE:' . $e->getTraceAsString());
      throw new Exception($error_msg);
    }


    // next flush the accessory mappings via our own bulk API
    try {
      $count = Salsify_Connect_Model_AccessoryMapping::bulkLoadMappings($this->_batch_accessories);
      self::_log("Successfully loaded " . $count . " new accessory mappings.");
      unset($this->_batch_accessories);
      $this->_batch_accessories = array();
    } catch (Exception $e) {
      $error_msg = 'ERROR: could not flush batch of accessory mappings: ' . $e->getMessage();
      self::_log($error_msg);
      self::_log('BACKTRACE:' . $e->getTraceAsString());
      throw new Exception($error_msg);
    }
  }


  // this creates the EAV attributes in the system for storing Salsify IDs for
  // products and categories if they don't already exist.
  private function _create_salsify_id_attributes_if_needed() {
    self::_log("ensuring that Salsify ID attributes exist in Magento...");

    Salsify_Connect_Model_AttributeMapping::createSalsifyIdAttributes();
    $this->_salsify_id_category_attribute_code = Salsify_Connect_Model_AttributeMapping::SALSIFY_CATEGORY_ID;
    $this->_salsify_attribute_id_code = Salsify_Connect_Model_AttributeMapping::SALSIFY_CATEGORY_ATTRIBUTE_ID;
    $this->_salsify_id_product_attribute_code = Salsify_Connect_Model_AttributeMapping::SALSIFY_PRODUCT_ID;

    self::_log("done ensuring that Salsify ID attributes exist in Magento.");
  }

  private function _get_attribute_code($attribute) {
    if (array_key_exists('__code', $attribute)) {
      return $attribute['__code'];
    }

    $id = $attribute['salsify:id'];
    $roles = $this->_get_attribute_role($attribute);
    return Salsify_Connect_Model_AttributeMapping::getCodeForId($id, $roles);
  }

  // creates the given attribute in Magento if it doesn't already exist.
  private function _get_or_create_attribute($attribute) {
    $id = $attribute['salsify:id'];
    if (!array_key_exists($id, $this->_attributes)) {
      $attribute = $this->_load_or_create_dbattribute($attribute);
      if ($attribute) {
        $this->_attributes[$id] = $attribute;
      } else {
        return null;
      }
    }
    return $this->_attributes[$id];
  }

  private function _load_or_create_dbattribute($attribute) {
    if (array_key_exists('__code', $attribute)) {
      return $attribute;
    }

    $id = $attribute['salsify:id'];

    if (array_key_exists('salsify:name', $attribute)) {
      $name = $attribute['salsify:name'];
    } else {
      $name = $id;
    }

    $role = $this->_get_attribute_role($attribute);

    $type = $this->_get_attribute_type($attribute);
    if ($type === self::CATEGORY) {
      $dbattribute = Salsify_Connect_Model_AttributeMapping::loadOrCreateCategoryAttributeBySalsifyId($id, $name, $role);
    } elseif ($type === self::PRODUCT) {
      $dbattribute = Salsify_Connect_Model_AttributeMapping::loadOrCreateProductAttributeBySalsifyId($id, $name, $role);
    }

    if (!$dbattribute) {
      return null;
    }

    $attribute['__code'] = $dbattribute->getAttributeCode();
    $attribute['__backend_type'] = $dbattribute->getBackendType();
    return $attribute;
  }

  private function _delete_attribute_with_salsify_id($attribute_id) {
    self::_log("attribute " . $attribute_id . " is really a category. deleting.");

    $roles = null;

    Salsify_Connect_Model_AttributeMapping::deleteCategoryAttribute($attribute_id, $roles);
    Salsify_Connect_Model_AttributeMapping::deleteProductAttribute($attribute_id, $roles);

    unset($this->_attributes[$attribute_id]);

    self::_log("attribute " . $attribute_id . " deleted.");
  }


  private function _get_attribute_role($attribute) {
    if (array_key_exists('salsify:role', $attribute)) {
      return $attribute['salsify:role'];
    }
  }

  // The type of the attribute. NOT boolean, etc. But rather whether it's an
  // attribute that's used for products, categories, etc.
  private function _get_attribute_type($attribute) {
    if (array_key_exists('type', $attribute)) {
      return $attribute['type'];
    } else {
      self::_log("WARNING: no type (product, category, etc.) stored in given attribute: " . var_export($attribute, true));
      return self::PRODUCT;
    }
  }


  // goes through all the categories that were seen in the import and makes
  // sure that they and their ancestors are all loaded into Magento.
  //
  // unlike most methods, this does not fail silently, but instead throws an
  // exception if something goes wrong. it is assumed that if we cannot import
  // categories it is simply not worth continuing on to import products.
  private function _import_categories() {
    self::_log("Done parsing category data. Ensuring they are in database.");

    $categories_for_import = $this->_prepare_categories_for_import();
    if (!empty($categories_for_import)) {
      $import = Mage::getModel('fastsimpleimport/import');
      try {
        $import->processCategoryImport($categories_for_import);
      } catch (Exception $e) {
        self::_log("ERROR: loading categories into the database. aborting load: " . $e->getMessage());
        throw $e;
      }
    }

    // NOTE this is a problem with the bulk import API. Currently it does not
    //      update the children_count in the database tables, which means that
    //      the categories are not expandable in the product detail pages. this
    //      fix is from the bug filing:
    //      https://github.com/avstudnitz/AvS_FastSimpleImport/issues/26
    self::_log("Running children_count fix sql...");
    $sql = "START TRANSACTION;
    DROP TABLE IF EXISTS `catalog_category_entity_tmp`;
    CREATE TABLE catalog_category_entity_tmp LIKE catalog_category_entity;
    INSERT INTO catalog_category_entity_tmp SELECT * FROM catalog_category_entity;

    UPDATE catalog_category_entity cce
    SET children_count =
    (
        SELECT count(cce2.entity_id) - 1 as children_county
        FROM catalog_category_entity_tmp cce2
        WHERE PATH LIKE CONCAT(cce.path,'%')
    );

    DROP TABLE catalog_category_entity_tmp;
    COMMIT;";
    try {
      $db = Mage::getSingleton('core/resource')
                ->getConnection('core_write');
      $db->query($sql);
    } catch (Exception $e) {
      self::_log("FAIL: " . $e->getMessage());
      throw $e;
    }
    self::_log("Done. Hopefully it worked and you can expand categories on product detail pages.");

    self::_log("Done ensuring categories are in Magento. Number of new categories created: " . count($categories_for_import) . " new categories imported.");
  }


  // Prepares the categories for import, returning an import-friendly array.
  // Notes:
  // * Categories already in the system (as identified by salsify_category_id)
  //   will be ignored.
  // * New root categories will be loaed here on a one-off basis, since the
  //   import API doesn't seem to be able to create new roots.
  // * The array will be sorted by depth. Required by import (parents must be
  //   created before their children).
  private function _prepare_categories_for_import() {
    $categories = array();
    $cleaned_categories = array();
    foreach ($this->_categories as $attribute_id => $categories_for_attribute) {
      if (array_key_exists($attribute_id, $this->_attributes)) {
        // First time seeing this. If it exists, let's make sure to delete the
        // actual attribute from the system. The reason we do this here is so
        // that we don't have to keep all of the attributes in memory as we go
        // through the prior attributes section. So we just assume that they're
        // all valid and then delete here.
        $this->_delete_attribute_with_salsify_id($attribute_id);
      }

      $cleaned_categories[$attribute_id] = array();

      foreach ($categories_for_attribute as $id => $category) {
        $cat = $this->_clean_and_prepare_category($category);
        if ($cat) {
          $cleaned_categories[$attribute_id][$id] = $cat;
          $categories[] = $cat;
        }
      }
    }
    $this->_categories = $cleaned_categories;

    $categories = $this->_sort_categories_by_depth($categories);

    $prepped_categories = array();
    foreach ($categories as $category) {
      if (in_array($category['salsify:attribute_id'], $this->_relationship_attributes)) {
        $mapping = Salsify_Connect_Model_AccessorycategoryMapping::getOrCreateMapping(
                     $category['salsify:attribute_id'],
                     $category['salsify:id'],
                     null
                   );
        if (!$mapping) {
          self::_log("WARNING: could not create AccessorycategoryMapping for accessory category: " . var_export($category,true));
        }

        // don't bother loading categories for accessory attributes
        // NOTE if a single category hierarchy be used for both products AND
        //      accessory relationships this will be problematic...
        continue;
      }

      if ($this->_get_category($category)) {
        // already exists in database. continue.
        continue;
      }

      if ($category['__depth'] == 0) {
        // create root category by hand. it's required for the mass import of
        // the other categories, and the API can't create the roots itself.

        if (!$this->_create_category($category)) {
          $msg = "ERROR: could not create root category. Aborting import: " . var_export($category, true);
          self::_log($msg);
          throw new Exception($msg);
        }
      } else {
        array_push($prepped_categories, $this->_prepare_category_for_import($category));
      }
    }
    return $prepped_categories;
  }


  // returns the database model category for the given category if it exists.
  //         null otherwise.
  private function _get_category($category) {
    return Mage::getModel('catalog/category')
               ->loadByAttribute($this->_salsify_id_category_attribute_code, $category['salsify:id']);
  }


  // returns a category array that is just liked the raw, parsed category array
  // that is passed in, except that it is filled out for the purpose of bulk
  // category import.
  //
  // primarily this means building up full path names that can be used in the
  // bulk import API to identify where in a category hierarchy to insert the
  // item. we also store the depth so that we might sort by it (curiously the
  // bulk import API requires that parents appear before children).
  //
  // the "clean" aspect of this really means to make the name of the category
  // safe for bulk import.
  private function _clean_and_prepare_category($category) {
    if (array_key_exists('__depth', $category)) {
      // already processed this one
      return $category;
    }

    $category['salsify:name'] = preg_replace('/\//', '|', $category['salsify:name']);

    $id = $category['salsify:id'];
    $attribute_id = $category['salsify:attribute_id'];
    if (array_key_exists('salsify:parent_id', $category)) {
      $parent_id = $category['salsify:parent_id'];
      if (!array_key_exists($parent_id, $this->_categories[$attribute_id])) {
        self::_log("WARNING: salsify:parent_id for category refers to an unknown parent. Skipping: " . var_export($category, true));
        return null;
      }

      // need to all this recursively, since we need the parent's path and depth
      // in order to calculate our own.
      $parent_category = $this->_clean_and_prepare_category($this->_categories[$attribute_id][$parent_id]);
      if (!$parent_category) {
        return null;
      }

      $category['__root']  = $parent_category['__root'];

      $parent_depth = $parent_category['__depth'];
      $category['__depth'] = $parent_depth + 1;
      if ($parent_depth == 0) {
        // path is relative not to the root, but to the first child of the root...
        $category['__path'] = $category['salsify:name'];
      } else {
        $category['__path'] = $parent_category['__path'] . '/' . $category['salsify:name'];
      }
    } else {
      // root category
      $category['__root']  = $category['salsify:name'];
      $category['__depth'] = 0;
      $category['__path']  = $category['salsify:name'];
    }
    return $category;
  }


  // returns a version of the category that is appropriate for the import API.
  // makes sure to include all the properties that are required by the import
  // API.
  private function _prepare_category_for_import($category) {
    return array(
      '_root' => $category['__root'],
      '_category' => $category['__path'],
      'name' => $category['salsify:name'],
      'description' => 'Created during import from Salsify.',
      'is_active' => 'yes',
      'include_in_menu' => 'yes',
      'position' => '1',

      // 'Price' is safe since it comes OOTB
      'available_sort_by' => 'Price',
      'default_sort_by' => 'Price',

      // optional
      'url_key' => $this->_get_url_key($category),
      // 'meta_description' => $category['salsify:name'],

      $this->_salsify_id_category_attribute_code => $category['salsify:id'],
      $this->_salsify_attribute_id_code => $category['salsify:attribute_id'],
    );
  }


  // creates a URL-friendly key for the given category.
  //
  // it will replace whitespace with friendlier dashes, lowerase the string,
  // and urlencode the result in case there are unfriendly characters in the
  // name.
  private function _get_url_key($category) {
    $key = strtolower($category['salsify:name']);
    $key = preg_replace('/\s\s+/', '-', $key);
    return urlencode($key);
  }


  // self-explanatory.
  private function _sort_categories_by_depth($categories) {
    if (empty($categories)) {
      return $categories;
    }

    $bins = array();
    $max_depth = 0;
    foreach ($categories as $category) {
      $depth = $category['__depth'];
      if (!array_key_exists($depth, $bins)) {
        $bins[$depth] = array();
      }
      $bins[$depth][] = $category;

      if ($depth > $max_depth) {
        $max_depth = $depth;
      }
    }

    $sorted_categories = array();
    for ($i = 0; $i <= $max_depth; $i++) {
      $sorted_categories = array_merge($sorted_categories, $bins[$i]);
    }
    return $sorted_categories;
  }


  // helper method that frees other parts of the code from having to worry about
  // the crazy nested array structure we've built up.
  private function _get_category_path($category) {
    $attribute_id = $category['salsify:attribute_id'];
    $id = $category['salsify:id'];
    return $this->_categories[$attribute_id][$id]['__path'];
  }


  // returns the newly created Magento Category model instance if successful,
  // or null if there was some problem in creating the category.
  private function _create_category($category) {
    $dbcategory = new Mage_Catalog_Model_Category();

    // multi-store
    // $category->setStoreId(0);

    $dbcategory->setName($category['salsify:name']);
    $dbcategory->setSalsifyCategoryId($category['salsify:id']);
    $dbcategory->setSalsifyAttributeId($category['salsify:attribute_id']);
    $dbcategory->setDescription('Created during Salsify import.');

    // TODO what are the other options? is this a reasonable default that I
    //      should be picking?
    $dbcategory->setDisplayMode('PRODUCTS_AND_PAGE');

    $dbcategory->setIsActive('1');
    $dbcategory->setIncludeInMenu('1');

    // TODO what is this?
    $dbcategory->setIsAnchor('0');

    if (array_key_exists('salsify:parent_id', $category)) {
      $parent_category = $this->_get_parent_category($category);
      $parent_dbcategory = $this->_get_category($parent_category);
    } else {
      // even though this is a 'root' category, it's parent is still the global
      // Magento root category (id 1), which never shows up in display anywhere.
      $parent_dbcategory = Mage::getModel('catalog/category')
                               ->load('1');
    }
    $dbcategory->setParentId($parent_dbcategory->getId());
    $dbcategory->setLevel($parent_dbcategory->getLevel() + 1);
    $dbcategory->setUrlKey($this->_get_url_key($category));
    $dbcategory->setPath($parent_dbcategory->getPath());

    // NOTE this broke things. Doesn't stop anything from working but when we
    //      support more sophisticated mappings we'll have to revisit this, so
    //      I'm keeping this non-working code around.
    // $attribute_set_id = $parent_category->getResource()
    //                                     ->getEntityType()
    //                                     ->getDefaultAttributeSetId();
    // $model->setAttributeSetId($attribute_set_id);

    try {
      $dbcategory->save();
      return $dbcategory;
    } catch (Exception $e) {
      self::_log("ERROR: creating category: " . $e->getMessage());
      return null;
    }
  }

  // helper function for _create_category. probably not necessary since that
  // is only now used to create root categories, which have no parents. however,
  // i like to keep it around since the method can be used (and has been) to
  // create the entire category hierarchy in Magento.
  private function _get_parent_category($category) {
    if (array_key_exists('salsify:parent_id', $category)) {
      $parent_id = $category['salsify:parent_id'];
      $attribute_id = $category['salsify:attribute_id'];
      if (array_key_exists($parent_id, $this->_categories[$attribute_id])) {
        return $this->_categories[$attribute_id][$parent_id];
      }
      self::_log("ERROR: salsify:parent_id mentioned in category but not seen in import: " . var_export($category, true));
    }
    return null;
  }
}