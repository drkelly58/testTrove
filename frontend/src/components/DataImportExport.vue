<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import {
  apiFetch,
  fetchSuites,
  projectExportUrl,
  workspaceCsvPreview,
  workspaceExportUrl,
  workspaceImport,
  type Suite,
} from '@/api';
import { loadAuthSession, type AuthSessionPayload } from '@/authSession';
import { PROJECT_CONTEXT_KEY } from '@/projectContext';
import { canManageWorkspace, canWriteCatalog } from '@/permissions';

const CSV_FIELDS: { key: string; label: string }[] = [
  { key: 'project_name', label: 'Project name' },
  { key: 'project_description', label: 'Project description' },
  { key: 'suite_name', label: 'Suite name' },
  { key: 'section_name', label: 'Section name' },
  { key: 'section_precondition', label: 'Section precondition' },
  { key: 'title', label: 'Case title' },
  { key: 'precondition', label: 'Precondition' },
  { key: 'priority', label: 'Priority' },
  { key: 'status', label: 'Status' },
  { key: 'steps', label: 'Steps (plain text)' },
  { key: 'expected', label: 'Expected (separate column, optional)' },
];

const projectCtx = inject(PROJECT_CONTEXT_KEY)!;

const authSession = ref<AuthSessionPayload | null>(null);
void loadAuthSession().then((s) => {
  authSession.value = s;
});
const canWorkspaceAdmin = computed(() => canManageWorkspace(authSession.value));
const canImportToProject = computed(() => canWriteCatalog(authSession.value, projectCtx.projectId));

const projects = computed(() => projectCtx.projects);
const loading = computed(() => projectCtx.loading);

const suites = ref<Suite[]>([]);
const message = ref<string | null>(null);
const error = ref<string | null>(null);

const exportSuiteIds = ref<number[]>([]);
const exportFormat = ref<'json' | 'csv'>('json');

const importFile = ref<HTMLInputElement | null>(null);
const importTargetSuiteId = ref(0);
const importTargetProjectId = ref(0);
const importCreateMissing = ref(false);
const importOnDuplicate = ref<'allow' | 'skip' | 'error'>('skip');

const csvImportPending = ref<File | null>(null);
const csvPreviewHeaders = ref<string[]>([]);
const csvColumnMapDraft = ref<Record<string, string>>({});
const csvSuggestedMode = ref<'project' | 'flat'>('flat');
const csvImportMode = ref<'auto' | 'flat' | 'project'>('auto');
const csvPreviewLoading = ref(false);

const allSuitesSelected = computed({
  get() {
    return suites.value.length > 0 && exportSuiteIds.value.length === suites.value.length;
  },
  set(v: boolean) {
    exportSuiteIds.value = v ? suites.value.map((s) => s.id) : [];
  },
});

async function loadSuitesForExport(pid: number) {
  try {
    suites.value = await fetchSuites(pid);
    exportSuiteIds.value = suites.value.map((s) => s.id);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load suites';
    suites.value = [];
    exportSuiteIds.value = [];
  }
}

watch(
  () => projectCtx.projectId,
  async (pid) => {
    if (pid === null) {
      suites.value = [];
      exportSuiteIds.value = [];
      return;
    }
    importTargetProjectId.value = pid;
    await loadSuitesForExport(pid);
  },
  { immediate: true },
);

function toggleSuiteId(id: number) {
  const i = exportSuiteIds.value.indexOf(id);
  if (i === -1) {
    exportSuiteIds.value = [...exportSuiteIds.value, id];
  } else {
    exportSuiteIds.value = exportSuiteIds.value.filter((x) => x !== id);
  }
}

function openImportPicker() {
  importFile.value?.click();
}

function applySuggestedColumnMap(suggested: Record<string, string>) {
  const d: Record<string, string> = {};
  for (const { key } of CSV_FIELDS) {
    d[key] = suggested[key] ?? '';
  }
  csvColumnMapDraft.value = d;
}

function buildColumnMapPayload(): Record<string, string> | undefined {
  const out: Record<string, string> = {};
  for (const { key } of CSV_FIELDS) {
    const v = (csvColumnMapDraft.value[key] ?? '').trim();
    if (v !== '') {
      out[key] = v;
    }
  }
  return Object.keys(out).length > 0 ? out : undefined;
}

