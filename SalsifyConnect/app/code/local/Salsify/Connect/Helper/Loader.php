<?php
require_once BP.DS.'lib'.DS.'JsonStreamingParser'.DS.'Listener.php';

/**
 * Parser of Salsify data. Also loads into the Magento database.
 */
class Salsify_Connect_Helper_Loader extends Mage_Core_Helper_Abstract implements \JsonStreamingParser\Listener {

  private function _log($msg) {
    Mage::log('Loader: ' . $msg, null, 'salsify.log', true);
  }

  // attribute_codes for attributes that store the Salsify IDs within Magento
  // for various object types.
  // TODO need to special case ids coming from salsify to make sure they don't
  //      accidentally intersect with this, though that seems like a low-probability
  //      event.
  const SALSIFY_CATEGORY_ID = 'salsify_category_id';
  const SALSIFY_CATEGORY_ID_NAME = 'Salsify Category ID';
  const SALSIFY_PRODUCT_ID = 'salsify_product_id';
  const SALSIFY_PRODUCT_ID_NAME = 'Salsify Product ID';

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

  // list of attribute IDs for relationships. our primary reason for keeping
  // these around is to ignore them when loading products, since no product
  // will ever be assigned to one of these.
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
  // TODO verify that this is, in fact, true
  const BATCH_SIZE = 1000;
  private $_batch;

  // current product that we're building up from parsing
  private $_product;

  // hash of all digital assets. we're not actually going to load the digital
  // assets during parsing (even though the bulk import API supports it), since
  // that requires that all images be downloaded locally. so instead what we're
  // going to do is save the assets and make them available in an accessor to
  // whatever is using the loader.
  private $_digital_assets;

  // keep track of nesting level during parsing. this is handy to know whether
  // the object you're leaving is nested, etc.
  private $_nesting_level;
  const HEADER_NESTING_LEVEL  = 2;
  const ITEM_NESTING_LEVEL = 4;

  // keeps track of current parsing state.
  // TODO parser header info
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
    $this->_log("Starting document load.");

    $this->_key_stack = array();
    $this->_value_stack = array();
    $this->_type_stack = array();

    $this->_nesting_level = 0;
    $this->_in_attributes = false;
    $this->_in_attribute_values = false;
    $this->_in_products = false;

