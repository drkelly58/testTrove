<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchRun, updateRun, updateRunItem, type RunDetail, type RunItemDetail, type RunItemSeverity } from '@/api';

const route = useRoute();
const router = useRouter();

const runId = computed(() => parseInt(String(route.params.runId), 10));
const loading = ref(true);
const error = ref<string | null>(null);
const run = ref<RunDetail | null>(null);
const items = ref<RunItemDetail[]>([]);
const index = ref(0);
const notesDraft = ref('');
const severityDraft = ref<RunItemSeverity>('unclear');
const screenshotDrafts = ref<string[]>(['']);
const videoDraft = ref('');
const saving = ref(false);
const runStateBusy = ref(false);

const current = computed(() => items.value[index.value] ?? null);
const total = computed(() => items.value.length);
const position = computed(() => (total.value ? index.value + 1 : 0));
const showSectionBadge = computed(() => {
  const names = Array.from(new Set(items.value.map((i) => i.section_name || 'Default')));
  return names.length > 1 || names[0] !== 'Default';
});
const sectionGroups = computed(() => {
  const groups: Array<{ name: string; start: number; count: number }> = [];
  for (const [i, item] of items.value.entries()) {
    const name = item.section_name || 'Default';
    const last = groups[groups.length - 1];
    if (last && last.name === name) {
      last.count += 1;
    } else {
      groups.push({ name, start: i, count: 1 });
    }
  }
  return groups;
});

watch(
  () => runId.value,
  async (id) => {
    if (!Number.isFinite(id) || id <= 0) {
      error.value = 'Invalid run';
      return;
    }
    loading.value = true;
    error.value = null;
    index.value = 0;
    try {
      const data = await fetchRun(id);
      run.value = data.run;
      items.value = data.items.map((row) => ({
        ...row,
        severity:
          row.severity === 'breaking' || row.severity === 'ui_only' || row.severity === 'unclear'
            ? row.severity
            : 'unclear',
        screenshots: Array.isArray(row.screenshots) ? row.screenshots : [],
        video_url: row.video_url ?? null,
      }));
      syncNotesDraft();
      syncMediaDrafts();
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load run';
      run.value = null;
      items.value = [];
    } finally {
      loading.value = false;
    }
  },
  { immediate: true },
);

watch(current, () => {
  syncNotesDraft();
  syncSeverityDraft();
  syncMediaDrafts();
});

function syncNotesDraft() {
  notesDraft.value = current.value?.notes ?? '';
}

function syncSeverityDraft() {
  const sev = current.value?.severity;
  severityDraft.value =
    sev === 'breaking' || sev === 'ui_only' || sev === 'unclear' ? sev : 'unclear';
}

function syncMediaDrafts() {
  const shots = current.value?.screenshots;
  if (Array.isArray(shots) && shots.length > 0) {
    screenshotDrafts.value = [...shots];
  } else {
    screenshotDrafts.value = [''];
  }
  videoDraft.value = current.value?.video_url ? String(current.value.video_url) : '';
}

function addScreenshotRow() {
  screenshotDrafts.value = [...screenshotDrafts.value, ''];
}

function removeScreenshotRow(idx: number) {
  const next = screenshotDrafts.value.filter((_, i) => i !== idx);
  screenshotDrafts.value = next.length > 0 ? next : [''];
}

function itemUpdatePayload(result: string, mode: 'setResult' | 'notes') {
  const notes = notesDraft.value.trim() || null;
  const base: {
    result: string;
    notes: string | null;
    severity?: RunItemSeverity;
    screenshots?: string[];
    video_url?: string | null;
  } = { result, notes };

  if (result === 'fail') {
    const shots = screenshotDrafts.value.map((s) => s.trim()).filter((s) => s !== '');
    const vid = videoDraft.value.trim();
    return {
      ...base,
      severity: severityDraft.value,
      screenshots: shots,
      video_url: vid ? vid : null,
    };
  }

  if (mode === 'setResult') {
    return {
      ...base,
      severity: 'unclear',
      screenshots: [],
      video_url: null,
    };
  }

  return base;
}

