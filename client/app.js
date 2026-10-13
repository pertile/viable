(function () {
  'use strict';

  const STORAGE_KEY = 'viable_events_client_config';
  const STATE_CHOICES = ['Proyecto', 'En licitación', 'Adjudicado', 'En obras', 'Paralizado', 'Finalizado', 'Cancelado'];
  const STATE_NUMBERS = { 'Proyecto': 0, 'En licitación': 10, 'Adjudicado': 20, 'En obras': 30, 'Paralizado': 40, 'Finalizado': 50, 'Cancelado': 60 };
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
    btnDeleteEvent: document.getElementById('btn-delete-event'),
    evDate: document.getElementById('ev-date'),
    evUrl: document.getElementById('ev-url'),
    evDescription: document.getElementById('ev-description'),
    evProgress: document.getElementById('ev-progress'),
    evState: document.getElementById('ev-state'),
    evSummary: document.getElementById('ev-summary'),
    evEndDate: document.getElementById('ev-end-date'),
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
    prGoogleSearch: document.getElementById('pr-google-search'),
    prArticleCompletionPending: document.getElementById('pr-article-completion-pending'),
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
    prRoadsChips: document.getElementById('pr-roads-chips'),
    prRoadsSearch: document.getElementById('pr-roads-search'),
    prRoadsResults: document.getElementById('pr-roads-results'),
  };

  let config = loadConfig();
  let projects = [];
  let regionsCache = [];
  let allRoadsCache = [];
  let selectedRoads = [];
  let eventDialogProject = null;
  let eventDialogInitialEndDate = '';
  let editingProjectId = null;
  let editingEventId = null;
  const expandedProjectIds = new Set();

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

  function buildStateCheckboxes(initialStates) {
    els.filterStates.querySelectorAll('label').forEach((l) => l.remove());
    STATE_CHOICES.forEach((state) => {
      const label = document.createElement('label');
      label.className = 'inline-check';
      const checked = initialStates ? initialStates.includes(state) : DEFAULT_EXCLUDED_STATES.indexOf(state) === -1;
      label.innerHTML = `<input type="checkbox" value="${escapeHtml(state)}" ${checked ? 'checked' : ''}> ${escapeHtml(state)}`;
      label.querySelector('input').addEventListener('change', onFiltersChanged);
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
        const label = '—'.repeat(r.depth) + ' ' + r.name;

        const opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = label;
        els.filterRegion.appendChild(opt);

        // En el formulario de proyecto van como checkboxes: un proyecto puede
        // tener varias regiones y con un <select multiple> hay que saberlo
        // (ctrl+clic) para no pisar la selección anterior.
        const check = document.createElement('label');
        check.className = 'inline-check';
        check.innerHTML = `<input type="checkbox" value="${r.id}"> ${escapeHtml(label)}`;
        els.prRegions.appendChild(check);
      });
    } catch (e) {
      // Silencioso: si falla, el filtro de región simplemente queda en "Todas".
    }
  }

  async function loadRoads() {
    try {
      // /wp/v2/tags limita a 100 por página — hay más de 100 rutas cargadas,
      // así que hay que recorrer todas las páginas o el buscador/creador
      // termina ofreciendo "crear" rutas que en realidad ya existen.
      const all = [];
      let page = 1;
      while (true) {
        const batch = await apiFetch('/wp/v2/tags?per_page=100&orderby=name&order=asc&page=' + page);
        all.push(...batch);
        if (batch.length < 100) break;
        page++;
      }
      allRoadsCache = all;
    } catch (e) {
      console.error('No se pudieron cargar las rutas existentes:', e);
    }
  }

  // ---- Selector de rutas (tags) con búsqueda y creación ----

  function renderRoadChips() {
    els.prRoadsChips.innerHTML = '';
    selectedRoads.forEach((r) => {
      const chip = document.createElement('span');
      chip.className = 'tag-chip';
      chip.innerHTML = `${escapeHtml(r.name)} <button type="button" aria-label="Quitar">×</button>`;
      chip.querySelector('button').addEventListener('click', () => {
        selectedRoads = selectedRoads.filter((x) => x.id !== r.id);
        renderRoadChips();
      });
      els.prRoadsChips.appendChild(chip);
    });
  }

  function addRoad(road) {
    if (!selectedRoads.some((r) => r.id === road.id)) {
      selectedRoads.push(road);
    }
    els.prRoadsSearch.value = '';
    els.prRoadsResults.innerHTML = '';
    renderRoadChips();
    els.prRoadsSearch.focus();
  }

  async function createAndAddRoad(name) {
    try {
      const created = await apiFetch('/wp/v2/tags', {
        method: 'POST',
        body: JSON.stringify({ name: name }),
      });
      allRoadsCache.push(created);
      addRoad({ id: created.id, name: created.name });
    } catch (e) {
      showProjectFormMessage('No se pudo crear la ruta: ' + e.message, true);
    }
  }

  function renderRoadResults(query) {
    const q = query.trim().toLowerCase();
    els.prRoadsResults.innerHTML = '';

    if (!q) return;

    const selectedIds = new Set(selectedRoads.map((r) => r.id));
    const matches = allRoadsCache
      .filter((r) => !selectedIds.has(r.id) && r.name.toLowerCase().includes(q))
      .slice(0, 8);

    matches.forEach((r) => {
      const li = document.createElement('li');
      li.textContent = r.name;
      li.addEventListener('click', () => addRoad({ id: r.id, name: r.name }));
      els.prRoadsResults.appendChild(li);
    });

    const exactMatch = allRoadsCache.some((r) => r.name.toLowerCase() === q);
    if (!exactMatch) {
      const li = document.createElement('li');
      li.className = 'tag-create-option';
      li.textContent = '+ Crear ruta "' + query.trim() + '"';
      li.addEventListener('click', () => createAndAddRoad(query.trim()));
      els.prRoadsResults.appendChild(li);
    }
  }

  els.prRoadsSearch.addEventListener('input', () => renderRoadResults(els.prRoadsSearch.value));

  // ---- Filtros en la URL ----
  // Así se puede recargar la página o compartir el link sin perder los
  // filtros aplicados (región, código, estados seleccionados).

  function updateUrlFromFilters() {
    const params = new URLSearchParams();
    const states = getSelectedStates();
    const region = els.filterRegion.value;
    const code = els.filterCode.value.trim();
    if (states.length) params.set('states', states.join(','));
    if (region) params.set('region', region);
    if (code) params.set('code', code);
    const qs = params.toString();
    history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
  }

  function onFiltersChanged() {
    updateUrlFromFilters();
    loadProjects();
  }

  els.filterRegion.addEventListener('change', onFiltersChanged);

  let codeFilterTimer = null;
  els.filterCode.addEventListener('input', () => {
    clearTimeout(codeFilterTimer);
    codeFilterTimer = setTimeout(onFiltersChanged, 300);
  });

  // ---- Tabla de proyectos ----

  async function loadProjects() {
    if (!isConfigured()) {
      els.tbody.innerHTML = '<tr><td colspan="12" class="hint">Configurá la conexión primero.</td></tr>';
      return;
    }

    els.tbody.innerHTML = '<tr><td colspan="12" class="hint">Cargando...</td></tr>';

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
      els.tbody.innerHTML = '<tr><td colspan="12" class="form-msg error">' + escapeHtml(e.message) + '</td></tr>';
    }
  }

  function googleSearchUrl(project) {
    let q = (project.google_search && project.google_search.trim()) || project.title;
    if (project.last_verified_on) {
      const parts = project.last_verified_on.split('/'); // d/m/Y
      if (parts.length === 3) {
        const [d, m, y] = parts;
        q += ' after:' + y + '-' + m.padStart(2, '0') + '-' + d.padStart(2, '0');
      }
    }
    return 'https://www.google.com/search?q=' + encodeURIComponent(q);
  }

  function wpAdminEditUrl(projectId) {
    return config.siteUrl.replace(/\/$/, '') + '/wp-admin/post.php?post=' + projectId + '&action=edit';
  }

  function renderTable() {
    els.selectAll.checked = false;
    updateBulkBar();

    if (!projects.length) {
      els.tbody.innerHTML = '<tr><td colspan="12" class="hint">No hay proyectos con estos filtros.</td></tr>';
      return;
    }

    els.tbody.innerHTML = '';
    projects.forEach((p) => {
      const tr = document.createElement('tr');

      const lastEvent = p.last_event
        ? `<div class="last-event-date">${escapeHtml(p.last_event.date)}</div><div class="last-event-text">${escapeHtml(p.last_event.summary || p.last_event.description || '')}</div>`
        : '<span class="hint">—</span>';

      const regionsText = p.regions && p.regions.length ? escapeHtml(p.regions.join(', ')) : '<span class="hint">—</span>';
      const roadsText = p.roads && p.roads.length ? escapeHtml(p.roads.join(', ')) : '<span class="hint">—</span>';
      const titleTooltip = p.short_description ? ` title="${escapeHtml(p.short_description)}"` : '';
      const titleText = p.has_content && p.url
        ? `<a href="${escapeHtml(p.url)}" target="_blank" rel="noopener"${titleTooltip}>${escapeHtml(p.title)}</a>`
        : `<span${titleTooltip}>${escapeHtml(p.title)}</span>`;

      tr.innerHTML = `
        <td><input type="checkbox" class="row-check" data-id="${p.id}"></td>
        <td>${p.code ? escapeHtml(p.code) : '<span class="hint">—</span>'}</td>
        <td>${p.type ? escapeHtml(p.type) : '<span class="hint">—</span>'}</td>
        <td>${titleText}</td>
        <td>${regionsText}</td>
        <td>${roadsText}</td>
        <td><span class="state-tag state-${escapeHtml((p.state || '').toLowerCase())}">${escapeHtml(p.state || '—')}</span></td>
        <td>${p.article_completion_pending ? '<span class="article-pending-tag">Pendiente</span>' : '<span class="hint">—</span>'}</td>
        <td>${p.progress !== null && p.progress !== '' ? escapeHtml(String(p.progress)) + '%' : '—'}</td>
        <td>${lastEvent}</td>
        <td>${p.last_verified_on ? escapeHtml(p.last_verified_on) : '<span class="hint">—</span>'}</td>
        <td class="row-actions">
          <button class="btn-icon btn-toggle-events" title="Ver eventos">${expandedProjectIds.has(p.id) ? '▾' : '▸'}</button>
          <button class="btn-icon btn-edit-project" title="Editar proyecto">✏️</button>
          <a class="btn-icon btn-wp-edit" title="Editar en WordPress" href="${wpAdminEditUrl(p.id)}" target="_blank" rel="noopener">📝</a>
          <button class="btn-icon btn-add-event" title="Agregar evento">🕒+</button>
          <a class="btn-icon btn-google" title="Buscar en Google" href="${googleSearchUrl(p)}" target="_blank" rel="noopener">🔎</a>
        </td>
      `;

      tr.querySelector('.row-check').addEventListener('change', updateBulkBar);
      tr.querySelector('.btn-add-event').addEventListener('click', () => openEventDialog(p));
      tr.querySelector('.btn-edit-project').addEventListener('click', () => openProjectDialog(p.id));

      const toggleBtn = tr.querySelector('.btn-toggle-events');
      toggleBtn.addEventListener('click', () => {
        if (expandedProjectIds.has(p.id)) {
          expandedProjectIds.delete(p.id);
          removeEventsRow(p.id);
          toggleBtn.textContent = '▸';
        } else {
          expandedProjectIds.add(p.id);
          toggleBtn.textContent = '▾';
          expandEvents(p, tr);
        }
      });

      els.tbody.appendChild(tr);
      if (expandedProjectIds.has(p.id)) {
        expandEvents(p, tr);
      }
    });
  }

  // ---- Eventos desplegados por proyecto ----

  function removeEventsRow(projectId) {
    const existing = els.tbody.querySelector('.events-row[data-project-id="' + projectId + '"]');
    if (existing) existing.remove();
  }

  function formatIsoToDmy(iso) {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return d + '/' + m + '/' + y;
  }

  async function expandEvents(project, rowEl) {
    removeEventsRow(project.id);

    const tr = document.createElement('tr');
    tr.className = 'events-row';
    tr.dataset.projectId = project.id;
    const td = document.createElement('td');
    td.colSpan = 11;
    td.innerHTML = '<div class="events-panel hint">Cargando eventos...</div>';
    tr.appendChild(td);
    rowEl.after(tr);

    try {
      const events = await apiFetch('/wp/v2/project-events?project=' + project.id + '&per_page=100');
      events.sort((a, b) => {
        const dateA = normalizeDateForInput((a.acf || {}).event_date);
        const dateB = normalizeDateForInput((b.acf || {}).event_date);
        return dateB.localeCompare(dateA);
      });
      renderEventsPanel(td, project, events);
    } catch (e) {
      td.innerHTML = '<div class="events-panel form-msg error">' + escapeHtml(e.message) + '</div>';
    }
  }

  function renderEventsPanel(td, project, events) {
    if (!events.length) {
      td.innerHTML = '<div class="events-panel hint">Sin eventos cargados todavía.</div>';
      return;
    }

    td.innerHTML = '<div class="events-panel"></div>';
    const panel = td.querySelector('.events-panel');

    events.forEach((ev) => {
      const acf = ev.acf || {};
      const dateDisplay = formatIsoToDmy(normalizeDateForInput(acf.event_date));
      const text = acf.url
        ? `<a href="${escapeHtml(acf.url)}" target="_blank" rel="noopener">${escapeHtml(acf.url)}</a>`
        : escapeHtml(acf.description || '');

      const extras = [];
      if (acf.progress !== '' && acf.progress !== null && acf.progress !== undefined) extras.push(escapeHtml(String(acf.progress)) + '%');
      if (acf.state) extras.push(escapeHtml(acf.state));

      const item = document.createElement('div');
      item.className = 'event-item';
      item.innerHTML = `
        <div class="event-item-main">
          <span class="event-item-date">${escapeHtml(dateDisplay)}</span>
          ${extras.length ? '<span class="event-item-extra">' + extras.join(' · ') + '</span>' : ''}
          <button type="button" class="btn-link btn-edit-event">Editar</button>
          <button type="button" class="btn-link btn-delete-event">Eliminar</button>
        </div>
        <div class="event-item-text">${text}</div>
        ${acf.summary ? '<div class="event-item-summary">' + escapeHtml(acf.summary) + '</div>' : ''}
      `;
      item.querySelector('.btn-edit-event').addEventListener('click', () => openEventDialog(project, ev));
      item.querySelector('.btn-delete-event').addEventListener('click', () => deleteEvent(project, ev));
      panel.appendChild(item);
    });
  }

  async function deleteEvent(project, event) {
    if (!confirm('¿Eliminar este evento? Esta acción no se puede deshacer.')) return;
    try {
      await apiFetch('/wp/v2/project-events/' + event.id + '?force=true', { method: 'DELETE' });
      await loadProjects();
    } catch (e) {
      alert('No se pudo eliminar el evento: ' + e.message);
    }
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

  // ---- Popup de nuevo/editar evento ----

  function openEventDialog(project, editEvent) {
    eventDialogProject = project;
    editingEventId = editEvent ? editEvent.id : null;
    const acf = (editEvent && editEvent.acf) || {};

    els.eventDialogTitle.textContent = (editEvent ? 'Editar evento — ' : 'Nuevo evento — ') + project.title;
    els.eventForm.reset();
    els.evDate.value = editEvent ? normalizeDateForInput(acf.event_date) : todayIso();
    els.evUrl.value = acf.url || '';
    els.evDescription.value = acf.description || '';
    els.evProgress.value = (acf.progress || acf.progress === 0) ? acf.progress : '';
    els.evState.value = acf.state || '';
    els.evSummary.value = acf.summary || '';
    eventDialogInitialEndDate = normalizeDateForInput(project.end_date);
    els.evEndDate.value = eventDialogInitialEndDate;
    els.eventFormMsg.textContent = '';
    els.eventFormMsg.className = 'form-msg';
    els.btnDeleteEvent.hidden = !editEvent;
    els.eventDialog.showModal();
  }

  els.btnCancelEvent.addEventListener('click', () => {
    els.eventDialog.close();
  });

  els.btnDeleteEvent.addEventListener('click', async () => {
    if (!editingEventId || !eventDialogProject) return;
    if (!confirm('¿Eliminar este evento? Esta acción no se puede deshacer.')) return;
    try {
      await apiFetch('/wp/v2/project-events/' + editingEventId + '?force=true', { method: 'DELETE' });
      els.eventDialog.close();
      await loadProjects();
    } catch (e) {
      showEventFormMessage('No se pudo eliminar: ' + e.message, true);
    }
  });

  els.eventForm.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    els.eventFormMsg.textContent = '';
    els.eventFormMsg.className = 'form-msg';

    if (!eventDialogProject) return;

    const eventProgress = els.evProgress.value !== '' ? Number(els.evProgress.value) : Number(eventDialogProject.progress || 0);
    const eventState = els.evState.value || eventDialogProject.state;
    if (eventProgress > 0 && (STATE_NUMBERS[eventState] || 0) < 30) {
      showEventFormMessage('Con avance mayor a 0, el estado debe ser En obras, Paralizado, Finalizado o Cancelado.', true);
      return;
    }

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
      const path = editingEventId ? '/wp/v2/project-events/' + editingEventId : '/wp/v2/project-events';
      await apiFetch(path, {
        method: editingEventId ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      });

      // Endpoint propio en vez de PUT /wp/v2/project/{id}: ACF marca como
      // obligatorios en el schema REST a code/short_description/state/roads,
      // así que un acf parcial con sólo end_date lo rechaza WordPress con
      // "code es una propiedad necesaria de acf".
      if (els.evEndDate.value !== eventDialogInitialEndDate) {
        await apiFetch('/viable/v1/project-end-date', {
          method: 'POST',
          body: JSON.stringify({
            project_id: eventDialogProject.id,
            end_date: els.evEndDate.value,
          }),
        });
      }

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
    setCheckedRegions([]);
    selectedRoads = [];
    els.prRoadsSearch.value = '';
    els.prRoadsResults.innerHTML = '';
    renderRoadChips();
    els.prDuplicationTypeWrap.hidden = true;
    els.projectFormMsg.textContent = '';
    els.projectFormMsg.className = 'form-msg';
  }

  function regionCheckboxes() {
    return Array.from(els.prRegions.querySelectorAll('input[type="checkbox"]'));
  }

  function setCheckedRegions(ids) {
    const idSet = new Set((ids || []).map(String));
    regionCheckboxes().forEach((cb) => { cb.checked = idSet.has(cb.value); });
  }

  function getCheckedRegions() {
    return regionCheckboxes().filter((cb) => cb.checked).map((cb) => Number(cb.value));
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
    els.prGoogleSearch.value = acf.google_search || '';
    els.prArticleCompletionPending.checked = Boolean(acf.article_completion_pending);
    els.prState.value = acf.state || 'Proyecto';
    els.prProgress.value = (acf.progress || acf.progress === 0) ? acf.progress : '';
    els.prProgressUpdatedOn.value = normalizeDateForInput(acf.progress_updated_on);
    els.prLastVerifiedOn.value = normalizeDateForInput(acf.last_verified_on);
    els.prDuration.value = acf.duration || acf.duration === 0 ? acf.duration : '';
    els.prBidDate.value = normalizeDateForInput(acf.bid_date);
    els.prAwardDate.value = normalizeDateForInput(acf.award_date);
    els.prStartDate.value = normalizeDateForInput(acf.start_date);
    els.prEndDate.value = normalizeDateForInput(acf.end_date);
    setCheckedRegions(acf.regions);

    selectedRoads = (acf.roads || []).map((id) => {
      const found = allRoadsCache.find((r) => r.id === id);
      return found ? { id: found.id, name: found.name } : { id: id, name: 'Ruta #' + id };
    });
    renderRoadChips();
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
      els.prArticleCompletionPending.checked = false;
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

  els.projectForm.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    showProjectFormMessage('', false);

    if (!selectedRoads.length) {
      showProjectFormMessage('Elegí al menos una ruta.', true);
      return;
    }

    if (Number(els.prProgress.value) > 0 && STATE_NUMBERS[els.prState.value] < 30) {
      showProjectFormMessage('Con avance mayor a 0, el estado debe ser En obras, Paralizado, Finalizado o Cancelado.', true);
      return;
    }

    const acf = {
      code: els.prCode.value.trim(),
      short_description: els.prShortDescription.value.trim(),
      state: els.prState.value,
      roads: selectedRoads.map((r) => r.id),
      article_completion_pending: els.prArticleCompletionPending.checked ? 1 : 0,
    };
    // Select con opción vacía ("— sin especificar —") o listas de taxonomía
    // vacías: si no hay valor, omitir la clave en vez de mandar "" o [] —
    // ACF exige al menos 1 item / una de las choices definidas, así que
    // mandar el "vacío" explícito rompe la request.
    const regions = getCheckedRegions();
    if (regions.length) acf.regions = regions;
    if (els.prType.value) acf.type = els.prType.value;
    if (!els.prDuplicationTypeWrap.hidden && els.prDuplicationType.value) {
      acf.duplication_type = els.prDuplicationType.value;
    }
    if (els.prLength.value !== '') acf.length = Number(els.prLength.value);
    if (els.prGoogleSearch.value.trim() !== '') acf.google_search = els.prGoogleSearch.value.trim();
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

  async function init() {
    if (!isConfigured()) {
      els.settingsPanel.hidden = false;
      return;
    }
    await loadRegions();
    const urlRegion = new URLSearchParams(location.search).get('region');
    if (urlRegion) els.filterRegion.value = urlRegion;
    updateUrlFromFilters();
    loadRoads();
    loadProjects();
  }

  (function applyInitialFiltersFromUrl() {
    const params = new URLSearchParams(location.search);
    const states = params.has('states') ? params.get('states').split(',').filter(Boolean) : null;
    buildStateCheckboxes(states);
    els.filterCode.value = params.get('code') || '';
  })();

  init();
})();
