(function () {
  'use strict';

  // Internal-interface sidebar: mobile hamburger toggle + backdrop click to
  // close. Mirrors smart-assess-internal.html's toggle-sidebar action.
  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('appSidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (toggle && sidebar && backdrop) {
    var setOpen = function (open) {
      sidebar.classList.toggle('open', open);
      backdrop.classList.toggle('open', open);
    };
    toggle.addEventListener('click', function () {
      setOpen(!sidebar.classList.contains('open'));
    });
    backdrop.addEventListener('click', function () {
      setOpen(false);
    });
  }

  // Generic client-side table search filter: any input with
  // data-table-search="<table-wrap id>" hides non-matching <tbody> rows as
  // you type. Works against whatever rows the server already rendered —
  // no extra queries.
  document.querySelectorAll('[data-table-search]').forEach(function (input) {
    var wrap = document.getElementById(input.getAttribute('data-table-search'));
    if (!wrap) return;
    var rows = wrap.querySelectorAll('tbody tr');
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      rows.forEach(function (row) {
        row.hidden = q !== '' && row.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  });
})();
