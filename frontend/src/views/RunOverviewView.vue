<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { fetchRun, type RunDetail, type RunItemDetail, type RunItemSeverity, type TestStep } from '@/api';
import { runOverviewSingleExpand } from '@/uiPreferences';

const route = useRoute();

const runId = computed(() => parseInt(String(route.params.runId), 10));
const loading = ref(true);
const error = ref<string | null>(null);
const run = ref<RunDetail | null>(null);
const items = ref<RunItemDetail[]>([]);
/** Expanded run item ids (reassign Set for reactivity). */
const expanded = ref(new Set<number>());

const sessionPath = computed(() => `/runs/${runId.value}`);

const summary = computed(() => {
  const list = items.value;
  const out = { total: list.length, pass: 0, fail: 0, blocked: 0, skipped: 0, untested: 0 };
  for (const i of list) {
    switch (i.result) {
      case 'pass':
        out.pass += 1;
        break;
      case 'fail':
        out.fail += 1;
        break;
      case 'blocked':
        out.blocked += 1;
        break;
      case 'skipped':
        out.skipped += 1;
        break;
      default:
        out.untested += 1;
    }
  }
  return out;
});

const sectionGroups = computed(() => {
  const groups: Array<{ name: string; items: RunItemDetail[] }> = [];
  for (const item of items.value) {
    const name = item.section_name || 'Default';
    const last = groups[groups.length - 1];
    if (last && last.name === name) {
      last.items.push(item);
    } else {
      groups.push({ name, items: [item] });
    }
  }
  return groups;
});

function normSeverity(s: string | undefined): RunItemSeverity {
  return s === 'breaking' || s === 'ui_only' || s === 'unclear' ? s : 'unclear';
}

