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

    btnNewProject: document.getElementById('btn-new-project'),
    projectDialog: document.getElementById('project-dialog'),
    projectDialogTitle: document.getElementById('project-dialog-title'),
    projectForm: document.getElementById('project-form'),
    btnCancelProject: document.getElementById('btn-cancel-project'),
    projectFormMsg: document.getElementById('project-form-msg'),
    prTitle: document.getElementById('pr-title'),
    prCode: document.getElementById('pr-code'),
    prShortDescription: document.getElementById('pr-short-description'),
    prType: document.getElementById('pr-type'),
    prDuplicationTypeWrap: document.getElementById('pr-duplication-type-wrap'),
    prDuplicationType: document.getElementById('pr-duplication-type'),
    prLength: document.getElementById('pr-length'),
    prSections: document.getElementById('pr-sections'),
    prState: document.getElementById('pr-state'),
    prProgress: document.getElementById('pr-progress'),
    prProgressUpdatedOn: document.getElementById('pr-progress-updated-on'),
    prLastVerifiedOn: document.getElementById('pr-last-verified-on'),
    prDuration: document.getElementById('pr-duration'),
    prBidDate: document.getElementById('pr-bid-date'),
    prAwardDate: document.getElementById('pr-award-date'),
    prStartDate: document.getElementById('pr-start-date'),
    prEndDate: document.getElementById('pr-end-date'),
    prRegions: document.getElementById('pr-regions'),
    prRoads: document.getElementById('pr-roads'),
  };

  let config = loadConfig();
  let projects = [];
  let regionsCache = [];
  let eventDialogProject = null;
  let editingProjectId = null;

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
      regionsCache = await apiFetch('/viable/v1/regions');
      regionsCache.forEach((r) => {
        const opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = '—'.repeat(r.depth) + ' ' + r.name;
        els.filterRegion.appendChild(opt);

        const opt2 = opt.cloneNode(true);
        els.prRegions.appendChild(opt2);
      });
    } catch (e) {
      // Silencioso: si falla, el filtro de región simplemente queda en "Todas".
    }
  }

  async function loadRoads() {
    try {
      const roads = await apiFetch('/wp/v2/tags?per_page=100&orderby=name&order=asc');
      roads.forEach((r) => {
        const opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = r.name;
        els.prRoads.appendChild(opt);
      });
    } catch (e) {
      // Silencioso: si falla, el selector de rutas queda vacío.
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
          <button class="btn-icon btn-edit-project" title="Editar proyecto">✏️</button>
          <button class="btn-icon btn-add-event" title="Agregar evento">+ Evento</button>
          <a class="btn-icon btn-google" title="Buscar en Google" href="${googleSearchUrl(p)}" target="_blank" rel="noopener">🔎</a>
        </td>
      `;

      tr.querySelector('.row-check').addEventListener('change', updateBulkBar);
      tr.querySelector('.btn-add-event').addEventListener('click', () => openEventDialog(p));
      tr.querySelector('.btn-edit-project').addEventListener('click', () => openProjectDialog(p.id));

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

  // ---- Popup de nuevo/editar proyecto ----

  // ACF no normaliza los date_picker al guardar: según cómo se haya cargado
  // cada fecha, el valor crudo puede venir como "Ymd" (wp-admin clásico) o
  // como ISO "Y-m-d" (código nuestro). Este helper soporta ambos para poder
  // precargar un <input type="date">.
  function normalizeDateForInput(raw) {
    if (!raw) return '';
    const s = String(raw);
    if (/^\d{8}$/.test(s)) {
      return s.slice(0, 4) + '-' + s.slice(4, 6) + '-' + s.slice(6, 8);
    }
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
      return s.slice(0, 10);
    }
    return '';
  }

  function resetProjectForm() {
    els.projectForm.reset();
    Array.from(els.prRegions.options).forEach((o) => { o.selected = false; });
    Array.from(els.prRoads.options).forEach((o) => { o.selected = false; });
    els.prDuplicationTypeWrap.hidden = true;
    els.projectFormMsg.textContent = '';
    els.projectFormMsg.className = 'form-msg';
  }

  function selectOptionsByIds(select, ids) {
    const idSet = new Set((ids || []).map(String));
    Array.from(select.options).forEach((o) => { o.selected = idSet.has(o.value); });
  }

  function populateProjectForm(full) {
    const acf = full.acf || {};
    els.prTitle.value = full.title ? full.title.raw : '';
    els.prCode.value = acf.code || '';
    els.prShortDescription.value = acf.short_description || '';
    els.prType.value = acf.type || '';
    els.prDuplicationTypeWrap.hidden = acf.type !== 'Duplicación';
    els.prDuplicationType.value = acf.duplication_type || '';
    els.prLength.value = acf.length || acf.length === 0 ? acf.length : '';
    els.prSections.value = acf.sections || '';
    els.prState.value = acf.state || 'Proyecto';
    els.prProgress.value = (acf.progress || acf.progress === 0) ? acf.progress : '';
    els.prProgressUpdatedOn.value = normalizeDateForInput(acf.progress_updated_on);
    els.prLastVerifiedOn.value = normalizeDateForInput(acf.last_verified_on);
    els.prDuration.value = acf.duration || acf.duration === 0 ? acf.duration : '';
    els.prBidDate.value = normalizeDateForInput(acf.bid_date);
    els.prAwardDate.value = normalizeDateForInput(acf.award_date);
    els.prStartDate.value = normalizeDateForInput(acf.start_date);
    els.prEndDate.value = normalizeDateForInput(acf.end_date);
    selectOptionsByIds(els.prRegions, acf.regions);
    selectOptionsByIds(els.prRoads, acf.roads);
  }

  els.prType.addEventListener('change', () => {
    els.prDuplicationTypeWrap.hidden = els.prType.value !== 'Duplicación';
  });

  async function openProjectDialog(projectId) {
    editingProjectId = projectId || null;
    resetProjectForm();

    if (!projectId) {
      els.projectDialogTitle.textContent = 'Nuevo proyecto';
      els.prState.value = 'Proyecto';
      els.projectDialog.showModal();
      return;
    }

    els.projectDialogTitle.textContent = 'Editar proyecto';
    els.projectDialog.showModal();

    try {
      const full = await apiFetch('/wp/v2/project/' + projectId + '?context=edit');
      populateProjectForm(full);
    } catch (e) {
      showProjectFormMessage('No se pudo cargar el proyecto: ' + e.message, true);
    }
  }

  els.btnNewProject.addEventListener('click', () => openProjectDialog(null));
  els.btnCancelProject.addEventListener('click', () => els.projectDialog.close());

  function selectedOptionValues(select) {
    return Array.from(select.selectedOptions).map((o) => Number(o.value));
  }

  els.projectForm.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    showProjectFormMessage('', false);

    const roads = selectedOptionValues(els.prRoads);
    if (!roads.length) {
      showProjectFormMessage('Elegí al menos una ruta.', true);
      return;
    }

    const acf = {
      code: els.prCode.value.trim(),
      short_description: els.prShortDescription.value.trim(),
      state: els.prState.value,
      roads: roads,
    };
    // Select con opción vacía ("— sin especificar —") o listas de taxonomía
    // vacías: si no hay valor, omitir la clave en vez de mandar "" o [] —
    // ACF exige al menos 1 item / una de las choices definidas, así que
    // mandar el "vacío" explícito rompe la request.
    const regions = selectedOptionValues(els.prRegions);
    if (regions.length) acf.regions = regions;
    if (els.prType.value) acf.type = els.prType.value;
    if (!els.prDuplicationTypeWrap.hidden && els.prDuplicationType.value) {
      acf.duplication_type = els.prDuplicationType.value;
    }
    if (els.prLength.value !== '') acf.length = Number(els.prLength.value);
    if (els.prSections.value.trim() !== '') acf.sections = els.prSections.value.trim();
    if (els.prProgress.value !== '') acf.progress = Number(els.prProgress.value);
    if (els.prDuration.value !== '') acf.duration = Number(els.prDuration.value);
    if (els.prProgressUpdatedOn.value) acf.progress_updated_on = els.prProgressUpdatedOn.value;
    if (els.prLastVerifiedOn.value) acf.last_verified_on = els.prLastVerifiedOn.value;
    if (els.prBidDate.value) acf.bid_date = els.prBidDate.value;
    if (els.prAwardDate.value) acf.award_date = els.prAwardDate.value;
    if (els.prStartDate.value) acf.start_date = els.prStartDate.value;
    if (els.prEndDate.value) acf.end_date = els.prEndDate.value;

    const payload = {
      title: els.prTitle.value.trim(),
      status: 'publish',
      acf: acf,
    };

    try {
      const path = editingProjectId ? '/wp/v2/project/' + editingProjectId : '/wp/v2/project';
      await apiFetch(path, {
        method: editingProjectId ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      });
      els.projectDialog.close();
      await loadProjects();
    } catch (e) {
      showProjectFormMessage(e.message, true);
    }
  });

  function showProjectFormMessage(text, isError) {
    els.projectFormMsg.textContent = text;
    els.projectFormMsg.className = 'form-msg ' + (isError ? 'error' : 'success');
  }

  // ---- Init ----

  function init() {
    if (!isConfigured()) {
      els.settingsPanel.hidden = false;
      return;
    }
    loadRegions();
    loadRoads();
    loadProjects();
  }

  buildStateCheckboxes();
  init();
})();
