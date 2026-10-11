(function () {
  'use strict';

  // Generic 4-step wizard controller for the Document Request and Land
  // Transfer forms. The underlying <form> is untouched and still POSTs
  // every field in one normal submission — this only changes which
  // .wizard-step blocks are visible and adds a pre-submit confirmation.
  document.querySelectorAll('[data-wizard]').forEach(function (wizard) {
    var steps = Array.prototype.slice.call(wizard.querySelectorAll('.wizard-step'));
    var pills = Array.prototype.slice.call(wizard.querySelectorAll('.step-pill'));
    var form = wizard.querySelector('form');
    var current = 0;

    function showStep(index) {
      current = Math.max(0, Math.min(steps.length - 1, index));
      steps.forEach(function (step, i) { step.hidden = i !== current; });
      pills.forEach(function (pill, i) {
        pill.classList.toggle('active', i === current);
        pill.classList.toggle('done', i < current);
      });
      if (current === steps.length - 1) renderReview(wizard);
      wizard.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    function currentStepIsValid() {
      var fields = steps[current].querySelectorAll('input, select, textarea');
      for (var i = 0; i < fields.length; i++) {
        if (!fields[i].checkValidity()) {
          fields[i].reportValidity();
          return false;
        }
      }
      return true;
    }

    wizard.querySelectorAll('[data-wizard-next]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (currentStepIsValid()) showStep(current + 1);
      });
    });
    wizard.querySelectorAll('[data-wizard-back]').forEach(function (btn) {
      btn.addEventListener('click', function () { showStep(current - 1); });
    });
    pills.forEach(function (pill, i) {
      pill.addEventListener('click', function () {
        // Only allow jumping to an already-completed step or the next one —
        // mirrors the prototype's stepper (no skipping ahead blind).
        if (i <= current + 1) showStep(i);
      });
    });

    // Pre-submit confirmation modal, built from whichever key fields this
    // wizard declares via data-review-field on its own markup.
    var modal = wizard.querySelector('.submit-modal-backdrop');
    var submitBtn = wizard.querySelector('[data-wizard-submit]');
    if (form && modal && submitBtn) {
      var list = modal.querySelector('[data-submit-modal-list]');
      submitBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!currentStepIsValid()) return;
        if (list) {
          list.innerHTML = '';
          wizard.querySelectorAll('[data-review-field]').forEach(function (el) {
            var label = el.getAttribute('data-review-field');
            var val = fieldDisplayValue(wizard, el);
            var li = document.createElement('li');
            li.innerHTML = '<span></span><span></span>';
            li.children[0].textContent = label;
            li.children[1].textContent = val || '—';
            list.appendChild(li);
          });
        }
        modal.hidden = false;
      });
      modal.querySelectorAll('[data-submit-modal-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () { modal.hidden = true; });
      });
      modal.querySelectorAll('[data-submit-modal-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function () { form.submit(); });
      });
    }

    function renderReview(wizard) {
      var reviewBody = wizard.querySelector('[data-review-body]');
      if (!reviewBody) return;
      reviewBody.innerHTML = '';
      var groups = {};
      wizard.querySelectorAll('[data-review-field]').forEach(function (el) {
        var group = el.getAttribute('data-review-group') || 'Details';
        groups[group] = groups[group] || [];
        groups[group].push(el);
      });
      Object.keys(groups).forEach(function (group) {
        var card = document.createElement('div');
        card.className = 'review-card';
        card.style.marginBottom = '16px';
        groups[group].forEach(function (el) {
          var row = document.createElement('div');
          row.className = 'review-row';
          var label = el.getAttribute('data-review-field');
          row.innerHTML = '<span class="k"></span><span class="v"></span>';
          row.querySelector('.k').textContent = label;
          row.querySelector('.v').textContent = fieldDisplayValue(wizard, el) || '—';
          card.appendChild(row);
        });
        var heading = document.createElement('h3');
        heading.style.fontSize = '14px';
        heading.style.marginBottom = '12px';
        heading.textContent = group;
        var wrap = document.createElement('div');
        wrap.appendChild(heading);
        wrap.appendChild(card);
        reviewBody.appendChild(wrap);
      });
    }

    function fieldDisplayValue(wizard, el) {
      var name = el.getAttribute('data-review-source');
      if (!name) return '';
      if (name === 'is_owner') {
        var cb = wizard.querySelector('[name="is_owner"]');
        return cb && cb.checked ? 'Yes, I am the registered owner' : 'No, I am a representative';
      }
      var fileInput = wizard.querySelector('input[type="file"][name="' + name + '"]');
      if (fileInput) {
        return fileInput.files && fileInput.files[0] ? fileInput.files[0].name : '';
      }
      var field = wizard.querySelector('[name="' + name + '"]');
      return field ? field.value : '';
    }

    showStep(0);
  });

  // Barangay custom combobox — enhances a native <select class="barangay-native-select">
  // into the trigger+menu widget while the native select stays the thing
  // that actually submits with the form (its value is kept in sync).
  document.querySelectorAll('[data-barangay-dropdown]').forEach(function (wrap) {
    var select = wrap.querySelector('.barangay-native-select');
    var trigger = wrap.querySelector('.barangay-trigger');
    var menu = wrap.querySelector('.barangay-menu');
    if (!select || !trigger || !menu) return;

    function sync() {
      var opt = select.options[select.selectedIndex];
      var label = trigger.querySelector('.barangay-trigger-label');
      if (opt && opt.value) {
        label.textContent = opt.textContent;
        trigger.classList.remove('placeholder');
      } else {
        label.textContent = 'Select a barangay…';
        trigger.classList.add('placeholder');
      }
      menu.querySelectorAll('.barangay-option').forEach(function (btn) {
        btn.classList.toggle('selected', btn.getAttribute('data-value') === select.value);
      });
    }

    function open(state) {
      wrap.classList.toggle('open', state);
      menu.hidden = !state;
    }

    trigger.addEventListener('click', function () { open(menu.hidden); });
    menu.querySelectorAll('.barangay-option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        select.value = btn.getAttribute('data-value');
        sync();
        open(false);
      });
    });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) open(false);
    });

    sync();
  });

  // ARP number compound input: keeps the real hidden/plain input's value as
  // the full "14-XXXX-XXXXX" string while the visible input only edits the
  // part after the fixed "14-" prefix chip.
  document.querySelectorAll('[data-arp-input]').forEach(function (wrap) {
    var real = wrap.querySelector('input[type="hidden"]');
    var visible = wrap.querySelector('input[type="text"]');
    if (!real || !visible) return;
    if (real.value && real.value.indexOf('14-') === 0) {
      visible.value = real.value.slice(3);
    }
    function sync() { real.value = visible.value ? '14-' + visible.value : ''; }
    visible.addEventListener('input', sync);
    sync();
  });
})();