watch(
  () => runId.value,
  async (id) => {
    if (!Number.isFinite(id) || id <= 0) {
      error.value = 'Invalid run';
      loading.value = false;
      return;
    }
    loading.value = true;
    error.value = null;
    expanded.value = new Set();
    try {
      const data = await fetchRun(id);
      run.value = data.run;
      items.value = data.items.map((row) => ({
        ...row,
        severity: normSeverity(row.severity),
        screenshots: Array.isArray(row.screenshots) ? row.screenshots : [],
        video_url: row.video_url ?? null,
      }));
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

function toggleExpand(id: number) {
  const next = new Set(expanded.value);
  if (next.has(id)) {
    next.delete(id);
  } else {
    if (runOverviewSingleExpand.value) {
      next.clear();
    }
    next.add(id);
  }
  expanded.value = next;
}

function isExpanded(id: number): boolean {
  return expanded.value.has(id);
}

function stepsSummary(steps: TestStep[]): string {
  if (!steps.length) {
    return 'No steps';
  }
  if (steps.length === 1) {
    const t = steps[0].action;
    return t.length > 140 ? `${t.slice(0, 140)}…` : t;
  }
  const first = steps[0].action;
  const head = first.length > 72 ? `${first.slice(0, 72)}…` : first;
  return `${steps.length} steps · ${head}`;
}

function severityLabel(s: RunItemSeverity): string {
  if (s === 'breaking') {
    return 'Breaking (blocks ship / core path)';
  }
  if (s === 'ui_only') {
    return 'UI only';
  }
  return 'Unclear';
}

function resultLabel(result: string): string {
  switch (result) {
    case 'pass':
      return 'Pass';
    case 'fail':
      return 'Fail';
    case 'blocked':
      return 'Blocked';
    case 'skipped':
      return 'Skipped';
    default:
      return 'Untested';
  }
}

function showFailureDetails(item: RunItemDetail): boolean {
  return (
    item.result === 'fail' ||
    evidenceShots(item).length > 0 ||
    Boolean(item.video_url?.trim())
  );
}

function formatWhen(iso: string | null): string {
  if (!iso) {
    return '—';
  }
  try {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
      return iso;
    }
    return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return iso;
  }
}

function evidenceShots(item: RunItemDetail): string[] {
  return item.screenshots.length > 0 ? item.screenshots : [];
}
</script>

<template>
  <div class="overview">
    <header class="top-bar">
      <RouterLink to="/runs" class="back">← Runs</RouterLink>
      <RouterLink v-if="run?.state === 'open'" :to="sessionPath" class="link-continue">Continue session</RouterLink>
    </header>

    <div v-if="loading" class="state">Loading run…</div>
    <div v-else-if="error" class="state err">{{ error }}</div>

    <template v-else-if="run">
      <div class="run-title-block">
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
          <span class="meta-inline">Started {{ formatWhen(run.created_at) }}</span>
        </div>
      </div>

      <div v-if="!items.length" class="state">This run has no cases (suite was empty when started).</div>

      <template v-else>
        <section class="summary-strip" aria-label="Result counts">
          <div class="stat">
            <span class="stat-n">{{ summary.total }}</span>
            <span class="stat-l">Total</span>
          </div>
          <div class="stat ok">
            <span class="stat-n">{{ summary.pass }}</span>
            <span class="stat-l">Pass</span>
          </div>
          <div class="stat bad">
            <span class="stat-n">{{ summary.fail }}</span>
            <span class="stat-l">Fail</span>
          </div>
          <div class="stat">
            <span class="stat-n">{{ summary.blocked }}</span>
            <span class="stat-l">Blocked</span>
          </div>
          <div class="stat">
            <span class="stat-n">{{ summary.skipped }}</span>
            <span class="stat-l">Skipped</span>
          </div>
          <div class="stat">
            <span class="stat-n">{{ summary.untested }}</span>
            <span class="stat-l">Untested</span>
          </div>
        </section>

        <h2 class="list-heading">Cases</h2>
        <div class="items-scroll">
          <template v-for="group in sectionGroups" :key="group.name + '-' + (group.items[0]?.id ?? '')">
            <h3 class="section-heading">{{ group.name }}</h3>
            <div
              v-for="item in group.items"
              :key="item.id"
              class="item-card"
              :class="{ open: isExpanded(item.id) }"
            >
              <button type="button" class="item-head" @click="toggleExpand(item.id)">
                <span class="chev" aria-hidden="true">{{ isExpanded(item.id) ? '▼' : '▶' }}</span>
                <div class="item-head-main">
                  <span class="item-title">{{ item.title }}</span>
                  <span class="item-sub muted">{{ stepsSummary(item.steps) }}</span>
                </div>
                <span class="pill" :class="'r-' + item.result">{{ item.result }}</span>
              </button>

              <div v-if="isExpanded(item.id)" class="item-body">
                <section class="detail-section" aria-label="Run outcome">
                  <div class="lbl">Result</div>
                  <dl class="meta-grid outcome-grid">
                    <div>
                      <dt>Outcome</dt>
                      <dd>
                        <span class="pill inline" :class="'r-' + item.result">{{ resultLabel(item.result) }}</span>
                      </dd>
                    </div>
                    <div>
                      <dt>Executed</dt>
                      <dd>{{ formatWhen(item.executed_at) }}</dd>
                    </div>
                    <div>
                      <dt>Priority</dt>
                      <dd>{{ item.priority }}</dd>
                    </div>
                    <div>
                      <dt>Case status</dt>
                      <dd>{{ item.status }}</dd>
                    </div>
                  </dl>
                </section>

                <section class="detail-section" aria-label="Notes">
                  <div class="lbl">Notes</div>
                  <p class="notes-text" :class="{ empty: !item.notes?.trim() }">
                    {{ item.notes?.trim() ? item.notes : 'No notes recorded.' }}
                  </p>
                </section>

                <section v-if="showFailureDetails(item)" class="detail-section" aria-label="Failure details">
                  <div class="lbl">Failure details</div>
                  <dl v-if="item.result === 'fail'" class="meta-grid">
                    <div>
                      <dt>Failure impact</dt>
                      <dd>{{ severityLabel(item.severity) }}</dd>
                    </div>
                  </dl>
                  <div v-if="evidenceShots(item).length || item.video_url" class="evidence">
                    <div v-if="evidenceShots(item).length" class="ev-block">
                      <div class="lbl sub">Screenshots</div>
                      <ul class="ev-links">
                        <li v-for="(url, idx) in evidenceShots(item)" :key="idx">
                          <a :href="url" target="_blank" rel="noopener noreferrer" @click.stop>Open screenshot {{ idx + 1 }}</a>
                        </li>
                      </ul>
                    </div>
                    <div v-if="item.video_url" class="ev-block">
                      <div class="lbl sub">Video</div>
                      <a :href="item.video_url" target="_blank" rel="noopener noreferrer" @click.stop>Open video</a>
                    </div>
                  </div>
                  <p v-else-if="item.result === 'fail'" class="empty-hint">No screenshot or video links recorded.</p>
                </section>

                <section class="detail-section" aria-label="Instructions">
                  <div class="lbl">Instructions</div>
                  <p v-if="item.precondition" class="pre">
                    <strong>Precondition:</strong> {{ item.precondition }}
                  </p>
                  <p v-if="!item.steps.length" class="empty-hint">No steps defined for this case.</p>
                  <ol v-else class="steps">
                    <li v-for="(st, i) in item.steps" :key="i">
                      <div class="sa">{{ st.action }}</div>
                      <div class="se">Expect: {{ st.expected }}</div>
                    </li>
                  </ol>
                </section>
              </div>
            </div>
          </template>
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
.overview {
  max-width: 720px;
}

.top-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  margin-bottom: 1rem;
}

.back {
  display: inline-block;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--muted);
  border-radius: 8px;
  padding: 0.4rem 0.65rem;
  font: inherit;
  text-decoration: none;
  font-size: 0.88rem;
}

.back:hover {
  color: var(--text);
}

.link-continue {
  font-weight: 600;
  font-size: 0.88rem;
  color: var(--accent-2);
  text-decoration: none;
}

.link-continue:hover {
  text-decoration: underline;
}

