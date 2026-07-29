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
    var config = window.KAYZART_NEW_PAGE || {};
    var maxBytes = Number(config.maxPromptBytes) || 8192;
    var bytesLabel = config.bytesLabel || 'bytes';
    var promptIsValid = true;

    function updatePromptCount() {
      if (!prompt || !counter) {
        return;
      }

      var bytes = getByteLength(prompt.value.trim());
      promptIsValid = bytes <= maxBytes;
      counter.textContent = bytes + ' / ' + maxBytes + ' ' + bytesLabel;
      counter.classList.toggle('is-error', !promptIsValid);
      prompt.setAttribute('aria-invalid', promptIsValid ? 'false' : 'true');

      if (submit && !form.classList.contains('is-submitting')) {
        submit.disabled = !promptIsValid;
      }
    }

    if (prompt && counter) {
      prompt.addEventListener('input', updatePromptCount);
      updatePromptCount();
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