async function setRunLocked() {
  const id = runId.value;
  const r = run.value;
  if (!r || !Number.isFinite(id)) {
    return;
  }
  runStateBusy.value = true;
  error.value = null;
  try {
    const updated = await updateRun(id, { state: 'locked' });
    run.value = { ...r, ...updated };
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not lock run';
  } finally {
    runStateBusy.value = false;
  }
}

async function setRunArchived() {
  const id = runId.value;
  const r = run.value;
  if (!r || !Number.isFinite(id)) {
    return;
  }
  runStateBusy.value = true;
  error.value = null;
  try {
    const updated = await updateRun(id, { state: 'archived' });
    run.value = { ...r, ...updated };
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not archive run';
  } finally {
    runStateBusy.value = false;
  }
}

function goPrev() {
  if (index.value > 0) {
    index.value -= 1;
  }
}

function goNext() {
  if (index.value < items.value.length - 1) {
    index.value += 1;
  }
}

async function patchCurrentItem(result: string, mode: 'setResult' | 'notes', advance: boolean) {
  const item = current.value;
  const id = runId.value;
  if (!item || !Number.isFinite(id)) {
    return;
  }
  saving.value = true;
  error.value = null;
  try {
    const res = await updateRunItem(id, item.id, itemUpdatePayload(result, mode));
    item.result = res.result;
    item.severity = res.severity;
    item.notes = res.notes;
    item.screenshots = res.screenshots;
    item.video_url = res.video_url;
    item.executed_at = res.executed_at;
    if (advance && index.value < items.value.length - 1) {
      index.value += 1;
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Save failed';
  } finally {
    saving.value = false;
  }
}

async function setResult(result: string) {
  if (result === 'fail') {
    const item = current.value;
    if (item?.result !== 'fail') {
      await patchCurrentItem('fail', 'setResult', false);
      return;
    }
    await patchCurrentItem('fail', 'setResult', true);
    return;
  }
  await patchCurrentItem(result, 'setResult', true);
}
</script>

<template>
  <div class="session">
    <header class="top-bar">
      <button type="button" class="back" @click="router.push('/runs')">← Runs</button>
      <div v-if="run" class="run-title">
        <h1>{{ run.name }}</h1>
        <p v-if="run.suite_name || run.section_name" class="run-sub">
          <template v-if="run.suite_name">{{ run.suite_name }}</template>
          <template v-if="run.suite_name && run.section_name"> · </template>
          <template v-if="run.section_name">{{ run.section_name }}</template>
        </p>
        <div class="run-head-meta">
          <span class="badge">{{
            run.run_kind === 'full_suite' ? 'Full suite run' : run.run_kind === 'section' ? 'Section run' : run.run_kind
          }}</span>
          <span class="state-badge">{{ run.state }}</span>
          <div v-if="run.state !== 'archived'" class="run-state-actions">
            <button
              v-if="run.state === 'open'"
              type="button"
              class="btn sm ghost"
              :disabled="runStateBusy"
              @click="setRunLocked"
            >
              Lock run
            </button>
            <button
              v-if="run.state === 'open' || run.state === 'locked'"
              type="button"
              class="btn sm ghost"
              :disabled="runStateBusy"
              @click="setRunArchived"
            >
              Archive run
            </button>
          </div>
        </div>
      </div>
    </header>

    <div v-if="loading" class="state">Loading run…</div>
    <div v-else-if="error" class="state err">{{ error }}</div>
    <div v-else-if="!items.length" class="state">This run has no cases (suite was empty when started).</div>

    <template v-else-if="current">
      <div class="progress">
        Case {{ position }} / {{ total }}
        <span class="pill" :class="'r-' + current.result">{{ current.result }}</span>
        <span v-if="showSectionBadge" class="section-pill">{{ current.section_name || 'Default' }}</span>
      </div>

      <nav v-if="showSectionBadge" class="sections-nav" aria-label="Run sections">
        <button
          v-for="group in sectionGroups"
          :key="group.name + group.start"
          type="button"
          class="section-jump"
          :class="{ active: index >= group.start && index < group.start + group.count }"
          @click="index = group.start"
        >
          {{ group.name }} <span>{{ group.count }}</span>
        </button>
      </nav>

      <article class="card">
        <h2>{{ current.title }}</h2>
        <p v-if="current.precondition" class="pre"><strong>Precondition:</strong> {{ current.precondition }}</p>
        <ol class="steps">
          <li v-for="(st, i) in current.steps" :key="i">
            <div class="sa">{{ st.action }}</div>
            <div class="se">Expect: {{ st.expected }}</div>
          </li>
        </ol>

        <label class="notes-lab">
          <span>Notes</span>
          <textarea
            v-model="notesDraft"
            class="notes"
            rows="8"
            placeholder="Evidence, bug id, environment…"
          />
        </label>

        <template v-if="current.result === 'fail'">
          <label class="notes-lab">
            <span>Failure impact</span>
            <select v-model="severityDraft" class="severity-select" :disabled="saving">
              <option value="breaking">Breaking (blocks ship / core path)</option>
              <option value="ui_only">UI only</option>
              <option value="unclear">Unclear</option>
            </select>
          </label>

          <div class="media-block">
            <div class="media-head">Screenshot URLs</div>
            <p class="media-hint">https links to images (e.g. hosted in your bug tracker).</p>
            <div v-for="(row, idx) in screenshotDrafts" :key="'shot-' + idx" class="shot-row">
              <input
                v-model="screenshotDrafts[idx]"
                type="url"
                class="shot-input"
                placeholder="https://…"
                :disabled="saving"
              />
              <button type="button" class="btn sm ghost rm-shot" :disabled="saving" @click="removeScreenshotRow(idx)">
                Remove
              </button>
            </div>
            <button type="button" class="btn sm ghost add-shot" :disabled="saving" @click="addScreenshotRow">
              Add screenshot URL
            </button>

            <label class="notes-lab video-lab">
              <span>Video URL (optional)</span>
              <input v-model="videoDraft" type="url" class="video-input" placeholder="https://…" :disabled="saving" />
            </label>
          </div>
        </template>

        <p v-if="current.result === 'fail'" class="fail-flow-hint">
          Add failure impact and links if needed, then press the button again to continue to the next case.
        </p>

        <div class="actions">
          <button type="button" class="btn pass" :disabled="saving" @click="setResult('pass')">Pass</button>
          <button
            type="button"
            class="btn fail"
            :disabled="saving"
            :title="current.result === 'fail' ? 'Save failure details and go to next case' : 'Mark as failed (stay on this case)'"
            @click="setResult('fail')"
          >
            {{ current.result === 'fail' ? 'Save failure & next' : 'Fail' }}
          </button>
          <button type="button" class="btn block" :disabled="saving" @click="setResult('blocked')">Blocked</button>
          <button type="button" class="btn skip" :disabled="saving" @click="setResult('skipped')">Skip</button>
          <button type="button" class="btn ghost" :disabled="saving" @click="setResult('untested')">Clear result</button>
        </div>
      </article>

      <nav class="pager">
        <button type="button" class="btn" :disabled="index <= 0" @click="goPrev">Previous case</button>
        <button type="button" class="btn" :disabled="index >= items.length - 1" @click="goNext">Next case</button>
      </nav>
    </template>
  </div>
</template>

<style scoped>
.session {
  max-width: 720px;
}

.top-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.back {
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--muted);
  border-radius: 8px;
  padding: 0.4rem 0.65rem;
  font: inherit;
  cursor: pointer;
}

