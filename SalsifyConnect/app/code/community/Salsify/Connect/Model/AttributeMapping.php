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
 * NOTE if a multiple properites map to a single property (in either direction)
 *      this does not work.
 * TODO throw an error if this is detected.
 */
class Salsify_Connect_Model_AttributeMapping
      extends Mage_Core_Model_Abstract
{

  private static function _log($msg) {
    Mage::log("Salsify_Connect_Model_AttributeMapping" . ': ' . $msg, null, 'salsify.log', true);
  }


  const SALSIFY_ATTRIBUTE_PREFIX = 'salsify_';


  // attribute_codes for attributes that store the Salsify IDs within Magento
  // for various object types.
  const SALSIFY_CATEGORY_ID                = 'salsify_category_id';
  const SALSIFY_CATEGORY_ID_NAME           = 'Salsify Category ID';
  const SALSIFY_CATEGORY_ATTRIBUTE_ID      = 'salsify_attribute_id';
  const SALSIFY_CATEGORY_ATTRIBUTE_ID_NAME = "Salsify Attribute ID";
  const SALSIFY_PRODUCT_ID                 = 'salsify_product_id';
  const SALSIFY_PRODUCT_ID_NAME            = 'Salsify Product ID';

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
  // $role is product_id, product_name, relation_type
  public static function getCodeForId($id, $role, $type = self::PRODUCT) {
    // try to look up in the DB to see if the mapping already exists
    $mapping = Mage::getModel('salsify_connect/attributemapping')
                   ->loadBySalsifyId($id);
    if ($mapping->id) {
      return $mapping->getCode();
    }

    // there are some special attributes that Magento treats differently from
    // and admin and UI perspective, e.g. name, id, etc. right now there are a
    // couple that map directly to salsify role. we'll have to do this until
    // we have a more broad mapping capability.

    if ($role === 'product_id') {
      self::_create_mapping($id, 'sku');
      return 'sku';
    } elseif ($role === 'product_name') {
      self::_create_mapping($id, 'name');
      return 'name';
    }

    // note: we can't save these mappings yet since we can't handle multiple
    // attributes mapping to a single attributes (in this case external ID for
    // Salsify).
    if ($id === self::SALSIFY_PRODUCT_ID) {
      return self::SALSIFY_PRODUCT_ID;
    } elseif ($id === self::SALSIFY_CATEGORY_ID) {
      return self::SALSIFY_CATEGORY_ID;
    } elseif ($id === self::SALSIFY_CATEGORY_ATTRIBUTE_ID) {
      return self::SALSIFY_CATEGORY_ATTRIBUTE_ID;
    }

    // doesn't seem to exist. create a mapping and persist for the future.
    $code = self::_create_attribute_code_from_salsify_id($id, $type);
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

    // TODO we're skipping export of this right now...but do we actually need
    //      this property at all given that the Salsify external ID is mapping
    //      to the SKU? or will that NOT actually be the case at some point?
    //      if it's not then we're no longer talking about a 1-1 mapping between
    //      products in salsify and magento...
    // if ($code === self::SALSIFY_PRODUCT_ID) {
    //   return self::getIdForCode('sku');
    // } else
    if ($code === self::SALSIFY_CATEGORY_ID) {
      return 'id';
    } elseif ($code === self::SALSIFY_CATEGORY_ATTRIBUTE_ID) {
      return 'attribute_id';
    }

    // no existing mapping exists. create one for posterity
    self::_create_mapping($code, $code);
    return $code;
  }


  // returns the salsify:role for the attribute with the given magento code.
  //
  // note that we don't have to do the accessory category stuff here since it's
  // dealt with in AccessorycategoryMapping
  public static function getRoleForMagentoCode($code) {
    if ($code === 'sku') {
      return 'product_id';
    } elseif ($code === 'name') {
      return 'product_name';
    } else {
      return null;
    }
  }


  // attribute with target_product_id role
  public static function getAttributeForAccessoryIds() {
    return 'salsify:target_product_id';
  }


  // this is the property that products use to refer to categories in magento
  public static function getCategoryAssignemntMagentoCode() {
    return 'category_ids';
  }


  // returns whether the given attribute code is 'owned' by magento and so
  // should not be imported.
  public static function isAttributeMagentoOwned($code) {
    $mag_attrs = self::getMagentoOwnedAttributeCodes();
    return in_array($code, $mag_attrs);
  }

  // this list was build up by trial-and-error. these are the attributes that
  // come back as part of the product attribute list that should be owned by
  // magento.
  //
  // in the future this should be configurable somehow by the user, especially
  // since there will no doubt be attriubtes created by the user (and therefore
  // unknowable by us in advance) that should not be exported to Salsify.
  //
  // right now we're not even exporting these to Salsify; they live in Magento
  // and are only visible in Magento. we'd have to think about how we want to
  // handle properties like this in general in terms of Salsify behavior (maybe
  // Salsify can get the properties but not export them, or Magento ignores
  // values for these properties if they come as part of an import, or something
  // like that).
  public static function getMagentoOwnedAttributeCodes() {
    return array(
      '_store',
      '_product_websites',

      'entity_id',
      'entity_type_id',
      'created_at',
      'updated_at',

      '_attribute_set',
      'attribute_set_id',
      '_category',
      'category_ids',
      '_type',
      'type_id',

      '_media_image',
      'image',
      'image_label',
      'small_image',
      'small_image_label',
      'thumbnail',
      'thumbnail_label',

      'options_container',
      'has_options',
      'required_options',

      'msrp_enabled',
      'msrp_display_actual_price_type',

      'visibility',
      'status',
      'url_key',
      'enable_googlecheckout',
      'special_from_date',

      // NOTE we ARE pushing out price, but these are more marketing campaign-
      //      focused. unclear what we gain by having them in Salsify unless
      //      they are used in rules. Either way, avoiding for now.
      'group_price',
      'group_price_changed',
      'tier_price',
      'tier_price_changed',

      'stock_item',
      'is_in_stock',
      'is_salable',
      'tax_class_id',
    );
  }


  // casts the value appropriately based on the magento backend type for a given
  // attribute
  public static function castValueByBackendType($value, $type) {
    if ($type == 'int') {
      if ($value) {
        $value = (int)$value;
      } else {
        $value = 0;
      }
    } elseif ($value == 'decimal') {
      if ($value) {
        $value = (float)$value;
      } else {
        $value = 0.0;
      }
    } elseif ($type == 'varchar' || $type == 'string') {
      if (!$value) {
        $value = '';
      }
    } elseif ($type == 'static') {
      if (!$value) {
        // this should basically not happen. these seem to be internal attributes
        // owned by magento and mostly are in the 'do not bother' list above.
        $value = '';
      }
    }

    return $value;
  }


  // returns an array of key => value for attributes that must be present for
  // a product to be imported into the system, along with some reasonable
  // defaults to use.
  //
  // this is a fair amount of work, so we suggest that users of this class cache
  // the results of this method, which shouldn't change from call to call.
  public static function getRequiredProductAttributesWithDefaults() {
    // these are attributes that are marked as "required" by magento but that
    // cannot really be used during an import.
    // this set was built up by hand.
    $codes_to_ignore = array(
      "'created_at'",
      "'links_purchased_separately'",
      "'links_title'",
      "'price_type'",
      "'price_view'",
      "'samples_title'",
      "'shipment_type'",
      "'sku_type'",
      "'updated_at'",
      "'weight_type'",
    );

    $product_entity_type_id = self::_getEntityTypeId(self::PRODUCT);
    $db = Mage::getSingleton('core/resource')
              ->getConnection('core_read');
    $query = "SELECT attribute_code, default_value, backend_type
              FROM eav_attribute
              WHERE is_required = true
              AND entity_type_id = '" . $product_entity_type_id . "'
              AND attribute_code NOT IN (" . implode(',', $codes_to_ignore) . ")
             ";
    $results = $db->fetchAll($query);

    // make sure that we're setting a value that is reasonable for each of the
    // attributes so that the import has the greatest chance of going through.
    $required_attributes = array();
    foreach ($results as $result) {
      $value = $result['default_value'];
      $type = $result['backend_type'];
      $value = self::castValueByBackendType($value, $type);
      $required_attributes[$result['attribute_code']] = $value;
    }

    $defaults = array(
      'sku' => null,
      '_type' => 'simple',
      '_attribute_set' => 'Default',
      '_product_websites' => 'base',

      'price' => 0.01,
      'qty' => 0,

      'short_description' => 'Imported from Salsify',
      'description' => 'Imported from Salsify',

      // usually 0 if not used by Magento for shipping/fulfillment.
      'weight' => 0,

      // 1 => Enabled
      'status' => 1,

      // 4 => Catalog, Search (e.g. visible everywhere)
      'visibility' => 4,

      // 2 => 'Taxable Goods'
      // TODO should this be 'None' or 'Shipping'?
      'tax_class_id' => 2,
    );

    foreach ($defaults as $code => $value) {
      if (!array_key_exists($code, $required_attributes) ||
          !$required_attributes[$code])
      {
        $required_attributes[$code] = $value;
      }
    }

    return $required_attributes;
  }


  // Salsify is much more permissive when it comes to codes/ids than is Magento.
  // Magento cannot handle spaces, and the codes must be no more than 30 chars
  // long. By convention they are also lowercase.
  //
  // This simply lowercases the Salsify ID, replaces whitespace with _'s,
  // non-ascii characters with nothing at all, and cuts the result to only 30
  // characters in length.
  //
  // If the ID happens to match a code that's already in the system, we give
  // that a shot.
  //
  // NOTE: do NOT start attribute codes with '_'. Magento treats _'s as special.
  //       the devious thing is that it will allow the creation of the attribute
  //       itself, but then FAIL on importing products that use a attribute
  //       codes that start with _. :::sigh:::
  private static function _create_attribute_code_from_salsify_id($id, $type) {
    $code = strtolower($id);
    $code = preg_replace('/\s+/', '_', $code);
    $code = preg_replace('/[^_a-zA-Z0-9]+/', '', $code);

    // TODO why doesn't this work?
    // if we have an ID that happens to match a magento ID (description,
    // short_description, weight, etc. being the most common)
    // $attribute = self::_loadAttributeByMagentoCode($type, $code);
    // if ($attribute) {
    //   $backend_type = $attribute->getBackendType();
    //   if ($backend_type == 'text') { // || $backend_type == 'varchar') {
    //     return $code;
    //   }
    // }

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


  public static function loadOrCreateCategoryAttributeBySalsifyId($id, $name, $role) {
    $mage_attribute = self::loadCategoryAttributeBySalsifyId($id, $role);
    if ($mage_attribute) {
      return $mage_attribute;
    } else {
      return self::_createCategoryAttribute($id, $name, $role);
    }
  }

  public static function loadOrCreateProductAttributeBySalsifyId($id, $name, $role) {
    $mage_attribute = self::loadProductAttributeBySalsifyId($id, $role);
    if ($mage_attribute) {
      return $mage_attribute;
    } else {
      return self::_createProductAttribute($id, $name, $role);
    }
  }


  public static function loadCategoryAttributeBySalsifyId($id, $role) {
    return self::_loadAttributeBySalsifyId(self::CATEGORY, $id, $role);
  }

  public static function loadProductAttributeBySalsifyId($id, $role) {
    return self::_loadAttributeBySalsifyId(self::PRODUCT, $id, $role);
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
  private static function _loadAttributeBySalsifyId($attribute_type, $id, $role) {
    $code = self::getCodeForId($id, $role);
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


  public static function deleteCategoryAttribute($id, $role) {
    self::_delete_attribute(self::CATEGORY, $id, $role);
  }

  public static function deleteProductAttribute($id, $role) {
    self::_delete_attribute(self::PRODUCT, $id, $role);
  }

  // Deletes an attribute with the given Salsify ID from the system if present.
  private static function _delete_attribute($attribute_type, $id, $role) {
    $attribute = self::_loadAttributeBySalsifyId($attribute_type, $id, $role);
    if ($attribute) {
      $attribute->delete();
    }
  }


  private static function _createCategoryAttribute($id, $name, $role) {
    return self::_createAttribute(self::CATEGORY, $id, $name, $role);
  }

  private static function _createProductAttribute($id, $name, $role) {
    return self::_createAttribute(self::PRODUCT, $id, $name, $role);
  }

  // creates the given attribute in Magento.
  //
  // thanks for starting point:
  // http://inchoo.net/ecommerce/magento/programatically-create-attribute-in-magento-useful-for-the-on-the-fly-import-system/
  //
  // More docs:
  // http://www.magentocommerce.com/wiki/5_-_modules_and_development/catalog/programmatically_adding_attributes_and_attribute_sets
  private static function _createAttribute($attribute_type, $id, $name, $role) {
    $code = self::getCodeForId($id, $role);

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

      'type' => $attribute_datatype,
      'backend_type' => $attribute_datatype,

      'frontend_input' => $frontend_type, //'boolean','text', etc.
      'frontend_label' => $name
      // # frontend_model
      // # frontend_class
      // # frontend_input_renderer
    );


    // by default have the new attribute show up and apply to all product types
    // in Magento.
    $attribute_data['apply_to'] = array('simple','grouped','configurable',
                                        'virtual','bundle','downloadable');


    // without the following the new attribute will not show up in the UI.
    // the group is the tab group when looking at the details of an object.
    if ($attribute_type === self::CATEGORY) {
      $group = 'General Information';
    } elseif ($attribute_type === self::PRODUCT) {
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
      // this shouldn't happen since, in theory, we *should* be loading an
      // existing attribute before trying to create it.
      self::_log('ERROR: could not create attribute for Salsify ID ' . $id . ': ' . $e->getMessage());
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
    self::loadOrCreateCategoryAttributeBySalsifyId(self::SALSIFY_CATEGORY_ATTRIBUTE_ID, self::SALSIFY_CATEGORY_ATTRIBUTE_ID_NAME, null);

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
          (strcasecmp($code, self::SALSIFY_CATEGORY_ATTRIBUTE_ID) === 0) ||
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


  public static function getDefaultAccessoryAttribute() {
    return "Accessory Category";
  }


  private static function _get_accessory_attribute_id() {
    $id = Salsify_Connect_Model_AccessorycategoryMapping::getSalsifyAttributeId();
    if (!$id) {
      $id = self::getDefaultAccessoryAttribute();
    }
    return $id;
  }


  // returns attributes that are for accessories (e.g. cross-sells, up-sells,
  // and related products).
  //
  // returns the array of these attributes in an array matching the salsify json
  // document format.
  public static function getAccessoryAttribute() {
    return array(
      array(
        "salsify:id" => self::_get_accessory_attribute_id(),
        "salsify:role" => "relation_type",
      )
    );
  }


  // similar to getAccessoryAttributes this returns, in an array format
  // consistent with the Salsify json document format, the set of attribute
  // values that are used in accessory relationships.
  public static function getAccessoryAttributeValues() {
    $attribute_id = self::_get_accessory_attribute_id();

    $attribute_values = array(
      array(
        "salsify:id" => Salsify_Connect_Model_AccessoryMapping::getAccessoryLabelIdForMagentoType(Salsify_Connect_Model_AccessoryMapping::CROSS_SELL),
        "salsify:name" => "Cross-sell",
        "salsify:attribute_id" => $attribute_id,
        "type" => Salsify_Connect_Model_AccessoryMapping::CROSS_SELL
      ),
      array(
        "salsify:id" => Salsify_Connect_Model_AccessoryMapping::getAccessoryLabelIdForMagentoType(Salsify_Connect_Model_AccessoryMapping::UP_SELL),
        "salsify:name" => "Up-sell",
        "salsify:attribute_id" => $attribute_id,
        "type" => Salsify_Connect_Model_AccessoryMapping::UP_SELL
      ),
      array(
        "salsify:id" => Salsify_Connect_Model_AccessoryMapping::getAccessoryLabelIdForMagentoType(Salsify_Connect_Model_AccessoryMapping::RELATED_PRODUCT),
        "salsify:name" => "Related Product",
        "salsify:attribute_id" => $attribute_id,
        "type" => Salsify_Connect_Model_AccessoryMapping::RELATED_PRODUCT
      )
    );

    foreach ($attribute_values as $attrv) {
      // make sure the mappings exist
      Salsify_Connect_Model_AccessorycategoryMapping::getOrCreateMapping(
        $attrv['salsify:attribute_id'],
        $attrv['salsify:id'],
        $attrv['type']
      );
    }

    $attribute_values = array();
    $values = Salsify_Connect_Model_AccessorycategoryMapping::getSalsifyAttributeValues();
    foreach ($values as $value) {
      $attribute_values[] = array(
        "salsify:id" => $value,
        // we can skip the name since presumably Salsify already had this
        "salsify:attribute_id" => $attribute_id
      );
    }

    return $attribute_values;
  }


  // currently not used.
  // public static function deleteAllSalsifyMappings() {
  //   $db = Mage::getSingleton('core/resource')
  //             ->getConnection('core_write');
  //   $db->query("delete from salsify_connect_attribute_mapping;");
  // }

}