.run-title-block h1 {
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

.meta-inline {
  font-size: 0.78rem;
  color: var(--muted);
}

.badge {
  display: inline-block;
  font-size: 0.72rem;
  padding: 0.2rem 0.45rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  color: var(--muted);
}

.state-badge {
  font-size: 0.72rem;
  padding: 0.2rem 0.45rem;
  border-radius: 6px;
  border: 1px solid color-mix(in srgb, var(--accent-2) 40%, var(--border));
  color: var(--accent-2);
  text-transform: lowercase;
}

.state {
  padding: 1rem 0;
  color: var(--muted);
}

.state.err {
  color: var(--danger);
}

.summary-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 1rem 0 1.25rem;
}

.stat {
  min-width: 4.5rem;
  padding: 0.45rem 0.55rem;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel);
  text-align: center;
}

.stat.ok {
  border-color: color-mix(in srgb, var(--success) 45%, var(--border));
}

.stat.bad {
  border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
}

.stat-n {
  display: block;
  font-weight: 700;
  font-size: 1.05rem;
  line-height: 1.2;
}

.stat-l {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.list-heading {
  margin: 0 0 0.5rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.items-scroll {
  max-height: min(70vh, 520px);
  overflow-y: auto;
  overflow-x: hidden;
  padding-right: 0.25rem;
  padding-bottom: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  align-items: stretch;
}

.section-heading {
  flex-shrink: 0;
  margin: 0.35rem 0 0.15rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.section-heading:first-child {
  margin-top: 0;
}

.item-card {
  flex-shrink: 0;
  min-height: min-content;
  border: 1px solid var(--border);
  border-radius: var(--radius, 12px);
  background: var(--panel);
}

.item-card.open {
  border-color: color-mix(in srgb, var(--accent) 35%, var(--border));
}

.item-head {
  width: 100%;
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem 0.8rem 0.95rem;
  border: none;
  border-radius: var(--radius, 12px);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  overflow: visible;
}

.item-card.open .item-head {
  border-radius: var(--radius, 12px) var(--radius, 12px) 0 0;
}

.item-head:hover {
  background: color-mix(in srgb, var(--panel-2) 65%, transparent);
}

.chev {
  flex: none;
  font-size: 0.65rem;
  color: var(--muted);
  margin-top: 0.2rem;
  width: 1rem;
}

.item-head-main {
  flex: 1;
  min-width: 0;
}

.item-title {
  display: block;
  font-weight: 600;
  font-size: 0.9rem;
  line-height: 1.4;
}

.item-sub {
  display: block;
  font-size: 0.78rem;
  margin-top: 0.25rem;
  line-height: 1.45;
  padding-bottom: 0.1em;
}

.muted {
  color: var(--muted);
}

.pill {
  flex: none;
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.2rem 0.45rem;
  border-radius: 999px;
  border: 1px solid var(--border);
  align-self: center;
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

.pill.inline {
  align-self: auto;
  display: inline-block;
}

.item-body {
  padding: 0 0.85rem 1.15rem;
  border-top: 1px solid var(--border);
  border-radius: 0 0 var(--radius, 12px) var(--radius, 12px);
  background: var(--panel-2);
  overflow: visible;
}

.detail-section {
  padding: 0.75rem 0 0.15rem;
}

.detail-section:last-child {
  padding-bottom: 0.35rem;
}

.detail-section + .detail-section {
  margin-top: 0.65rem;
  padding-top: 0.75rem;
  border-top: 1px solid color-mix(in srgb, var(--border) 70%, transparent);
}

.meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(7rem, 1fr));
  gap: 0.5rem 1rem;
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

.meta-grid dt {
  margin: 0;
  font-size: 0.68rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.meta-grid dd {
  margin: 0.15rem 0 0;
}

.pre {
  margin: 0.5rem 0;
  font-size: 0.85rem;
  color: var(--muted);
}

.lbl {
  font-size: 0.68rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
  margin-bottom: 0.25rem;
}

.notes-text {
  margin: 0;
  white-space: pre-wrap;
  font-size: 0.85rem;
  line-height: 1.45;
}

.notes-text.empty,
.empty-hint {
  margin: 0;
  font-size: 0.85rem;
  color: var(--muted);
  font-style: italic;
}

.lbl.sub {
  margin-top: 0.35rem;
  margin-bottom: 0.15rem;
}

.evidence {
  margin: 0.65rem 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ev-links {
  margin: 0.25rem 0 0;
  padding-left: 1.1rem;
  font-size: 0.85rem;
}

.ev-links a,
.ev-block > a {
  color: var(--accent-2);
  font-weight: 600;
}

.steps {
  margin: 0.25rem 0 0;
  padding-left: 1.15rem;
  padding-bottom: 0.35rem;
  color: var(--muted);
  font-size: 0.85rem;
}

.steps li {
  margin-bottom: 0.5rem;
}

.steps li:last-child {
  margin-bottom: 0;
  padding-bottom: 0.15rem;
}

.sa {
  color: var(--text);
  font-weight: 500;
}

.se {
  margin-top: 0.15rem;
}
</style>