    // create the attributes to store the salsify ID for all object types.
    //
    // TODO set the salsify_id for ALL objects coming into the system
    //      currently missing: attributes
    $this->_create_salsify_id_attributes_if_needed();
  }


  public function end_document() {
    $this->_log("Finished parsing document. Flushing final product data and reindexing.");

    $this->_flush_batch();
    $this->_reindex();

    $this->_log("Finished parsing, loading, and reindexing. Only digital assets remain, and left as an exercise to the caller.");
  }


  // update all indexes in Magento. it doesn't really pay to be picky about this
  // since most indexes update almost instantly, and the ones that don't we have
  // to update anyway.
  private function _reindex() {
    $this->_log("Rebuilding all indexes.");

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
      // TODO is this always true?
      $this->_attribute['type'] = self::PRODUCT;
    } elseif ($this->_in_attribute_values) {
      $this->_attribute['type'] = self::CATEGORY;
    } else {
      $this->_log("ERROR: _start_attribute when not in attributes or attribute values");
    }
  }

  private function _end_attribute() {
    // NOTE: if the attribute turns out to be a category, it will be deleted
    //       from Magento during category loading.
    $success = $this->_create_attribute_if_needed($this->_attribute);
    if ($success) {
      // check to see if the given attribute is the special target_product_id
      // attribute
      if (array_key_exists('roles', $this->_attribute)) {
        $roles = $this->_attribute['roles'];
        if (array_key_exists('accessories', $roles)) {
          $accessory_roles = $roles['accessories'];
          if (in_array('target_product_id', $accessory_roles)) {
            $this->_target_product_attribute = $this->_attribute['id'];
          }
        }
      }

      // check to see if the given attribute is the attribute associated with
      // a product relationship hierarchy (as opposed to a product hierarchy).
      if (array_key_exists('global', $this->_attribute)) {
        $roles = $this->_attribute['global'];
        if (array_key_exists('accessory_label', $roles)) {
          array_push($this->_relationship_attributes, $this->_attribute['id']);
        }
      }
    }

    unset($this->_attribute);
  }


  private function _start_category() {
    $this->_category = array();
  }

  private function _end_category() {
    if (!array_key_exists('attribute_id', $this->_category)) {
      $this->_log("ERROR: no attribute_id specified for category, so skipping: " . var_export($this->_category, true));
    } elseif (!array_key_exists('id', $this->_category)) {
      $this->_log("ERROR: no id specified for category, so skipping: " . var_export($this->_category, true));
    } else {
      $attribute_id = $this->_category['attribute_id'];
      $id = $this->_category['id'];

      if (!array_key_exists('name', $this->_category)) {
        $this->_log("WARNING: name not given for category. using ID as name: " . var_export($this->_category, true));
        $this->_category['name'] = $id;
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

    // TODO unfortunately this does not work with accessory relationships (see
    //      note above by _batch declaration)
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
  //
  // TODO handle multiple category assignments.
  private function _prepare_product($product) {
    if (!array_key_exists('sku', $product)) {
      $this->_log("ERROR: product must have a SKU and does not: " . var_export($product, true));
      return null;
    }

    $prepped_product = array();
    $extra_product_values = array();

    foreach ($product as $key => $value) {
      if ($key === 'accessories') {
        // process accessory relationships for the product
        if ($this->_target_product_attribute) {
          $accessory_skus = $this->_prepare_product_accessories($value);
          if (!empty($accessory_skus)) {
            $product['_links_crosssell_sku'] = array_pop($accessory_skus);
            foreach ($accessory_skus as $accessory_sku) {
              array_push($extra_product_values,
                         array('_links_crosssell_sku' => $accessory_sku));
            }
          }
        } else {
          $this->_log("WARNING: accessories for product when no attribute for role target_product_id was set: " . var_export($product, true));
        }
        unset($product['accessories']);
      } elseif (is_array($value)) {
        // multi-valued thing. wish we could do better, but see this for why not:
        // https://github.com/avstudnitz/AvS_FastSimpleImport/issues/9
        $product[$key] = implode(', ', $value);
      }
    }

    $product = $this->_prepare_product_add_required_values($product);

    // add the Salsify ID for good measure, even though it is mapped to the sku.
    $product[self::SALSIFY_PRODUCT_ID] = $product['sku'];

    array_push($prepped_product, $product);
    if (!empty($extra_product_values)) {
      $prepped_product = array_merge($prepped_product, $extra_product_values);
    }

    return $prepped_product;
  }


  // accessories is as per the salsify import format in terms of nesting.
  //
  // TODO right now we're just doing cross-sells, but we should have a
  //      mapping from the specific accessory categories to cross/up/etc.
  //      sells in Magento.
  private function _prepare_product_accessories($accessories) {
    $accessory_skus = array();
    foreach ($accessories as $accessory) {
      $sku = $accessory[$this->_target_product_attribute];
      array_push($accessory_skus, $sku);
    }
    return $accessory_skus;
  }


  // adds values for all the properties required by Magento so that the product
  // can be imported.
  //
  // TODO query the system to get the full list of required attributes.
  //      otherwise the bulk import fails silently...
  private function _prepare_product_add_required_values($product) {
    // TODO when Salsify supports Kits, this will have to change.
    $product['_type'] = 'simple';

    // TODO get the attribute set from its category? we could be precalculating
    //      the attributes that are used in each of the categories in Salsify
    //      in order to get a rough cut into Magento.
    $product['_attribute_set'] = 'Default';

    // TODO multi-store support
    $product['_product_websites'] = 'base';

    // TODO get these from Salsify, but need more metadata in the export to
    //      get them.
    if (!array_key_exists('price', $product)) {
      $product['price'] = 0.01;
    }
    if (!array_key_exists('short_description', $product)) {
      $product['short_description'] = 'IMPORTED FROM SALSIFY';
    }
    if (!array_key_exists('description', $product)) {
      $product['description'] = 'IMPORTED FROM SALSIFY';
    }
    if (!array_key_exists('weight', $product)) {
      $product['weight'] = 0;
    }

    if (!array_key_exists('status', $product)) {
      // TODO what does the status value mean?
      $product['status'] = 1;
    }

    if (!array_key_exists('visibility', $product)) {
      // TODO what does visibility '4' mean?
      $product['visibility'] = 4;
    }

    if (!array_key_exists('tax_class_id', $product)) {
      // TODO should we be setting a different tax class? can we get the system
      //      default?
      $product['tax_class_id'] = 2;
    }

    if (!array_key_exists('qty', $product)) {
      $product['qty'] = 0;
    }

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
        $this->_log("ERROR: in a nested object in attribute_values, but shouldn't be: " . var_export($this->_category, true));
        $this->_log("ERROR: nested thing for above error: " . var_export($value, true));
      } elseif ($this->_in_products) {
        if (array_key_exists($key, $this->_attributes)) {
          $code = $this->_attribute_code($this->_attributes[$key]);
          $this->_product[$code] = $value;
        } elseif ($key === 'accessories') {
          $this->_product[$key] = $value;
        } elseif ($key === 'digital_assets') {
          array_push($this->_digital_assets, $value);
        } else {
          $this->_log("ERROR: product has key of undeclared attribute. skipping attribute: " . $key);
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
        $this->_log("Starting to parse attributes.");
        $this->_in_attributes = true;
        $this->_attributes = array();
        $this->_relationship_attributes = array();
      } elseif ($key === 'attribute_values') {
        $this->_log("Starting to parse categories (attribute_values).");
        $this->_in_attribute_values = true;
        $this->_categories = array();
      } elseif ($key === 'products') {
        $this->_log("Starting to parse products.");
        $this->_in_products = true;
        $this->_batch = array();
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
          if (array_key_exists($value, $this->_categories[$key])) {
            $category = $this->_categories[$key][$value];
            // TODO allow multiple category assignments per product
            $this->_product['_category'] = $this->_get_category_path($category);
          } else {
            $this->_log("ERROR: product category assignment to unknown category. Skipping: " . $key . '=' . $value);
          }
        } elseif (array_key_exists($key, $this->_attributes)) {
          $attribute = $this->_attributes[$key];
          $code = $this->_attribute_code($attribute);
          $this->_product[$code] = $value;
        } else {
          $this->_log('ERROR: skipping unrecognized attribute id on product: ' . $key);
        }
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_add_nested_value($value);
    }
  }


  private function _flush_batch() {
    $this->_log("Flushing product batch of size: " . count($this->_batch));

    try {
      Mage::getSingleton('fastsimpleimport/import')
          ->setBehavior(Mage_ImportExport_Model_Import::BEHAVIOR_REPLACE)
          ->processProductImport($this->_batch);

      // for PHP GC
      unset($this->_batch);
      $this->_batch = array();
    } catch (Exception $e) {
      $this->_log('ERROR: could not flush batch: ' . $e->getMessage());
      $this->_log('BACKTRACE:' . $e->getTraceAsString());
    }
  }


  private function _create_salsify_id_attributes_if_needed() {
    // TODO pass in is_unique into the creation to make sure that it is, in fact,
    //      unique
    // TODO figure out how to prevent the value from being editable
    //      possibly: http://stackoverflow.com/questions/6384120/magento-read-only-and-hidden-product-attributes

    $attribute = array();
    $attribute['id'] = self::SALSIFY_PRODUCT_ID;
    $attribute['name'] = self::SALSIFY_PRODUCT_ID_NAME;
    $attribute['type'] = self::PRODUCT;
    $this->_create_attribute_if_needed($attribute);

    $attribute = array();
    $attribute['id'] = self::SALSIFY_CATEGORY_ID;
    $attribute['name'] = self::SALSIFY_CATEGORY_ID_NAME;
    $attribute['type'] = self::CATEGORY;
    $this->_create_attribute_if_needed($attribute);
  }


  // creates the given attribute in Magento if it doesn't already exist.
  //
  // TODO set salsify ID on attributes
  private function _create_attribute_if_needed($attribute) {
    $id = $attribute['id'];
    if (!array_key_exists($id, $this->_attributes)) {
      // TODO  when Salsify has bundles we'll have to deal with this.
      $product_type = 'simple';
      
      $dbattribute = $this->_get_attribute($attribute);
      if (!$dbattribute) {
        $dbattribute = $this->_create_attribute($attribute, $product_type);
      }

      if ($dbattribute) {
        $this->_attributes[$id] = $attribute;
      } else {
        // failed to create attribute
        return null;
      }
    }
    return $this->_attributes[$id];
  }


  private function _delete_attribute_from_salsify_id($attribute_id) {
    $this->_log("attribute " . $attribute_id . " is really a category. deleting.");

    $attribute = array();
    $attribute['id'] = $attribute_id;
    
    $attribute['type'] = self::CATEGORY;
    $this->_delete_attribute($attribute);

    $attribute['type'] = self::PRODUCT;
    $this->_delete_attribute($attribute);

    unset($this->_attributes[$attribute_id]);
  }

  private function _delete_attribute($attribute) {
    $dbattribute = $this->_get_attribute($attribute);
    if ($dbattribute) {
      $dbattribute->delete();
    }
  }


  // returns an attribute_code for the given attribute.
  //
  // the unique identifier used by Magento for attributes is the attribute_code.
  // a code is limited to 30 characters, and looks like it shouldn't contain
  // any spaces. this must be consistent across import runs for a given salsify
  // attribute.
  private function _attribute_code($attribute) {

    // there are some special attributes that Magento treats differently from
    // and admin and UI perspective, e.g. name, id, etc. right now there are a
    // couple that map directly to salsify roles.
    //
    // TODO have a more broad mapping mapping strategy from salsify attributes
    //      to Magento roles.

    $is_id = false;
    $is_name = false;

    if (array_key_exists('roles', $attribute)) {
      $roles = $attribute['roles'];
      if (array_key_exists('products', $roles)) {
        $product_roles = $roles['products'];
        if (in_array('id', $product_roles)) {
          $is_id = true;
        }
        if (in_array('name', $product_roles)) {
          $is_name = true;
        }
      }
    }

    if ($is_id) {
      return 'sku';
    } elseif ($is_name) {
      return 'name';
    }

    $id = $attribute['id'];

    if ($id === self::SALSIFY_PRODUCT_ID) {
      return self::SALSIFY_PRODUCT_ID;
    } elseif ($id === self::SALSIFY_CATEGORY_ID) {
      return self::SALSIFY_CATEGORY_ID;
    }

    // code can only be 30 characters at most and cannot contain spaces
    // creating a checksum seemed to be the easiest way to accomplish that,
    // though it has the downside of creating opaque atttribute_ids which do
    // show up in the admin panel...
    //
    // TODO generate a more user-friendly attribute (certain admin features
    //      inexplicably deal with attribute IDs instead of names, making it
    //      pretty hard to manage the attributes without a key).
    $code = 'salsify_'.md5($id);
    $code = substr($code, 0, 30);
    return $code;
  }


  // return database model of given attribute
  // Thanks http://www.sharpdotinc.com/mdost/2009/04/06/magento-getting-product-attributes-values-and-labels/
  //
  // TODO possibly use the same way I get categories (byAttribute) which would
  //      be cleaner
  private function _get_attribute($attribute) {
    if (!array_key_exists('type', $attribute)) {
      $type = self::PRODUCT;
    } else {
      $type = $attribute['type'];
    }

    $model = Mage::getResourceModel('eav/entity_attribute');
    $code  = $this->_attribute_code($attribute);

    if ($type === self::CATEGORY) {
      $attribute_id = $model->getIdByCode('catalog_category', $code);
    } elseif ($type === self::PRODUCT) {
      $attribute_id = $model->getIdByCode('catalog_product', $code);
    }

    if (!$attribute_id) {
      return null;
    }

    $attribute = Mage::getModel('catalog/resource_eav_attribute')
                     ->load($attribute_id);
    return $attribute;
  }


  // creates the given attribute in Magento.
  //
  // Thanks to http://inchoo.net/ecommerce/magento/programatically-create-attribute-in-magento-useful-for-the-on-the-fly-import-system/
  // as a starting point.
  // More docs: http://www.magentocommerce.com/wiki/5_-_modules_and_development/catalog/programmatically_adding_attributes_and_attribute_sets
  //
  // TODO support multi-store (see 'is_global' below)
  private function _create_attribute($attribute, $attribute_type, $product_type) {
    // There are even more options that we're not setting here. For example:
    // http://alanstorm.com/magento_attribute_migration_generator

    $code = $this->_attribute_code($attribute);
    $name = $attribute['name'];

    // At the moment we only get text properties from Salsify. In fact, since
    // we don't enforce datatypes in Salsify a single attribute could, in
    // theory, have a numeric value and a text value, so for now we have to
    // pick 'text' here to be safe.
    $attribute_type = 'text';
    $frontend_type  = 'text';

    // Keeping this around since it was tricky to figure out the first time.
    // if ($attribute_type === 'varchar') {
    //   $frontend_type  = 'text';
    // } else {
    //   $frontend_type  = $attribute_type;
    // }

    // I *think* this is everything we COULD be setting, with some properties
    // commented out. I got values from eav_attribute and catalog_eav_attribute

    $attribute_data = array(
      'attribute_code' => $code,
      'note' => 'Added automatically during Salsify import',
      'default_value_text' => '',
      'default_value_yesno' => 0,
      'default_value_date' => '',
      'default_value_textarea' => '',
      // # default_value - set below

      // These are available but shouldn't be set here.
      // # attribute_model
      // # backend_model
      // # backend_table
      // # source_model

      'is_user_defined' => 1,
      'is_global' => 1,
      'is_unique' => 0,
      'is_required' => 0,
      // # is_visible
      'is_configurable' => 0,
      'is_searchable' => 0,
      'is_filterable' => 0,
      'is_filterable_in_search' => 0,
      'is_visible_in_advanced_search' => 0,
      'is_comparable' => 0,
      'is_used_for_price_rules' => 0,
      'is_wysiwyg_enabled' => 0,
      'is_html_allowed_on_front' => 0,
      'is_visible_on_front' => 1,
      // # is_used_for_promo_rules
      'used_in_product_listing' => 0,
      'used_for_sort_by' => 0,
      // # position?

      // TODO is type even required here?
      'type' => $attribute_type,
      'backend_type' => $attribute_type,

      'frontend_input' => $frontend_type, //'boolean','text', etc.
      'frontend_label' => $name,
      // # frontend_model
      // # frontend_class
      // # frontend_input_renderer

      // without this it will not show up in the UI
      // TODO we we have to set this here if the group is being set below?
      'group' => 'General',

      // TODO apply_to multiple types by default? right now Salsify itself only
      //      really supports the simple type. also, if we leave this out it
      //      might automatically apply to everything, which is maybe what we
      //      want by default.
      'apply_to' => array($product_type), //array('grouped') see http://www.magentocommerce.com/wiki/modules_reference/english/mage_adminhtml/catalog_product/producttype
    );

    $model = Mage::getModel('catalog/resource_eav_attribute');

    $default_value_field = $model->getDefaultValueByInput($frontend_type);
    if ($default_value_field) {
      $attribute_data['default_value'] = $attribute_data[$default_value_field];
    }

    $model->addData($attribute_data);

    // Need to add the properties to a specific group of they don't show up in
    // the admin UI at all. In the future we might want to make this an option
    // so that we don't pollute the general attribute set. Maybe dumping all
    // into a Salsify group?
    $entity_type_id     = $this->_get_entity_type_id($attribute);
    $attribute_set_id   = $this->_get_attribute_set_id($attribute);
    $attribute_group_id = $this->_get_attribute_group_id($entity_type_id, $attribute_set_id);

    $model->setEntityTypeId($entity_type_id);
    $model->setAttributeSetId($attribute_set_id);
    $model->setAttributeGroupId($attribute_group_id);

    try {
      $model->save();
    } catch (Exception $e) {
      $this->_log('ERROR: could not create attribute <' . $attribute['id'] . '>: ' . $e->getMessage());
      return null;
    }

    // should be in the DB now
    return $this->_get_attribute($attribute);
  }


  // The type of the attribute. NOT boolean, etc. But rather whether it's an
  // attribute that's used for products, categories, etc.
  private function _get_attribute_type($attribute) {
    if (array_key_exists('type', $attribute)) {
      return $attribute['type'];
    } else {
      $this->_log("WARNING: no type (product, category, etc.) stored in given attribute: " . var_export($attribute, true));
      // TODO should this be an error?
      return self::PRODUCT;
    }
  }


  // returns the entity_type_id for the given attribute.
  //
  // the entity_type is used by Magento to determine the type of thing that
  // something (e.g. attribute, etc.) deals with. a product is a type of thing,
  // as is a customer, attribute, or category. there is a magento table that
  // lists all the types. we are only concerned with products and categories.
  private function _get_entity_type_id($attribute) {
    $type = $this->_get_attribute_type($attribute);
    $model = Mage::getModel('eav/entity');

    if ($type === self::PRODUCT) {
      $model->setType('catalog_product');
    } elseif ($type === self::CATEGORY) {
      $model->setType('catalog_category');
    } else {
      $this->_log("ERROR: unrecognized type id in attribute: " . var_export($attribute, true));
      return null;
    }

    return $model->getTypeId();
  }


  // returns the default attribute_set_id for the given attribute.
  //
  // Magento organizes attributes into attribute sets. these determine where in
  // the admin and site given attributes are show, what types of products they
  // are used with, etc.
  private function _get_attribute_set_id($attribute) {
    $type = $this->_get_attribute_type($attribute);

    if ($type === self::PRODUCT) {
      $model = Mage::getModel('catalog/product');
    } elseif ($type === self::CATEGORY) {
      $model = Mage::getModel('catalog/category');
    } else {
      $this->_log("ERROR: unrecognized type id in attribute: " . var_export($attribute, true));
      return null;
    }

    return $model->getResource()
                 ->getEntityType()
                 ->getDefaultAttributeSetId();
  }


  // returns the default attribute_group_id for the given entity type and
  // attribute set.
  //
  // as with attribute sets, attribute groups are affect where given attributes
  // show up in the admin, and what types of things they can be used with.
  private function _get_attribute_group_id($entity_type_id, $attribute_set_id) {
    # wish I knew a better way to do this without having to get the core setup...
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    return $setup->getDefaultAttributeGroupId($entity_type_id, $attribute_set_id);
  }


  // goes through all the categories that were seen in the import and makes
  // sure that they and their ancestors are all loaded into Magento.
  //
  // unlike most methods, this does not fail silently, but instead throws an
  // exception if something goes wrong. it is assumed that if we cannot import
  // categories it is simply not worth continuing on to import products.
  private function _import_categories() {
    $this->_log("Done parsing category data. Ensuring they are in database.");

    $categories_for_import = $this->_prepare_categories_for_import();
    if (!empty($categories_for_import)) {
      $import = Mage::getModel('fastsimpleimport/import');
      try {
        $import->processCategoryImport($categories_for_import);
      } catch (Exception $e) {
        $this->_log("ERROR: loading categories into the database. aborting load: " . $e->getMessage());
        throw $e;
      }
    }

    $this->_log("Done ensuring categories are in Magento. Number of new categories created: " . count($categories_for_import) . " new categories imported.");
  }


  // Prepares the categories for import, returning an import-friendly array.
  // Notes:
  // * Categories already in the system (as identified by salsify_category_id)
  //   will be ignored.
  // * New root categories will be loaed here on a one-off basis, since the
  //   import API doesn't seem to be able to create new roots.
  // * The array will be sorted by depth. Required by import (parents must be
  //   created before their children).
  //
  // TODO should be we be creating new roots per attribute? Right now the data
  //      we're getting takes care of rooting itself, so that feels like it
  //      would create an additional, unnatural attribute type.
  //      The biggest argument to add another level, however, is that the PATH
  //      (and therefore URL) does not include the root, and maybe it should?
  //      This greatly depends on the data, however.
  private function _prepare_categories_for_import() {
    $categories = array();
    $cleaned_categories = array();
    foreach ($this->_categories as $attribute_id => $categories_for_attribute) {
      if (in_array($attribute_id, $this->_attributes)) {
        // First time seeing this. If it exists, let's make sure to delete the
        // actual attribute from the system. The reason we do this here is so
        // that we don't have to keep all of the attributes in memory as we go
        // through the prior attributes section. So we just assume that they're
        // all valid and then delete here.
        $this->_delete_attribute_from_salsify_id($attribute_id);
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
      if (in_array($this->_relationship_attributes($category['attribute_id']))) {
        // don't bother loading categories for accessory attributes
        // TODO can a single category hierarchy be used for both products AND
        //      accessory relationships? if so, it might be foolish of us to
        //      not load them here...
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
          $this->_log($msg);
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
               ->loadByAttribute(self::SALSIFY_CATEGORY_ID, $category['id']);
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

    // TODO figure out how to encode front-slashes so that the import API will
    //      allow them in the path.
    $category['name'] = preg_replace('/\//', '|', $category['name']);

    $id = $category['id'];
    $attribute_id = $category['attribute_id'];
    if (array_key_exists('parent_id', $category)) {
      $parent_id = $category['parent_id'];
      if (!array_key_exists($parent_id, $this->_categories[$attribute_id])) {
        $this->_log("WARNING: parent_id for category refers to an unknown parent. Skipping: " . var_export($category, true));
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
        $category['__path']  = $category['name'];
      } else {
        $category['__path']  = $parent_category['__path'] . '/' . $category['name'];
      }
    } else {
      // root category
      $category['__root']  = $category['name'];
      $category['__depth'] = 0;
      $category['__path']  = $category['name'];
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
      'name' => $category['name'],
      'description' => 'Created during import from Salsify.',
      'is_active' => 'yes',
      'include_in_menu' => 'yes',
      'position' => '1',

      // 'Price' is safe since it comes OOTB
      'available_sort_by' => 'Price',
      'default_sort_by' => 'Price',

      // optional
      'url_key' => $this->_get_url_key($category),
      // 'meta_description' => $category['name'],

      self::SALSIFY_CATEGORY_ID => $category['id'],
    );
  }


  // creates a URL-friendly key for the given category.
  //
  // it will replace whitespace with friendlier dashes, lowerase the string,
  // and urlencode the result in case there are unfriendly characters in the
  // name.
  private function _get_url_key($category) {
    $key = strtolower($category['name']);
    $key = preg_replace('/\s\s+/', '-', $key);
    return urlencode($key);
  }


  // self-explanatory.
  private function _sort_categories_by_depth($categories) {
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
    $attribute_id = $category['attribute_id'];
    $id = $category['id'];
    return $this->_categories[$attribute_id][$id]['__path'];
  }


  // returns the newly created Magento Category model instance if successful,
  // or null if there was some problem in creating the category.
  //
  // TODO can't update existing categories (i.e. re-parent)
  private function _create_category($category) {
    $dbcategory = new Mage_Catalog_Model_Category();

    // TODO we're currently ignoring this. I *think* doing so sets the default
    //      store. Either way at some point we need to support multi-store.
    // $category->setStoreId(0);

    $dbcategory->setName($category['name']);
    $dbcategory->setSalsifyCategoryId($category['id']);
    $dbcategory->setDescription('Created during Salsify import.');

    // TODO what are the other options? is this a reasonable default that I
    //      should be picking?
    $dbcategory->setDisplayMode('PRODUCTS_AND_PAGE');

    $dbcategory->setIsActive('1');
    $dbcategory->setIncludeInMenu('1');

    // TODO what is this?
    $dbcategory->setIsAnchor('0');

    if (array_key_exists('parent_id', $category)) {
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

    // TODO this broke things. I wouldn't care except that there is a column in
    //      the database for this value which seems to be set for the demo data.
    //
    // $attribute_set_id = $parent_category->getResource()
    //                                     ->getEntityType()
    //                                     ->getDefaultAttributeSetId();
    // $model->setAttributeSetId($attribute_set_id);

    try {
      $dbcategory->save();
      return $dbcategory;
    } catch (Exception $e) {
      $this->_log("ERROR: creating category: " . $e->getMessage());
      return null;
    }
  }

  // helper function for _create_category. probably not necessary since that
  // is only now used to create root categories, which have no parents. however,
  // i like to keep it around since the method can be used (and has been) to
  // create the entire category hierarchy in Magento.
  private function _get_parent_category($category) {
    if (array_key_exists('parent_id', $category)) {
      $parent_id = $category['parent_id'];
      $attribute_id = $category['attribute_id'];
      if (array_key_exists($parent_id, $this->_categories[$attribute_id])) {
        return $this->_categories[$attribute_id][$parent_id];
      }
      $this->_log("ERROR: parent_id mentioned in category but not seen in import: " . var_export($category, true));
    }
    return null;
  }
}