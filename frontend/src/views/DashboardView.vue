<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { fetchProjectRuns, type RunSummary } from '@/api';
import { loadAuthSession, type AuthSessionPayload } from '@/authSession';
import {
  canManageUsers,
  canViewRuns,
  defaultLandingPath,
  isViewerOnlyOnAllProjects,
} from '@/permissions';
import { PROJECT_CONTEXT_KEY } from '@/projectContext';

const projectCtx = inject(PROJECT_CONTEXT_KEY)!;

const session = ref<AuthSessionPayload | null>(null);
void loadAuthSession().then((s) => {
  session.value = s;
});

const runs = ref<RunSummary[]>([]);
const runsLoading = ref(false);
const runsError = ref<string | null>(null);

const userLabel = computed(() => session.value?.user?.display_name ?? 'there');
const showWorkspace = computed(() => !isViewerOnlyOnAllProjects(session.value));
const showUsers = computed(() => canManageUsers(session.value));
const primaryHref = computed(() => defaultLandingPath(session.value));
const primaryLabel = computed(() => (primaryHref.value === '/runs' ? 'Open runs' : 'Open workspace'));
const showWorkspaceShortcut = computed(() => showWorkspace.value && primaryHref.value !== '/');
const showRunsShortcut = computed(() => primaryHref.value !== '/runs');

const projectName = computed(() => {
  const pid = projectCtx.projectId;
  if (pid === null) {
    return null;
  }
  return projectCtx.projects.find((p) => p.id === pid)?.name ?? `Project #${pid}`;
});

const canLoadRuns = computed(() => {
  const pid = projectCtx.projectId;
  if (pid === null) {
    return false;
  }
  return canViewRuns(session.value, pid);
});

const metrics = computed(() => {
  const list = runs.value;
  let totalRuns = 0;
  let openRuns = 0;
  let totalItems = 0;
  let passed = 0;
  let failed = 0;
  let untested = 0;
  const stateCounts: Record<string, number> = {};

  for (const r of list) {
    totalRuns += 1;
    if (r.state === 'open') {
      openRuns += 1;
    }
    stateCounts[r.state] = (stateCounts[r.state] ?? 0) + 1;
    totalItems += r.item_count;
    passed += r.passed;
    failed += r.failed;
    untested += r.untested;
  }

  const other = Math.max(0, totalItems - passed - failed - untested);
  const decided = passed + failed;
  const passRate = decided > 0 ? Math.round((passed / decided) * 1000) / 10 : null;
  const completionPct =
    totalItems > 0 ? Math.round(((totalItems - untested) / totalItems) * 1000) / 10 : null;

  return {
    totalRuns,
    openRuns,
    totalItems,
    passed,
    failed,
    untested,
    other,
    passRate,
    completionPct,
    stateCounts,
  };
});

const resultSegments = computed(() => {
  const m = metrics.value;
  const total = m.passed + m.failed + m.untested + m.other;
  const pct = (n: number) => (total > 0 ? (n / total) * 100 : 0);
  return [
    { key: 'pass', label: 'Pass', value: m.passed, widthPct: pct(m.passed), color: 'var(--success)' },
    { key: 'fail', label: 'Fail', value: m.failed, widthPct: pct(m.failed), color: 'var(--danger)' },
    {
      key: 'untested',
      label: 'Untested',
      value: m.untested,
      widthPct: pct(m.untested),
      color: 'color-mix(in srgb, var(--muted) 55%, var(--border))',
    },
    {
      key: 'other',
      label: 'Other',
      value: m.other,
      widthPct: pct(m.other),
      color: 'color-mix(in srgb, var(--accent) 35%, var(--border))',
    },
  ].filter((s) => s.value > 0);
});