.back:hover {
  color: var(--text);
}

.run-title h1 {
  margin: 0;
  font-size: 1.15rem;
}

.run-sub {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
  color: var(--muted);
  line-height: 1.35;
}

.run-head-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
  margin-top: 0.35rem;
}

.state-badge {
  font-size: 0.72rem;
  padding: 0.2rem 0.45rem;
  border-radius: 6px;
  border: 1px solid color-mix(in srgb, var(--accent-2) 40%, var(--border));
  color: var(--accent-2);
  text-transform: lowercase;
}

.run-state-actions {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.btn.sm.ghost {
  font-size: 0.72rem;
  padding: 0.25rem 0.5rem;
  color: var(--muted);
  font-weight: 600;
}

.btn.sm.ghost:hover:not(:disabled) {
  color: var(--text);
}

.run-head-meta .badge {
  margin-top: 0;
}

.badge {
  display: inline-block;
  font-size: 0.72rem;
  padding: 0.2rem 0.45rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  color: var(--muted);
}

.progress {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem;
  margin-bottom: 0.75rem;
  font-size: 0.88rem;
  color: var(--muted);
}

.section-pill {
  font-size: 0.72rem;
  padding: 0.2rem 0.45rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 45%, var(--border));
  color: var(--text);
}

.sections-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-bottom: 0.75rem;
}

