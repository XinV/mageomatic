<?php

/**
 * Maintains and persists the mappings between Salsify attribute IDs and Magento
 * attributes codes.
 *
 * There is lots of fucntionality here around creating and maintaining attributes
 * that could be moved to a Helper or something, but we're leaving it here for
 * convenience since most of it relies on the mapping and constants related to
 * the mapping.
 *
 * TODO: keep track of attributes created here in its own table instead of
 *       relying on the existing attribute_code starting with salsify_
 *       convention.
 *
 * TODO: if a multiple properites map to a single property (in either direction)
 *       this does not work.
 */
class Salsify_Connect_Model_AttributeMapping extends Mage_Core_Model_Abstract {

  private static function _log($msg) {
    Mage::log('AttributeMapping: ' . $msg, null, 'salsify.log', true);
  }


  const SALSIFY_ATTRIBUTE_PREFIX = 'salsify_';


  // attribute_codes for attributes that store the Salsify IDs within Magento
  // for various object types.
  const SALSIFY_CATEGORY_ID      = 'salsify_category_id';
  const SALSIFY_CATEGORY_ID_NAME = 'Salsify Category ID';
  const SALSIFY_PRODUCT_ID       = 'salsify_product_id';
  const SALSIFY_PRODUCT_ID_NAME  = 'Salsify Product ID';

  public static function getSalsifyProductIdAttributeCode() {
    return self::SALSIFY_PRODUCT_ID;
  }


