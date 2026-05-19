(function (wp) {
  var wpRef = wp || {};
  var __ = wpRef.i18n && wpRef.i18n.__ ? wpRef.i18n.__ : function (text) {
    return text;
  };
  var domReady = wpRef.domReady
    ? wpRef.domReady
    : function (callback) {
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', callback);
        } else {
          callback();
        }
      };

  domReady(function () {
    var data = window.KAYZART_PAGE_LIST || {};
    if (!data.createUrl) {
      return;
    }

    var addNewButton = document.querySelector('.wrap .page-title-action');
    if (!addNewButton || document.querySelector('.kayzart-page-list-create')) {
      return;
    }

    var button = document.createElement('a');
    button.className = 'page-title-action kayzart-page-list-create';
    button.href = data.createUrl;
    button.textContent = __( 'Add landing page', 'kayzart-live-code-editor' );

    addNewButton.insertAdjacentElement('afterend', button);
  });
})(window.wp);
