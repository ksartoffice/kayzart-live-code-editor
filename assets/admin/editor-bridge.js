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
  var labels = data.labels || {};
  var actionUrl = data.actionUrl || '';
  var buttonLabel = labels.edit || __( 'Edit with Kayzart', 'kayzart-live-code-editor');

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

  var buildActionUrl = function (baseUrl, postId) {
    if (!baseUrl || !postId) {
      return '';
    }
    return baseUrl + '&post_id=' + postId;
  };

  var redirectToKayzArt = function (postId) {
    var url = buildActionUrl(actionUrl, postId);
    if (!url) {
      return;
    }
    window.location.href = url;
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
      document.querySelector('.interface-interface-skeleton') ||
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
    button.textContent = buttonLabel;

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

    var findPreviewHost = function () {
      return document.querySelector(
        [
          '.interface-interface-skeleton__content',
          '.edit-post-layout__content',
          '.editor-visual-editor'
        ].join(', ')
      );
    };

    var createActionLink = function (className, href, text) {
      var link = document.createElement('a');
      link.className = className;
      link.href = href;
      link.textContent = text;
      return link;
    };

    var mountPreview = function () {
      if (!data.previewUrl) {
        return;
      }

      var host = findPreviewHost();
      if (!host) {
        return;
      }
      if (host.querySelector('.kayzart-editor-preview')) {
        return;
      }

      host.classList.add('kayzart-editor-preview-host');

      var editorUrl = buildActionUrl(actionUrl, Number(data.postId) || getPostIdFromBlock());
      var panel = document.createElement('section');
      panel.className = 'kayzart-editor-preview';
      panel.setAttribute('aria-label', labels.eyebrow || __( 'Managed by Kayzart', 'kayzart-live-code-editor'));

      var header = document.createElement('div');
      header.className = 'kayzart-editor-preview__header';

      var copy = document.createElement('div');
      copy.className = 'kayzart-editor-preview__copy';
      var heading = document.createElement('h2');
      heading.className = 'kayzart-editor-preview__title';
      heading.textContent = labels.eyebrow || __( 'Managed by Kayzart', 'kayzart-live-code-editor');
      var titleLabel = document.createElement('label');
      titleLabel.className = 'kayzart-editor-preview__titleLabel';
      titleLabel.textContent = labels.titleLabel || __( 'Page title', 'kayzart-live-code-editor');
      var titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.className = 'kayzart-editor-preview__titleInput';
      titleInput.setAttribute('aria-label', titleLabel.textContent);
      var editorSelector = wpRef.data && wpRef.data.select ? wpRef.data.select('core/editor') : null;
      if (editorSelector && editorSelector.getEditedPostAttribute) {
        titleInput.value = editorSelector.getEditedPostAttribute('title') || '';
      }
      titleInput.addEventListener('input', function () {
        var editorDispatch = wpRef.data && wpRef.data.dispatch ? wpRef.data.dispatch('core/editor') : null;
        if (editorDispatch && editorDispatch.editPost) {
          editorDispatch.editPost({ title: titleInput.value });
        }
      });
      var description = document.createElement('p');
      description.className = 'kayzart-editor-preview__description';
      description.textContent =
        labels.description ||
        __( 'Edit the page content in Kayzart. You can continue to change WordPress page settings here.', 'kayzart-live-code-editor');
      copy.appendChild(heading);
      copy.appendChild(titleLabel);
      copy.appendChild(titleInput);
      copy.appendChild(description);

      var actions = document.createElement('div');
      actions.className = 'kayzart-editor-preview__actions';
      if (data.viewUrl) {
        var viewLink = createActionLink(
          'components-button is-secondary kayzart-editor-preview__view',
          data.viewUrl,
          labels.view || __( 'View page', 'kayzart-live-code-editor')
        );
        viewLink.target = '_blank';
        viewLink.rel = 'noopener noreferrer';
        actions.appendChild(viewLink);
      }
      if (editorUrl) {
        actions.appendChild(
          createActionLink(
            'components-button is-primary kayzart-editor-preview__edit',
            editorUrl,
            buttonLabel
          )
        );
      }

      header.appendChild(copy);
      header.appendChild(actions);

      var frameWrap = document.createElement('div');
      frameWrap.className = 'kayzart-editor-preview__frameWrap';
      var status = document.createElement('div');
      status.className = 'kayzart-editor-preview__status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.textContent = labels.loading || __( 'Loading preview…', 'kayzart-live-code-editor');

      var reload = document.createElement('button');
      reload.type = 'button';
      reload.className = 'components-button is-secondary kayzart-editor-preview__reload';
      reload.textContent = labels.reload || __( 'Reload preview', 'kayzart-live-code-editor');
      reload.hidden = true;

      var iframe = document.createElement('iframe');
      iframe.className = 'kayzart-editor-preview__frame';
      iframe.title = labels.eyebrow || __( 'Kayzart page preview', 'kayzart-live-code-editor');

      var loadTimer = 0;
      var startLoading = function () {
        panel.classList.remove('is-loaded', 'has-load-warning');
        reload.hidden = true;
        status.textContent = labels.loading || __( 'Loading preview…', 'kayzart-live-code-editor');
        window.clearTimeout(loadTimer);
        loadTimer = window.setTimeout(function () {
          panel.classList.add('has-load-warning');
          status.textContent =
            labels.loadFailed ||
            __( 'The preview is taking longer than expected to load.', 'kayzart-live-code-editor');
          reload.hidden = false;
        }, 12000);
      };

      iframe.addEventListener('load', function () {
        window.clearTimeout(loadTimer);
        panel.classList.add('is-loaded');
        panel.classList.remove('has-load-warning');
        reload.hidden = true;
      });
      iframe.addEventListener('error', function () {
        window.clearTimeout(loadTimer);
        panel.classList.add('has-load-warning');
        status.textContent =
          labels.loadFailed ||
          __( 'The preview is taking longer than expected to load.', 'kayzart-live-code-editor');
        reload.hidden = false;
      });
      reload.addEventListener('click', function () {
        startLoading();
        iframe.src = data.previewUrl;
      });

      frameWrap.appendChild(status);
      frameWrap.appendChild(reload);
      frameWrap.appendChild(iframe);
      panel.appendChild(header);
      panel.appendChild(frameWrap);
      host.appendChild(panel);
      startLoading();
      iframe.src = data.previewUrl;
    };

    mountPreview();

    var observerTarget = findBlockObserverTarget();
    var observer = new MutationObserver(function () {
      insertBlockToolbarButton();
      mountPreview();
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
    button.textContent = buttonLabel;
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


