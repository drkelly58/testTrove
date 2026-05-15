<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EntityFormDialog from '@/components/EntityFormDialog.vue';
import IconButton from '@/components/IconButton.vue';
import type { FieldDef } from '@/components/EntityFormDialog.vue';
import { deleteRun, fetchProjectRuns, updateRun, type RunSummary } from '@/api';
import { loadAuthSession, type AuthSessionPayload } from '@/authSession';
import { PROJECT_CONTEXT_KEY } from '@/projectContext';
import { canExecuteRuns, canWriteCatalog } from '@/permissions';

const projectCtx = inject(PROJECT_CONTEXT_KEY)!;

const authSession = ref<AuthSessionPayload | null>(null);
void loadAuthSession().then((s) => {
  authSession.value = s;
});
const canWrite = computed(() => canWriteCatalog(authSession.value, projectCtx.projectId));
const canRun = computed(() => canExecuteRuns(authSession.value, projectCtx.projectId));

const runs = ref<RunSummary[]>([]);
const runsLoading = ref(false);
const runsError = ref<string | null>(null);

const deleteRunTarget = ref<RunSummary | null>(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deletePreviewTitle = ref('');
const deletePreviewMessage = ref('');
const deletePreviewConsequences = ref<string[]>([]);
const deletePreviewNote = ref<string | undefined>(undefined);

const editRun = ref<RunSummary | null>(null);
const editRunFields = ref<FieldDef[]>([]);
const editRunBusy = ref(false);
const editRunError = ref<string | null>(null);

function openEditRun(r: RunSummary) {
  editRun.value = r;
  editRunError.value = null;
  editRunFields.value = [
    { key: 'name', label: 'Name', kind: 'text', initial: r.name, required: true, autofocus: true },
    {
      key: 'state',
      label: 'State',
      kind: 'select',
      initial: r.state,
      options: [
        { value: 'open', label: 'open' },
        { value: 'locked', label: 'locked' },
        { value: 'archived', label: 'archived' },
      ],
    },
  ];
}

function closeEditRun() {
  editRun.value = null;
  editRunError.value = null;
  editRunFields.value = [];
}

function onEditRunModel(open: boolean) {
  if (!open) {
    closeEditRun();
  }
}

async function saveEditRun(values: Record<string, string | number | boolean | null>) {
  const r = editRun.value;
  const pid = projectCtx.projectId;
  if (!r || pid === null) {
    return;
  }
  editRunBusy.value = true;
  editRunError.value = null;
  try {
    await updateRun(r.id, {
      name: String(values.name ?? '').trim(),
      state: values.state as 'open' | 'locked' | 'archived',
    });
    closeEditRun();
    await loadRuns(pid);
  } catch (e) {
    editRunError.value = e instanceof Error ? e.message : 'Save failed';
  } finally {
    editRunBusy.value = false;
  }
}

function pluralize(n: number, one: string, many?: string): string {
  return `${n} ${n === 1 ? one : many ?? one + 's'}`;
}

async function askDeleteRun(r: RunSummary) {
  runsError.value = null;
  deleteRunTarget.value = r;
  deletePreviewTitle.value = `Delete run "${r.name}"?`;
  deletePreviewMessage.value = 'This permanently removes the run and all recorded results for its cases.';
  deletePreviewConsequences.value = ['Loading cascade preview…'];
  deletePreviewNote.value = undefined;
  deleteOpen.value = true;
  try {
    const preview = await deleteRun(r.id, { dryRun: true });
    const c = preview.cascade;
    const items: string[] = [];
    if (c.run_items > 0) {
      items.push(`${pluralize(c.run_items, 'case result row')} will be removed`);
    } else {
      items.push('No case result rows to remove.');
    }
    deletePreviewConsequences.value = items;
    deletePreviewNote.value = 'Test cases and suites are not deleted. This action cannot be undone.';
  } catch (e) {
    deletePreviewConsequences.value = [];
    deletePreviewNote.value = e instanceof Error ? `Preview failed: ${e.message}` : 'Preview failed';
  }
}

function cancelDeleteRun() {
  deleteRunTarget.value = null;
  deleteOpen.value = false;
}

async function confirmDeleteRun() {
  const r = deleteRunTarget.value;
  if (!r || projectCtx.projectId === null) {
    return;
  }
  const pid = projectCtx.projectId;
  deleteBusy.value = true;
  try {
    await deleteRun(r.id);
    deleteOpen.value = false;
    deleteRunTarget.value = null;
    await loadRuns(pid);
  } catch (e) {
    runsError.value = e instanceof Error ? e.message : 'Delete failed';
  } finally {
    deleteBusy.value = false;
  }
}

async function loadRuns(pid: number) {
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
      return;
    }
    await loadRuns(pid);
  },
  { immediate: true },
);
</script>