.section-jump {
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--muted);
  border-radius: 999px;
  padding: 0.3rem 0.55rem;
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.section-jump.active {
  border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
  color: var(--text);
}

.section-jump span {
  opacity: 0.75;
  margin-left: 0.25rem;
}

.pill {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.2rem 0.45rem;
  border-radius: 999px;
  border: 1px solid var(--border);
}

.pill.r-pass {
  border-color: color-mix(in srgb, var(--success) 55%, var(--border));
  color: var(--success);
}
.pill.r-fail {
  border-color: color-mix(in srgb, var(--danger) 55%, var(--border));
  color: var(--danger);
}
.pill.r-untested {
  opacity: 0.85;
}

.state {
  padding: 1rem 0;
  color: var(--muted);
}

.state.err {
  color: var(--danger);
}

.card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--radius, 12px);
  padding: 1rem 1.1rem;
}

.card h2 {
  margin: 0 0 0.5rem;
  font-size: 1.05rem;
}

.pre {
  margin: 0 0 0.75rem;
  font-size: 0.88rem;
  color: var(--muted);
}

.steps {
  margin: 0 0 1rem;
  padding-left: 1.15rem;
  color: var(--muted);
  font-size: 0.88rem;
}

.sa {
  color: var(--text);
  font-weight: 500;
}

.se {
  margin-top: 0.15rem;
}

.notes-lab .severity-select {
  margin-top: 0.25rem;
  width: 100%;
  max-width: 28rem;
  padding: 0.45rem 0.5rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--panel);
  color: var(--text);
  font: inherit;
  font-size: 0.88rem;
}

.notes-lab {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.5rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
}

.notes {
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.65rem;
  font: inherit;
  resize: vertical;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  min-height: 10rem;
}

.media-block {
  margin: 0.75rem 0 0.35rem;
  padding: 0.65rem 0 0;
  border-top: 1px solid var(--border);
}

.media-head {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
  margin-bottom: 0.25rem;
}

.media-hint {
  margin: 0 0 0.5rem;
  font-size: 0.72rem;
  color: var(--muted);
  line-height: 1.35;
}

.shot-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
  margin-bottom: 0.4rem;
}

.shot-input,
.video-input {
  flex: 1 1 12rem;
  min-width: 0;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.45rem 0.5rem;
  font: inherit;
  font-size: 0.85rem;
}

.rm-shot {
  flex: 0 0 auto;
}

.add-shot {
  margin-bottom: 0.65rem;
}

.video-lab {
  margin-top: 0.35rem;
  margin-bottom: 0;
}

.btn {
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.75rem;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.btn.sm {
  font-size: 0.78rem;
  padding: 0.35rem 0.55rem;
  margin-bottom: 0.85rem;
}

.fail-flow-hint {
  margin: 0 0 0.65rem;
  font-size: 0.78rem;
  color: var(--muted);
  line-height: 1.4;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.btn.pass {
  border-color: color-mix(in srgb, var(--success) 55%, var(--border));
  color: var(--success);
}

.btn.fail {
  border-color: color-mix(in srgb, var(--danger) 55%, var(--border));
  color: var(--danger);
}

.btn.block,
.btn.skip,
.btn.ghost {
  color: var(--muted);
}

.pager {
  display: flex;
  gap: 0.5rem;
  margin-top: 1rem;
}
</style>
