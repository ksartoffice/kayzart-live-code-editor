(function () {
  'use strict';

  function updateChoiceGroup(fieldset) {
    var maximum = Number(fieldset.getAttribute('data-kayzart-max-choices')) || 0;
    var checkboxes = Array.prototype.slice.call(fieldset.querySelectorAll('input[type="checkbox"]'));
    var selected = checkboxes.filter(function (checkbox) { return checkbox.checked; });
    var required = checkboxes.some(function (checkbox) {
      return checkbox.getAttribute('data-kayzart-required-group') === 'true';
    });

    checkboxes.forEach(function (checkbox, index) {
      checkbox.disabled = maximum > 0 && selected.length >= maximum && !checkbox.checked;
      if (required) {
        checkbox.required = selected.length === 0 && index === 0;
      }
    });

    var counter = fieldset.querySelector('[data-kayzart-choice-count]');
    if (counter) {
      counter.textContent = selected.length + ' / ' + maximum;
    }
  }

  function updateCharacterCount(textarea) {
    var maximum = Number(textarea.getAttribute('data-kayzart-character-limit')) || 0;
    var counter = document.querySelector('[data-kayzart-character-count]');
    if (counter) {
      counter.textContent = Array.from(textarea.value).length + ' / ' + maximum;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-kayzart-max-choices]').forEach(function (fieldset) {
      fieldset.addEventListener('change', function () { updateChoiceGroup(fieldset); });
      updateChoiceGroup(fieldset);
    });

    var textarea = document.querySelector('[data-kayzart-character-limit]');
    if (textarea) {
      textarea.addEventListener('input', function () { updateCharacterCount(textarea); });
      updateCharacterCount(textarea);
    }
  });
})();
