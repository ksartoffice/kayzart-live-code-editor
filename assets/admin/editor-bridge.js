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
    var statusWasChanged =
      statusInput && statusInput.getAttribute('data-kayzart-status-changed') === 'true';
    var postStatus = statusWasChanged
      ? statusInput.value
      : originalStatusInput
        ? originalStatusInput.value
        : statusInput
          ? statusInput.value
          : '';
    var draftStatuses = ['auto-draft', 'draft', 'pending'];
    var updateStatuses = ['publish', 'private', 'future'];
    var isCustomStatus =
      postStatus &&
      draftStatuses.indexOf(postStatus) < 0 &&
      updateStatuses.indexOf(postStatus) < 0;
    var submitter = null;
    var temporaryFields = [];
    var statusInputWasDisabled = false;

    if (isCustomStatus) {
      if (statusInput) {
        statusInputWasDisabled = statusInput.disabled;
        statusInput.disabled = true;
      }
      var customStatusInput = document.createElement('input');
      customStatusInput.type = 'hidden';
      customStatusInput.name = 'post_status';
      customStatusInput.value = postStatus;
      form.appendChild(customStatusInput);
      temporaryFields.push(customStatusInput);

      var preserveStatusInput = document.createElement('input');
      preserveStatusInput.type = 'hidden';
      preserveStatusInput.name = 'kayzart_preserve_post_status';
      preserveStatusInput.value = postStatus;
      form.appendChild(preserveStatusInput);
      temporaryFields.push(preserveStatusInput);

      submitter = document.createElement('button');
      submitter.type = 'submit';
      submitter.hidden = true;
      form.appendChild(submitter);
      temporaryFields.push(submitter);
    } else {
      var submitterId = updateStatuses.indexOf(postStatus) >= 0 ? 'publish' : 'save-post';
      submitter = document.getElementById(submitterId);
    }
    if (!submitter || submitter.disabled || submitter.classList.contains('disabled')) {
      if (statusInput && isCustomStatus) {
        statusInput.disabled = statusInputWasDisabled;
      }
      temporaryFields.forEach(function (field) {
        field.remove();
      });
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
        if (statusInput && isCustomStatus) {
          statusInput.disabled = statusInputWasDisabled;
        }
        temporaryFields.forEach(function (field) {
          field.remove();
        });
        setEditActionBusy(link, false);
      }
    }, 0);
  };

  var createBridgeCard = function (options) {
    var editorUrl = buildActionUrl(actionUrl, options.getPostId());
    var panel = document.createElement('section');
    panel.className = 'kayzart-editor-bridge ' + options.modifierClass;
    panel.setAttribute('aria-label', labels.eyebrow || __( 'Managed by Kayzart', 'kayzart-live-code-editor'));

    var card = document.createElement('div');
    card.className = 'kayzart-editor-bridge__card';

    var copy = document.createElement('div');
    copy.className = 'kayzart-editor-bridge__copy';
    var heading = document.createElement('h2');
    heading.className = 'kayzart-editor-bridge__title';
    heading.textContent = labels.eyebrow || __( 'Managed by Kayzart', 'kayzart-live-code-editor');
    copy.appendChild(heading);

    if (options.showTitleInput) {
      var titleLabel = document.createElement('label');
      titleLabel.className = 'kayzart-editor-bridge__titleLabel';
      titleLabel.textContent = labels.titleLabel || __( 'Page title', 'kayzart-live-code-editor');
      var titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.className = 'kayzart-editor-bridge__titleInput';
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
    description.className = 'kayzart-editor-bridge__description';
    description.textContent =
      labels.description ||
      __( 'Edit the page content in Kayzart. You can continue to change WordPress page settings here.', 'kayzart-live-code-editor');
    copy.appendChild(description);

    var actions = document.createElement('div');
    actions.className = 'kayzart-editor-bridge__actions';
    if (data.viewUrl) {
      var viewLink = createActionLink(
        options.secondaryButtonClass + ' kayzart-editor-bridge__view',
        data.viewUrl,
        labels.view || __( 'View page', 'kayzart-live-code-editor')
      );
      viewLink.target = '_blank';
      viewLink.rel = 'noopener noreferrer';
      actions.appendChild(viewLink);
    }
    if (editorUrl) {
      var editLink = createActionLink(
        options.primaryButtonClass + ' kayzart-editor-bridge__edit',
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

    card.appendChild(copy);
    card.appendChild(actions);
    panel.appendChild(card);

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
    var findBridgeHost = function () {
      return document.querySelector(
        [
          '.interface-interface-skeleton__content',
          '.edit-post-layout__content',
          '.editor-visual-editor'
        ].join(', ')
      );
    };

    var mountBridge = function () {
      var host = findBridgeHost();
      if (!host || host.querySelector('.kayzart-editor-bridge')) {
        return;
      }

      host.classList.add('kayzart-editor-bridge-host');
      host.appendChild(
        createBridgeCard({
          getPostId: function () {
            return Number(data.postId) || getPostIdFromBlock();
          },
          modifierClass: 'kayzart-editor-bridge--block',
          primaryButtonClass: 'components-button is-primary',
          secondaryButtonClass: 'components-button is-secondary',
          showTitleInput: data.supportsTitle === true,
          onEdit: handleBlockEditorEdit
        })
      );
    };

    mountBridge();

    var observerTarget = findBlockObserverTarget();
    var observer = new MutationObserver(mountBridge);
    observer.observe(observerTarget, { childList: true, subtree: true });

    window.addEventListener('unload', function () {
      observer.disconnect();
    });
  };

  var setupClassicEditor = function () {
    if (document.querySelector('.kayzart-editor-bridge--classic')) {
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

    var statusInput = document.getElementById('post_status');
    if (statusInput) {
      statusInput.addEventListener('change', function () {
        statusInput.setAttribute('data-kayzart-status-changed', 'true');
      });
    }

    var panel = createBridgeCard({
      getPostId: function () {
        return getPostIdFromClassic() || Number(data.postId) || 0;
      },
      modifierClass: 'kayzart-editor-bridge--classic',
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