function cancelCsvImport() {
  csvImportPending.value = null;
  csvPreviewHeaders.value = [];
  csvColumnMapDraft.value = {};
  csvImportMode.value = 'auto';
}

async function runCsvImport() {
  const file = csvImportPending.value;
  if (!file) {
    return;
  }
  message.value = null;
  error.value = null;
  try {
    const d = await workspaceImport(file, {
      create_missing_entities: importCreateMissing.value,
      on_duplicate: importOnDuplicate.value,
      target_suite_id: importTargetSuiteId.value > 0 ? importTargetSuiteId.value : undefined,
      target_project_id: importTargetProjectId.value > 0 ? importTargetProjectId.value : undefined,
      csv_mode: csvImportMode.value,
      column_map: buildColumnMapPayload(),
    });
    if (d.suites_touched && d.suites_touched.length > 0) {
      message.value = `Imported ${d.imported} case(s); skipped ${d.skipped_duplicates ?? 0} duplicate(s). Suites: ${d.suites_touched.join(', ')}.`;
    } else {
      message.value = `Imported ${d.imported} case(s); skipped ${d.skipped_duplicates ?? 0} duplicate(s) into suite ${d.suite_id ?? '—'}.`;
    }
    cancelCsvImport();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Import failed';
  }
}

async function onImportFile(ev: Event) {
  const input = ev.target as HTMLInputElement;
  const file = input.files?.[0];
  message.value = null;
  error.value = null;
  if (!file) {
    return;
  }
  const lower = file.name.toLowerCase();
  if (lower.endsWith('.csv')) {
    csvImportPending.value = file;
    csvPreviewLoading.value = true;
    csvImportMode.value = 'auto';
    try {
      const prev = await workspaceCsvPreview(file);
      csvPreviewHeaders.value = prev.headers;
      csvSuggestedMode.value = prev.suggested_mode;
      applySuggestedColumnMap(prev.suggested_column_map);
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Could not read CSV preview';
      cancelCsvImport();
    } finally {
      csvPreviewLoading.value = false;
    }
    input.value = '';
    return;
  }

  cancelCsvImport();

  try {
    const d = await workspaceImport(file, {
      create_missing_entities: importCreateMissing.value,
      on_duplicate: importOnDuplicate.value,
      target_suite_id: importTargetSuiteId.value > 0 ? importTargetSuiteId.value : undefined,
    });
    if (d.suites_touched && d.suites_touched.length > 0) {
      message.value = `Imported ${d.imported} case(s); skipped ${d.skipped_duplicates ?? 0} duplicate(s). Suites: ${d.suites_touched.join(', ')}.`;
    } else {
      message.value = `Imported ${d.imported} case(s); skipped ${d.skipped_duplicates ?? 0} duplicate(s) into suite ${d.suite_id ?? '—'}.`;
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Import failed';
  }
  input.value = '';
}

async function downloadExportFile(url: string, fallbackName: string) {
  const res = await apiFetch(url);
  if (res.status === 501) {
    const j = (await res.json()) as { error?: string };
    throw new Error(j.error ?? 'Export not available.');
  }
  if (!res.ok) {
    throw new Error(await res.text());
  }
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition');
  let name = fallbackName;
  const m = cd?.match(/filename="([^"]+)"/);
  if (m) {
    name = m[1];
  }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  URL.revokeObjectURL(a.href);
}

async function runExport() {
  message.value = null;
  error.value = null;
  const pid = projectCtx.projectId;
  if (pid === null) {
    error.value = 'Choose a project.';
    return;
  }
  if (exportSuiteIds.value.length === 0) {
    error.value = 'Select at least one suite to export (or select all).';
    return;
  }
  const url = workspaceExportUrl(pid, exportSuiteIds.value, exportFormat.value);
  try {
    await downloadExportFile(url, `export.${exportFormat.value}`);
    message.value = 'Download started.';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Export failed';
  }
}

async function runExportFullProject() {
  message.value = null;
  error.value = null;
  const pid = projectCtx.projectId;
  if (pid === null) {
    error.value = 'Choose a project.';
    return;
  }
  const url = projectExportUrl(pid, exportFormat.value);
  try {
    await downloadExportFile(url, `export.${exportFormat.value}`);
    message.value = 'Full project download started.';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Export failed';
  }
}
</script>

