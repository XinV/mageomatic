<?php
require_once BP.DS.'lib'.DS.'JsonStreamingParser'.DS.'Listener.php';

/**
 * Parser of Salsify data. Also loads into the Magento database.
 */
class Salsify_Connect_Helper_Loader extends Mage_Core_Helper_Abstract implements \JsonStreamingParser\Listener {

  // TODO factor into a log helper
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

  // For types of attributes
  const CATEGORY = 1;
  const PRODUCT  = 2;

  // For keeping track of whether specific categories were successfully loaded.
  const LOAD_FAILED        = -1;
  const LOAD_NOT_ATTEMPTED = 1;
  const LOAD_SUCCEEDED     = 2;

  // Current keys and values that we're building up. We have to do it this way
  // vs. just having a current object stack because php deals with arrays as
  // pass-by-value.
  private $_key_stack;
  private $_value_stack;
  private $_type_stack; // since php doesn't have a separate hash
  const ARRAY_TYPE  = 0;
  const OBJECT_TYPE = 1;

  // cached attributes
  private $_attributes;

  // current attribute
  private $_attribute;

  // category hierarchy
  private $_categories;

  // current category that we're building up
  private $_category;

  // Current product batch that has been read in.
  const BATCH_SIZE = 1000;
  private $_batch;

  // Current product.
  private $_product;

  // keep track of nesting level during parsing
  private $_nesting_level;
  const HEADER_NESTING_LEVEL  = 2;
  const ITEM_NESTING_LEVEL = 4;

  // keeps track of current state
  private $_in_attributes;
  private $_in_attribute_values;
  private $_in_products;


  public function start_document() {
    $this->_log("Starting document load.");

    $this->_key_stack = array();
    $this->_value_stack = array();
    $this->_type_stack = array();

    $this->_attributes = array();
    // $this->_attribute = null;

    $this->_categories = array();
    // $this->_category = null;

    $this->_batch = array();
    // $this->_product = null;

    $this->_nesting_level = 0;
    $this->_in_attributes = false;
    $this->_in_attribute_values = false;
    $this->_in_products = false;

    $this->_create_salsify_id_attributes_if_needed();

    // FIXME set the salsify_id for ALL objects coming into the system
    //       currently missing: attributes
  }


  public function end_document() {
    $this->_log("Finished document. Flushing final product data and reindexing.");

    $this->_flush_batch();
    $this->_reindex();
  }


  // Update all indexes in Magento.
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
          $this->_create_categories_if_needed();
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
    // FIXME Salsify ID is not being set on these

