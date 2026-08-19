(function () {
  'use strict';

  // Every question is optional, so this script only guides — it never blocks
  // a submission and never disables a choice.

  function updateOtherField(container) {
    var groupName = container.getAttribute('data-kayzart-other-for');
    var trigger = container.getAttribute('data-kayzart-other-value') || 'other';
    var form = container.closest('form');
    if (!form || !groupName) {
      return;
    }

    var inputs = Array.prototype.slice.call(
      form.querySelectorAll('[name="' + groupName + '"], [name="' + groupName + '[]"]')
    );
    var selected = inputs.some(function (input) {
      return input.checked && input.value === trigger;
    });
    var text = container.querySelector('input[type="text"]');
    container.hidden = !selected && !(text && text.value !== '');
  }

  function updateCharacterCount(textarea) {
    var maximum = Number(textarea.getAttribute('data-kayzart-character-limit')) || 0;
    var question = textarea.closest('.kayzart-feedbackQuestion') || document;
    var counter = question.querySelector('[data-kayzart-character-count]');
    if (counter) {
      counter.textContent = Array.from(textarea.value).length + ' / ' + maximum;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-kayzart-other-for]').forEach(function (container) {
      var form = container.closest('form');
      if (form) {
        form.addEventListener('change', function () {
          updateOtherField(container);
        });
      }
      updateOtherField(container);
    });

    document.querySelectorAll('[data-kayzart-character-limit]').forEach(function (textarea) {
      textarea.addEventListener('input', function () {
        updateCharacterCount(textarea);
      });
      updateCharacterCount(textarea);
    });
  });
})();