  // For types of attributes. In Magento's EAV struction attributes of products,
  // categories, customers, etc., are stored in different EAV tables.
  const CATEGORY = 1;
  const PRODUCT  = 2;
  // const CUSTOMER = 3; for example. you could have as many as there are
  //                     Magento entity types.


  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/attributemapping');
  }


  // thanks
  // http://stackoverflow.com/questions/3197239/magento-select-from-database
  public function loadByMagentoCode($code) {
    $this->setId(null)->load($code, 'code');
    return $this;
  }

  public function loadBySalsifyId($id) {
    $this->setId(null)->load($id, 'salsify_id');
    return $this;
  }


  // $id is the salsify id
  //
  // $roles is an array that follows the structure of roles from a Salsify json
  //        document. so there are nested arrays for 'products' roles, 'global'
  //        roles, etc.
  //
  // TODO have a more broad mapping mapping strategy from salsify attributes
  //      to Magento roles.
  public static function getCodeForId($id, $roles) {
    // try to look up in the DB to see if the mapping already exists
    $mapping = Mage::getModel('salsify_connect/attributemapping')
                   ->loadBySalsifyId($id);
    if ($mapping->id) {
      return $mapping->getCode();
    }

    // there are some special attributes that Magento treats differently from
    // and admin and UI perspective, e.g. name, id, etc. right now there are a
    // couple that map directly to salsify roles. we'll have to do this until
    // we have a more broad mapping capability.

    if ($roles) {
      if (array_key_exists('products', $roles)) {
        $product_roles = $roles['products'];
        if (in_array('id', $product_roles)) {
          self::_create_mapping($id, 'sku');
          return 'sku';
        }
        if (in_array('name', $product_roles)) {
          self::_create_mapping($id, 'name');
          return 'name';
        }
      }
    }

    // TODO: we can't save these mappings yet since we can't handle multiple
    //       attributes mapping to a single attributes (in this case external ID
    //       for Salsify).
    if ($id === self::SALSIFY_PRODUCT_ID) {
      return self::SALSIFY_PRODUCT_ID;
    } elseif ($id === self::SALSIFY_CATEGORY_ID) {
      return self::SALSIFY_CATEGORY_ID;
    }

    // doesn't seem to exist. create a mapping and persist for the future.
    $code = self::_create_attribute_code_from_salsify_id($id);
    $mapping = self::_create_mapping($id, $code);

    return $code;
  }


  // given a Magento attribute code, returns the Salsify ID that should be used
  // for that attribute.
  public static function getIdForCode($code) {
    $mapping = Mage::getModel('salsify_connect/attributemapping')
                   ->loadByMagentoCode($code);
    $mapping_id = $mapping->getId();
    if ($mapping_id) {
      return $mapping->getSalsifyId();
    }

    // TODO: we're skipping export of this right now...but do we actually need
    //       this property at all given that the Salsify external ID is mapping
    //       to the SKU? or will that NOT actually be the case at some point?
    //       if it's not then we're no longer talking about a 1-1 mapping between
    //       products in salsify and magento...
    // if ($code === self::SALSIFY_PRODUCT_ID) {
    //   return self::getIdForCode('sku');
    // } else
    if ($code === self::SALSIFY_CATEGORY_ID) {
      return 'id';
    }

    // no existing mapping exists. create one for posterity
    self::_create_mapping($code, $code);
    return $code;
  }


  // returns an array as per Salsify json format with roles for the attribute
  // with the given code.
  // "roles":{"products":["id"],"accessories":["target_product_id"]}
  public static function getRolesForMagentoCode($code) {
    $roles = array();

    if ($code === 'sku') {
      $roles['products'] = array();
      array_push($roles['products'], 'id');
    }

    if ($code === 'name') {
      $roles['products'] = array();
      array_push($roles['products'], 'name');
    }

    // FIXME not correct. we need a separate category for accessories
    // if ($code === self::getCategoryAssignemntMagentoCode()) {
    //   $roles['global'] = array();
    //   array_push($roles['global'], 'accessory_label');
    // }

    if (empty($roles)) {
      return null;
    } else {
      return $roles;
    }
  }


  // this is the property that products use to refer to categories in magento
  // TODO need to create a mapping for products to this during ingest
  public static function getCategoryAssignemntMagentoCode() {
    return 'category_ids';
  }


  // Salsify is much more permissive when it comes to codes/ids than is Magento.
  // Magento cannot handle spaces, and the codes must be no more than 30 chars
  // long. By convention they are also lowercase.
  //
  // This simply lowercases the Salsify ID, replaces whitespace with _'s,
  // non-ascii characters with nothing at all, and cuts the result to only 30
  // characters in length.
  //
  // Without further intervention, the result might intersect with built-in
  // Magento properties (e.g. sku -> sku), so we add a leading _s_ just in case.
  //
  // NOTE: do NOT start attribute codes with '_'. Magento treats _'s as special.
  //       the devious thing is that it will allow the creation of the attribute
  //       itself, but then FAIL on importing products that use a attribute
  //       codes that start with _. :::sigh:::
  //
  // TODO: once we have a more robust mapping mechanism from Salsify to Magento
  //       properties we shouldn't require the _s_ prefix.
  private static function _create_attribute_code_from_salsify_id($id) {
    $code = strtolower($id);
    $code = preg_replace('/\s+/', '_', $code);
    $code = preg_replace('/[^_a-zA-Z0-9]+/', '', $code);
    $code = substr(self::SALSIFY_ATTRIBUTE_PREFIX . $code, 0, 30);
    return $code;
  }


  // Creates a new mapping in the DB.
  private static function _create_mapping($id, $code) {
    $mapping = Mage::getModel('salsify_connect/attributemapping');
    $mapping->setSalsifyId($id);
    $mapping->setCode($code);
    $mapping->save();
    return $mapping;
  }


  public static function loadOrCreateCategoryAttributeBySalsifyId($id, $name, $roles) {
    $mage_attribute = self::loadCategoryAttributeBySalsifyId($id, $roles);
    if ($mage_attribute) {
      return $mage_attribute;
    } else {
      return self::_createCategoryAttribute($id, $name, $roles);
    }
  }

  public static function loadOrCreateProductAttributeBySalsifyId($id, $name, $roles) {
    $mage_attribute = self::loadProductAttributeBySalsifyId($id, $roles);
    if ($mage_attribute) {
      return $mage_attribute;
    } else {
      return self::_createProductAttribute($id, $name, $roles);
    }
  }


  public static function loadCategoryAttributeBySalsifyId($id, $roles) {
    return self::_loadAttributeBySalsifyId(self::CATEGORY, $id, $roles);
  }

  public static function loadProductAttributeBySalsifyId($id, $roles) {
    return self::_loadAttributeBySalsifyId(self::PRODUCT, $id, $roles);
  }

  public static function loadCategoryAttributeByMagentoCode($code) {
    return self::_loadAttributeByMagentoCode(self::CATEGORY, $code);
  }

  public static function loadProductAttributeByMagentoCode($code) {
    return self::_loadAttributeByMagentoCode(self::PRODUCT, $code);
  }

  // returns the attribute for the given Salsify ID, or null if the attribute
  // does not exist.
  //
  // return database model of given attribute
  private static function _loadAttributeBySalsifyId($attribute_type, $id, $roles) {
    $code = self::getCodeForId($id, $roles);
    return self::_loadAttributeByMagentoCode($attribute_type, $code);
  }

  // return database model of given attribute
  // Thanks http://www.sharpdotinc.com/mdost/2009/04/06/magento-getting-product-attributes-values-and-labels/
  private static function _loadAttributeByMagentoCode($attribute_type, $code) {
    $eav_model = Mage::getResourceModel('eav/entity_attribute');
    if ($attribute_type === self::CATEGORY) {
      $attribute_id = $eav_model->getIdByCode('catalog_category', $code);
    } elseif ($attribute_type === self::PRODUCT) {
      $attribute_id = $eav_model->getIdByCode('catalog_product', $code);
    }

    if (!$attribute_id) {
      return null;
    }

    return Mage::getModel('catalog/resource_eav_attribute')
               ->load($attribute_id);
  }


  public static function deleteCategoryAttribute($id, $roles) {
    self::_delete_attribute(self::CATEGORY, $id, $roles);
  }

  public static function deleteProductAttribute($id, $roles) {
    self::_delete_attribute(self::PRODUCT, $id, $roles);
  }

  // Deletes an attribute with the given Salsify ID from the system if present.
  private static function _delete_attribute($attribute_type, $id, $roles) {
    $attribute = self::_loadAttributeBySalsifyId($attribute_type, $id, $roles);
    if ($attribute) {
      $attribute->delete();
    }
  }


  private static function _createCategoryAttribute($id, $name, $roles) {
    return self::_createAttribute(self::CATEGORY, $id, $name, $roles);
  }

  private static function _createProductAttribute($id, $name, $roles) {
    return self::_createAttribute(self::PRODUCT, $id, $name, $roles);
  }

  // creates the given attribute in Magento.
  //
  // thanks for starting point:
  // http://inchoo.net/ecommerce/magento/programatically-create-attribute-in-magento-useful-for-the-on-the-fly-import-system/
  //
  // More docs:
  // http://www.magentocommerce.com/wiki/5_-_modules_and_development/catalog/programmatically_adding_attributes_and_attribute_sets
  //
  // TODO: support multi-store (see 'is_global' below)
  private static function _createAttribute($attribute_type, $id, $name, $roles) {
    $code = self::getCodeForId($id, $roles);

    // At the moment we only get text properties from Salsify. In fact, since
    // we don't enforce datatypes in Salsify a single attribute could, in
    // theory, have a numeric value and a text value, so for now we have to
    // pick 'text' here to be safe.
    $attribute_datatype = 'text';
    $frontend_type  = 'text';

    // Keeping this around since it was tricky to figure out the first time.
    // if ($attribute_datatype === 'varchar') {
    //   $frontend_type  = 'text';
    // } else {
    //   $frontend_type  = $attribute_datatype;
    // }

    // I *think* this is everything we COULD be setting, with some properties
    // commented out. I got values from eav_attribute and catalog_eav_attribute
    // For example:
    // http://alanstorm.com/magento_attribute_migration_generator
    // https://makandracards.com/magento/6721-product-attribute-addition

    $attribute_data = array(
      'attribute_code' => $code,
      'note' => 'Created by Salsify',
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
      'type' => $attribute_datatype,
      'backend_type' => $attribute_datatype,

      'frontend_input' => $frontend_type, //'boolean','text', etc.
      'frontend_label' => $name
      // # frontend_model
      // # frontend_class
      // # frontend_input_renderer
    );


    // TODO apply_to multiple types by default? right now Salsify itself only
    //      really supports the simple type. also, if we leave this out it
    //      might automatically apply to everything, which is maybe what we
    //      want by default.
    $attribute_data['apply_to'] = array('simple'); //array('grouped') see http://www.magentocommerce.com/wiki/modules_reference/english/mage_adminhtml/catalog_product/producttype

    // without this it will not show up in the UI. the group is the tab group
    // when looking at the details of an object.
    if ($attribute_type == self::CATEGORY) {
      $group = 'General Information';
    } elseif ($attribute_type == self::PRODUCT) {
      $group = 'General';
    }
    $attribute_data['group'] = $group;


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
    $entity_type_id     = self::_getEntityTypeId($attribute_type);
    $attribute_set_id   = self::_getAttributeSetId($attribute_type);
    $attribute_group_id = self::_getAttributeGroupId($entity_type_id, $attribute_set_id);

    $model->setEntityTypeId($entity_type_id);
    $model->setAttributeSetId($attribute_set_id);
    $model->setAttributeGroupId($attribute_group_id);

    try {
      $model->save();
    } catch (Exception $e) {
      $this->_log('ERROR: could not create attribute for Salsify ID ' . $id . ': ' . $e->getMessage());
      return null;
    }

    return $model;
  }


  // returns the entity_type_id for the given attribute.
  //
  // the entity_type is used by Magento to determine the type of thing that
  // something (e.g. attribute, etc.) deals with. a product is a type of thing,
  // as is a customer, attribute, or category. there is a magento table that
  // lists all the types. we are only concerned with products and categories.
  private static function _getEntityTypeId($attribute_type) {
    $model = Mage::getModel('eav/entity');

    if ($attribute_type === self::CATEGORY) {
      $model->setType('catalog_category');
    } elseif ($attribute_type === self::PRODUCT) {
      $model->setType('catalog_product');
    }

    return $model->getTypeId();
  }


  // returns the default attribute_set_id for the given attribute.
  //
  // Magento organizes attributes into attribute sets. these determine where in
  // the admin and site given attributes are show, what types of products they
  // are used with, etc.
  private static function _getAttributeSetId($attribute_type) {
    if ($attribute_type === self::CATEGORY) {
      $model = Mage::getModel('catalog/category');
    } elseif ($attribute_type === self::PRODUCT) {
      $model = Mage::getModel('catalog/product');
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
  private static function _getAttributeGroupId($entity_type_id, $attribute_set_id) {
    // wish I knew a better way to do this without having to get the core setup...
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    return $setup->getDefaultAttributeGroupId($entity_type_id, $attribute_set_id);
  }


  public static function createSalsifyIdAttributes() {
    // TODO pass in is_unique into the creation to make sure that it is, in fact,
    //      unique
    // TODO figure out how to prevent the value from being editable
    //      possibly: http://stackoverflow.com/questions/6384120/magento-read-only-and-hidden-product-attributes

    self::loadOrCreateCategoryAttributeBySalsifyId(self::SALSIFY_CATEGORY_ID, self::SALSIFY_CATEGORY_ID_NAME, null);

    self::loadOrCreateProductAttributeBySalsifyId(self::SALSIFY_PRODUCT_ID, self::SALSIFY_PRODUCT_ID_NAME, null);
  }


  // deletes all attributes created by Salsify from the system. the trick is not
  // to accidentally delete attributes that come with magento or were created
  // within magento.
  //
  // returns the total number of attributes deleted.
  public static function deleteSalsifyAttributes() {
    $total_deleted = 0;
    $total_deleted += self::_deleteSalsifyAttributes(self::CATEGORY);
    $total_deleted += self::_deleteSalsifyAttributes(self::PRODUCT);
    return $total_deleted;
  }

  private static function _deleteSalsifyAttributes($attribute_type) {
    $deleted_attribute_count = 0;

    $attributes = self::_getAttributes($attribute_type);
    foreach($attributes as $attribute) {

      // NOTE: just in case, in mysql the attributes can be deleted this way too:
      //       (if you use _s_ instead of salsify for the selectors below)
      // delete from eav_entity_attribute where attribute_id IN (select attribute_id from eav_attribute where attribute_code like 'salsify%');
      // delete from eav_attribute where attribute_code like 'salsify%';

      $code = $attribute['code'];
      if ((strcasecmp(substr($code, 0, strlen(self::SALSIFY_ATTRIBUTE_PREFIX)), self::SALSIFY_ATTRIBUTE_PREFIX) === 0) ||
          (strcasecmp($code, self::SALSIFY_CATEGORY_ID) === 0) ||
          (strcasecmp($code, self::SALSIFY_PRODUCT_ID) === 0))
      {
        Mage::getModel('eav/entity_attribute')
            ->load($attribute['attribute_id'])
            ->delete();

        $deleted_attribute_count++;
      }
    }

    return $deleted_attribute_count;
  }


  // handy function that returns a collection of all product attributes in
  // Magento.
  public static function getProductAttributes() {
    return self::_getAttributes(self::PRODUCT);
  }


  // returns ALL attributes in the system for the given attribute_type
  private static function _getAttributes($attribute_type) {
    $all_attributes = array();

    if ($attribute_type === self::CATEGORY) {
      $type = 'catalog_category';
    } elseif ($attribute_type === self::PRODUCT) {
      $type = 'catalog_product';
    }
    $product_entity_type_id = Mage::getModel('eav/entity')
                                  ->setType($type)
                                  ->getTypeId();
    $attribute_set_collection = Mage::getModel('eav/entity_attribute_set')
                                    ->getCollection()
                                    ->setEntityTypeFilter($product_entity_type_id);
    
    foreach ($attribute_set_collection as $attribute_set) {
      $attributes = Mage::getModel('catalog/product_attribute_api')
                        ->items($attribute_set->getId());
      foreach($attributes as $attribute) {
        array_push($all_attributes, $attribute);
      }
    }

    return $all_attributes;
  }


  // currently not used.
  // public static function deleteAllSalsifyMappings() {
  //   $db = Mage::getSingleton('core/resource')
  //             ->getConnection('core_write');
  //   $db->query("delete from salsify_connect_attribute_mapping;");
  // }

}