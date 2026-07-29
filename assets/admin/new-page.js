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

  function getByteLength(value) {
    if (window.TextEncoder) {
      return new window.TextEncoder().encode(value).length;
    }

    return unescape(encodeURIComponent(value)).length;
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
    var maxBytes = Number(config.maxPromptBytes) || 8192;
    var bytesLabel = config.bytesLabel || 'bytes';
    var promptIsValid = true;
    var promptBytes = 0;
    var improving = false;
    var previousPrompt = null;
    var applyingPromptValue = false;

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

      promptBytes = getByteLength(prompt.value.trim());
      promptIsValid = promptBytes <= maxBytes;
      counter.textContent = promptBytes + ' / ' + maxBytes + ' ' + bytesLabel;
      counter.classList.toggle('is-error', !promptIsValid);
      prompt.setAttribute('aria-invalid', promptIsValid ? 'false' : 'true');

      if (submit && !form.classList.contains('is-submitting')) {
        submit.disabled = !promptIsValid;
      }
      if (improve) {
        improve.disabled = improving || promptBytes === 0 || !promptIsValid;
      }
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

    if (prompt && counter) {
      prompt.addEventListener('input', function () {
        updatePromptCount();
        if (!applyingPromptValue && previousPrompt !== null) {
          hideUndo();
          setStatus('', '');
        }
      });
      updatePromptCount();
    }

    if (improve && prompt) {
      improve.addEventListener('click', function () {
        var submittedPrompt = prompt.value.trim();
        var originalPrompt = prompt.value;
        if (!submittedPrompt || !promptIsValid || improving || !config.improveUrl || !config.restNonce) {
          return;
        }

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
          body: JSON.stringify({
            prompt: submittedPrompt,
            title: title ? title.value.trim() : '',
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
          if (prompt.value.trim() !== submittedPrompt) {
            setStatus(config.staleMessage || 'The instruction changed while AI was working. Run the improvement again.', 'info');
            return;
          }
          if (!data || typeof data.improvedPrompt !== 'string' || !data.improvedPrompt.trim()) {
            throw new Error(config.errorMessage || 'The instruction could not be improved. Please try again.');
          }

          previousPrompt = originalPrompt;
          applyingPromptValue = true;
          prompt.value = data.improvedPrompt.trim();
          updatePromptCount();
          applyingPromptValue = false;
          if (undo) {
            undo.hidden = false;
          }
          setStatus(config.improvedMessage || 'The instruction was improved. Review it before creating the page.', 'success');
          prompt.focus();
        }).catch(function (error) {
          setStatus(error && error.message ? error.message : (config.errorMessage || 'The instruction could not be improved. Please try again.'), 'error');
        }).then(function () {
          setImproving(false);
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