<template>
  <div class="runs-hub">
    <header class="head">
      <h1>Test runs</h1>
      <p class="sub">
        Each run is a <strong>snapshot</strong> of either a <strong>full suite</strong> (every case) or a single
        <strong>section</strong> (cases in that section only). Custom <strong>run books</strong> (pick-your-own case
        lists) are planned for a later release.
      </p>
    </header>

    <div v-if="projectCtx.loading" class="state">Loading…</div>
    <div v-else-if="projectCtx.error" class="state err">{{ projectCtx.error }}</div>

    <template v-else>
      <p v-if="projectCtx.projectId === null" class="empty">Pick a project in the top bar to list runs.</p>

      <template v-else>
        <div v-if="runsLoading" class="state">Loading runs…</div>
        <div v-else-if="runsError" class="state err">{{ runsError }}</div>

        <template v-else>
          <p v-if="!runs.length" class="empty">
            No runs yet. Open <strong>Workspace</strong>, pick a suite or section, and use the <strong>play</strong> button to
            start a run.
          </p>

          <table v-else class="tbl">
            <thead>
              <tr>
                <th>Run</th>
                <th>Suite</th>
                <th>State</th>
                <th>Progress</th>
                <th class="col-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in runs" :key="r.id">
                <td>
                  <span class="run-name">{{ r.name }}</span>
                  <span class="meta">{{
                    r.run_kind === 'full_suite' ? 'Full suite' : r.run_kind === 'section' ? 'Section run' : r.run_kind
                  }}</span>
                </td>
                <td>
                  <span class="suite-cell-title">{{ r.suite_name || '—' }}</span>
                  <span v-if="r.section_name" class="suite-cell-sub">{{ r.section_name }}</span>
                </td>
                <td><span class="state-pill">{{ r.state }}</span></td>
                <td>
                  <span class="prog"
                    >{{ r.passed }}/{{ r.item_count }} pass · {{ r.failed }} fail · {{ r.untested }} left</span
                  >
                </td>
                <td class="col-actions">
                  <div class="run-row-actions">
                    <RouterLink class="link" :to="'/runs/' + r.id + '/overview'">Overview</RouterLink>
                    <RouterLink v-if="canRun" class="link" :to="'/runs/' + r.id">Continue</RouterLink>
                    <IconButton v-if="canRun" label="Edit run" title="Edit run name and state" @click="openEditRun(r)">
                      <svg viewBox="0 0 24 24">
                        <path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z" fill="none" />
                      </svg>
                    </IconButton>
                    <IconButton
                      v-if="canWrite"
                      danger
                      label="Delete this run"
                      title="Delete this run (removes all result rows)"
                      @click="askDeleteRun(r)"
                    >
                      <svg viewBox="0 0 24 24">
                        <path
                          d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h12zM10 11v6M14 11v6"
                          fill="none"
                        />
                      </svg>
                    </IconButton>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </template>
      </template>
    </template>

    <ConfirmDialog
      v-model="deleteOpen"
      :title="deletePreviewTitle"
      :message="deletePreviewMessage"
      :consequences="deletePreviewConsequences"
      :note="deletePreviewNote"
      :busy="deleteBusy"
      confirm-label="Delete run"
      @confirm="confirmDeleteRun"
      @cancel="cancelDeleteRun"
    />

    <EntityFormDialog
      v-if="editRun"
      :model-value="true"
      @update:model-value="onEditRunModel"
      title="Edit run"
      :fields="editRunFields"
      :busy="editRunBusy"
      :error-message="editRunError"
      @submit="saveEditRun"
    />
  </div>
</template>

<style scoped>
.runs-hub {
  max-width: 900px;
}

.head h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  font-family: var(--font-display, 'Outfit', sans-serif);
}

.sub {
  margin: 0 0 1.25rem;
  color: var(--muted);
  font-size: 0.9rem;
  line-height: 1.45;
}

.state {
  padding: 1rem 0;
}

.state.err {
  color: var(--danger);
}

.empty {
  color: var(--muted);
  font-size: 0.9rem;
  line-height: 1.45;
}

.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.tbl th,
.tbl td {
  text-align: left;
  padding: 0.55rem 0.65rem;
  border-bottom: 1px solid var(--border);
}

.tbl th {
  color: var(--muted);
  font-weight: 600;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.run-name {
  display: block;
  font-weight: 600;
}

.meta {
  display: block;
  font-size: 0.72rem;
  color: var(--muted);
  margin-top: 0.12rem;
}

.suite-cell-title {
  display: block;
  font-weight: 500;
}

.suite-cell-sub {
  display: block;
  font-size: 0.72rem;
  color: var(--muted);
  margin-top: 0.12rem;
}

.prog {
  color: var(--muted);
  font-size: 0.82rem;
}

.link {
  font-weight: 600;
  color: var(--accent-2);
  text-decoration: none;
}

.link:hover {
  text-decoration: underline;
}

.col-actions {
  white-space: nowrap;
  width: 1%;
}

.run-row-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.35rem;
}

.state-pill {
  font-size: 0.72rem;
  text-transform: lowercase;
  padding: 0.15rem 0.45rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  color: var(--muted);
}
</style>
