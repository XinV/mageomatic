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
      onSuccess: function(response) {
        // note that this is unlikely to ever be called since the page will
        // almost always be reloaded before this callback is given a chance.
      },
      onFailure: function(response) {
        // console.log(response);
      }
    });
  }


  // just reloads the page. no big deal.
  function reloadPage() {
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
        // next kickoff the background worker and, if successful, reload page
        createWorker(workerUrl);

        // reload the page now so that we can see the new export
        // reloadPage();
      },
      onFailure: function(response) {
        // console.log(response);
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