    // NOTE: if the attribute turns out to be a category, it will be deleted
    //       from Magento during category loading.
    $this->_create_attribute_if_needed($this->_attribute);
    unset($this->_attribute);
  }


  private function _start_category() {
    $this->_category = array();
  }

  private function _end_category() {
    $this->_categories[$this->_category['id']] = $this->_category;
    unset($this->_category);
  }


  private function _start_product() {
    $this->_product = array();
  }

  private function _end_product() {
    $clean_product = $this->_prepare_product($this->_product);
    array_push($this->_batch, $clean_product);
    unset($this->_product);

    if (count($this->_batch) > self::BATCH_SIZE) {
      $this->_flush_batch();
    }
  }

  // Prepares the product that we've loaded from Salsify for Magento.
  private function _prepare_product($product) {
    // FIXME query the system to get the full list of required attributes.
    //       otherwise the bulk import fails silently...

    // TODO when Salsify supports Kits, this will have to change.
    $product['_type'] = 'simple';

    // TODO get the attribute set from the category. we could be precalculating
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

    // FIXME figure out the best solution to get multi-valued properties into
    //       Magento. This *might* just work, but we'd have to be careful to remove
    //       commas from incoming data.
    $clean_product = array();
    foreach($product as $key => $val) {
      if (is_array($val)) {
        $val = implode(', ', $val);
      }
      $clean_product[$key] = $val;
    }
    $product = $clean_product;

    // add the Salsify ID for good measure, even though it is mapped to the sku.
    $product[self::SALSIFY_PRODUCT_ID] = $product['sku'];

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
      $key = array_pop($this->_key_stack);

      // at the root of an object
      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_attribute_values) {
        $this->_log("ERROR: in a nested object in attribute_values, but shouldn't be: " . var_export($this->_category, true));
        $this->_log("ERROR: nested thing for above: " . var_export($value,true));
      } elseif ($this->_in_products) {
        if (array_key_exists($key, $this->_attributes)) {
          $code = $this->_attribute_code($this->_attributes[$key]);
          $this->_product[$code] = $value;
        } else {
          $this->_log("ERROR: product has key of undeclared attribute: " . $key);
        }
      }
    } else {
      // within a nested object of some kind
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
  }


  // Key will always be a string
  public function key($key) {
    array_push($this->_key_stack, $key);

    if ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
      if ($key === 'attributes') {
        $this->_log("Starting to parse attributes.");
        $this->_in_attributes = true;
      } elseif ($key === 'attribute_values') {
        $this->_log("Starting to parse categories (attribute_values).");
        $this->_in_attribute_values = true;
      } elseif ($key === 'products') {
        $this->_log("Starting to parse products.");
        $this->_in_products = true;
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
        if (!array_key_exists($key, $this->_attributes)) {
          $this->_log('ERROR: skipping unrecognized property id on product: ' . $key);
        } elseif (array_key_exists($key, $this->_categories)) {
          if (array_key_exists($value, $this->_categories[$key])) {
            $category = $this->_categories[$key][$value];
            // TODO allow multiple category assignments per product
            $product[_category] = $this->_get_path($category);
          } else {
            $this->_log("ERROR: product category assignment to unknown category. Skipping: " . $key . '=' . $value);
          }
        } else {
          $attribute = $this->_attributes[$key];
          $code = $this->_attribute_code($attribute);
          $this->_product[$code] = $value;
        }
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      // unbelievable how PHP doesn't have array_peek...
      $parent = array_pop($this->_value_stack);
      $type = array_pop($this->_type_stack);
      if ($type === self::ARRAY_TYPE) {
        array_push($parent, $value);
      } elseif ($type === self::OBJECT_TYPE) {
        $key = array_pop($this->_key_stack);
        $parent[$key] = $value;
      }
      array_push($this->_value_stack, $parent);
      array_push($this->_type_stack, $type);
    }
  }


  private function _flush_batch() {
    $this->_log("Flushing product batch of size: ".count($this->_batch));

    try {
      // TODO decide which of these APIs to use. Right now I prefer the
      //      FastSimpleImport. It has a slightly nicer API, different modes,
      //      and gives much better error reporting. For example, when import
      //      was failing due to 256-character properties, FastSimple gave me
      //      error messages, but ApiImport did not.

      Mage::getSingleton('fastsimpleimport/import')
          ->setBehavior(Mage_ImportExport_Model_Import::BEHAVIOR_REPLACE)
          ->processProductImport($this->_batch);

      // $api = Mage::getModel('api_import/import_api');
      // $api->importEntities($this->_batch);

      $this->_batch = array();
    } catch (Exception $e) {
      $this->_log('ERROR could not flush batch: ' . $e->getMessage());
      $this->_log('Backtrace:' . $e->getTraceAsString());
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


  private function _create_attribute_if_needed($attribute) {
    $id = $attribute['id'];
    if (!array_key_exists($id, $this->_attributes)) {
      // TODO  when Salsify has bundles we'll have to deal with this.
      $product_type = 'simple';

      // At the moment we only get text properties from Salsify. In fact, since
      // we don't enforce datatypes in Salsify a single attribute could, in
      // theory, have a numeric value and a text value, so for now we have to
      // pick 'text' here to be safe.
      $type = 'text';
      
      $dbattribute = $this->_get_attribute($attribute);
      if (!$dbattribute) {
        $dbattribute = $this->_create_attribute($attribute, $type, $product_type);
      }

      $this->_attributes[$id] = $attribute;
    }
    return $this->_attributes[$id];
  }


  private function _delete_attribute_from_salsify_id($attribute_id) {
    $this->_log("attribute " . $attribute_id . " is really a category. deleting");

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


  private function _attribute_code($attribute) {
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
    // TODO get other OOTB type attributes via mapping from Salsify.

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
    // Could also have edited this file:
    //   /app/code/core/Mage/Eav/Model/Entity/Attribute.php
    //   CONST ATTRIBUTE_CODE_MAX_LENGTH = 60;
    $code = 'salsify_'.md5($id);
    $code = substr($code, 0, 30);
    return $code;
  }


  // Thanks http://www.sharpdotinc.com/mdost/2009/04/06/magento-getting-product-attributes-values-and-labels/
  // return database model of given attribute
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


  // Thanks to http://inchoo.net/ecommerce/magento/programatically-create-attribute-in-magento-useful-for-the-on-the-fly-import-system/
  // as a starting point.
  // More docs: http://www.magentocommerce.com/wiki/5_-_modules_and_development/catalog/programmatically_adding_attributes_and_attribute_sets
  private function _create_attribute($attribute, $attribute_type, $product_type) {
    // There are even more options that we're not setting here. For example:
    // http://alanstorm.com/magento_attribute_migration_generator

    $code = $this->_attribute_code($attribute);
    $name = $attribute['name'];

    // I *think* this is everything we COULD be setting, with some properties
    // commented out. I got values from eav_attribute and catalog_eav_attribute

    // TODO support multiple stores (see 'is_global' below)

    if ($attribute_type === 'varchar') {
      $frontend_type  = 'text';
    } else {
      $frontend_type  = $attribute_type;
    }

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
      //      really supports the simple type.
      'apply_to' => array($product_type), //array('grouped') see http://www.magentocommerce.com/wiki/modules_reference/english/mage_adminhtml/catalog_product/producttype
    );

    $model = Mage::getModel('catalog/resource_eav_attribute');

    $default_value_field = $model->getDefaultValueByInput($attribute_data['frontend_input']);
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
      $this->_log('ERROR: could not create attribute <'.$name.'>: '.$e->getMessage());
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
      return self::PRODUCT;
    }
  }


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


  private function _get_attribute_group_id($entity_type_id, $attribute_set_id) {
    # wish I knew a better way to do this without having to get the core setup...
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    return $setup->getDefaultAttributeGroupId($entity_type_id, $attribute_set_id);
  }


  // This goes through all the categories that were seen in the import and makes
  // sure that they and their ancestors are all loaded into Magento.
  private function _create_categories_if_needed() {
    $this->_log("finished parsing category data. now loading into database.");

    $this->_prepare_category_hierarchy();
    // after preparing the categories, the categories are grouped by the
    // attribute that they're associated with.
    // TODO we should be organizing them like this all along during parsing to
    //      make the treatment of _categories more consistent and maintainable.
    // TODO should be we be creating new roots per attribute? Right now the data
    //      we're getting takes care of rooting itself, so that feels like it
    //      would create an additional, unnatural attribute type.
    foreach ($this->_categories as $attribute_id => $categories) {
      foreach ($categories as $category) {
        $id = $category['id'];
        $this->_create_category_and_ancestors($attribute_id, $category);
      }
    }

    $this->_log("done loading categories into database.");
  }


  // Divides all the category values by attribute_id. Makes sure each category
  // value has __load_status set to false (keeps track of whether or not it's been
  // loaded into the DB). Makes sure all child values for each category value
  // are set.
  private function _prepare_category_hierarchy() {
    $prepped_categories = array();
    foreach ($this->_categories as $id => $category) {
      if (!array_key_exists('attribute_id', $category)) {
        $this->_log("ERROR: no attribute_id specified for category: " . var_export($category, true));
        continue;
      }
      $attribute_id = $category['attribute_id'];

      if (in_array($attribute_id, $this->_attributes)) {
        // First time seeing this. If it exists, let's make sure to delete the
        // actual attribute from the system. The reason we do this here is so
        // that we don't have to keep all of the attributes in memory as we go
        // through the prior attributes section. So we just assume that they're
        // all valid and then delete here.
        $this->_delete_attribute_from_salsify_id($attribute_id);
      }

      if (array_key_exists($attribute_id, $prepped_categories)) {
        $categories = $prepped_categories[$attribute_id];
      } else {
        $categories = array();
      }

      if (!array_key_exists('name', $category)) {
        $this->_log("WARNING: name not given for category. using ID as name: " . var_export($category, true));
        $category['name'] = $category['id'];
      }

      // can't used _set_load_status here since we're about to overwrite the 
      // global _categories variable.
      $category['__load_status'] = self::LOAD_NOT_ATTEMPTED;
      $categories[$id] = $category;
      $prepped_categories[$attribute_id] = $categories;
    }

    $this->_categories = $prepped_categories;
  }


  // returns the database model category for the given category if it exists.
  //         null otherwise.
  private function _get_category($category) {
    return Mage::getModel('catalog/category')
               ->loadByAttribute(self::SALSIFY_CATEGORY_ID, $category['id']);
  }


  // ensures that a category and all of it's ancestors are in the DB. this will
  // start from the root and work its way up. returns true if successful.
  private function _create_category_and_ancestors($attribute_id, $category) {
    $load_status = $this->_get_load_status($attribute_id, $category);
    if ($load_status === self::LOAD_SUCCEEDED) {
      return true;
    } elseif ($load_status === self::LOAD_FAILED) {
      return false;
    }

    // check if the category already exists in the DB. we still go through the
    // rest of this instead of shortcutting here to make sure the entire
    // ancestry is loaded.
    $dbcategory = $this->_get_category($category);

    // first must create ancestry
    if (array_key_exists('parent_id', $category)) {
      $parent_id = $category['parent_id'];
      if (!array_key_exists($parent_id, $this->_categories[$attribute_id])) {
        $this->_log("ERROR: parent ID mentioned for category was not included in import file: " . var_export($category, true));
        $this->_set_load_status($attribute_id, $category, self::LOAD_FAILED);
        return false;
      }
      $parent_category = $this->_categories[$attribute_id][$parent_id];
      $parent_loaded = $this->_create_category_and_ancestors($attribute_id, $parent_category);
      if (!$parent_loaded) {
        $this->_log("ERROR: could not load ancestry for category: " . var_export($category, true));
        $this->_set_load_status($attribute_id, $category, self::LOAD_FAILED);
        return false;
      }

      $parent_path = $this->_get_path($parent_category);
      $this->_set_path($category, $parent_path . '/' . $category['name']);
    } else {
      $this->_set_path($category, $category['name']);
    }

    // finally, create the category if it hasn't been created
    if (!$dbcategory) {
      $dbcategory = $this->_create_category($attribute_id, $category);
    }
    if ($dbcategory) {
      $this->_set_load_status($attribute_id, $category, self::LOAD_SUCCEEDED);
      return true;
    } else {
      $this->_set_load_status($attribute_id, $category, self::LOAD_FAILED);
      return false;
    }
  }


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

  private function _get_path($category) {
    $attribute_id = $category['attribute_id'];
    return $this->_categories[$attribute_id][$category['id']]['__path'];
  }

  private function _set_path($category, $path) {
    $attribute_id = $category['attribute_id'];
    $this->_categories[$attribute_id][$category['id']]['__path'] = $path;
  }

  private function _get_load_status($attribute_id, $category) {
    return $this->_categories[$attribute_id][$category['id']]['__load_status'];
  }

  private function _set_load_status($attribute_id, $category, $load_status) {
    $this->_categories[$attribute_id][$category['id']]['__load_status'] = $load_status;
  }


  // returns the Magento Category model instance if successful, null if not.
  //
  // TODO can't update existing categories (i.e. re-parent)
  private function _create_category($attribute_id, $category) {
    $dbcategory = new Mage_Catalog_Model_Category();

    // TODO we're currently ignoring this. I *think* doing so sets the default
    //      store. Either way at some point we need to support multiple stores.
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

    // FIXME this seemed to fuck things up
    // $attribute_set_id = $parent_category->getResource()
    //                                     ->getEntityType()
    //                                     ->getDefaultAttributeSetId();
    // $model->setAttributeSetId($attribute_set_id);

    // FIXME need this?
    // $attributeGroupId = $setup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
    // $model->setAttributeGroupId($attributeGroupId);

    try {
      $dbcategory->save();
      return $dbcategory;
    } catch (Exception $e) {
      $this->_log("ERROR creating category (will not try entire tree): " . $e->getMessage());
      return null;
    }
  }

  // creates a URL-friendly key for this category. it will replace whitespace
  // with friendlier dashes, lowerase the string, and urlencode it in case there
  // are unfriendly characters in the name.
  private function _get_url_key($category) {
    if (array_key_exists('name', $category)) {
      $key = $category['name'];
    } else {
      $key = $category ['id'];
    }
    $key = strtolower($key);
    $key = preg_replace('/\s\s+/', '-', $key);
    return urlencode($key);
  }
}