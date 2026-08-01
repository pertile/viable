(function () {
  'use strict';

  const STORAGE_KEY = 'viable_events_client_config';
  const STATE_CHOICES = ['Proyecto', 'En licitación', 'Adjudicado', 'En obras', 'Paralizado', 'Finalizado', 'Cancelado'];
  const DEFAULT_EXCLUDED_STATES = ['Finalizado', 'Cancelado'];

  const els = {
    btnSettings: document.getElementById('btn-settings'),
    settingsPanel: document.getElementById('settings-panel'),
    cfgSiteUrl: document.getElementById('cfg-site-url'),
    cfgUsername: document.getElementById('cfg-username'),
    cfgAppPassword: document.getElementById('cfg-app-password'),
    btnSaveSettings: document.getElementById('btn-save-settings'),

    filterRegion: document.getElementById('filter-region'),
    filterCode: document.getElementById('filter-code'),
    filterStates: document.getElementById('filter-states'),

    bulkBar: document.getElementById('bulk-bar'),
    bulkCount: document.getElementById('bulk-count'),
    bulkDate: document.getElementById('bulk-date'),
    btnBulkVerify: document.getElementById('btn-bulk-verify'),
    bulkMsg: document.getElementById('bulk-msg'),

    selectAll: document.getElementById('select-all'),
    tbody: document.getElementById('projects-tbody'),

    eventDialog: document.getElementById('event-dialog'),
    eventDialogTitle: document.getElementById('event-dialog-title'),
    eventForm: document.getElementById('event-form'),
    btnCancelEvent: document.getElementById('btn-cancel-event'),
    evDate: document.getElementById('ev-date'),
    evUrl: document.getElementById('ev-url'),
    evDescription: document.getElementById('ev-description'),
    evProgress: document.getElementById('ev-progress'),
    evState: document.getElementById('ev-state'),
    evSummary: document.getElementById('ev-summary'),
    eventFormMsg: document.getElementById('event-form-msg'),
  };

  let config = loadConfig();
  let projects = [];
  let eventDialogProject = null;

  function loadConfig() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
    } catch (e) {
      return {};
    }
  }

  function saveConfig(next) {
    config = next;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(config));
  }

  function isConfigured() {
    return !!(config.siteUrl && config.username && config.appPassword);
  }

  function apiUrl(path) {
    return config.siteUrl.replace(/\/$/, '') + '/wp-json' + path;
  }

  function authHeader() {
    return 'Basic ' + btoa(config.username + ':' + config.appPassword);
  }

  async function apiFetch(path, options) {
    options = options || {};
    options.headers = Object.assign({
      Authorization: authHeader(),
      'Content-Type': 'application/json',
    }, options.headers || {});

    const res = await fetch(apiUrl(path), options);
    const isJson = (res.headers.get('content-type') || '').includes('application/json');
    const body = isJson ? await res.json() : null;

    if (!res.ok) {
      const message = (body && (body.message || body.code)) || ('Error HTTP ' + res.status);
      throw new Error(message);
    }
    return body;
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function todayIso() {
    return new Date().toISOString().slice(0, 10);
  }

  // ---- Settings ----

  els.btnSettings.addEventListener('click', () => {
    els.cfgSiteUrl.value = config.siteUrl || '';
    els.cfgUsername.value = config.username || '';
    els.cfgAppPassword.value = config.appPassword || '';
    els.settingsPanel.hidden = !els.settingsPanel.hidden;
  });

  els.btnSaveSettings.addEventListener('click', () => {
    saveConfig({
      siteUrl: els.cfgSiteUrl.value.trim(),
      username: els.cfgUsername.value.trim(),
      appPassword: els.cfgAppPassword.value.trim(),
    });
    els.settingsPanel.hidden = true;
    init();
  });

  // ---- Filtros ----

  function buildStateCheckboxes() {
    els.filterStates.querySelectorAll('label').forEach((l) => l.remove());
    STATE_CHOICES.forEach((state) => {
      const label = document.createElement('label');
      label.className = 'inline-check';
      const checked = DEFAULT_EXCLUDED_STATES.indexOf(state) === -1;
      label.innerHTML = `<input type="checkbox" value="${escapeHtml(state)}" ${checked ? 'checked' : ''}> ${escapeHtml(state)}`;
      label.querySelector('input').addEventListener('change', loadProjects);
      els.filterStates.appendChild(label);
    });
  }

  function getSelectedStates() {
    return Array.from(els.filterStates.querySelectorAll('input[type="checkbox"]:checked')).map((i) => i.value);
  }

  async function loadRegions() {
    try {
      const regions = await apiFetch('/viable/v1/regions');
      regions.forEach((r) => {
        const opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = '—'.repeat(r.depth) + ' ' + r.name;
        els.filterRegion.appendChild(opt);
      });
    } catch (e) {
      // Silencioso: si falla, el filtro de región simplemente queda en "Todas".
    }
  }

  els.filterRegion.addEventListener('change', loadProjects);

  let codeFilterTimer = null;
  els.filterCode.addEventListener('input', () => {
    clearTimeout(codeFilterTimer);
    codeFilterTimer = setTimeout(loadProjects, 300);
  });

  // ---- Tabla de proyectos ----

  async function loadProjects() {
    if (!isConfigured()) {
      els.tbody.innerHTML = '<tr><td colspan="9" class="hint">Configurá la conexión primero.</td></tr>';
      return;
    }

    els.tbody.innerHTML = '<tr><td colspan="9" class="hint">Cargando...</td></tr>';

    const states = getSelectedStates();
    const region = els.filterRegion.value;
    const code = els.filterCode.value.trim();
    const params = new URLSearchParams();
    if (states.length) params.set('states', states.join(','));
    if (region) params.set('region', region);
    if (code) params.set('code', code);

    try {
      projects = await apiFetch('/viable/v1/projects-dashboard?' + params.toString());
      renderTable();
    } catch (e) {
      els.tbody.innerHTML = '<tr><td colspan="9" class="form-msg error">' + escapeHtml(e.message) + '</td></tr>';
    }
  }

  function googleSearchUrl(project) {
    const q = encodeURIComponent(project.title);
    let url = 'https://www.google.com/search?q=' + q;
    if (project.last_verified_on) {
      const parts = project.last_verified_on.split('/'); // d/m/Y
      if (parts.length === 3) {
        const [d, m, y] = parts;
        const minDate = m + '/' + d + '/' + y; // Google usa MM/DD/YYYY en tbs
        const today = new Date();
        const maxDate = (today.getMonth() + 1) + '/' + today.getDate() + '/' + today.getFullYear();
        url += '&tbs=cdr:1,cd_min:' + minDate + ',cd_max:' + maxDate;
      }
    }
    return url;
  }

  function renderTable() {
    els.selectAll.checked = false;
    updateBulkBar();

    if (!projects.length) {
      els.tbody.innerHTML = '<tr><td colspan="9" class="hint">No hay proyectos con estos filtros.</td></tr>';
      return;
    }

    els.tbody.innerHTML = '';
    projects.forEach((p) => {
      const tr = document.createElement('tr');

      const lastEvent = p.last_event
        ? `<div class="last-event-date">${escapeHtml(p.last_event.date)}</div><div class="last-event-text">${escapeHtml(p.last_event.summary || p.last_event.description || '')}</div>`
        : '<span class="hint">—</span>';

      const regionsText = p.regions && p.regions.length ? escapeHtml(p.regions.join(', ')) : '<span class="hint">—</span>';

      tr.innerHTML = `
        <td><input type="checkbox" class="row-check" data-id="${p.id}"></td>
        <td>${p.code ? escapeHtml(p.code) : '<span class="hint">—</span>'}</td>
        <td>${escapeHtml(p.title)}</td>
        <td>${regionsText}</td>
        <td><span class="state-tag state-${escapeHtml((p.state || '').toLowerCase())}">${escapeHtml(p.state || '—')}</span></td>
        <td>${p.progress !== null && p.progress !== '' ? escapeHtml(String(p.progress)) + '%' : '—'}</td>
        <td>${lastEvent}</td>
        <td>${p.last_verified_on ? escapeHtml(p.last_verified_on) : '<span class="hint">—</span>'}</td>
        <td class="row-actions">
          <button class="btn-icon btn-add-event" title="Agregar evento">+ Evento</button>
          <a class="btn-icon btn-google" title="Buscar en Google" href="${googleSearchUrl(p)}" target="_blank" rel="noopener">🔎</a>
        </td>
      `;

      tr.querySelector('.row-check').addEventListener('change', updateBulkBar);
      tr.querySelector('.btn-add-event').addEventListener('click', () => openEventDialog(p));

      els.tbody.appendChild(tr);
    });
  }

  els.selectAll.addEventListener('change', () => {
    document.querySelectorAll('.row-check').forEach((cb) => { cb.checked = els.selectAll.checked; });
    updateBulkBar();
  });

  function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map((cb) => Number(cb.dataset.id));
  }

  function updateBulkBar() {
    const ids = getSelectedIds();
    els.bulkBar.hidden = ids.length === 0;
    els.bulkCount.textContent = ids.length + (ids.length === 1 ? ' proyecto seleccionado' : ' proyectos seleccionados');
  }

  els.bulkDate.value = todayIso();

  els.btnBulkVerify.addEventListener('click', async () => {
    const ids = getSelectedIds();
    if (!ids.length) return;

    els.bulkMsg.textContent = '';
    els.bulkMsg.className = 'form-msg';

    try {
      const date = els.bulkDate.value || todayIso();
      const result = await apiFetch('/viable/v1/projects-bulk-verify', {
        method: 'POST',
        body: JSON.stringify({ project_ids: ids, date: date }),
      });
      els.bulkMsg.textContent = result.updated + ' proyecto(s) actualizados.';
      els.bulkMsg.className = 'form-msg success';
      await loadProjects();
    } catch (e) {
      els.bulkMsg.textContent = e.message;
      els.bulkMsg.className = 'form-msg error';
    }
  });

  // ---- Popup de nuevo evento ----

  function openEventDialog(project) {
    eventDialogProject = project;
    els.eventDialogTitle.textContent = 'Nuevo evento — ' + project.title;
    els.eventForm.reset();
    els.evDate.value = todayIso();
    els.eventFormMsg.textContent = '';
    els.eventFormMsg.className = 'form-msg';
    els.eventDialog.showModal();
  }

  els.btnCancelEvent.addEventListener('click', () => {
    els.eventDialog.close();
  });

  els.eventForm.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    els.eventFormMsg.textContent = '';
    els.eventFormMsg.className = 'form-msg';

    if (!eventDialogProject) return;

    const url = els.evUrl.value.trim();
    const description = els.evDescription.value.trim();
    if (!url && !description) {
      showEventFormMessage('Cargá una URL o un texto descriptivo.', true);
      return;
    }

    const acf = {
      project: eventDialogProject.id,
      event_date: els.evDate.value, // ISO (yyyy-mm-dd): formato inequívoco para ACF
    };
    if (url) acf.url = url;
    if (description) acf.description = description;
    if (els.evProgress.value !== '') acf.progress = Number(els.evProgress.value);
    if (els.evState.value !== '') acf.state = els.evState.value;
    if (els.evSummary.value.trim() !== '') acf.summary = els.evSummary.value.trim();

    const payload = {
      title: eventDialogProject.title + ' — ' + els.evDate.value,
      status: 'publish',
      acf: acf,
    };

    try {
      await apiFetch('/wp/v2/project-events', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      els.eventDialog.close();
      await loadProjects();
    } catch (e) {
      showEventFormMessage(e.message, true);
    }
  });

  function showEventFormMessage(text, isError) {
    els.eventFormMsg.textContent = text;
    els.eventFormMsg.className = 'form-msg ' + (isError ? 'error' : 'success');
  }

  // ---- Init ----

  function init() {
    if (!isConfigured()) {
      els.settingsPanel.hidden = false;
      return;
    }
    loadRegions();
    loadProjects();
  }

  buildStateCheckboxes();
  init();
})();
