(function () {
  'use strict';

  // Every question is optional, so this script only guides — it never blocks
  // a submission and never disables a choice.

  function updateOtherField(container) {
    var trigger = container.getAttribute('data-kayzart-other-value') || 'other';
    var form = container.closest('form');
    var groups = (container.getAttribute('data-kayzart-other-for') || '')
      .split(',')
      .filter(function (name) {
        return name !== '';
      });
    if (!form || groups.length === 0) {
      return;
    }

    // One description can belong to several questions, so it stays visible
    // while any of them has "Other" selected.
    var selector = groups
      .map(function (name) {
        return '[name="' + name + '"], [name="' + name + '[]"]';
      })
      .join(', ');
    var inputs = Array.prototype.slice.call(form.querySelectorAll(selector));
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

  // Only choice questions are counted; the free comment box is not a fieldset.
  function unansweredQuestions(form) {
    return Array.prototype.slice.call(form.querySelectorAll('fieldset.kayzart-feedbackQuestion')).filter(
      function (fieldset) {
        return !fieldset.querySelector('input[type="radio"]:checked, input[type="checkbox"]:checked');
      }
    );
  }

  function setUpUnansweredPrompt(form) {
    var prompt = form.querySelector('.kayzart-feedbackUnanswered');
    if (!prompt) {
      return;
    }

    var message = prompt.querySelector('[data-kayzart-unanswered-message]');
    var sendAnyway = prompt.querySelector('[data-kayzart-send-anyway]');
    var review = prompt.querySelector('[data-kayzart-review-unanswered]');
    var acknowledged = false;
    var pending = [];

    if (sendAnyway) {
      sendAnyway.addEventListener('click', function () {
        acknowledged = true;
      });
    }

    if (review) {
      review.addEventListener('click', function () {
        prompt.hidden = true;
        if (pending.length === 0) {
          return;
        }
        var first = pending[0];
        first.scrollIntoView({ block: 'center' });
        var input = first.querySelector('input');
        if (input) {
          input.focus();
        }
      });
    }

    form.addEventListener('submit', function (event) {
      if (acknowledged) {
        return;
      }

      pending = unansweredQuestions(form);
      if (pending.length === 0) {
        return;
      }

      event.preventDefault();
      if (message) {
        var singular = message.getAttribute('data-singular') || '';
        var plural = message.getAttribute('data-plural') || '';
        message.textContent =
          pending.length === 1 ? singular : plural.replace('%d', String(pending.length));
      }
      prompt.hidden = false;
      prompt.scrollIntoView({ block: 'center' });
      if (sendAnyway) {
        sendAnyway.focus();
      }
    });
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

    document.querySelectorAll('.kayzart-feedbackForm').forEach(setUpUnansweredPrompt);
  });
})();
