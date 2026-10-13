(function () {
  'use strict';

  var config = window.SMART_ASSESS_CONFIG || {};
  var selectedFiles = Object.create(null);
  var formValues = Object.create(null);
  var internalRowActions = Object.create(null);
  var isOwner = true;

  window.SMART_ASSESS_SET_FILE = function (key, file) {
    selectedFiles[key] = file;
  };
  window.SMART_ASSESS_REMOVE_FILE = function (key) {
    delete selectedFiles[key];
  };
  window.SMART_ASSESS_NOTICE = notice;

  function notice(message) {
    var node = document.createElement('div');
    node.className = 'login-error';
    node.setAttribute('role', 'alert');
    node.textContent = message;
    node.style.position = 'fixed';
    node.style.zIndex = '1000';
    node.style.top = '16px';
    node.style.left = '50%';
    node.style.transform = 'translateX(-50%)';
    node.style.maxWidth = 'min(560px, calc(100vw - 32px))';
    document.body.appendChild(node);
    window.setTimeout(function () { node.remove(); }, 6000);
  }

  function currentInternalView() {
    try {
      var state = JSON.parse(localStorage.getItem('smartAssessMAOSystemV1') || '{}');
      return typeof state.view === 'string' ? state.view : 'dashboard';
    } catch (error) {
      return 'dashboard';
    }
  }

  function jsonRequest(action, data) {
    data = data || {};
    data.action = action;
    data.csrf = config.csrf;
    data.returnView = currentInternalView();
    return fetch(config.api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(data)
    }).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok || !body.ok) throw new Error(body.error || 'The request could not be completed.');
        return body;
      });
    });
  }

  function getJson(action, params) {
    var query = new URLSearchParams(params || {});
    query.set('action', action);
    return fetch(config.api + '?' + query.toString(), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok || !body.ok) throw new Error(body.error || 'The request could not be completed.');
        return body;
      });
    });
  }

  function inputValue(id) {
    var element = document.getElementById('f-' + id);
    return element ? element.value : '';
  }

  function submitPublicRequest() {
    var page = window.SMART_ASSESS_PAGE || {};
    var form = page.formData || {};
    var transferType = inputValue('transferType') || formValues.transferType || form.transferType || '';
    var flow = (transferType || document.querySelector('[data-upload="ctcTdOrTitle"]')) ? 'landtransfer' : 'docreq';
    var identityDocuments = isOwner
      ? [{ doc_key: 'ownerId', label: 'Property Owner Valid ID' }]
      : [
          { doc_key: 'requesterId', label: 'Authorized Representative Valid ID' },
          { doc_key: 'ownerId', label: 'Property Owner Valid ID' },
          { doc_key: 'authLetter', label: 'Authorization Letter' }
        ];
    var documents = flow === 'landtransfer'
      ? [
          { doc_key: 'ctcTdOrTitle', label: 'Certified True Copy of Tax Declaration or Title' },
          { doc_key: 'notarialDeed', label: 'Notarial Deed of Sale or Donation' },
          { doc_key: 'vicinityMap', label: 'Vicinity Map' },
          { doc_key: 'certNoImprovement', label: 'Certification of No Improvement' },
          { doc_key: 'taxClearance', label: 'Tax Clearance' }
        ].concat(identityDocuments)
      : identityDocuments;
    var contact = formValues.contact || inputValue('contact') || form.contact || '';
    var arpNumber = formValues.arp || inputValue('arp') || form.arp || '';
    var arpDigits = String(arpNumber).replace(/\D/g, '');
    if (arpDigits.length === 11 && arpDigits.indexOf('14') === 0) arpDigits = arpDigits.slice(2);
    arpDigits = arpDigits.slice(0, 9);
    if (arpDigits.length === 9) {
      arpNumber = '14-' + arpDigits.slice(0, 4) + '-' + arpDigits.slice(4);
    }
    var email = formValues.email || inputValue('email') || form.email || '';
    email = String(email).trim();
    if (email && email.indexOf('@') === -1) email += '@gmail.com';
    var payload = {
      flow: flow,
      first_name: formValues.firstName || inputValue('firstName') || form.firstName || '',
      middle_name: formValues.middleName || inputValue('middleName') || form.middleName || '',
      last_name: formValues.lastName || inputValue('lastName') || form.lastName || '',
      contact_number: contact,
      email: email,
      address_line: formValues.addressLine || inputValue('addressLine') || form.addressLine || '',
      province: formValues.province || inputValue('province') || form.province || 'Batangas',
      city: formValues.city || inputValue('city') || form.city || 'Mabini',
      zip_code: formValues.zip || inputValue('zip') || form.zip || '4202',
      document_type: formValues.documentType || inputValue('documentType') || form.documentType || '',
      transfer_type: transferType,
      purpose: formValues.purpose || inputValue('purpose') || form.purpose || '',
      arp_number: arpNumber,
      property_address: formValues.propertyAddress || inputValue('propertyAddress') || form.propertyAddress || '',
      barangay: formValues.barangay || inputValue('barangay') || form.barangay || '',
      documents: documents,
      is_owner: document.getElementById('f-isOwner') ?
        document.getElementById('f-isOwner').checked : isOwner
    };
    var body = new FormData();
    body.append('payload', JSON.stringify(payload));
    body.append('csrf', config.csrf);
    Object.keys(selectedFiles).forEach(function (key) {
      body.append('file_' + key, selectedFiles[key]);
    });
    return fetch(config.api + '?action=submit_request', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: body
    }).then(function (response) {
      return response.json().then(function (result) {
        if (!response.ok || !result.ok) throw new Error(result.error || 'The request could not be submitted.');
        window.location.assign('/index.php?submitted=' + encodeURIComponent(result.request.reference_no));
      });
    }).catch(function (error) {
      notice(error.message);
    });
  }

  function internalSuccess(action, data) {
    return jsonRequest(action, data).then(function () {
      var view = currentInternalView();
      window.location.assign('/internal.php?view=' + encodeURIComponent(view));
    }).catch(function (error) {
      notice(error.message);
    });
  }

  document.addEventListener('change', function (event) {
    var input = event.target;
    if (input && input.matches('[data-file-input]') && input.files && input.files[0]) {
      selectedFiles[input.getAttribute('data-file-input')] = input.files[0];
    }
    if (input && input.matches('[data-bind-select]')) {
      formValues[input.getAttribute('data-bind-select')] = input.value;
    }
    if (input && input.matches('#f-isOwner')) isOwner = input.checked;
  }, true);

  document.addEventListener('input', function (event) {
    var input = event.target;
    if (!input || !input.matches('[data-bind]')) return;
    var key = input.getAttribute('data-bind');
    formValues[key] = input.value;
    if (key === 'contact') formValues[key] = '+63' + input.value.replace(/\D/g, '').slice(0, 10);
    if (key === 'arp' && input.value && input.value.indexOf('14-') !== 0) formValues[key] = '14-' + input.value;
  }, true);

  document.addEventListener('click', function (event) {
    if (config.portal === 'client') {
      var clientAction = event.target.closest('[data-action]');
      if (!clientAction) return;
      var action = clientAction.getAttribute('data-action');
      if (action === 'confirm-submit') {
        event.preventDefault();
        event.stopImmediatePropagation();
        submitPublicRequest();
      } else if (action === 'track-search') {
        event.preventDefault();
        event.stopImmediatePropagation();
        var input = document.getElementById('track-input');
        var reference = input ? input.value.trim().toUpperCase() : '';
        if (!reference) {
          notice('Enter a request reference number to track.');
          return;
        }
        window.location.assign('/index.php?track=' + encodeURIComponent(reference));
      }
      return;
    }

    var internalAction = event.target.closest('[data-action]');
    if (!internalAction) return;
    var actionName = internalAction.getAttribute('data-action');
    if (actionName === 'view-archived-staff') {
      event.preventDefault();
      event.stopImmediatePropagation();
      openArchivedAccountDetails(internalAction.getAttribute('data-id'));
    } else if (actionName === 'logout') {
      event.preventDefault();
      event.stopImmediatePropagation();
      internalSuccess('internal_logout', {});
    } else if (actionName === 'start-processing' || actionName === 'complete-processing') {
      event.preventDefault();
      event.stopImmediatePropagation();
      internalSuccess(actionName === 'start-processing' ? 'start_processing' : 'complete_processing', {
        id: internalAction.getAttribute('data-id')
      });
    } else if (actionName === 'ann-publish-now' || actionName === 'ann-unpublish' || actionName === 'ann-cancel') {
      event.preventDefault();
      event.stopImmediatePropagation();
      internalSuccess('announcement_transition', {
        id: internalAction.getAttribute('data-id'),
        transition: actionName
      });
    } else if (actionName === 'confirm-yes') {
      var state;
      try {
        state = JSON.parse(localStorage.getItem('smartAssessMAOSystemV1') || '{}');
      } catch (error) {
        notice('Could not read the selected action. Refresh the page and try again.');
        return;
      }
      var confirmation = state.confirm || {};
      var actionMap = {
        'archive-staff': 'archive_account',
        'restore-staff': 'restore_account',
        'delete-archived-staff': 'delete_account',
        'toggle-staff-status': 'toggle_account',
        'delete-ann': 'delete_announcement'
      };
      if (actionMap[confirmation.action]) {
        event.preventDefault();
        event.stopImmediatePropagation();
        internalSuccess(actionMap[confirmation.action], { id: confirmation.id });
      }
    }
  }, true);

  function rowActionDetails(actionCell) {
    var actions = Array.prototype.slice.call(actionCell.querySelectorAll('[data-action]')).map(function (control) {
      return {
        action: control.getAttribute('data-action'),
        id: control.getAttribute('data-id') || '',
        label: (control.textContent || '').trim()
      };
    });
    var names = actions.map(function (item) { return item.action; });
    var detailAction = null;
    if (names.indexOf('view-doc-req') !== -1) detailAction = 'view-doc-req';
    else if (names.indexOf('view-land-req') !== -1) detailAction = 'view-land-req';
    else if (names.indexOf('view-staff') !== -1) detailAction = 'view-staff';
    else if (names.indexOf('restore-staff') !== -1 || names.indexOf('delete-archived-staff') !== -1) detailAction = 'view-archived-staff';
    else if (names.indexOf('view-report') !== -1) detailAction = 'view-report';
    else if (names.indexOf('view-req-result') !== -1) detailAction = 'view-req-result';
    else if (names.indexOf('edit-ann') !== -1) detailAction = 'edit-ann';
    if (!detailAction) return null;

    var detail = actions.find(function (item) { return item.action === detailAction; });
    if (detailAction === 'view-archived-staff') {
      detail = actions.find(function (item) { return item.action === 'restore-staff' || item.action === 'delete-archived-staff'; });
    }
    if (!detail) return null;
    return { action: detailAction, id: detail.id, controls: actions };
  }

  function openArchivedAccountDetails(id) {
    var row = Array.prototype.slice.call(document.querySelectorAll('.table-wrap tr.record-clickable-row[data-action="view-archived-staff"]')).find(function (candidate) {
      return candidate.getAttribute('data-id') === id;
    });
    if (!row || !internalApp) return;
    var existing = internalApp.querySelector('[data-archived-account-detail]');
    if (existing) existing.remove();

    var details = internalRowActions['view-archived-staff:' + id];
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.setAttribute('data-action', 'close-modal');
    overlay.setAttribute('data-archived-account-detail', '');
    var modal = document.createElement('div');
    modal.className = 'modal fade-in';
    var head = document.createElement('div');
    head.className = 'modal-head';
    var heading = document.createElement('h3');
    heading.textContent = row.querySelector('[data-label="Full Name"]')?.textContent.trim() || row.cells[0]?.textContent.trim() || 'Archived Account';
    head.appendChild(heading);
    var close = document.createElement('button');
    close.className = 'modal-close';
    close.type = 'button';
    close.setAttribute('data-action', 'close-modal');
    close.setAttribute('aria-label', 'Close');
    close.textContent = '×';
    head.appendChild(close);
    modal.appendChild(head);

    var card = document.createElement('div');
    card.className = 'review-card';
    Array.prototype.slice.call(row.cells).forEach(function (cell) {
      if (cell.classList.contains('actions-cell')) return;
      var item = document.createElement('div');
      item.className = 'review-row';
      var label = document.createElement('span');
      label.className = 'k';
      label.textContent = cell.getAttribute('data-label') || '';
      var value = document.createElement('span');
      value.className = 'v';
      value.textContent = cell.textContent.trim();
      item.appendChild(label);
      item.appendChild(value);
      card.appendChild(item);
    });
    modal.appendChild(card);

    var footer = document.createElement('div');
    footer.className = 'modal-foot';
    (details || []).forEach(function (control) {
      if (control.action !== 'restore-staff' && control.action !== 'delete-archived-staff') return;
      var button = document.createElement('button');
      button.type = 'button';
      button.className = control.action === 'delete-archived-staff' ? 'btn btn-outline danger' : 'btn btn-primary';
      button.setAttribute('data-action', control.action);
      button.setAttribute('data-id', id);
      button.textContent = control.label;
      footer.appendChild(button);
    });
    modal.appendChild(footer);
    overlay.appendChild(modal);
    internalApp.appendChild(overlay);
  }

  function enhanceInternalDetails(root) {
    root.querySelectorAll('.modal').forEach(function (modal) {
      if (modal.dataset.rowActionsEnhanced === 'true') return;
      var staffHeading = modal.querySelector('.modal-head p');
      var editStaff = modal.querySelector('.modal-foot [data-action="edit-staff"]');
      if (staffHeading && staffHeading.textContent.trim() === 'Staff Information' && editStaff) {
        var staffId = editStaff.getAttribute('data-id');
        var staffActions = internalRowActions['view-staff:' + staffId] || [];
        var footer = modal.querySelector('.modal-foot');
        staffActions.forEach(function (control) {
          if (control.action !== 'toggle-staff-status' && control.action !== 'archive-staff') return;
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'btn btn-outline';
          button.setAttribute('data-action', control.action);
          button.setAttribute('data-id', staffId);
          button.textContent = control.label;
          footer.insertBefore(button, editStaff);
        });
        modal.dataset.rowActionsEnhanced = 'true';
        return;
      }

      var announcementForm = modal.querySelector('form[data-form="save-ann"]');
      if (announcementForm) {
        var announcementId = announcementForm.getAttribute('data-id');
        var announcementActions = internalRowActions['edit-ann:' + announcementId] || [];
        var announcementFooter = announcementForm.querySelector('.modal-foot');
        announcementActions.forEach(function (control) {
          if (control.action === 'edit-ann') return;
          var button = document.createElement('button');
          button.type = 'button';
          button.className = control.action === 'delete-ann' ? 'btn btn-outline danger' : 'btn btn-outline';
          button.setAttribute('data-action', control.action);
          button.setAttribute('data-id', announcementId);
          button.textContent = control.label;
          announcementFooter.insertBefore(button, announcementFooter.firstChild);
        });
        modal.dataset.rowActionsEnhanced = 'true';
      }
    });
  }

  function enhanceInternalTables(root) {
    if (config.portal !== 'internal') return;

    root.querySelectorAll('.table-wrap table').forEach(function (table) {
      if (table.dataset.actionsColumnRemoved === 'true') return;
      var headerRow = table.querySelector('thead tr');
      if (!headerRow) return;
      var headers = Array.prototype.slice.call(headerRow.children);
      var actionsIndex = headers.findIndex(function (header) {
        return header.classList.contains('actions-cell') || header.textContent.trim().toLowerCase() === 'actions';
      });
      if (actionsIndex === -1) return;

      var header = headers[actionsIndex];
      var labels = headers.map(function (cell) { return cell.textContent.trim(); });
      Array.prototype.slice.call(table.querySelectorAll('tbody tr')).forEach(function (row) {
        var cells = Array.prototype.slice.call(row.children);
        var actionCell = cells[actionsIndex];
        var emptyCell = row.querySelector('td[colspan]');
        if (emptyCell && cells.length === 1) {
          var oldSpan = Number(emptyCell.getAttribute('colspan'));
          if (oldSpan > 1) emptyCell.setAttribute('colspan', String(oldSpan - 1));
        }
        if (actionCell && actionCell.tagName === 'TD' && !actionCell.hasAttribute('colspan')) {
          var detail = rowActionDetails(actionCell);
          if (detail) {
            var actionKey = detail.action + ':' + detail.id;
            internalRowActions[actionKey] = detail.controls;
            row.classList.add('record-clickable-row');
            if (detail.action === 'view-doc-req' || detail.action === 'view-land-req') row.classList.add('request-clickable-row');
            row.setAttribute('data-action', detail.action);
            row.setAttribute('data-id', detail.id);
            row.setAttribute('tabindex', '0');
            row.setAttribute('aria-label', 'Open ' + (labels[0] || 'record') + ' details for ' + detail.id);
            row.setAttribute('aria-keyshortcuts', 'Enter Space');
            row.dataset.rowActionsEnhanced = 'true';
            row.addEventListener('keydown', function (event) {
              if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
              event.preventDefault();
              row.click();
            });
          }
          actionCell.remove();
        } else if (actionCell && actionCell.tagName === 'TD' && actionCell.hasAttribute('colspan')) {
          var oldSpan = Number(actionCell.getAttribute('colspan'));
          if (oldSpan > 1) actionCell.setAttribute('colspan', String(oldSpan - 1));
        }
      });

      header.remove();
      var colgroup = table.querySelector('colgroup');
      if (colgroup && colgroup.children[actionsIndex]) colgroup.children[actionsIndex].remove();
      table.classList.remove('has-actions');
      table.dataset.actionsColumnRemoved = 'true';
    });
    enhanceInternalDetails(root);
  }

  if (config.portal === 'internal') {
    var internalApp = document.getElementById('app');
    if (internalApp) {
      internalApp.addEventListener('click', function (event) {
        var row = event.target.closest('tr.record-clickable-row');
        if (!row) return;
        var control = event.target.closest('a,button,input,select,textarea,label,[contenteditable="true"]');
        if (!control || control === row) return;
        var controlAction = control.closest('[data-action]');
        if (!controlAction || controlAction === row) event.stopImmediatePropagation();
      });
      var internalRowsObserver = new MutationObserver(function () {
        enhanceInternalTables(internalApp);
      });
      internalRowsObserver.observe(internalApp, { childList: true, subtree: true });
      enhanceInternalTables(internalApp);
    }
  }

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-form]');
    if (!form || config.portal !== 'internal') return;
    var type = form.getAttribute('data-form');
    var data = Object.fromEntries(new FormData(form).entries());
    if (event.submitter && event.submitter.name) {
      data[event.submitter.name] = event.submitter.value;
      if (event.submitter.name === 'saveMode') data.publishMode = event.submitter.value;
    }
    var actions = {
      login: 'internal_login',
      'create-account': 'create_account',
      'edit-staff': 'edit_account',
      'update-profile': 'update_profile',
      'save-ann': 'save_announcement',
      'update-status': 'update_request_status',
      'send-notif': 'send_notification'
    };
    if (!actions[type]) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    if (type === 'edit-staff' || type === 'update-status') {
      data.id = form.getAttribute('data-id') || '';
    }
    if (type === 'update-status') {
      data.kind = form.getAttribute('data-kind') || '';
    }
    internalSuccess(actions[type], data);
  }, true);
})();
