<?php

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'DJJob');
require_once('DJJob.php');

class Salsify_Connect_Adminhtml_ManageImportsController extends Mage_Adminhtml_Controller_action {


  // All you need to add is the action!
  const BASE_ADMIN_URL = 'salsify/adminhtml_manageimports/';


  const INDEX_MENU_ID  = 'salsify_connect_menu/manage_imports';
  const CONFIG_MENU_ID = 'salsify_connect_menu/configuration';


  // FIXME factor into a log helper
  private function _log($msg) {
    Mage::log('ManageImports: ' . $msg, null, 'salsify.log', true);
  }


  private function _get_url($action) {
    return Mage::helper("adminhtml")->getUrl(self::BASE_ADMIN_URL . $action);
  }


  private function _start_render($menu_id) {
    $this->_log('rendering '.$menu_id);

    $this->loadLayout();
    $this->_setActiveMenu($menu_id);

    // add a left block to the layout
    // FIXME add links to the menu items for easy use on the left
    //       not sure how to create a link in magento to a resource, though...
    $this->_addLeft($this->getLayout()
                         ->createBlock('core/text')
                         ->setText('<h1>FUTURE SITE OF SWEET MENU</h1>'));
  }


  private function _render_html($html) {
    $block = $this->getLayout()
                  ->createBlock('core/text')
                  ->setText($html);
    $this->_addContent($block);
  }


  private function _render_js($js) {
    $jsblock = $this->getLayout()
                    ->createBlock('core/text')
                    ->setText($js);
    $this->_addJs($jsblock);
  }


  private function _end_render() {
    $this->renderLayout();
  }

 
  // FIXME remove when we're going live. this is just for testing.
  public function testAction() {
    $this->_start_render('salsify_connect_menu/test');

    $loader = Mage::helper('salsify_connect/loader');
    // called just to make sure that the salsify external id exists
    $loader->start_document();

    $this->test_load_categories();

    $this->_end_render();
  }


  private function test_load_categories() {
    $data = array();
    $data[] = array(
      '_root' => 'Root Catalog',
      '_category' => 'Test2',
      'name' => 'Test2',
      'description' => 'Test2',
      'is_active' => 'yes',
      'include_in_menu' => 'yes',
      // 'meta_description' => 'Meta Test',
      'available_sort_by' => 'Price',
      'default_sort_by' => 'Price',
      'position' => '1',
    );
    $data[] = array(
      '_root' => 'Root Catalog',
      '_category' => 'Test2/Test3',
      'name' => 'TestTest',
      'description' => 'Test3',
      'is_active' => 'yes',
      'include_in_menu' => 'yes',
      // 'meta_description' => 'Meta Test',
      'available_sort_by' => 'Price',
      'default_sort_by' => 'Price',
      'position' => '1',
    );

    /** @var $import AvS_FastSimpleImport_Model_Import */
    $import = Mage::getModel('fastsimpleimport/import');
    try {
      $import->processCategoryImport($data);
    } catch (Exception $e) {
      $this->_log("ERROR: " . $e->getMessage());
    }
  }


  public function indexAction() {
    $this->_start_render(self::INDEX_MENU_ID);

    // FIXME load the block that we want to load programatically
    //       try to load the Salsify_Connect_Block_Adminhtml_ManageImports
    //       block as it SHOULD be able to do the trick...
    $configurl = $this->_get_url('config');
    $usage = '<h1>Import Process</h1>'
           . '<ul>'
           . '  <li><a href="'.$configurl.'">Create a configuration</a></li>'
           . '</ul>';
    $this->_render_html($usage);


    // FIXME how do we get the block to show here?

    // saw this:
    // $this->getLayout()->getBlock('head')->setCanLoadExtJs(true);

    // tried this:
    // $imports_block = Mage::getBlock('salsify_connect/adminhtml_manageimports');
    // $this->_addContent($importsblock);

    $this->_end_render();
  }


  public function configurationAction() {
    $this->_start_render(self::CONFIG_MENU_ID);

    // must render this via a block
    $this->_render_html('<h1>COMING SOON!</h1>');

    $this->_end_render();
  }


  // FIXME only used for development purposes. remove once no longer necessary.
  public function testloadAction() {
    $this->_start_render('salsify_connect_menu/testload');
    
    $salsify = Mage::helper('salsify_connect');
    $file = BP.DS.'var'.DS.'salsify'.DS.'simple3.json';
    $salsify->load_data($file);

    $this->_render_html("<h1>Import Succeeded</h1>");
    $this->_render_html("Imported from: " . $file);

    $this->_end_render();
  }


