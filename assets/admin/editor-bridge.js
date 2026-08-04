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

  var createActionLink = function (className, href, text) {
    var link = document.createElement('a');
    link.className = className;
    link.href = href;
    link.textContent = text;
    return link;
  };

  var setEditActionBusy = function (link, busy) {
    link.classList.toggle('is-busy', busy);
    if (busy) {
      link.setAttribute('aria-disabled', 'true');
      link.textContent = __( 'Saving...', 'kayzart-live-code-editor');
    } else {
      link.removeAttribute('aria-disabled');
      link.textContent = buttonLabel;
    }
  };

  var handleBlockEditorEdit = function (event, link, editorUrl) {
    if (link.classList.contains('is-busy')) {
      event.preventDefault();
      return;
    }

    var selector = wpRef.data && wpRef.data.select ? wpRef.data.select('core/editor') : null;
    if (!selector || !selector.isEditedPostDirty) {
      event.preventDefault();
      return;
    }
    if (!selector.isEditedPostDirty()) {
      return;
    }

    event.preventDefault();
    var editorDispatch = wpRef.data && wpRef.data.dispatch ? wpRef.data.dispatch('core/editor') : null;
    if (!editorDispatch || !editorDispatch.savePost) {
      return;
    }

    setEditActionBusy(link, true);
    var saveRequest;
    try {
      saveRequest = editorDispatch.savePost();
    } catch (error) {
      setEditActionBusy(link, false);
      return;
    }

    Promise.resolve(saveRequest).then(
      function () {
        var savedSelector = wpRef.data && wpRef.data.select ? wpRef.data.select('core/editor') : null;
        var stillDirty =
          savedSelector && savedSelector.isEditedPostDirty
            ? savedSelector.isEditedPostDirty()
            : true;
        var didSucceed =
          savedSelector && savedSelector.didPostSaveRequestSucceed
            ? savedSelector.didPostSaveRequestSucceed()
            : false;
        if (!stillDirty && didSucceed) {
          window.location.href = editorUrl;
          return;
        }

        setEditActionBusy(link, false);
      },
      function () {
        setEditActionBusy(link, false);
      }
    );
  };

  var handleClassicEditorEdit = function (event, link) {
    if (link.classList.contains('is-busy')) {
      event.preventDefault();
      return;
    }

    var form = document.getElementById('post');
    if (!form) {
      return;
    }

    event.preventDefault();
    var statusInput = document.getElementById('post_status');
    var originalStatusInput = document.getElementById('original_post_status');
    var postStatus = statusInput ? statusInput.value : originalStatusInput ? originalStatusInput.value : '';
    var updateStatuses = ['publish', 'private', 'future'];
    var submitterId = updateStatuses.indexOf(postStatus) >= 0 ? 'publish' : 'save-post';
    var submitter = document.getElementById(submitterId);
    if (!submitter || submitter.disabled || submitter.classList.contains('disabled')) {
      return;
    }

    var redirectFlag = form.querySelector('input[name="kayzart_open_after_save"]');
    if (!redirectFlag) {
      redirectFlag = document.createElement('input');
      redirectFlag.type = 'hidden';
      redirectFlag.name = 'kayzart_open_after_save';
      form.appendChild(redirectFlag);
    }
    redirectFlag.value = '1';

    var submitEvent = null;
    var captureSubmit = function (submit) {
      submitEvent = submit;
    };
    form.addEventListener('submit', captureSubmit, { once: true });
    setEditActionBusy(link, true);
    submitter.click();

    window.setTimeout(function () {
      form.removeEventListener('submit', captureSubmit);
      if (!submitEvent || submitEvent.defaultPrevented) {
        redirectFlag.remove();
        setEditActionBusy(link, false);
      }
    }, 0);
  };

  var createPreviewPanel = function (options) {
    var editorUrl = buildActionUrl(actionUrl, options.getPostId());
    var panel = document.createElement('section');
    panel.className = 'kayzart-editor-preview ' + options.modifierClass;
    panel.setAttribute('aria-label', labels.eyebrow || __( 'Managed by Kayzart', 'kayzart-live-code-editor'));

    var header = document.createElement('div');
    header.className = 'kayzart-editor-preview__header';

    var copy = document.createElement('div');
    copy.className = 'kayzart-editor-preview__copy';
    var heading = document.createElement('h2');
    heading.className = 'kayzart-editor-preview__title';
    heading.textContent = labels.eyebrow || __( 'Managed by Kayzart', 'kayzart-live-code-editor');
    copy.appendChild(heading);

    if (options.showTitleInput) {
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
      copy.appendChild(titleLabel);
      copy.appendChild(titleInput);
    }

    var description = document.createElement('p');
    description.className = 'kayzart-editor-preview__description';
    description.textContent =
      labels.description ||
      __( 'Edit the page content in Kayzart. You can continue to change WordPress page settings here.', 'kayzart-live-code-editor');
    copy.appendChild(description);

    var actions = document.createElement('div');
    actions.className = 'kayzart-editor-preview__actions';
    if (data.viewUrl) {
      var viewLink = createActionLink(
        options.secondaryButtonClass + ' kayzart-editor-preview__view',
        data.viewUrl,
        labels.view || __( 'View page', 'kayzart-live-code-editor')
      );
      viewLink.target = '_blank';
      viewLink.rel = 'noopener noreferrer';
      actions.appendChild(viewLink);
    }
    if (editorUrl) {
      var editLink = createActionLink(
        options.primaryButtonClass + ' kayzart-editor-preview__edit',
        editorUrl,
        buttonLabel
      );
      if (options.onEdit) {
        editLink.addEventListener('click', function (event) {
          options.onEdit(event, editLink, editorUrl);
        });
      }
      actions.appendChild(editLink);
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
    reload.className = options.secondaryButtonClass + ' kayzart-editor-preview__reload';
    reload.textContent = labels.reload || __( 'Reload preview', 'kayzart-live-code-editor');
    reload.hidden = true;

    var iframe = document.createElement('iframe');
    iframe.className = 'kayzart-editor-preview__frame';
    iframe.title = labels.eyebrow || __( 'Kayzart page preview', 'kayzart-live-code-editor');
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    iframe.setAttribute('sandbox', 'allow-scripts');

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
    startLoading();
    iframe.src = data.previewUrl;

    return panel;
  };

  var findBlockObserverTarget = function () {
    return (
      document.querySelector('.interface-interface-skeleton') ||
      document.querySelector('.interface-interface-skeleton__content') ||
      document.body
    );
  };

  var setupBlockEditor = function () {
    var findPreviewHost = function () {
      return document.querySelector(
        [
          '.interface-interface-skeleton__content',
          '.edit-post-layout__content',
          '.editor-visual-editor'
        ].join(', ')
      );
    };

    var mountPreview = function () {
      if (!data.previewUrl) {
        return;
      }

      var host = findPreviewHost();
      if (!host || host.querySelector('.kayzart-editor-preview')) {
        return;
      }

      host.classList.add('kayzart-editor-preview-host');
      host.appendChild(
        createPreviewPanel({
          getPostId: function () {
            return Number(data.postId) || getPostIdFromBlock();
          },
          modifierClass: 'kayzart-editor-preview--block',
          primaryButtonClass: 'components-button is-primary',
          secondaryButtonClass: 'components-button is-secondary',
          showTitleInput: data.supportsTitle === true,
          onEdit: handleBlockEditorEdit
        })
      );
    };

    mountPreview();

    var observerTarget = findBlockObserverTarget();
    var observer = new MutationObserver(mountPreview);
    observer.observe(observerTarget, { childList: true, subtree: true });

    window.addEventListener('unload', function () {
      observer.disconnect();
    });
  };

  var setupClassicEditor = function () {
    if (!data.previewUrl || document.querySelector('.kayzart-editor-preview--classic')) {
      return;
    }

    var editor = document.getElementById('postdivrich');
    var title = document.getElementById('titlediv');
    var content = document.getElementById('post-body-content');
    if (
      (!editor || !editor.parentNode) &&
      (!title || !title.parentNode) &&
      !content
    ) {
      return;
    }

    var panel = createPreviewPanel({
      getPostId: function () {
        return getPostIdFromClassic() || Number(data.postId) || 0;
      },
      modifierClass: 'kayzart-editor-preview--classic',
      primaryButtonClass: 'button button-primary',
      secondaryButtonClass: 'button button-secondary',
      showTitleInput: false,
      onEdit: handleClassicEditorEdit
    });

    if (editor && editor.parentNode) {
      editor.classList.add('kayzart-classic-editor-source');
      editor.setAttribute('aria-hidden', 'true');
      editor.parentNode.insertBefore(panel, editor);
      return;
    }

    if (title && title.parentNode) {
      title.insertAdjacentElement('afterend', panel);
      return;
    }

    if (content) {
      content.prepend(panel);
    }
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