const stateSegments = computed(() => {
  const m = metrics.value;
  const known: { key: string; label: string; color: string }[] = [
    { key: 'open', label: 'Open', color: 'color-mix(in srgb, var(--accent) 55%, var(--border))' },
    { key: 'complete', label: 'Complete', color: 'color-mix(in srgb, var(--success) 45%, var(--border))' },
    { key: 'locked', label: 'Locked', color: 'color-mix(in srgb, var(--muted) 65%, var(--border))' },
    { key: 'archived', label: 'Archived', color: 'color-mix(in srgb, var(--muted) 40%, var(--panel-2))' },
  ];
  const total = m.totalRuns;
  const accounted = new Set(known.map((k) => k.key));
  let other = 0;
  for (const [st, n] of Object.entries(m.stateCounts)) {
    if (!accounted.has(st)) {
      other += n;
    }
  }
  const rows = known
    .map((o) => ({
      ...o,
      value: m.stateCounts[o.key] ?? 0,
      widthPct: total > 0 ? ((m.stateCounts[o.key] ?? 0) / total) * 100 : 0,
    }))
    .filter((s) => s.value > 0);
  if (other > 0) {
    rows.push({
      key: 'other_state',
      label: 'Other',
      value: other,
      color: 'color-mix(in srgb, var(--accent-2) 30%, var(--border))',
      widthPct: total > 0 ? (other / total) * 100 : 0,
    });
  }
  return rows;
});

const stateAriaLabel = computed(() => {
  const parts = Object.entries(metrics.value.stateCounts).map(([k, v]) => `${k} ${v}`);
  return `Runs by state: ${parts.join(', ')}`;
});

const recentRuns = computed(() =>
  [...runs.value]
    .sort((a, b) => {
      const t = b.created_at.localeCompare(a.created_at);
      return t !== 0 ? t : b.id - a.id;
    })
    .slice(0, 6),
);

function formatShortDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) {
    return iso.slice(0, 10);
  }
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

async function loadRuns(pid: number) {
  if (!canViewRuns(session.value, pid)) {
    runs.value = [];
    runsError.value = null;
    return;
  }
  runsLoading.value = true;
  runsError.value = null;
  try {
    runs.value = await fetchProjectRuns(pid);
  } catch (e) {
    runsError.value = e instanceof Error ? e.message : 'Failed to load runs';
    runs.value = [];
  } finally {
    runsLoading.value = false;
  }
}

watch(
  () => projectCtx.projectId,
  async (pid) => {
    if (pid === null) {
      runs.value = [];
      runsError.value = null;
      return;
    }
    await loadRuns(pid);
  },
  { immediate: true },
);

watch(session, async (s) => {
  const pid = projectCtx.projectId;
  if (pid !== null && s) {
    await loadRuns(pid);
  }
});
</script>