  // FIXME make this into a form that the user can use to enter a configuration
  public function configAction() {
    $this->_start_render('salsify_connect_menu/config');

    // $params = $this->getRequest()->getParams();

    // if (!array_key_exists('api_key', $params)) {
    //   throw new Exception("Must specify api_key to use for import.");
    // }
    // $api_key = $params['api_key'];

    // if (!array_key_exists('salsify_url', $params)) {
    //   throw new Exception("Must specify salsify_url to use for import.");
    // }
    // $url = urldecode($params['salsify_url']);

    $api_key = 'yNoKZx9UabqqQ1m2c6K2';
    $url = 'http://localhost:5000/';

    $config = Mage::getModel('salsify_connect/configuration');
    $config->setApiKey($api_key);
    $config->setUrl($url);
    $config->save();

    $id = $config->getId();
    $import_url = $this->_get_url('import') . '?config=' . $id;

    $this->_render_html('<h1>Configuration created: ' . $id . '</h1>');
    $this->_render_html('Next: <a href="'.$import_url.'">Kick off import</a>');

    $this->_end_render();
  }

  // FIXME remove for dev
  public function importAction() {
    $params = $this->getRequest()->getParams();
    if (!array_key_exists('config', $params)) {
      throw new Exception("Must specify configuration ID to use for import.");
    }
    $config_id = $params['config'];

    $model = Mage::getModel('salsify_connect/importrun');
    $model->setConfigurationId($config_id);
    $model->save();
    $model->start_import();

    $url = $this->_get_url('chimport') . '?id=' . $model->getId();
    $this->_redirectUrl($url);
  }


  // FIXME make this into some kind of polling/monitoring/restful thing that
  //       is called by JS from the manage_imports main area
  public function chimportAction() {
    $this->_start_render('salsify_connect_menu/chimport');

    $params = $this->getRequest()->getParams();
    $import_id = $params['id'];

    $import = Mage::getModel('salsify_connect/importrun');
    $import->load((int)$import_id);
    if (!$import->getId()) {
      throw new Exception("Must specify a valid import ID.");
    }
    $this->_render_html("Current status: " . $import->get_status_string() . '<br/>');

    if (!$import->is_done()) {
      $this->_render_html("<br/><br/>Attempting next stage...");
      if($import->is_waiting_on_salsify()) {
        $advanced = $import->start_download_if_ready();
        if (!$advanced) {
          $this->_render_html('<br/>Still waiting on Salsify.');
        } else {
          $this->_render_html('<br/>Download is ready. Enqueued background job to complete import.');
          $this->sneaky_worker_thread_start();
        }
      } elseif ($import->is_waiting_on_worker()) {
        $this->_render_html('<br/>Still waiting on background worker to pick up the job.');
        $this->sneaky_worker_thread_start();
      }
    }

    $url = $this->_get_url('chimport') . '?id=' . $import_id;
    $this->_render_html('<br><a href="'.$url.'">Re-chimport</a>');

    $this->_end_render();
  }

  private function sneaky_worker_thread_start() {
    $worker_url = $this->_get_url('worker');

    // TODO add a local jquery fallback (mostly for offline testing)
    $jquery = '<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>';
    $sneaky = '<script type="text/javascript">$.get("'.$worker_url.'");</script>';
    $this->_render_js($jquery);
    $this->_render_js($sneaky);
  }


  public function workerAction() {
    // TODO add a one-time token or something like that to enable this to be
    //      called safely.
    $job = Mage::getModel('salsify_connect/importjob');
    $job->start_worker();
  }


  public function cleanerAction() {
    $this->_start_render('salsify_connect_menu/cleaner');

    $this->_render_html("Salsify Cleaner");

    $salsify_products = Mage::getModel('catalog/product')
                            ->getCollection()
                            ->addFieldToFilter('price', array("eq"=>0.0100));
    $this->_render_html("Total Salsify products deleted: " . count($salsify_products) . '<br/>');
    foreach($salsify_products as $product) { $product->delete(); }

    // TODO delete all the properties programtically.
    // In mysql:
    // delete from eav_entity_attribute where attribute_id IN (select attribute_id from eav_attribute where attribute_code like 'salsify%');
    // delete from eav_attribute where attribute_code like 'salsify%';
    //
    // $salsify_attributes = Mage::getModel('catalog/resource_eav_attribute')
    //                           ->getCollection()
    //                           ->addFieldToFilter('attribute_code', array('like'=>'salsify_%'));
    // $this->_render_html("Total Salsify attributes deleted: " . count($salsify_attributes) . '<br/>');
    // foreach($salsify_attributes as $attribute) { $attribute->delete(); }

    // TODO clear out the jobs table as well.

    $this->_end_render();
  }

}