<template>
  <div class="data-io">
    <p class="data-io-lede">
      Export selected suites or import files. Workspace JSON (v2) can recreate projects and suites when enabled.
      Uses the project selected in the <strong>top bar</strong>.
    </p>

    <div v-if="loading" class="banner muted">Loading…</div>
    <div v-if="message" class="banner ok">{{ message }}</div>
    <div v-if="error" class="banner err">{{ error }}</div>

    <section class="block">
      <h3 class="block-title">Export</h3>
      <p class="hint">
        <strong>Selected suites</strong> uses the checkboxes below (workspace JSON v2 or multi-suite CSV).
        <strong>Full project</strong> downloads every suite in one file, without using the selection.
      </p>

      <label class="field">
        <span class="lab">Suites to include</span>
        <label class="check-line">
          <input v-model="allSuitesSelected" type="checkbox" />
          <span>All suites in project</span>
        </label>
        <ul class="suite-picks">
          <li v-for="s in suites" :key="s.id">
            <label class="check-line">
              <input
                type="checkbox"
                :checked="exportSuiteIds.includes(s.id)"
                @change="toggleSuiteId(s.id)"
              />
              <span>{{ s.name }}</span>
            </label>
          </li>
        </ul>
      </label>

      <label class="field">
        <span class="lab">Format</span>
        <select v-model="exportFormat" class="input">
          <option value="json">JSON (workspace, multiple suites)</option>
          <option value="csv">CSV (workspace, multiple suites)</option>
        </select>
      </label>

      <div class="export-actions">
        <button type="button" class="btn primary" :disabled="projectCtx.projectId === null" @click="runExport">
          Download selected suites
        </button>
        <button type="button" class="btn" :disabled="projectCtx.projectId === null" @click="runExportFullProject">
          Download full project
        </button>
      </div>
    </section>

    <section v-if="canImportToProject || canWorkspaceAdmin" class="block">
      <h3 class="block-title">Import</h3>
      <p class="hint">
        <strong>JSON</strong>: workspace v2 carries project and suite names; flat lists need a target suite.
        <strong>CSV</strong>: choosing a <code>.csv</code> file opens a review step (column mapping and import mode).
      </p>
      <p class="hint">
        The <strong>steps</strong> cell is plain text, one numbered step per line. Put the expected result on the next line as
        <code>Expected: …</code> (or <code>Result: …</code>). Lines starting with <code>*</code> attach a variant to the previous step.
        You can also map a separate <strong>expected</strong> column.
      </p>

      <label class="field">
        <span class="lab">Target project (optional, for CSV)</span>
        <select v-model.number="importTargetProjectId" class="input" :disabled="!projects.length">
          <option :value="0">From CSV (project_name column)</option>
          <option v-for="p in projects" :key="'imp-' + p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </label>

      <label class="field">
        <span class="lab">Target suite (flat JSON / flat CSV only)</span>
        <select v-model.number="importTargetSuiteId" class="input">
          <option :value="0">— Select suite —</option>
          <option v-for="s in suites" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </label>

      <label v-if="canWorkspaceAdmin" class="check-line block">
        <input v-model="importCreateMissing" type="checkbox" />
        <span>Create missing projects &amp; suites when the file defines them</span>
      </label>

      <fieldset class="field dup-field">
        <legend class="lab">Duplicate case titles (same suite)</legend>
        <label class="radio-line">
          <input v-model="importOnDuplicate" type="radio" value="skip" />
          <span>Skip duplicates (recommended)</span>
        </label>
        <label class="radio-line">
          <input v-model="importOnDuplicate" type="radio" value="error" />
          <span>Abort entire import if any duplicate title exists</span>
        </label>
        <label class="radio-line">
          <input v-model="importOnDuplicate" type="radio" value="allow" />
          <span>Allow duplicate titles (insert every row)</span>
        </label>
      </fieldset>

      <div v-if="csvPreviewLoading" class="banner muted">Reading CSV…</div>

      <div v-if="csvImportPending && !csvPreviewLoading" class="csv-review">
        <p class="csv-pending">
          <span class="lab">File</span>
          <strong>{{ csvImportPending.name }}</strong>
        </p>
        <p class="hint">
          Suggested import shape:
          <strong>{{ csvSuggestedMode === 'project' ? 'Project / multi-suite' : 'Flat (single suite)' }}</strong>.
        </p>

        <label class="field">
          <span class="lab">Interpret CSV as</span>
          <select v-model="csvImportMode" class="input">
            <option value="auto">Auto</option>
            <option value="project">Project (suites + cases)</option>
            <option value="flat">Flat (cases only → target suite)</option>
          </select>
        </label>

        <div class="column-map">
          <span class="lab">Column mapping</span>
          <p class="hint tight">Use <strong>Auto</strong> to match typical header names, or pick a column for custom labels.</p>
          <div v-for="row in CSV_FIELDS" :key="row.key" class="map-row">
            <span class="map-label">{{ row.label }}</span>
            <select v-model="csvColumnMapDraft[row.key]" class="input input-sm">
              <option value="">Auto</option>
              <option v-for="h in csvPreviewHeaders" :key="row.key + '\0' + h" :value="h">{{ h }}</option>
            </select>
          </div>
        </div>

        <div class="btn-row">
          <button type="button" class="btn primary" @click="runCsvImport">Import this CSV</button>
          <button type="button" class="btn" @click="cancelCsvImport">Cancel</button>
        </div>
      </div>

      <input
        ref="importFile"
        type="file"
        class="sr-only"
        accept=".csv,.json,application/json,text/csv"
        @change="onImportFile"
      />
      <button type="button" class="btn primary" @click="openImportPicker">Choose file to import…</button>
    </section>
  </div>