<template>
  <div class="dash">
    <header class="dash-head">
      <h1>Dashboard</h1>
      <p class="sub">
        <template v-if="projectName">Metrics for <strong>{{ projectName }}</strong>.</template>
        <template v-else>Pick a project in the header to load run metrics.</template>
        Quick links below for the rest of the workspace.
      </p>
    </header>

    <section v-if="projectCtx.projectId !== null" class="metrics-block" aria-labelledby="dash-kpi-heading">
      <h2 id="dash-kpi-heading" class="section-title">Run health</h2>
      <p v-if="!canLoadRuns" class="hint muted">You do not have access to runs for this project.</p>
      <p v-else-if="runsLoading" class="hint muted">Loading run data…</p>
      <p v-else-if="runsError" class="hint err" role="alert">{{ runsError }}</p>
      <template v-else>
        <div class="kpi-grid">
          <div class="kpi">
            <span class="kpi-lab">Total runs</span>
            <span class="kpi-val">{{ metrics.totalRuns }}</span>
            <span class="kpi-sub">{{ metrics.openRuns }} open</span>
          </div>
          <div class="kpi">
            <span class="kpi-lab">Run items</span>
            <span class="kpi-val">{{ metrics.totalItems }}</span>
            <span class="kpi-sub">Across all runs</span>
          </div>
          <div class="kpi">
            <span class="kpi-lab">Completion</span>
            <span class="kpi-val">{{ metrics.completionPct !== null ? `${metrics.completionPct}%` : '—' }}</span>
            <span class="kpi-sub">Items with any result</span>
          </div>
          <div class="kpi">
            <span class="kpi-lab">Pass rate</span>
            <span class="kpi-val">{{ metrics.passRate !== null ? `${metrics.passRate}%` : '—' }}</span>
            <span class="kpi-sub">Pass ÷ (pass + fail)</span>
          </div>
        </div>

        <div class="charts">
          <div class="chart-card">
            <h3 class="chart-title">All run items by result</h3>
            <div
              v-if="metrics.totalItems === 0"
              class="chart-empty muted"
            >
              No run items yet. Start a run from the workspace or runs hub.
            </div>
            <template v-else>
              <div
                class="stack"
                role="img"
                :aria-label="`Pass ${metrics.passed}, fail ${metrics.failed}, untested ${metrics.untested}, other ${metrics.other} of ${metrics.totalItems} items`"
              >
                <div
                  v-for="seg in resultSegments"
                  :key="seg.key"
                  class="stack-seg"
                  :class="'seg-' + seg.key"
                  :style="{ width: seg.widthPct + '%', background: seg.color }"
                  :title="`${seg.label}: ${seg.value}`"
                />
              </div>
              <ul class="legend" aria-hidden="true">
                <li v-for="seg in resultSegments" :key="'leg-' + seg.key">
                  <span class="swatch" :style="{ background: seg.color }" />
                  {{ seg.label }}
                  <span class="legend-val">{{ seg.value }}</span>
                </li>
              </ul>
            </template>
          </div>

          <div class="chart-card">
            <h3 class="chart-title">Runs by state</h3>
            <div v-if="metrics.totalRuns === 0" class="chart-empty muted">No runs in this project.</div>
            <template v-else>
              <div
                class="stack"
                role="img"
                :aria-label="stateAriaLabel"
              >
                <div
                  v-for="seg in stateSegments"
                  :key="seg.key"
                  class="stack-seg"
                  :style="{ width: seg.widthPct + '%', background: seg.color }"
                  :title="`${seg.label}: ${seg.value}`"
                />
              </div>
              <ul class="legend" aria-hidden="true">
                <li v-for="seg in stateSegments" :key="'sleg-' + seg.key">
                  <span class="swatch" :style="{ background: seg.color }" />
                  {{ seg.label }}
                  <span class="legend-val">{{ seg.value }}</span>
                </li>
              </ul>
            </template>
          </div>
        </div>

        <div v-if="recentRuns.length" class="recent">
          <h3 class="chart-title">Recent runs</h3>
          <ul class="recent-list">
            <li v-for="r in recentRuns" :key="r.id">
              <RouterLink class="recent-link" :to="'/runs/' + r.id + '/overview'">
                <span class="recent-name">{{ r.name }}</span>
                <span class="recent-meta">
                  {{ r.state }} · {{ formatShortDate(r.created_at) }} · {{ r.passed }}/{{ r.item_count }} pass
                </span>
              </RouterLink>
            </li>
          </ul>
          <RouterLink class="recent-more" to="/runs">View all runs →</RouterLink>
        </div>
      </template>
    </section>

    <h2 class="section-title links-heading">Shortcuts</h2>
    <ul class="tiles" role="list">
      <li>
        <RouterLink class="tile" :to="primaryHref">
          <span class="tile-title">{{ primaryLabel }}</span>
          <span class="tile-hint">Continue where you usually work — {{ userLabel }}.</span>
        </RouterLink>
      </li>
      <li v-if="showWorkspaceShortcut">
        <RouterLink class="tile" to="/">
          <span class="tile-title">Workspace</span>
          <span class="tile-hint">Suites, sections, and cases for the selected project.</span>
        </RouterLink>
      </li>
      <li v-if="showRunsShortcut">
        <RouterLink class="tile" to="/runs">
          <span class="tile-title">Runs</span>
          <span class="tile-hint">Execution hub and run history.</span>
        </RouterLink>
      </li>
      <li v-if="showUsers">
        <RouterLink class="tile" to="/admin/users">
          <span class="tile-title">Users</span>
          <span class="tile-hint">Manage accounts (admin).</span>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.dash {
  max-width: 56rem;
  margin: 0 auto;
  padding: 1.5rem 1.25rem 2.5rem;
  color: var(--text);
}

