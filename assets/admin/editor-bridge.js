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

  var data = window.KAYZART_EDITOR || {};
  var actionUrl = data.actionUrl || '';

  var getPostIdFromBlock = function () {
    if (!wpRef.data || !wpRef.data.select) {
      return 0;
    }
    var selector = wpRef.data.select('core/editor');
    if (!selector || !selector.getCurrentPostId) {
      return 0;
    }
    return Number(selector.getCurrentPostId()) || 0;
  };

  var getPostIdFromClassic = function () {
    var input = document.getElementById('post_ID');
    if (!input) {
      return 0;
    }
    return Number(input.value) || 0;
  };

  var redirectToKayzArt = function (postId) {
    if (!actionUrl || !postId) {
      return;
    }
    window.location.href = actionUrl + '&post_id=' + postId;
  };

  var waitForPostAndRedirect = function (button, getPostId) {
    if (!wpRef.data || !wpRef.data.dispatch) {
      return;
    }

    var dispatch = wpRef.data.dispatch('core/editor');
    if (!dispatch || !dispatch.savePost) {
      return;
    }

    button.classList.add('is-busy');
    button.textContent = __( 'Saving...', 'kayzart-live-code-editor');

    dispatch.savePost();
    var unsubscribe = wpRef.data.subscribe(function () {
      var selector = wpRef.data.select('core/editor');
      var isSaving =
        (selector && selector.isSavingPost && selector.isSavingPost()) ||
        (selector && selector.isAutosavingPost && selector.isAutosavingPost());
      var id = getPostId();
      if (!isSaving && id) {
        unsubscribe();
        redirectToKayzArt(id);
      }
    });
  };

  var findBlockToolbar = function () {
    return document.querySelector(
      [
        '.editor-document-tools',
        '.edit-post-header__toolbar',
        '.editor-header__toolbar',
        '.edit-post-header-toolbar'
      ].join(', ')
    );
  };

  var findBlockObserverTarget = function () {
    return (
      document.querySelector('.interface-interface-skeleton__header') ||
      document.querySelector('.editor-header') ||
      document.querySelector('.edit-post-header') ||
      document.body
    );
  };

  var insertBlockToolbarButton = function () {
    var toolbar = findBlockToolbar();
    if (!toolbar) {
      return;
    }
    if (document.querySelector('.kayzart-editor-toolbar')) {
      return;
    }

    var container = document.createElement('div');
    container.className = 'kayzart-editor-toolbar';

    var button = document.createElement('a');
    button.className = 'components-button is-primary kayzart-editor-toolbar__button';
    button.href = '#';
    button.textContent = __( 'Edit landing page', 'kayzart-live-code-editor');

    button.addEventListener('click', function (event) {
      event.preventDefault();
      var postId = getPostIdFromBlock() || Number(data.postId) || 0;
      if (postId) {
        redirectToKayzArt(postId);
        return;
      }

      waitForPostAndRedirect(button, getPostIdFromBlock);
    });

    container.appendChild(button);

    toolbar.appendChild(container);
  };

  var setupBlockEditor = function () {
    insertBlockToolbarButton();

    var observerTarget = findBlockObserverTarget();
    var observer = new MutationObserver(function () {
      insertBlockToolbarButton();
    });
    observer.observe(observerTarget, { childList: true, subtree: true });

    window.addEventListener('unload', function () {
      observer.disconnect();
    });
  };

  var setupClassicEditor = function () {
    if (document.querySelector('.kayzart-editor-bridge')) {
      return;
    }

    var container = document.createElement('div');
    container.className = 'kayzart-editor-bridge';

    var button = document.createElement('a');
    button.className = 'button button-primary kayzart-editor-bridge__button';
    button.textContent = __( 'Edit landing page', 'kayzart-live-code-editor');
    button.href = '#';

    container.appendChild(button);

    var titleDiv = document.getElementById('titlediv');
    if (titleDiv && titleDiv.parentNode) {
      titleDiv.insertAdjacentElement('afterend', container);
    } else {
      var content = document.getElementById('post-body-content');
      if (content) {
        content.prepend(container);
      }
    }

    button.addEventListener('click', function (event) {
      event.preventDefault();
      var postId = getPostIdFromClassic() || Number(data.postId) || 0;
      redirectToKayzArt(postId);
    });
  };

  domReady(function () {
    if (!data.enabled && !document.body.classList.contains('post-type-kayzart')) {
      return;
    }

    if (data.enabled) {
      document.body.classList.add('kayzart-editor-locked');
    }

    if (document.body.classList.contains('block-editor-page')) {
      setupBlockEditor();
    } else {
      setupClassicEditor();
    }
  });
})(window.wp);


