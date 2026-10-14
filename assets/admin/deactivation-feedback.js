(function () {
  'use strict';

  // WordPress prints footer scripts before admin_footer-plugins.php markup.
  function initialize() {
    var config = window.KAYZART_DEACTIVATION;
    var dialog = document.getElementById('kayzart-deactivation-dialog');
    if (!config || !dialog || typeof dialog.showModal !== 'function' || !window.fetch || !window.AbortController) return;
    var form = dialog.querySelector('form');
    var error = dialog.querySelector('[role="alert"]');
    var trigger = null;
    var destination = '';
    var busy = false;
    var finished = false;

    function close() {
      if (busy) return;
      dialog.close();
      if (trigger) trigger.focus();
    }

    document.addEventListener('click', function (event) {
      var link = event.target.closest && event.target.closest('tr[data-plugin="' + config.pluginFile + '"] .deactivate a');
      if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      if (new URL(link.href, window.location.href).searchParams.get('action') !== 'deactivate') return;
      event.preventDefault();
      if (busy || dialog.open) return;
      trigger = link;
      destination = link.href;
      finished = false;
      form.reset();
      error.textContent = '';
      dialog.showModal();
      dialog.querySelector('h2').focus();
    });

    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      close();
    });
    dialog.addEventListener('keydown', function (event) {
      if (event.key !== 'Tab') return;
      var radio = form.querySelector('input[type="radio"]:checked') || form.querySelector('input[type="radio"]');
      var controls = Array.from(form.querySelectorAll('input:not(:disabled), textarea:not(:disabled), button:not(:disabled)'))
        .filter(function (control) { return control.type !== 'radio' || control === radio; });
      var first = controls[0];
      var last = controls[controls.length - 1];
      if (!first) {
        event.preventDefault();
        dialog.querySelector('h2').focus();
      } else if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    dialog.querySelector('[data-kayzart-cancel]').addEventListener('click', close);

    function deactivate() {
      if (finished) return;
      finished = true;
      window.location.assign(destination);
    }

    dialog.querySelector('[data-kayzart-skip]').addEventListener('click', function () {
      if (!busy) deactivate();
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busy || finished) return;
      var reason = form.querySelector('[name="reason"]:checked');
      var comment = form.querySelector('[name="comment"]').value.trim();
      if (!reason && !comment) {
        error.textContent = config.emptyError;
        return;
      }
      if (Array.from(comment).length > 1000) {
        error.textContent = config.lengthError;
        form.querySelector('[name="comment"]').focus();
        return;
      }
      var body = new URLSearchParams({
        action: config.action, nonce: config.nonce, submission_id: config.submissionId,
        reason: reason ? reason.value : '', comment: comment,
      });
      busy = true;
      error.textContent = '';
      form.querySelectorAll('button, input, textarea').forEach(function (control) { control.disabled = true; });
      var controller = new window.AbortController();
      var timer = window.setTimeout(finish, 5000);
      function finish() {
        window.clearTimeout(timer);
        controller.abort();
        deactivate();
      }
      try {
        window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal }).then(finish, finish);
      } catch (failure) {
        finish();
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
}());