.dash-head {
  margin-bottom: 1.25rem;
}

.dash-head h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  font-family: var(--font-display);
}

.sub {
  margin: 0;
  font-size: 0.92rem;
  color: var(--muted);
  line-height: 1.45;
}

.sub strong {
  color: var(--text);
  font-weight: 600;
}

.section-title {
  margin: 0 0 0.65rem;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
}

.links-heading {
  margin-top: 2rem;
}

.metrics-block {
  margin-bottom: 0.5rem;
}

.hint {
  margin: 0 0 0.75rem;
  font-size: 0.88rem;
}

.hint.muted {
  color: var(--muted);
}

.hint.err {
  color: var(--danger);
}

.kpi-grid {
  display: grid;
  gap: 0.65rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin-bottom: 1.25rem;
}

@media (min-width: 720px) {
  .kpi-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

.kpi {
  border-radius: var(--radius, 12px);
  border: 1px solid var(--border);
  background: var(--panel);
  padding: 0.85rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.kpi-lab {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.kpi-val {
  font-size: 1.45rem;
  font-weight: 700;
  font-family: var(--font-display);
  line-height: 1.1;
}

.kpi-sub {
  font-size: 0.78rem;
  color: var(--muted);
}

.charts {
  display: grid;
  gap: 0.85rem;
  margin-bottom: 1.25rem;
}

@media (min-width: 720px) {
  .charts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.chart-card {
  border-radius: var(--radius, 12px);
  border: 1px solid var(--border);
  background: var(--panel);
  padding: 1rem 1.1rem;
}

.chart-title {
  margin: 0 0 0.75rem;
  font-size: 0.95rem;
  font-weight: 700;
}

.chart-empty {
  font-size: 0.86rem;
  line-height: 1.45;
  margin: 0;
}

.stack {
  display: flex;
  height: 1.35rem;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid var(--border);
  background: var(--panel-2);
}

.stack-seg {
  min-width: 0;
  height: 100%;
  transition: opacity 0.15s ease;
}

.stack-seg:hover {
  opacity: 0.88;
}

.legend {
  list-style: none;
  margin: 0.65rem 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
  font-size: 0.8rem;
  color: var(--muted);
}

.legend li {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.swatch {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 2px;
  flex-shrink: 0;
}

.legend-val {
  color: var(--text);
  font-weight: 600;
  margin-left: 0.15rem;
}

.recent {
  border-radius: var(--radius, 12px);
  border: 1px solid var(--border);
  background: var(--panel);
  padding: 1rem 1.1rem 0.85rem;
  margin-bottom: 0.25rem;
}

.recent .chart-title {
  margin-bottom: 0.55rem;
}

.recent-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.recent-list li {
  border-top: 1px solid var(--border);
}

.recent-list li:first-child {
  border-top: none;
}

.recent-link {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.55rem 0;
  text-decoration: none;
  color: inherit;
  border-radius: 6px;
  margin: 0 -0.25rem;
  padding-left: 0.25rem;
  padding-right: 0.25rem;
}

.recent-link:hover {
  background: color-mix(in srgb, var(--accent) 8%, transparent);
}

.recent-name {
  font-weight: 600;
  font-size: 0.9rem;
}

.recent-meta {
  font-size: 0.78rem;
  color: var(--muted);
}

.recent-more {
  display: inline-block;
  margin-top: 0.5rem;
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--accent);
  text-decoration: none;
}

.recent-more:hover {
  text-decoration: underline;
}

.tiles {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}

@media (min-width: 640px) {
  .tiles {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.tile {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 1rem 1.1rem;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel);
  text-decoration: none;
  color: inherit;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.tile:hover {
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 18%, transparent);
}

.tile-title {
  font-weight: 700;
  font-size: 1rem;
}

.tile-hint {
  font-size: 0.84rem;
  color: var(--muted);
  line-height: 1.4;
}
</style>