</template>

<style scoped>
.data-io-lede {
  margin: 0 0 1rem;
  color: var(--muted);
  font-size: 0.84rem;
  line-height: 1.45;
}

.banner {
  padding: 0.55rem 0.75rem;
  border-radius: 8px;
  margin-bottom: 0.75rem;
  font-size: 0.88rem;
}

.banner.muted {
  background: var(--panel-2);
  color: var(--muted);
}

.banner.ok {
  background: color-mix(in srgb, var(--success) 14%, var(--panel-2));
  border: 1px solid color-mix(in srgb, var(--success) 45%, var(--border));
  color: var(--text);
}

.banner.err {
  background: color-mix(in srgb, var(--danger) 12%, var(--panel-2));
  border: 1px solid color-mix(in srgb, var(--danger) 45%, var(--border));
  color: var(--danger);
}

.block {
  padding: 0 0 1.25rem;
  margin-bottom: 0.5rem;
  border-bottom: 1px solid color-mix(in srgb, var(--border) 70%, transparent);
}

.block:last-child {
  border-bottom: none;
  margin-bottom: 0;
  padding-bottom: 0;
}

.block-title {
  margin: 0 0 0.35rem;
  font-size: 0.95rem;
  font-weight: 700;
}

.hint {
  margin: 0 0 0.85rem;
  color: var(--muted);
  font-size: 0.8rem;
  line-height: 1.45;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
}

.lab {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
}

.input {
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.65rem;
  font: inherit;
  font-size: 0.88rem;
}

.suite-picks {
  list-style: none;
  margin: 0.25rem 0 0;
  padding: 0;
  max-height: 8rem;
  overflow: auto;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: color-mix(in srgb, var(--panel-2) 88%, transparent);
}

.suite-picks li {
  padding: 0.25rem 0.5rem;
  border-bottom: 1px solid color-mix(in srgb, var(--border) 60%, transparent);
}

.suite-picks li:last-child {
  border-bottom: none;
}

.check-line {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.86rem;
  cursor: pointer;
}

.check-line.block {
  margin-bottom: 0.85rem;
}

.dup-field {
  border: none;
  margin: 0 0 1rem;
  padding: 0;
}

.radio-line {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin-bottom: 0.35rem;
  font-size: 0.84rem;
  cursor: pointer;
}

.btn {
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.85rem;
  font: inherit;
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
}

.btn.primary {
  border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--accent) 35%, var(--panel-2)),
    var(--panel-2)
  );
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.export-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.hint.tight {
  margin-top: 0.25rem;
  margin-bottom: 0.65rem;
}

.csv-review {
  margin: 0.75rem 0 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: color-mix(in srgb, var(--panel-2) 90%, transparent);
}

.csv-pending {
  margin: 0 0 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.column-map {
  margin: 0.75rem 0;
}

.map-row {
  display: grid;
  grid-template-columns: minmax(0, 9.5rem) 1fr;
  gap: 0.5rem;
  align-items: center;
  margin-bottom: 0.45rem;
}

.map-label {
  font-size: 0.78rem;
  color: var(--muted);
}

.input-sm {
  padding: 0.35rem 0.5rem;
  font-size: 0.82rem;
}

.btn-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
