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
    var generateButton = form.querySelector('#kayzart-generate-ai');
    var blankButton = form.querySelector('#kayzart-create-blank');
    var blankHint = form.querySelector('#kayzart-create-blank-hint');
    var submitButtons = form.querySelectorAll('button[type="submit"]');
    var config = window.KAYZART_NEW_PAGE || {};
    var ai = config.ai || { available: true };
    var setupCard = form.querySelector('#kayzart-ai-setup-card');
    var setupLink = form.querySelector('#kayzart-ai-open-settings');
    var setupGuide = form.querySelector('#kayzart-ai-setup-guide');
    var returnNotice = form.querySelector('#kayzart-ai-return');
    var recheck = form.querySelector('#kayzart-ai-recheck');
    var result = form.querySelector('#kayzart-ai-check-result');
    var reason = form.querySelector('#kayzart-ai-unavailable-reason');
    var checking = false;
    var maxChars = Number(config.maxPromptChars) || 8000;
    var charsLabel = config.charsLabel || 'characters';
    var promptIsValid = true;
    var promptChars = 0;

    function updateSetupGuide(guide) {
      if (!setupGuide || !guide) return;
      var previousDetails = setupGuide.querySelector('details');
      var details = document.createElement('details');
      details.className = 'kayzart-ai-connection-guide';
      details.open = Boolean(previousDetails && previousDetails.open);
      var summary = document.createElement('summary');
      summary.textContent = guide.summary;
      details.appendChild(summary);
      var steps = document.createElement('ol');
      guide.steps.forEach(function (step) {
        var item = document.createElement('li');
        item.textContent = step;
        steps.appendChild(item);
      });
      details.appendChild(steps);
      guide.links.forEach(function (link) {
        var paragraph = document.createElement('p');
        var anchor = document.createElement('a');
        anchor.href = link.url;
        anchor.target = '_blank';
        anchor.rel = 'noopener noreferrer';
        anchor.textContent = link.label + ' ' + config.newTabLabel;
        paragraph.appendChild(anchor);
        details.appendChild(paragraph);
      });
      setupGuide.replaceChildren(details);
    }

    function updatePromptCount() {
      if (!prompt || !counter) {
        return;
      }

      promptChars = getCharLength(prompt.value.trim());
      promptIsValid = promptChars > 0 && promptChars <= maxChars;
      counter.textContent = promptChars + ' / ' + maxChars + ' ' + charsLabel;
      counter.classList.toggle('is-error', !promptIsValid);
      prompt.setAttribute('aria-invalid', promptIsValid ? 'false' : 'true');

      if (generateButton && !form.classList.contains('is-submitting')) {
        generateButton.disabled = !ai.available || !promptIsValid || checking;
      }
      if (blankButton && !form.classList.contains('is-submitting')) {
        blankButton.disabled = promptChars > 0;
      }
      if (blankHint) {
        blankHint.hidden = promptChars === 0;
      }
    }

    if (setupLink) {
      setupLink.addEventListener('click', function () {
        if (returnNotice) returnNotice.hidden = false;
      });
    }

    if (recheck) {
      recheck.addEventListener('click', async function () {
        if (checking || form.classList.contains('is-submitting')) return;
        checking = true;
        recheck.disabled = true;
        recheck.setAttribute('aria-busy', 'true');
        if (result) result.textContent = config.checkingLabel;
        updatePromptCount();
        try {
          var response = await fetch(ai.availabilityUrl, {
            method: 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: { 'X-WP-Nonce': config.restNonce }
          });
          var data = await response.json();
          if (!response.ok || data.ok !== true || typeof data.ai?.available !== 'boolean') throw new Error('availability');
          ai = data.ai;
          maxChars = Number(ai.maxPromptChars) || maxChars;
          var refreshedNonce = response.headers.get('X-WP-Nonce');
          if (refreshedNonce) config.restNonce = refreshedNonce;
          if (setupCard) setupCard.hidden = ai.available;
          if (generateButton) generateButton.hidden = !ai.available;
          if (setupLink) {
            setupLink.hidden = ai.available || !ai.canSetUp;
            if (ai.setupUrl) setupLink.href = ai.setupUrl;
          }
          if (setupGuide) {
            setupGuide.hidden = ai.available || !ai.canSetUp;
            if (!setupGuide.hidden) updateSetupGuide(ai.setupGuide);
          }
          if (reason) reason.textContent = ai.unavailableMessage || '';
          if (ai.available) {
            var description = form.querySelector('#kayzart-initial-ai-prompt-description');
            if (description) description.textContent = config.readyLabel;
          }
          if (result) result.textContent = ai.available ? config.readyLabel : (ai.unavailableMessage || config.notReadyLabel);
        } catch (error) {
          if (result) result.textContent = config.checkError;
        } finally {
          checking = false;
          recheck.disabled = false;
          recheck.removeAttribute('aria-busy');
          updatePromptCount();
        }
      });
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

    if (prompt && counter) {
      prompt.addEventListener('input', function () {
        resizePrompt();
        updatePromptCount();
      });
      window.addEventListener('resize', resizePrompt);
      resizePrompt();
      updatePromptCount();
    }

    form.addEventListener('submit', function (event) {
      updatePromptCount();
      var submitter = event.submitter;
      var startMode = submitter && submitter.value === 'ai' ? 'ai' : 'blank';

      if (
        (startMode === 'ai' && (!ai.available || !promptIsValid)) ||
        checking ||
        (startMode === 'blank' && prompt && promptChars > 0) ||
        form.classList.contains('is-submitting')
      ) {
        event.preventDefault();
        return;
      }

      var startModeInput = document.createElement('input');
      startModeInput.type = 'hidden';
      startModeInput.name = 'start_mode';
      startModeInput.value = startMode;
      form.appendChild(startModeInput);
      if (startMode === 'blank' && prompt) {
        prompt.disabled = true;
      }

      form.classList.add('is-submitting');
      form.setAttribute('aria-busy', 'true');
      Array.prototype.forEach.call(submitButtons, function (button) {
        button.disabled = true;
      });
      if (submitter) {
        submitter.textContent = submitter.getAttribute('data-loading-label') || submitter.textContent;
      }
    });
  });
})(window.wp);
