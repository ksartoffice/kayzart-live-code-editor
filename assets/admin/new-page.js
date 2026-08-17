(function (wp) {
  var domReady = wp && wp.domReady
    ? wp.domReady
    : function (callback) {
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', callback);
        } else {
          callback();
        }
      };

  // Counted in code points so the number matches PHP's mb_strlen(), which is what the server enforces.
  function getCharLength(value) {
    var length = 0;
    for (var index = 0; index < value.length; index += 1) {
      var code = value.charCodeAt(index);
      if (code >= 0xd800 && code <= 0xdbff && index + 1 < value.length) {
        var next = value.charCodeAt(index + 1);
        if (next >= 0xdc00 && next <= 0xdfff) {
          index += 1;
        }
      }
      length += 1;
    }

    return length;
  }

  domReady(function () {
    var form = document.querySelector('.kayzart-create-form');
    if (!form) {
      return;
    }

    var prompt = form.querySelector('#kayzart-initial-ai-prompt');
    var counter = form.querySelector('#kayzart-initial-ai-prompt-count');
    var submit = form.querySelector('#submit');
    var title = form.querySelector('#kayzart-create-title');
    var improve = form.querySelector('#kayzart-ai-improve');
    var improveLabel = improve && improve.querySelector('.kayzart-ai-improve__label');
    var undo = form.querySelector('#kayzart-ai-improve-undo');
    var improveStatus = form.querySelector('#kayzart-ai-improve-status');
    var config = window.KAYZART_NEW_PAGE || {};
    var maxChars = Number(config.maxPromptChars) || 8000;
    var charsLabel = config.charsLabel || 'characters';
    var promptIsValid = true;
    var promptChars = 0;
    var improving = false;
    var previousPrompt = null;
    var applyingPromptValue = false;
    var activeImproveRequest = null;
    var startModeInputs = form.querySelectorAll('input[name="start_mode"]');

    function isStartingWithAi() {
      var selected = form.querySelector('input[name="start_mode"]:checked');
      return !selected || selected.value === 'ai';
    }

    function setStatus(message, type) {
      if (!improveStatus) {
        return;
      }
      improveStatus.textContent = message || '';
      improveStatus.className = 'kayzart-ai-improve-status' + (type ? ' is-' + type : '');
    }

    function hideUndo() {
      previousPrompt = null;
      if (undo) {
        undo.hidden = true;
      }
    }

    function updatePromptCount() {
      if (!prompt || !counter) {
        return;
      }

      promptChars = getCharLength(prompt.value.trim());
      promptIsValid = promptChars <= maxChars && (!isStartingWithAi() || promptChars > 0);
      counter.textContent = promptChars + ' / ' + maxChars + ' ' + charsLabel;
      counter.classList.toggle('is-error', !promptIsValid);
      prompt.setAttribute('aria-invalid', promptIsValid ? 'false' : 'true');

      if (submit && !form.classList.contains('is-submitting')) {
        submit.disabled = !promptIsValid;
      }
      if (improve) {
        improve.disabled = !isStartingWithAi() || improving || promptChars === 0 || !promptIsValid;
      }
    }

    function syncStartMode() {
      var aiMode = isStartingWithAi();
      if (prompt) {
        prompt.disabled = !aiMode;
        prompt.required = aiMode;
      }
      if (!aiMode && activeImproveRequest) {
        cancelImproveRequest(activeImproveRequest, false);
      }
      updatePromptCount();
    }

    function resizePrompt() {
      if (!prompt) {
        return;
      }

      prompt.style.height = '0px';
      var styles = window.getComputedStyle(prompt);
      var borderHeight = (parseFloat(styles.borderTopWidth) || 0) + (parseFloat(styles.borderBottomWidth) || 0);
      var contentHeight = prompt.scrollHeight + borderHeight;
      var maxHeight = parseFloat(styles.maxHeight);
      var hasMaxHeight = !isNaN(maxHeight);
      var nextHeight = hasMaxHeight ? Math.min(contentHeight, maxHeight) : contentHeight;
      prompt.style.height = nextHeight + 'px';
      prompt.style.overflowY = hasMaxHeight && contentHeight > maxHeight ? 'auto' : 'hidden';
    }

    function setImproving(value) {
      improving = value;
      if (improve) {
        improve.classList.toggle('is-loading', improving);
        improve.setAttribute('aria-busy', improving ? 'true' : 'false');
      }
      if (improveLabel) {
        improveLabel.textContent = improving
          ? (config.improvingLabel || 'Improving…')
          : (config.improveLabel || 'Improve with AI');
      }
      updatePromptCount();
    }

    function currentTitle() {
      return title ? title.value.trim() : '';
    }

    function cancelImproveRequest(request, showStatus) {
      if (activeImproveRequest !== request) {
        return;
      }

      activeImproveRequest = null;
      request.controller.abort();
      setImproving(false);
      if (showStatus) {
        setStatus(config.staleMessage || 'The title or instruction changed, so the AI improvement was canceled. Run it again to use the latest content.', 'info');
      }
    }

    function cancelImprovementIfInputsChanged() {
      var request = activeImproveRequest;
      if (!request) {
        return;
      }
      if (prompt.value.trim() === request.prompt && currentTitle() === request.title) {
        return;
      }

      cancelImproveRequest(request, true);
    }

    if (prompt && counter) {
      prompt.addEventListener('input', function () {
        resizePrompt();
        updatePromptCount();
        if (!applyingPromptValue) {
          cancelImprovementIfInputsChanged();
        }
        if (!applyingPromptValue && previousPrompt !== null) {
          hideUndo();
          setStatus('', '');
        }
      });
      window.addEventListener('resize', resizePrompt);
      resizePrompt();
      updatePromptCount();
    }

    if (title) {
      title.addEventListener('input', cancelImprovementIfInputsChanged);
    }

    Array.prototype.forEach.call(startModeInputs, function (input) {
      input.addEventListener('change', syncStartMode);
    });
    syncStartMode();

    if (improve && prompt) {
      improve.addEventListener('click', function () {
        var submittedPrompt = prompt.value.trim();
        var submittedTitle = currentTitle();
        var originalPrompt = prompt.value;
        if (!submittedPrompt || !promptIsValid || improving || !config.improveUrl || !config.restNonce) {
          return;
        }

        var request = {
          prompt: submittedPrompt,
          title: submittedTitle,
          controller: new window.AbortController(),
        };
        activeImproveRequest = request;

        hideUndo();
        setStatus('', '');
        setImproving(true);

        window.fetch(config.improveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.restNonce,
          },
          signal: request.controller.signal,
          body: JSON.stringify({
            prompt: submittedPrompt,
            title: submittedTitle,
          }),
        }).then(function (response) {
          return response.json().catch(function () {
            return {};
          }).then(function (data) {
            if (!response.ok) {
              throw new Error(data && data.message ? data.message : (config.errorMessage || 'The instruction could not be improved. Please try again.'));
            }
            return data;
          });
        }).then(function (data) {
          if (activeImproveRequest !== request) {
            return;
          }
          if (prompt.value.trim() !== submittedPrompt || currentTitle() !== submittedTitle) {
            cancelImproveRequest(request, true);
            return;
          }
          if (!data || typeof data.improvedPrompt !== 'string' || !data.improvedPrompt.trim()) {
            throw new Error(config.errorMessage || 'The instruction could not be improved. Please try again.');
          }

          previousPrompt = originalPrompt;
          applyingPromptValue = true;
          prompt.value = data.improvedPrompt.trim();
          resizePrompt();
          updatePromptCount();
          applyingPromptValue = false;
          if (undo) {
            undo.hidden = false;
          }
          setStatus(config.improvedMessage || 'The instruction was improved. Review it before creating the page.', 'success');
          prompt.focus();
        }).catch(function (error) {
          if (activeImproveRequest !== request) {
            return;
          }
          if (error && error.name === 'AbortError') {
            return;
          }
          setStatus(error && error.message ? error.message : (config.errorMessage || 'The instruction could not be improved. Please try again.'), 'error');
        }).then(function () {
          if (activeImproveRequest === request) {
            activeImproveRequest = null;
            setImproving(false);
          }
        });
      });
    }

    if (undo && prompt) {
      undo.addEventListener('click', function () {
        if (previousPrompt === null) {
          return;
        }
        var value = previousPrompt;
        applyingPromptValue = true;
        prompt.value = value;
        resizePrompt();
        updatePromptCount();
        applyingPromptValue = false;
        hideUndo();
        setStatus(config.restoredMessage || 'The original instruction was restored.', 'info');
        prompt.focus();
      });
    }

    form.addEventListener('submit', function (event) {
      updatePromptCount();

      if (!promptIsValid || form.classList.contains('is-submitting')) {
        event.preventDefault();
        return;
      }

      form.classList.add('is-submitting');
      form.setAttribute('aria-busy', 'true');
      if (submit) {
        submit.disabled = true;
        submit.value = submit.getAttribute('data-loading-label') || submit.value;
      }
    });
  });
})(window.wp);
