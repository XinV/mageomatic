<?php
require_once BP.DS.'lib'.DS.'salsify'.DS.'MultipartUploader'.DS.'Uploader.php';

/**
 * This class is the gateway to Salsify. It should be the only class that knows
 * anything about Salsify's API (though other classes know about the Salsify
 * JSON file format).
 */
class Salsify_Connect_Helper_SalsifyAPI
      extends Mage_Core_Helper_Abstract
{

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  // Salsify auth token
  private $_api_key;

  // Base url to Salsify. Will usually be https://app.salsify.com/
  private $_base_url;


  // When we're IMPORTING from Salsify, it thinks of it as an EXPORT
  const IMPORT_FROM_SALSIFY_PATH = '/api/exports';

  // When we're EXPORTING from Magento, Salsify thinks of it as an IMPORT
  const EXPORT_TO_SALSIFY_PATH = '/api/imports';


  public function __construct() {
    $this->_config = Mage::getModel('salsify_connect/configuration')
                         ->getInstance();
    $this->_api_key = $this->_config->getApiKey();
    $this->_base_url = $this->_config->getUrl();
  }


  private function _get_url_suffix() {
    return '?format=json&auth_token=' . $this->_api_key;
  }


  private function _response_valid($response) {
    $response_code = $response->getResponseCode();
    return ($response_code >= 200 && $response_code <= 299);
  }


  // returns the Salsify service URL to start Salsify exporting its data for
  // importing into Magento
  private function _get_create_salsify_export_url() {
    return $this->_base_url . self::IMPORT_FROM_SALSIFY_PATH . $this->_get_url_suffix();
  }

  // returns the Salsify service URL to get details about a specific Salsify
  // export (Magento import).
  private function _get_salsify_export_url($salsify_export_id) {
    return $this->_base_url . self::IMPORT_FROM_SALSIFY_PATH . '/' . $salsify_export_id . $this->_get_url_suffix();
  }

  private function _get_start_salsify_export_run_url($salsify_export_id) {
    return $this->_base_url . self::IMPORT_FROM_SALSIFY_PATH . '/' . $salsify_export_id . '/runs' . $this->_get_url_suffix();
  }

  private function _get_check_salsify_export_run_url($salsify_export_run_id) {
    return $this->_base_url . self::IMPORT_FROM_SALSIFY_PATH . '/runs/' . $salsify_export_run_id . $this->_get_url_suffix();
  }

  // the first thing we need when sending data to Salsify is what it thinks of
  // as a 'mount point', which is a basically a destination to which we can send
  // the exported Magento data.
  private function _get_create_mount_url() {
    return $this->_base_url . self::EXPORT_TO_SALSIFY_PATH . '/mounts' . $this->_get_url_suffix();
  }

  // gets the base service URL for Salsify imports (Magento export)
  private function _get_create_salsify_import_url() {
    return $this->_base_url . self::EXPORT_TO_SALSIFY_PATH . $this->_get_url_suffix();
  }

  private function _get_start_salsify_import_run_url($salsify_import_id) {
    return $this->_base_url . self::EXPORT_TO_SALSIFY_PATH . '/' . $salsify_import_id . '/runs' . $this->_get_url_suffix();
  }

  private function _get_check_salsify_import_run_url($salsify_import_run_id) {
    return $this->_base_url . self::EXPORT_TO_SALSIFY_PATH . '/runs/' . $salsify_import_run_id . $this->_get_url_suffix();
  }


  public function download_product_data_from_salsify() {
    self::_log("Importing data from Salsify.");

    // first we need to create the actual import in Salsify
    self::_log("Creating export configuration in Salsify for exported data...");
    $salsify_export_id = $this->_create_salsify_export();

    // second we have to get Salsify to actually create the export
    self::_log("Kicking off export run in Salsify...");
    $salsify_export_run_id = $this->_start_salsify_export_run($salsify_export_id);

    // next we can check the status until it's done...
    self::_log("Waiting for Salsify to finish preparing the export...");
    $url = $this->_wait_for_salsify_to_finish_preparing_export($salsify_export_run_id);

    self::_log("Done downloading export from salsify");
    return $url;
  }


  // creates an actual import in Salsify that can be referred to by its token.
  //
  // TODO make this configurable with "compressed" vs. not once we figure out
  //      how to deal with GZipped stuff in PHP.
  private function _create_salsify_export() {
    if (!$this->_base_url || !$this->_api_key) {
      throw new Exception("Base URL and API key must be set to create a new export.");
    }

    $url = $this->_get_create_salsify_export_url();
    $request = new HttpRequest($url, HTTP_METH_POST);
    $request->addHeaders(array('Content-Type' => 'application/json'));
    $now = Mage::getModel('core/date')->timestamp(time());
    $request->setBody(json_encode(array(
      'filter' => null,
      'compress' => false,
      'name' => 'Export to Magento ' . date('m/d/y h:i:s', $now)
    )));
    $mes = $request->send();
    $response = json_decode($mes->getBody(),true);

    if (!$this->_response_valid($mes)) {
      $error = $response['errors'][0];
      self::_log("Error received from Salsify when creating export configuration: " . $error);
      throw new Exception("Error received from Salsify when creating export configuration: " . $error);
    }

    if (!array_key_exists('id', $response)) {
      throw new Exception("Error: no token returned when creating Salsify export.");
    }
    $token = $response['id'];
    self::_log("SUCCESS creating export. Salsify export token: " . $token);

    return $token;
  }


  private function _start_salsify_export_run($id) {
    $request = new HttpRequest($this->_get_start_salsify_export_run_url($id), HTTP_METH_POST);
    $response = $request->send();
    $response_json = json_decode($response->getBody(), true);
    if (!$this->_response_valid($response)) {
      if (array_key_exists('errors', $response_json)) {
        $error = $resopnse_json['errors'][0];
      } else {
        $error = "No details provided by Salsify.";
      }
      throw new Exception("Could not start Salsify export: " . var_export($error, true));
    }

    $id = $response_json['id'];
    self::_log("Export run started. ID: " . $id);
    return $id;
  }


  // returns the JSON document from salsify as a php array that describes what
  // the status of the import with the given token is.
  private function _get_salsify_export($id) {
    if (!$this->_base_url || !$this->_api_key) {
      throw new Exception("Base URL and API key must be set to create a new import.");
    }
    $url = $this->_get_salsify_export_url($id);
    $req = new HttpRequest($url, HTTP_METH_GET);
    $mes = $req->send();

    if (!$this->_response_valid($mes)) {
      throw new Exception("Error received from Salsify: " . $mes->getResponseStatus());
    }
self::_log("FIXME HERE");
    return json_decode($mes->getBody(), true);
  }


  // waits until salsify is done preparing the given export, and returns the URL
  // when done. throws an exception if anything funky occurs.
  private function _wait_for_salsify_to_finish_preparing_export($id) {
    do {
      sleep(5);
      $url = $this->is_salsify_done_preparing_export($id);
    } while (!$url);
    return $url;
  }


  // checks whether salsify is done preparing the data export with the given id.
  // return null if not.
  // return the url of the document if it's done.
  // throw an Exception if anything strange occurs.
  private function _is_salsify_done_preparing_export($id) {
    $export = $this->_get_salsify_export($id);

    if (!array_key_exists('status', $export)) {
      throw new Exception('Malformed document returned from Salsify: ' . var_export($export,true));
    }
    $status = $export['status'];
    if ($status === 'running') {
      // still going
      return null;
    } elseif ($status === 'failed') {
      // extremely unlikely. this would be an internal error in Salsify
      throw new Exception('Salsify failed to produce an export for Magento.');
    } elseif ($status !== 'completed') {
      throw new Exception('Malformed document returned from Salsify. Unknown status: ' . $export['status']);
    } elseif (!array_key_exists('url', $export)) {
      throw new Exception('Malformed document returned from Salsify. No URL returned for successful Salsify export.');
    }

    $url = $export['url'];
    if (!$url) {
      throw new Exception("Processing done but no public URL. Check for errors with Salsify administrator. Export job ID: " . $id);
    }

    return $url;
  }


  // sends the given salsify product data file to Salsify and kicks off its
  // processing.
  //
  // throws an exception is anything goes wrong.
  public function upload_product_data_to_salsify($export_file) {
    self::_log("Exporting " . $export_file . " to Salsify.");

    // first we need to get a mount point
    self::_log("Getting mount point for export...");
    $mount_details = $this->_get_salsify_upload_mount_point();

    // second we have to actually upload the file to Salsify
    self::_log("Uploading to Salsify...");
    $upload_key = $this->_upload_export_to_salsify($mount_details, $export_file);

    // third we need to create the actual import in Salsify
    self::_log("Creating import in Salsify for exported data...");
    $salsify_import_id = $this->_create_salsify_import($upload_key, $export_file);

    // fourth we have to get Salsify to actually process the import
    self::_log("Kicking off import run in Salsify...");
    $salsify_import_run_id = $this->_start_salsify_import_run($salsify_import_id);

    // finally we can check the status until it's done...
    while ($this->_salsify_import_still_running($salsify_import_run_id)) {
      self::_log("Salsify import not yet done...");
      sleep(10);
    }

    $import = $this->_get_salsify_import_details($salsify_import_run_id);
    if (!array_key_exists('status', $import)) {
      throw new Exception("Malformed import reponse given from Salsify: " . var_export($import,true));
    }
    $status = $import['status'];
    if ($status === 'failed') {
      if (array_key_exists('failure_reason', $import)) {
        // something has gone wrong. return the failure.
        $failure_reason = $import['failure_reason'];
        $error_msg = "Error: Salsify count not complete the export. Failure reason given: " . $failure_reason;
        self::_log($error_msg);
        throw new Exception($error_msg);
      }
    }

    self::_log("Export to Salsify completed successfully!");
    return true;
  }


  // gets details required to upload our export to Salsify
  private function _get_salsify_upload_mount_point() {
    $url = $this->_get_create_mount_url();
    $request = new HttpRequest($url, HTTP_METH_POST);
    $response = $request->send();
    if (!$this->_response_valid($response)) {
      throw new Exception("ERROR: could not create Salsify mount point for exporting data: " . var_export($response,true));
    }
    return json_decode($response->getBody(), true);
  }


  // Uploads the Magento export to Salsify.
  //
  // Returns the 'key' returned from Salsify, which is required to get Salsify
  // to actually import the data.
  private function _upload_export_to_salsify($mount_response, $export_file) {
    $uploader = new \MultipartUploader\Uploader($mount_response['url']);

    $form_data = $mount_response['formData'];
    $uploader->addPart('key', $form_data['key']);
    $uploader->addPart('AWSAccessKeyId', $form_data['AWSAccessKeyId']);
    $uploader->addPart('acl', $form_data['acl']);
    $uploader->addPart('policy', $form_data['policy']);
    $uploader->addPart('signature', $form_data['signature']);

    $uploader->addFile('file', $export_file, 'application/json');

    $response = $uploader->postData();
    if (!$this->_response_valid($response)) {
      throw new Exception("ERROR: could not upload export file to Salsify: " . var_export($response,true));
    }

    return $form_data['key'];
  }


  // Creates a Salsify import for this Magento export.
  //
  // Returns the ID of the Salsify import for later reference.
  private function _create_salsify_import($key, $export_file) {
    $request = new HttpRequest($this->_get_create_salsify_import_url(), HTTP_METH_POST);
    $request->addHeaders(array('Content-Type' => 'application/json'));
    $request->setBody(json_encode(array(
      'import_format' => array(
        'type' => 'json_import_format'
      ),
      'import_source' => array(
        'type' => 'cloud_import_source',
        'upload_path' => $key,
        'file' => basename($export_file)
      )
    )));

    $response = $request->send();
    if (!$this->_response_valid($response)) {
      throw new Exception("ERROR: could not create Salsify import for Magento export: " . var_export($response,true));
    }

    $response_json = json_decode($response->getBody(), true);
    return $response_json['id'];
  }


  // tells Salsify to start processing the file that we've uploaded
  private function _start_salsify_import_run($id) {
    $request = new HttpRequest($this->_get_start_salsify_import_run_url($id), HTTP_METH_POST);
    $response = $request->send();
    if (!$this->_response_valid($response)) {
      throw new Exception("ERROR: could not start Salsify import run: " . var_export($response,true));
    }
    $response_json = json_decode($response->getBody(), true);
    return $response_json['id'];
  }


  // fetches the details from Salsify about the given import run
  private function _get_salsify_import_details($salsify_import_run_id) {
    $request = new HttpRequest($this->_get_check_salsify_import_run_url($salsify_import_run_id), HTTP_METH_GET);
    $response = $request->send();
    if (!$this->_response_valid($response)) {
      throw new Exception("ERROR: could not check up on import run status: " . var_export($response,true));
    }
    return json_decode($response->getBody(), true);
  }


  // returns whether the salsify import run is still going
  private function _salsify_import_still_running($salsify_import_run_id) {
    $import = $this->_get_salsify_import_details($salsify_import_run_id);
    $status = $import['status'];

    // there are multiple 'done' or 'stopped' states, so we just want to return
    // whether it's really done.
    return (strcasecmp($status, 'running') != 0);
  }

}