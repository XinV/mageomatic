/**
 * Definition for salsify.connect submodule for the Salsify Connect admin
 * interface in Magento.
 *
 * NOTE: we're using Prototype's Ajax here since that library is automatically
 *       loaded by Magento, and it felt unnecessary to introduce a more modern
 *       JS library for the basic calls that we're making here.
 */

var salsify = (function (parent) {
  var sc = parent.connect = parent.connect || {};


  // creates a background worker
  function createWorker(workerUrl) {
    new Ajax.Request(workerUrl, {
      method: 'post',
      // loaderArea : false, // don't show the 'Please wait' dialog here
      onSuccess: function(response) {
        // reloadPage();
      },
      onFailure: function(response) {
        reloadPage();
      }
    });
  }


  // just reloads the page. no big deal.
  function reloadPage() {
    // true is to force a get from the server (rather than using the browser
    // cache).
    document.location.reload(true);
  }


  function createSync(syncUrl, workerUrl) {
    // this has to be done in 2 steps. the first is to create the export, and
    // the second is to kick off the worker process in the background with an
    // AJAX call that will take quite some time to return (and, in fact, we'll
    // probably have refreshed the page or gone elsewhere before it even does
    // return).

    // first create the export run...
    new Ajax.Request(syncUrl, {
      method: 'post',
      onSuccess: function(response) {
        // console.log(response);
        var json = response.responseText.evalJSON();
        if (json.success) {
          // next kickoff the background worker and, if successful, reload page
          createWorker(workerUrl);
        } else {
          reloadPage();
        }

        // reload the page now so that we can see the new export
        // currently not doing this since the delayed jobs is fundamentally
        // broken without a cron job. the server times out on long requests.
        // reloadPage();
      },
      onFailure: function(response) {
        // console.log(response);
        reloadPage();
      }
    });
  }


  // creates and kicks off a new import job from Salsify.
  //
  // the createUrl and workerUrl parameters are necessary since they contain
  // extra information (noteably security stuff) that we won't know in advance.
  sc.createImport = function (createUrl, workerUrl) {
    createSync(createUrl, workerUrl);
  };

  // basically the same as createImport, but for exports.
  sc.createExport = function (createUrl, workerUrl) {
    createSync(createUrl, workerUrl);
  };


  return parent;
}(salsify || {}));