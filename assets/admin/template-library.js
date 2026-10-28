(function () {
  'use strict';

  function initialize() {
    var methods = document.querySelector('.kayzart-start-methods');
    var panel = document.getElementById('kayzart-template-panel');
    var editorForm = document.querySelector('.kayzart-create-form');
    if (!methods || !panel || !editorForm) return;
    var mode = methods.getAttribute('data-default-start') || 'template';
    var config = window.KAYZART_NEW_PAGE || {};
    var prompt = editorForm.querySelector('#kayzart-initial-ai-prompt');
    var setupCard = editorForm.querySelector('#kayzart-ai-setup-card');
    var setupLink = editorForm.querySelector('#kayzart-ai-open-settings');
    var generate = editorForm.querySelector('#kayzart-generate-ai');
    var blank = editorForm.querySelector('#kayzart-create-blank');
    var heading = editorForm.querySelector('#kayzart-create-ai-title');
    var description = heading && heading.nextElementSibling;
    var aiHeading = heading && heading.textContent;
    var aiDescription = description && description.textContent;

    function select(next) {
      mode = next;
      panel.hidden = mode !== 'template';
      editorForm.hidden = mode === 'template';
      methods.querySelectorAll('[data-kayzart-start]').forEach(function (button) {
        button.setAttribute('aria-pressed', String(button.getAttribute('data-kayzart-start') === mode));
      });
      var ai = config.ai || {};
      if (setupCard) setupCard.hidden = mode !== 'ai' || ai.available;
      if (setupLink) setupLink.hidden = mode !== 'ai' || ai.available || !ai.canSetUp;
      if (generate) generate.hidden = mode !== 'ai' || !ai.available;
      if (blank) blank.hidden = mode === 'ai' && Boolean(generate && !generate.hidden);
      if (heading) heading.textContent = mode === 'blank' ? methods.getAttribute('data-blank-title') : aiHeading;
      if (description) description.textContent = mode === 'blank' ? methods.getAttribute('data-blank-description') : aiDescription;
      if (prompt) {
        var field = prompt.closest('.kayzart-create-field');
        if (field) field.hidden = mode === 'blank' && !prompt.value.trim();
      }
      var kept = document.getElementById('kayzart-template-kept-input');
      if (kept) kept.hidden = !prompt || !prompt.value.trim();
    }

    methods.addEventListener('click', function (event) {
      var button = event.target.closest('[data-kayzart-start]');
      if (button) select(button.getAttribute('data-kayzart-start'));
    });
    document.addEventListener('kayzart-ai-availability-changed', function () { select(mode); });
    if (prompt) prompt.addEventListener('input', function () { select(mode); });
    select(mode);

    panel.querySelectorAll('.kayzart-template-form').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (form.getAttribute('aria-busy') === 'true') {
          event.preventDefault();
          return;
        }
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"]:not(:disabled)').forEach(function (button) {
          button.setAttribute('data-original-label', button.textContent);
          button.disabled = true;
          button.textContent = button.getAttribute('data-loading-label') || button.textContent;
        });
      });
    });
    window.addEventListener('pageshow', function () {
      panel.querySelectorAll('form[aria-busy="true"]').forEach(function (form) {
        form.removeAttribute('aria-busy');
        form.querySelectorAll('[data-original-label]').forEach(function (button) {
          button.disabled = false;
          button.textContent = button.getAttribute('data-original-label');
          button.removeAttribute('data-original-label');
        });
      });
    });

    var language = panel.querySelector('[data-template-language-filter]');
    var cards = Array.from(panel.querySelectorAll('.kayzart-template-card')).map(function (card) {
      return { element: card, variants: JSON.parse(card.getAttribute('data-template-variants') || '{}') };
    });
    function selectLocale(locale) {
      var count = 0;
      cards.forEach(function (card) {
        var variant = card.variants[locale];
        card.element.hidden = !variant;
        if (!variant) return;
        count++;
        card.element.querySelector('.kayzart-template-art img').src = variant.thumbnail;
        card.element.querySelector('[data-template-preview]').setAttribute('data-template-preview', variant.preview);
        card.element.querySelector('[name="template_locale"]').value = locale;
      });
      var empty = panel.querySelector('[data-template-empty]');
      if (empty) empty.hidden = count > 0;
    }
    // Each language has its own card; keep the typed page title when switching.
    var titles = panel.querySelectorAll('.kayzart-template-form [name="post_title"]');
    titles.forEach(function (title) {
      title.addEventListener('input', function () {
        titles.forEach(function (other) { if (other !== title) other.value = title.value; });
      });
    });
    if (language) {
      language.addEventListener('change', function () { selectLocale(language.value); });
      window.addEventListener('pageshow', function () { selectLocale(language.value); });
      selectLocale(language.value);
    }

    var dialog = document.getElementById('kayzart-template-preview');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    var frame = dialog.querySelector('iframe');
    var status = dialog.querySelector('[data-template-preview-status]');
    var opener = null;
    var request = null;
    var documents = new Map();

    dialog.addEventListener('close', function () {
      if (request) request.abort();
      request = null;
      frame.removeAttribute('srcdoc');
      if (opener) opener.focus();
    });
    dialog.querySelector('[data-template-close]').addEventListener('click', function () { dialog.close(); });
    function selectPreviewWidth(width) {
      frame.style.width = width === 'mobile' ? '390px' : '100%';
      dialog.querySelectorAll('[data-template-width]').forEach(function (button) {
        button.setAttribute('aria-pressed', String(button.getAttribute('data-template-width') === width));
      });
    }
    dialog.querySelectorAll('[data-template-width]').forEach(function (button) {
      button.addEventListener('click', function () { selectPreviewWidth(button.getAttribute('data-template-width')); });
    });
    panel.querySelectorAll('[data-template-preview]').forEach(function (button) {
      button.addEventListener('click', async function () {
        opener = button;
        var url = button.getAttribute('data-template-preview');
        selectPreviewWidth('desktop');
        frame.removeAttribute('srcdoc');
        status.textContent = status.getAttribute('data-loading');
        if (!dialog.open) dialog.showModal();
        if (request) request.abort();
        var current = new AbortController();
        request = current;
        try {
          var documentHtml = documents.get(url);
          if (!documentHtml) {
            var response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: current.signal });
            var result = await response.json();
            if (!response.ok || !result.success || typeof result.data.document !== 'string') throw new Error('preview');
            documentHtml = result.data.document;
            documents.set(url, documentHtml);
          }
          if (request !== current || !dialog.open) return;
          frame.srcdoc = documentHtml;
          status.textContent = '';
        } catch (error) {
          if (request === current && error.name !== 'AbortError') status.textContent = status.getAttribute('data-error');
        }
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
}());
