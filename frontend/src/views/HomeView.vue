<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import draggable from 'vuedraggable';
import CaseEditorModal from '@/components/CaseEditorModal.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EntityFormDialog from '@/components/EntityFormDialog.vue';
import IconButton from '@/components/IconButton.vue';
import type { FieldDef } from '@/components/EntityFormDialog.vue';
import {
  assignedToUserIdFromForm,
  bulkSetCasesStatus,
  isRunAssignedToOther,
  createCase,
  createRunFromSection,
  createRunFromSuite,
  createSection,
  createSuite,
  deleteCase,
  deleteProject,
  deleteSection,
  deleteSuite,
  duplicateCase,
  duplicateSuite,
  fetchCases,
  fetchProjectMembers,
  fetchProjectRuns,
  fetchSections,
  fetchSuites,
  moveCaseToSuite,
  moveStepBetweenCases,
  reorderCasesInSection,
  runAssigneeSelectOptions,
  updateProject,
  updateSection,
  updateSuite,
  type CaseWorkflowStatus,
  type CreateRunBody,
  type Project,
  type ProjectMember,
  type RunSummary,
  type Section,
  type Suite,
  type TestCase,
} from '@/api';
import { loadAuthSession, type AuthSessionPayload } from '@/authSession';
import { PROJECT_CONTEXT_KEY } from '@/projectContext';
import {
  canAssignRuns,
  canExecuteRuns,
  canReadCatalog,
  canWriteCatalog,
  projectRoleFor,
} from '@/permissions';
import { stepsAsArray } from '@/stepsModel';

const router = useRouter();
const projectCtx = inject(PROJECT_CONTEXT_KEY)!;

const authSession = ref<AuthSessionPayload | null>(null);
void loadAuthSession().then((s) => {
  authSession.value = s;
});

const canRead = computed(() => canReadCatalog(authSession.value, projectCtx.projectId));
const canWrite = computed(() => canWriteCatalog(authSession.value, projectCtx.projectId));
const canRun = computed(() => canExecuteRuns(authSession.value, projectCtx.projectId));
const canAssign = computed(() => canAssignRuns(authSession.value, projectCtx.projectId));
const currentUserId = computed(() => authSession.value?.user?.id ?? null);

const projectTesters = ref<ProjectMember[]>([]);

async function loadProjectTesters(pid: number) {
  if (!canAssign.value) {
    projectTesters.value = [];
    return;
  }
  try {
    const members = await fetchProjectMembers(pid);
    projectTesters.value = members.filter((m) => m.role === 'tester');
  } catch {
    projectTesters.value = [];
  }
}

watch(
  () => [projectCtx.projectId, authSession.value] as const,
  () => {
    if (!authSession.value?.auth_required || projectCtx.projectId == null) {
      return;
    }
    if (!canRead.value && projectRoleFor(authSession.value, projectCtx.projectId) === 'viewer') {
      void router.replace({ name: 'runs' });
    }
  },
);

function caseSteps(c: TestCase) {
  return stepsAsArray(c.steps);
}

const error = ref<string | null>(null);
const treeDataLoading = ref(false);

const selectedProject = computed(
  () => projectCtx.projects.find((p) => p.id === projectCtx.projectId) ?? null,
);

const viewLoading = computed(() => projectCtx.loading || treeDataLoading.value);
const suites = ref<Suite[]>([]);
const selectedSuiteId = ref<number | null>(null);
const sections = ref<Section[]>([]);
const cases = ref<TestCase[]>([]);

const newSuiteName = ref('');
const newSectionName = ref('');
const newSectionPrecondition = ref('');
const newCaseTitle = ref('');

/** When set, the next “Add test case” inserts before/after this case in its section. */
const caseInsertAnchor = ref<{ caseId: number; title: string; position: 'before' | 'after' } | null>(null);

/** Drag-reorder list for the active section (synced from loaded cases). */
const sectionCasesOrder = ref<TestCase[]>([]);
const caseReorderBusy = ref(false);

function applyLocalCaseOrder(ordered: TestCase[]) {
  ordered.forEach((c, index) => {
    const row = cases.value.find((x) => x.id === c.id);
    if (row) {
      row.sort_order = index;
    }
  });
}

async function onCaseListReordered() {
  const sid = selectedSuiteId.value;
  const secId = selectedExplorerSectionId.value;
  if (!sid || secId == null || sectionCasesOrder.value.length < 2) {
    return;
  }
  const orderedIds = sectionCasesOrder.value.map((c) => c.id);
  const previousIds =
    activeExplorerSectionGroup.value?.cases.map((c) => c.id) ?? [];
  if (orderedIds.join(',') === previousIds.join(',')) {
    return;
  }
  const previous = activeExplorerSectionGroup.value ? [...activeExplorerSectionGroup.value.cases] : [];
  caseReorderBusy.value = true;
  error.value = null;
  applyLocalCaseOrder(sectionCasesOrder.value);
  try {
    await reorderCasesInSection(sid, secId, orderedIds);
  } catch (e) {
    sectionCasesOrder.value = [...previous];
    applyLocalCaseOrder(previous);
    error.value = e instanceof Error ? e.message : 'Could not reorder cases';
  } finally {
    caseReorderBusy.value = false;
  }
}

async function moveCaseInSection(c: TestCase, direction: -1 | 1) {
  if (sectionCasesOrder.value.length === 0) {
    syncSectionCasesOrderFromExplorer();
  }
  const list = sectionCasesOrder.value;
  const idx = list.findIndex((x) => x.id === c.id);
  if (idx < 0) {
    return;
  }
  const target = idx + direction;
  if (target < 0 || target >= list.length) {
    return;
  }
  const next = [...list];
  const tmp = next[idx]!;
  next[idx] = next[target]!;
  next[target] = tmp;
  sectionCasesOrder.value = next;
  await onCaseListReordered();
}

/** Runs for Explorer counts: loaded per selected project, non-blocking. */
const explorerRuns = ref<RunSummary[]>([]);
const explorerRunsProjectId = ref<number | null>(null);
const explorerRunsLoading = ref(false);
const explorerRunsError = ref(false);
let explorerRunsFetchSeq = 0;

const selectedProjectRunsReady = computed((): RunSummary[] | null => {
  const pid = projectCtx.projectId;
  if (pid === null || explorerRunsProjectId.value !== pid || explorerRunsError.value || explorerRunsLoading.value) {
    return null;
  }
  return explorerRuns.value;
});

function classifyRun(r: RunSummary): 'failed' | 'passed' | 'open' | 'neutral' {
  if (r.failed > 0) {
    return 'failed';
  }
  if (r.item_count > 0 && r.untested === 0) {
    return 'passed';
  }
  if (r.untested > 0 && r.failed === 0) {
    return 'open';
  }
  return 'neutral';
}

function aggregateBuckets(runs: RunSummary[], suiteId: number | null) {
  const relevant =
    suiteId !== null ? runs.filter((r) => r.suite_id === suiteId) : runs.slice();
  let failed = 0;
  let passed = 0;
  let open = 0;
  for (const r of relevant) {
    const c = classifyRun(r);
    if (c === 'failed') {
      failed += 1;
    } else if (c === 'passed') {
      passed += 1;
    } else if (c === 'open') {
      open += 1;
    }
  }
  const total = failed + passed + open;
  return { failed, passed, open, total, rawCount: relevant.length };
}

function formatRunBucketsLine(
  b: { failed: number; passed: number; open: number; total: number; rawCount: number },
  opts: { withRunsWord: boolean },
) {
  if (b.rawCount === 0) {
    return 'No runs yet';
  }
  if (b.total === 0) {
    return opts.withRunsWord ? `Runs ${b.rawCount}` : String(b.rawCount);
  }
  const head = opts.withRunsWord ? `Runs ${b.rawCount}` : String(b.rawCount);
  const parts: string[] = [head];
  if (b.passed > 0) {
    parts.push(`${b.passed} ok`);
  }
  if (b.failed > 0) {
    parts.push(`${b.failed} fail`);
  }
  if (b.open > 0) {
    parts.push(`${b.open} open`);
  }
  return parts.join(' · ');
}

function suiteExplorerMeta(s: Suite): string {
  if (explorerRunsLoading.value) {
    return '…';
  }
  if (explorerRunsError.value || selectedProjectRunsReady.value === null) {
    return '';
  }
  const b = aggregateBuckets(selectedProjectRunsReady.value, s.id);
  return formatRunBucketsLine(b, { withRunsWord: false });
}

async function fetchExplorerRuns(projectId: number) {
  const seq = ++explorerRunsFetchSeq;
  explorerRunsLoading.value = true;
  explorerRunsError.value = false;
  explorerRunsProjectId.value = null;
  explorerRuns.value = [];
  try {
    const data = await fetchProjectRuns(projectId);
    if (seq !== explorerRunsFetchSeq || projectCtx.projectId !== projectId) {
      return;
    }
    explorerRuns.value = data;
    explorerRunsProjectId.value = projectId;
  } catch {
    if (seq === explorerRunsFetchSeq && projectCtx.projectId === projectId) {
      explorerRunsError.value = true;
      explorerRuns.value = [];
      explorerRunsProjectId.value = projectId;
    }
  } finally {
    if (seq === explorerRunsFetchSeq) {
      explorerRunsLoading.value = false;
    }
  }
}

const selectedSuite = computed(() => suites.value.find((s) => s.id === selectedSuiteId.value) ?? null);
const otherSuites = computed(() => suites.value.filter((s) => s.id !== selectedSuiteId.value));

/** All sections in the selected suite (ordered), each with its cases — used by Explorer tree and detail pane. */
const explorerSectionGroups = computed((): { section: Section; cases: TestCase[] }[] => {
  const map = new Map<number, { section: Section; cases: TestCase[] }>();
  for (const s of sections.value) {
    map.set(s.id, { section: s, cases: [] });
  }
  const caseOrder = (a: TestCase, b: TestCase) => {
    const ao = a.sort_order ?? 0;
    const bo = b.sort_order ?? 0;
    if (ao !== bo) {
      return ao - bo;
    }
    return a.id - b.id;
  };
  for (const c of cases.value) {
    const existing = map.get(c.section_id);
    if (existing) {
      existing.cases.push(c);
    } else {
      map.set(c.section_id, {
        section: {
          id: c.section_id,
          suite_id: c.suite_id,
          name: c.section_name || 'Default',
          precondition: c.section_precondition ?? null,
          sort_order: 0,
          created_at: '',
        },
        cases: [c],
      });
    }
  }
  const ordered = [...sections.value].sort((a, b) => {
    if (a.sort_order !== b.sort_order) {
      return a.sort_order - b.sort_order;
    }
    return a.id - b.id;
  });
  const seen = new Set<number>();
  const out: { section: Section; cases: TestCase[] }[] = [];
  for (const s of ordered) {
    const g = map.get(s.id);
    if (g) {
      out.push(g);
      seen.add(s.id);
    }
  }
  for (const [id, g] of map) {
    if (!seen.has(id)) {
      out.push(g);
    }
  }
  for (const g of out) {
    g.cases.sort(caseOrder);
  }
  return out;
});

const showSectionHeadings = computed(
  () =>
    explorerSectionGroups.value.length > 1 ||
    explorerSectionGroups.value.some((g) => g.section.name !== 'Default'),
);

/** Left tree + right pane: one focused section at a time (defaults to first section in suite). */
const selectedExplorerSectionId = ref<number | null>(null);

const activeExplorerSectionGroup = computed(
  () => explorerSectionGroups.value.find((g) => g.section.id === selectedExplorerSectionId.value) ?? null,
);

function syncSectionCasesOrderFromExplorer() {
  const g = activeExplorerSectionGroup.value;
  sectionCasesOrder.value = g ? [...g.cases] : [];
}

watch(
  activeExplorerSectionGroup,
  () => {
    if (!caseReorderBusy.value) {
      syncSectionCasesOrderFromExplorer();
    }
  },
  { immediate: true, deep: true },
);

watch(selectedSuiteId, () => {
  caseInsertAnchor.value = null;
});

watch([selectedSuiteId, explorerSectionGroups], () => {
  const groups = explorerSectionGroups.value;
  if (groups.length === 0) {
    selectedExplorerSectionId.value = null;
    return;
  }
  const cur = selectedExplorerSectionId.value;
  if (cur === null || !groups.some((g) => g.section.id === cur)) {
    selectedExplorerSectionId.value = groups[0]!.section.id;
  }
});

function treeSelectExplorerSection(sectionId: number) {
  selectedExplorerSectionId.value = sectionId;
}

const stepMoveTarget = ref<Record<string, string>>({});

async function loadSuites(pid: number) {
  suites.value = await fetchSuites(pid);
  selectedSuiteId.value = suites.value[0]?.id ?? null;
  if (selectedSuiteId.value) {
    await loadSections(selectedSuiteId.value);
    await loadCases(selectedSuiteId.value);
  } else {
    sections.value = [];
    cases.value = [];
  }
}

async function loadSections(sid: number) {
  sections.value = await fetchSections(sid);
}

async function loadCases(sid: number) {
  cases.value = await fetchCases(sid);
  stepMoveTarget.value = {};
}

watch(
  () => projectCtx.projectId,
  async (pid) => {
    treeDataLoading.value = true;
    error.value = null;
    try {
      if (pid === null) {
        suites.value = [];
        selectedSuiteId.value = null;
        sections.value = [];
        cases.value = [];
        explorerRuns.value = [];
        explorerRunsProjectId.value = null;
        explorerRunsLoading.value = false;
        explorerRunsError.value = false;
        projectTesters.value = [];
        return;
      }
      void fetchExplorerRuns(pid);
      void loadProjectTesters(pid);
      await loadSuites(pid);
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load';
    } finally {
      treeDataLoading.value = false;
    }
  },
  { immediate: true },
);

async function addSuite() {
  const pid = projectCtx.projectId;
  const name = newSuiteName.value.trim();
  if (!pid || !name) {
    return;
  }
  error.value = null;
  try {
    await createSuite(pid, name);
    newSuiteName.value = '';
    await loadSuites(pid);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not create suite';
  }
}

function clearCaseInsertAnchor() {
  caseInsertAnchor.value = null;
}

function setCaseInsertAnchor(c: TestCase, position: 'before' | 'after') {
  caseInsertAnchor.value = { caseId: c.id, title: c.title, position };
  selectedExplorerSectionId.value = c.section_id;
}

async function addCase() {
  const sid = selectedSuiteId.value;
  const title = newCaseTitle.value.trim();
  if (!sid || !title) {
    return;
  }
  const anchor = caseInsertAnchor.value;
  error.value = null;
  try {
    const body: Parameters<typeof createCase>[1] = {
      title,
      steps: [
        { action: 'Arrange', expected: 'Preconditions met' },
        { action: 'Act', expected: 'Behavior occurs' },
        { action: 'Assert', expected: 'Outcome verified' },
      ],
      priority: 'medium',
      status: 'ready',
      section_id: selectedExplorerSectionId.value ?? sections.value[0]?.id,
    };
    if (anchor?.position === 'before') {
      body.before_case_id = anchor.caseId;
    } else if (anchor?.position === 'after') {
      body.after_case_id = anchor.caseId;
    }
    await createCase(sid, body);
    newCaseTitle.value = '';
    caseInsertAnchor.value = null;
    await loadCases(sid);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not create case';
  }
}

async function onSelectSuite(sid: number) {
  selectedSuiteId.value = sid;
  await loadSections(sid);
  await loadCases(sid);
}

async function addSection() {
  const sid = selectedSuiteId.value;
  const name = newSectionName.value.trim();
  if (!sid || !name) {
    return;
  }
  error.value = null;
  try {
    await createSection(sid, {
      name,
      precondition: newSectionPrecondition.value.trim() || null,
    });
    newSectionName.value = '';
    newSectionPrecondition.value = '';
    await loadSections(sid);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not create section';
  }
}

type TreeDialogCtx =
  | { kind: 'project'; id: number }
  | { kind: 'suite'; id: number }
  | { kind: 'section'; suiteId: number; id: number }
  | { kind: 'suite-run'; suiteId: number }
  | { kind: 'section-run'; sectionId: number };

const treeDialogCtx = ref<TreeDialogCtx | null>(null);
const treeDialogFields = ref<FieldDef[]>([]);
const treeDialogTitle = ref('Edit');
const treeDialogSubmitLabel = ref('Save');
const treeDialogBusy = ref(false);
const treeDialogError = ref<string | null>(null);

function resolveSectionForEdit(sec: Section): Section {
  return sections.value.find((x) => x.id === sec.id) ?? sec;
}

function buildRunDialogFields(nameInitial: string): FieldDef[] {
  const fields: FieldDef[] = [
    {
      key: 'name',
      label: 'Run name',
      kind: 'text',
      required: true,
      initial: nameInitial,
      autofocus: true,
    },
  ];
  if (canAssign.value && (projectTesters.value.length > 0 || currentUserId.value != null)) {
    fields.push({
      key: 'assigned_to_user_id',
      label: 'Who will be performing this test?',
      kind: 'select',
      initial: '',
      options: runAssigneeSelectOptions(projectTesters.value, currentUserId.value),
      help: 'Optional. Assigned testers will see this run in their Test runs list.',
    });
  }
  return fields;
}

function createRunBodyFromDialog(
  name: string,
  values: Record<string, string | number | boolean | null>,
): CreateRunBody {
  const body: CreateRunBody = { name };
  const assignedTo = assignedToUserIdFromForm(values);
  if (assignedTo !== undefined) {
    body.assigned_to_user_id = assignedTo;
  }
  return body;
}

function isRunDialogCtx(ctx: TreeDialogCtx | null): ctx is
  | { kind: 'suite-run'; suiteId: number }
  | { kind: 'section-run'; sectionId: number } {
  return ctx?.kind === 'suite-run' || ctx?.kind === 'section-run';
}

function resolveRunDialogSubmitLabel(
  values: Record<string, string | number | boolean | null>,
): string {
  if (!isRunDialogCtx(treeDialogCtx.value)) {
    return treeDialogSubmitLabel.value;
  }
  return isRunAssignedToOther(values, currentUserId.value) ? 'Assign test' : 'Start test run';
}

async function afterRunCreated(
  runId: number,
  values: Record<string, string | number | boolean | null>,
): Promise<void> {
  closeTreeDialog();
  const pid = projectCtx.projectId;
  if (pid !== null) {
    void fetchExplorerRuns(pid);
  }
  if (isRunAssignedToOther(values, currentUserId.value)) {
    await router.push({ name: 'runs' });
    return;
  }
  await router.push(`/runs/${runId}`);
}

function defaultRunName(label: string): string {
  const ts = new Date().toISOString().replace('T', ' ').slice(0, 19);
  return `Run: ${label} · ${ts}`;
}

function closeTreeDialog() {
  treeDialogCtx.value = null;
  treeDialogError.value = null;
  treeDialogFields.value = [];
}

function startEditProject(p: Project) {
  treeDialogCtx.value = { kind: 'project', id: p.id };
  treeDialogTitle.value = 'Edit project';
  treeDialogSubmitLabel.value = 'Save';
  treeDialogError.value = null;
  treeDialogFields.value = [
    { key: 'name', label: 'Name', kind: 'text', initial: p.name, required: true, autofocus: true },
    {
      key: 'description',
      label: 'Description',
      kind: 'textarea',
      initial: p.description ?? '',
      rows: 4,
      placeholder: 'Optional',
    },
  ];
}

function startEditSuite(s: Suite) {
  treeDialogCtx.value = { kind: 'suite', id: s.id };
  treeDialogTitle.value = 'Edit suite';
  treeDialogSubmitLabel.value = 'Save';
  treeDialogError.value = null;
  treeDialogFields.value = [
    { key: 'name', label: 'Name', kind: 'text', initial: s.name, required: true, autofocus: true },
  ];
}

function startEditSection(s: Section) {
  const full = resolveSectionForEdit(s);
  treeDialogCtx.value = { kind: 'section', suiteId: full.suite_id, id: full.id };
  treeDialogTitle.value = 'Edit section';
  treeDialogSubmitLabel.value = 'Save';
  treeDialogError.value = null;
  treeDialogFields.value = [
    { key: 'name', label: 'Name', kind: 'text', initial: full.name, required: true, autofocus: true },
    {
      key: 'precondition',
      label: 'Precondition',
      kind: 'textarea',
      initial: full.precondition ?? '',
      rows: 3,
      placeholder: 'Optional',
    },
    { key: 'sort_order', label: 'Sort order', kind: 'number', initial: full.sort_order, min: 0 },
  ];
}

async function openSuiteRunDialog(s: Suite) {
  const pid = projectCtx.projectId;
  if (pid !== null) {
    await loadProjectTesters(pid);
  }
  treeDialogCtx.value = { kind: 'suite-run', suiteId: s.id };
  treeDialogTitle.value = 'Start test run';
  treeDialogSubmitLabel.value = 'Start run';
  treeDialogError.value = null;
  treeDialogFields.value = buildRunDialogFields(defaultRunName(s.name));
}

async function openSectionRunDialog(section: Section) {
  const suiteN = selectedSuite.value?.name ?? 'Suite';
  const pid = projectCtx.projectId;
  if (pid !== null) {
    await loadProjectTesters(pid);
  }
  treeDialogCtx.value = { kind: 'section-run', sectionId: section.id };
  treeDialogTitle.value = 'Start test run';
  treeDialogSubmitLabel.value = 'Start run';
  treeDialogError.value = null;
  treeDialogFields.value = buildRunDialogFields(defaultRunName(`${suiteN} / ${section.name}`));
}

function onTreeDialogModel(open: boolean) {
  if (!open) {
    closeTreeDialog();
  }
}

async function onTreeDialogSubmit(values: Record<string, string | number | boolean | null>) {
  const ctx = treeDialogCtx.value;
  if (!ctx) {
    return;
  }
  treeDialogBusy.value = true;
  treeDialogError.value = null;
  try {
    if (ctx.kind === 'project') {
      await updateProject(ctx.id, {
        name: String(values.name ?? '').trim(),
        description: values.description == null ? null : String(values.description).trim() || null,
      });
      closeTreeDialog();
      await projectCtx.refreshProjects();
    } else if (ctx.kind === 'suite') {
      const pid = projectCtx.projectId;
      if (!pid) {
        return;
      }
      await updateSuite(pid, ctx.id, {
        name: String(values.name ?? '').trim(),
      });
      closeTreeDialog();
      await loadSuites(pid);
    } else if (ctx.kind === 'section') {
      const prev = sections.value.find((x) => x.id === ctx.id);
      const so = values.sort_order;
      const sortOrder = typeof so === 'number' && Number.isFinite(so) ? so : (prev?.sort_order ?? 0);
      await updateSection(ctx.suiteId, ctx.id, {
        name: String(values.name ?? '').trim(),
        precondition: values.precondition == null ? null : String(values.precondition).trim() || null,
        sort_order: sortOrder,
      });
      closeTreeDialog();
      await loadSections(ctx.suiteId);
      await loadCases(ctx.suiteId);
    } else if (ctx.kind === 'suite-run') {
      const name = String(values.name ?? '').trim();
      const r = await createRunFromSuite(ctx.suiteId, createRunBodyFromDialog(name, values));
      await afterRunCreated(r.id, values);
    } else if (ctx.kind === 'section-run') {
      const name = String(values.name ?? '').trim();
      const r = await createRunFromSection(ctx.sectionId, createRunBodyFromDialog(name, values));
      await afterRunCreated(r.id, values);
    }
  } catch (err) {
    treeDialogError.value = err instanceof Error ? err.message : 'Request failed';
  } finally {
    treeDialogBusy.value = false;
  }
}

const editorOpen = ref(false);
const editingCase = ref<TestCase | null>(null);

function openEditor(c: TestCase) {
  editingCase.value = c;
  editorOpen.value = true;
}

async function onCaseSaved() {
  const sid = selectedSuiteId.value;
  if (sid) {
    await loadSections(sid);
    await loadCases(sid);
  }
}

async function duplicateCaseClick(c: TestCase) {
  const sid = selectedSuiteId.value;
  if (!sid) {
    return;
  }
  error.value = null;
  try {
    await duplicateCase(sid, c.id);
    await loadCases(sid);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Duplicate failed';
  }
}

async function duplicateSuiteClick(s: Suite) {
  const pid = projectCtx.projectId;
  if (!pid) {
    return;
  }
  error.value = null;
  try {
    const d = await duplicateSuite(pid, s.id);
    await loadSuites(pid);
    selectedSuiteId.value = d.id;
    await loadSections(d.id);
    await loadCases(d.id);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Duplicate suite failed';
  }
}

async function onMoveCaseToSuite(c: TestCase, ev: Event) {
  const sel = ev.target as HTMLSelectElement;
  const raw = sel.value;
  const targetSuiteId = raw ? parseInt(raw, 10) : NaN;
  sel.selectedIndex = 0;
  if (!raw || Number.isNaN(targetSuiteId)) {
    return;
  }
  const fromSid = selectedSuiteId.value;
  const pid = projectCtx.projectId;
  if (!fromSid || !pid) {
    return;
  }
  error.value = null;
  try {
    await moveCaseToSuite(fromSid, c.id, targetSuiteId);
    await loadSuites(pid);
    selectedSuiteId.value = targetSuiteId;
    await loadSections(targetSuiteId);
    await loadCases(targetSuiteId);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Move case failed';
  }
}

async function submitMoveStep(c: TestCase, stepIndex: number) {
  const sid = selectedSuiteId.value;
  if (!sid) {
    return;
  }
  const key = `${c.id}-${stepIndex}`;
  const raw = stepMoveTarget.value[key] ?? '';
  const targetCaseId = raw ? parseInt(raw, 10) : NaN;
  if (!raw || Number.isNaN(targetCaseId)) {
    error.value = 'Pick a target case for the step.';
    return;
  }
  if (targetCaseId === c.id) {
    error.value = 'Pick a different case (reorder steps in Edit).';
    return;
  }
  error.value = null;
  try {
    await moveStepBetweenCases(sid, c.id, stepIndex, targetCaseId);
    stepMoveTarget.value = { ...stepMoveTarget.value, [key]: '' };
    await loadCases(sid);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Move step failed';
  }
}

async function treeSelectSuite(sid: number) {
  if (selectedSuiteId.value === sid) {
    return;
  }
  await onSelectSuite(sid);
}

type DeleteTarget =
  | { kind: 'project'; project: Project }
  | { kind: 'suite'; suite: Suite }
  | { kind: 'section'; section: Section }
  | { kind: 'case'; suiteId: number; testCase: TestCase };

const deleteTarget = ref<DeleteTarget | null>(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deletePreviewTitle = ref('');
const deletePreviewMessage = ref('');
const deletePreviewConsequences = ref<string[]>([]);
const deletePreviewNote = ref<string | undefined>(undefined);

function pluralize(n: number, one: string, many?: string): string {
  return `${n} ${n === 1 ? one : many ?? one + 's'}`;
}

async function askDeleteProject(p: Project) {
  error.value = null;
  deleteTarget.value = { kind: 'project', project: p };
  deletePreviewTitle.value = `Delete project "${p.name}"?`;
  deletePreviewMessage.value = 'This permanently removes the project and everything it contains.';
  deletePreviewConsequences.value = ['Loading cascade preview…'];
  deletePreviewNote.value = undefined;
  deleteOpen.value = true;
  try {
    const preview = await deleteProject(p.id, { dryRun: true });
    const c = preview.cascade;
    const items: string[] = [];
    if (c.suites > 0) items.push(`${pluralize(c.suites, 'test suite')}`);
    if (c.cases > 0) items.push(`${pluralize(c.cases, 'test case')}`);
    if (c.runs > 0) items.push(`${pluralize(c.runs, 'test run')} (with ${pluralize(c.run_items, 'recorded result')})`);
    if (c.versions > 0) items.push(`${pluralize(c.versions, 'stored case version', 'stored case versions')}`);
    if (items.length === 0) items.push('No additional records to remove.');
    deletePreviewConsequences.value = items;
    deletePreviewNote.value = 'This action cannot be undone.';
  } catch (e) {
    deletePreviewConsequences.value = [];
    deletePreviewNote.value = e instanceof Error ? `Preview failed: ${e.message}` : 'Preview failed';
  }
}

async function askDeleteSuite(s: Suite) {
  error.value = null;
  deleteTarget.value = { kind: 'suite', suite: s };
  deletePreviewTitle.value = `Delete suite "${s.name}"?`;
  deletePreviewMessage.value = 'This permanently removes the suite and all the test cases it contains.';
  deletePreviewConsequences.value = ['Loading cascade preview…'];
  deletePreviewNote.value = undefined;
  deleteOpen.value = true;
  try {
    const preview = await deleteSuite(s.project_id, s.id, { dryRun: true });
    const c = preview.cascade;
    const items: string[] = [];
    if (c.cases > 0) items.push(`${pluralize(c.cases, 'test case')}`);
    if (c.versions > 0) items.push(`${pluralize(c.versions, 'stored case version', 'stored case versions')}`);
    if (c.run_items > 0) items.push(`${pluralize(c.run_items, 'recorded run result')}`);
    if (c.detached_runs > 0) {
      items.push(
        `${pluralize(c.detached_runs, 'existing run')} will be kept but unlinked from this suite`,
      );
    }
    if (items.length === 0) items.push('No additional records to remove.');
    deletePreviewConsequences.value = items;
    deletePreviewNote.value = 'This action cannot be undone.';
  } catch (e) {
    deletePreviewConsequences.value = [];
    deletePreviewNote.value = e instanceof Error ? `Preview failed: ${e.message}` : 'Preview failed';
  }
}

async function askDeleteCase(c: TestCase) {
  const sid = selectedSuiteId.value;
  if (!sid) {
    return;
  }
  error.value = null;
  deleteTarget.value = { kind: 'case', suiteId: sid, testCase: c };
  deletePreviewTitle.value = `Delete test case "${c.title}"?`;
  deletePreviewMessage.value = 'This permanently removes the test case from the suite.';
  deletePreviewConsequences.value = ['Loading cascade preview…'];
  deletePreviewNote.value = undefined;
  deleteOpen.value = true;
  try {
    const preview = await deleteCase(sid, c.id, { dryRun: true });
    const cc = preview.cascade;
    const items: string[] = [];
    if (cc.versions > 0) items.push(`${pluralize(cc.versions, 'stored case version', 'stored case versions')}`);
    if (cc.run_items > 0) items.push(`${pluralize(cc.run_items, 'recorded run result')}`);
    if (items.length === 0) items.push('No additional records to remove.');
    deletePreviewConsequences.value = items;
    deletePreviewNote.value = 'This action cannot be undone.';
  } catch (e) {
    deletePreviewConsequences.value = [];
    deletePreviewNote.value = e instanceof Error ? `Preview failed: ${e.message}` : 'Preview failed';
  }
}

async function askDeleteSection(section: Section) {
  error.value = null;
  deleteTarget.value = { kind: 'section', section };
  deletePreviewTitle.value = `Delete section "${section.name}"?`;
  deletePreviewMessage.value = 'This permanently removes the section and all test cases inside it.';
  deletePreviewConsequences.value = ['Loading cascade preview…'];
  deletePreviewNote.value = undefined;
  deleteOpen.value = true;
  try {
    const preview = await deleteSection(section.suite_id, section.id, { dryRun: true });
    const cc = preview.cascade;
    const items: string[] = [];
    if (cc.cases > 0) items.push(`${pluralize(cc.cases, 'test case')}`);
    if (cc.versions > 0) items.push(`${pluralize(cc.versions, 'stored case version', 'stored case versions')}`);
    if (cc.run_items > 0) items.push(`${pluralize(cc.run_items, 'recorded run result')}`);
    if (items.length === 0) items.push('No additional records to remove.');
    deletePreviewConsequences.value = items;
    deletePreviewNote.value = 'This action cannot be undone.';
  } catch (e) {
    deletePreviewConsequences.value = [];
    deletePreviewNote.value = e instanceof Error ? `Preview failed: ${e.message}` : 'Preview failed';
  }
}

function cancelDelete() {
  deleteTarget.value = null;
  deleteOpen.value = false;
}

async function confirmDelete() {
  const t = deleteTarget.value;
  if (!t) {
    return;
  }
  deleteBusy.value = true;
  try {
    if (t.kind === 'project') {
      await deleteProject(t.project.id);
      deleteOpen.value = false;
      deleteTarget.value = null;
      await projectCtx.refreshProjects();
    } else if (t.kind === 'suite') {
      await deleteSuite(t.suite.project_id, t.suite.id);
      const removedSuiteId = t.suite.id;
      const pid = t.suite.project_id;
      deleteOpen.value = false;
      deleteTarget.value = null;
      if (selectedSuiteId.value === removedSuiteId) {
        selectedSuiteId.value = null;
        cases.value = [];
      }
      await loadSuites(pid);
    } else if (t.kind === 'section') {
      await deleteSection(t.section.suite_id, t.section.id);
      const sid = t.section.suite_id;
      deleteOpen.value = false;
      deleteTarget.value = null;
      await loadSections(sid);
      await loadCases(sid);
    } else {
      await deleteCase(t.suiteId, t.testCase.id);
      const sid = t.suiteId;
      deleteOpen.value = false;
      deleteTarget.value = null;
      await loadCases(sid);
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Delete failed';
  } finally {
    deleteBusy.value = false;
  }
}

const bulkSelected = ref(new Set<number>());
const bulkSelectedCount = computed(() => bulkSelected.value.size);
const bulkMultiTargetStatus = ref<CaseWorkflowStatus>('ready');

const bulkStateDialogOpen = ref(false);
const bulkStateDialogBusy = ref(false);
const bulkStateDialogTitle = ref('');
const bulkStateDialogMessage = ref('');
const bulkStateDialogConsequences = ref<string[]>([]);
const bulkStateDialogDanger = ref(false);
const bulkStateDialogConfirmLabel = ref('Apply');

type GroupedCases = { section: Section; cases: TestCase[] };

type BulkStatePending =
  | { type: 'section'; section: Section; status: CaseWorkflowStatus }
  | { type: 'multi-deprecated'; caseIds: number[] };

const bulkStatePending = ref<BulkStatePending | null>(null);

function bulkIsSelected(id: number) {
  return bulkSelected.value.has(id);
}

function toggleBulkSelected(id: number) {
  const s = new Set(bulkSelected.value);
  if (s.has(id)) {
    s.delete(id);
  } else {
    s.add(id);
  }
  bulkSelected.value = s;
}

function clearBulkSelected() {
  bulkSelected.value = new Set();
}

function selectAllInGrouped(group: GroupedCases) {
  const s = new Set(bulkSelected.value);
  for (const c of group.cases) {
    s.add(c.id);
  }
  bulkSelected.value = s;
}

function deselectAllInGrouped(group: GroupedCases) {
  const s = new Set(bulkSelected.value);
  for (const c of group.cases) {
    s.delete(c.id);
  }
  bulkSelected.value = s;
}

watch(selectedSuiteId, () => {
  clearBulkSelected();
});

watch(selectedExplorerSectionId, () => {
  clearBulkSelected();
});

function openBulkStateDialog(p: BulkStatePending) {
  bulkStatePending.value = p;
  if (p.type === 'section') {
    const n = explorerSectionGroups.value.find((g) => g.section.id === p.section.id)?.cases.length ?? 0;
    bulkStateDialogTitle.value = `Set all cases in “${p.section.name}” to ${p.status}?`;
    bulkStateDialogMessage.value =
      'Every test case in this section will be updated. A version snapshot is stored for each case that changes.';
    bulkStateDialogConsequences.value = [`${n} test case(s) in this section.`];
    bulkStateDialogDanger.value = p.status === 'deprecated';
    bulkStateDialogConfirmLabel.value = 'Apply to section';
  } else {
    bulkStateDialogTitle.value = `Mark ${p.caseIds.length} selected case(s) as deprecated?`;
    bulkStateDialogMessage.value =
      'Deprecated cases remain in the suite but are marked as not recommended for new runs.';
    bulkStateDialogConsequences.value = [];
    bulkStateDialogDanger.value = true;
    bulkStateDialogConfirmLabel.value = 'Mark deprecated';
  }
  bulkStateDialogOpen.value = true;
}

function cancelBulkStateDialog() {
  bulkStateDialogOpen.value = false;
  bulkStatePending.value = null;
}

async function confirmBulkStateDialog() {
  const sid = selectedSuiteId.value;
  const p = bulkStatePending.value;
  if (!sid || !p) {
    return;
  }
  bulkStateDialogBusy.value = true;
  error.value = null;
  try {
    const body =
      p.type === 'section'
        ? { status: p.status, section_id: p.section.id }
        : { status: 'deprecated' as const, case_ids: p.caseIds };
    const r = await bulkSetCasesStatus(sid, body);
    if (r.unknown_case_ids.length > 0) {
      error.value = `Updated ${r.updated}; unknown ids (not in suite): ${r.unknown_case_ids.join(', ')}`;
    }
    await loadCases(sid);
    clearBulkSelected();
    bulkStateDialogOpen.value = false;
    bulkStatePending.value = null;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Bulk update failed';
  } finally {
    bulkStateDialogBusy.value = false;
  }
}

async function applyMultiBulkStatus() {
  const sid = selectedSuiteId.value;
  if (!sid || bulkSelected.value.size === 0) {
    return;
  }
  const st = bulkMultiTargetStatus.value;
  if (st === 'deprecated') {
    openBulkStateDialog({ type: 'multi-deprecated', caseIds: Array.from(bulkSelected.value) });
    return;
  }
  error.value = null;
  try {
    const r = await bulkSetCasesStatus(sid, {
      status: st,
      case_ids: Array.from(bulkSelected.value),
    });
    if (r.unknown_case_ids.length > 0) {
      error.value = `Updated ${r.updated}; unknown ids: ${r.unknown_case_ids.join(', ')}`;
    }
    await loadCases(sid);
    clearBulkSelected();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Bulk update failed';
  }
}

function askSectionBulkStatus(section: Section, status: CaseWorkflowStatus) {
  openBulkStateDialog({ type: 'section', section, status });
}
</script>

<template>
  <div class="layout">
    <aside class="nav-tree panel" aria-label="Workspace tree">
      <div class="panel-head">
        <h2>Explorer</h2>
        <p class="hint">Suites and sections for the project selected in the top bar. Pick a section to view its cases.</p>
      </div>

      <div v-if="selectedProject && canWrite" class="project-explorer-actions" aria-label="Project actions">
        <IconButton label="Edit project" title="Edit project" @click="startEditProject(selectedProject)">
          <svg viewBox="0 0 24 24">
            <path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z" fill="none" />
          </svg>
        </IconButton>
        <IconButton
          danger
          label="Delete project (cascades to all suites and cases)"
          title="Delete project (cascades to all suites and cases)"
          @click="askDeleteProject(selectedProject)"
        >
          <svg viewBox="0 0 24 24">
            <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h12zM10 11v6M14 11v6" fill="none" />
          </svg>
        </IconButton>
      </div>

      <div v-if="viewLoading" class="tree-state">Loading…</div>
      <div v-else-if="!projectCtx.projects.length" class="tree-state muted">No projects yet. Create one from the top bar.</div>
      <div v-else-if="projectCtx.projectId === null" class="tree-state muted">Pick a project in the top bar to browse suites.</div>
      <ul v-else class="tree" role="tree" aria-label="Test suites and sections">
        <li v-if="!suites.length" class="tree-leaf muted">No suites — add one below.</li>
        <li
          v-for="s in suites"
          :key="s.id"
          class="tree-branch suite-branch"
          role="treeitem"
          :aria-expanded="s.id === selectedSuiteId"
        >
          <div class="suite-line">
            <button
              type="button"
              class="tree-row suite-row"
              :class="{ active: s.id === selectedSuiteId }"
              @click="treeSelectSuite(s.id)"
            >
              <span class="tree-chevron sm" :class="{ open: s.id === selectedSuiteId }" aria-hidden="true" />
              <span class="tree-label">
                <span class="tree-title">{{ s.name }}</span>
                <span class="tree-meta">{{ suiteExplorerMeta(s) }}</span>
              </span>
            </button>
            <IconButton
              v-if="canRun"
              accent
              label="Start test run for this suite"
              title="Start test run (full suite)"
              @click.stop="openSuiteRunDialog(s)"
            >
              <svg viewBox="0 0 24 24">
                <path
                  d="M9 6.5l12 5.5-12 5.5V6.5z"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linejoin="round"
                />
              </svg>
            </IconButton>
            <IconButton v-if="canWrite" label="Edit suite" title="Edit suite" @click.stop="startEditSuite(s)">
              <svg viewBox="0 0 24 24">
                <path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z" fill="none" />
              </svg>
            </IconButton>
            <IconButton
              v-if="canWrite"
              label="Duplicate suite with all cases"
              title="Duplicate suite (copy all cases)"
              @click.stop="duplicateSuiteClick(s)"
            >
              <svg viewBox="0 0 24 24">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" fill="none" />
                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" fill="none" />
              </svg>
            </IconButton>
            <IconButton
              v-if="canWrite"
              danger
              label="Delete suite (cascades to all cases)"
              title="Delete suite (cascades to all cases)"
              @click.stop="askDeleteSuite(s)"
            >
              <svg viewBox="0 0 24 24">
                <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h12zM10 11v6M14 11v6" fill="none" />
              </svg>
            </IconButton>
          </div>

          <ul v-if="s.id === selectedSuiteId" class="tree-children section-children" role="group">
            <li v-if="!explorerSectionGroups.length" class="tree-leaf muted">No sections in this suite yet.</li>
            <li v-for="group in explorerSectionGroups" :key="group.section.id" class="tree-branch section-branch">
              <div class="section-line">
                <button
                  type="button"
                  class="tree-row section-row"
                  :class="{ active: group.section.id === selectedExplorerSectionId }"
                  role="treeitem"
                  :aria-selected="group.section.id === selectedExplorerSectionId"
                  @click="treeSelectExplorerSection(group.section.id)"
                >
                  <span class="tree-label">
                    <span class="tree-title">{{ group.section.name }}</span>
                    <span class="tree-meta">{{ group.cases.length }} case{{ group.cases.length === 1 ? '' : 's' }}</span>
                  </span>
                </button>
                <IconButton
                  v-if="canRun"
                  accent
                  label="Start test run for this section"
                  title="Start test run (this section only)"
                  @click.stop="openSectionRunDialog(group.section)"
                >
                  <svg viewBox="0 0 24 24">
                    <path
                      d="M9 6.5l12 5.5-12 5.5V6.5z"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linejoin="round"
                    />
                  </svg>
                </IconButton>
                <IconButton v-if="canWrite" label="Edit section" title="Edit section" @click.stop="startEditSection(group.section)">
                  <svg viewBox="0 0 24 24">
                    <path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z" fill="none" />
                  </svg>
                </IconButton>
                <IconButton v-if="canWrite" danger label="Delete section" title="Delete section" @click.stop="askDeleteSection(group.section)">
                  <svg viewBox="0 0 24 24">
                    <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h12zM10 11v6M14 11v6" fill="none" />
                  </svg>
                </IconButton>
              </div>
            </li>
          </ul>
        </li>
      </ul>

      <form v-if="canWrite" class="inline form-with-icon tree-add-suite" @submit.prevent="addSuite">
        <input v-model="newSuiteName" class="input" type="text" placeholder="New suite in project" :disabled="!selectedProject" />
        <IconButton
          type="submit"
          accent
          label="Add suite to project"
          title="Add suite to this project"
          :disabled="!selectedProject"
        >
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
        </IconButton>
      </form>
      <form v-if="canWrite" class="inline form-with-icon tree-add-section" @submit.prevent="addSection">
        <input v-model="newSectionName" class="input" type="text" placeholder="New section" :disabled="!selectedSuite" />
        <input
          v-model="newSectionPrecondition"
          class="input"
          type="text"
          placeholder="Shared precondition / permission"
          :disabled="!selectedSuite"
        />
        <IconButton type="submit" accent label="Add section" title="Add section to suite" :disabled="!selectedSuite">
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
        </IconButton>
      </form>
    </aside>

    <section class="detail panel">
      <div class="panel-head">
        <h2>Test cases</h2>
        <p class="hint" v-if="selectedSuite && activeExplorerSectionGroup">
          Section “{{ activeExplorerSectionGroup.section.name }}” · {{ selectedSuite.name }} · {{ selectedProject?.name }}
        </p>
        <p class="hint" v-else-if="projectCtx.projectId !== null">Select a suite in the tree to view cases.</p>
        <p class="hint" v-else>Select a project in the top bar to view cases.</p>
      </div>
      <form v-if="canWrite" class="inline form-with-icon case-add-form" @submit.prevent="addCase">
        <input
          v-model="newCaseTitle"
          class="input"
          type="text"
          placeholder="New test case title"
          :disabled="!selectedSuite"
        />
        <p v-if="caseInsertAnchor" class="case-insert-hint">
          Will insert {{ caseInsertAnchor.position === 'before' ? 'before' : 'after' }}
          “{{ caseInsertAnchor.title }}”.
          <button type="button" class="linkish" @click="clearCaseInsertAnchor">Clear</button>
        </p>
        <IconButton
          type="submit"
          accent
          :label="caseInsertAnchor ? 'Add test case at position' : 'Add test case'"
          :title="caseInsertAnchor ? 'Add test case at chosen position' : 'Add test case at end of section'"
          :disabled="!selectedSuite"
        >
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
        </IconButton>
      </form>

      <div v-if="viewLoading" class="state">Loading…</div>
      <div v-else-if="error" class="state error">{{ error }}</div>
      <div v-else-if="projectCtx.projectId === null" class="state muted">Pick a project in the top bar to view cases.</div>
      <div v-else-if="!selectedSuite" class="state muted">Select a suite in the Explorer to view cases.</div>
      <div v-else-if="!explorerSectionGroups.length" class="state muted">No sections in this suite yet. Add one in the Explorer sidebar.</div>
      <div v-else-if="!activeExplorerSectionGroup" class="state muted">Select a section in the Explorer to view cases.</div>

      <div v-else-if="!activeExplorerSectionGroup.cases.length" class="state muted">
        No cases in this section yet. Add one above or pick another section in the Explorer.
      </div>

      <div v-else>
        <div
          v-if="canWrite && bulkSelectedCount > 0"
          class="bulk-status-bar"
          role="region"
          aria-label="Bulk status for selected cases"
        >
          <span class="bulk-status-meta">{{ bulkSelectedCount }} selected</span>
          <select v-model="bulkMultiTargetStatus" class="input sm" aria-label="New status for selection">
            <option value="draft">draft</option>
            <option value="ready">ready</option>
            <option value="deprecated">deprecated</option>
          </select>
          <button type="button" class="btn sm" @click="applyMultiBulkStatus">Apply to selection</button>
          <button type="button" class="btn sm" @click="clearBulkSelected">Clear selection</button>
        </div>
        <div class="cases">
        <section v-if="activeExplorerSectionGroup" :key="activeExplorerSectionGroup.section.id" class="case-section">
          <header class="section-head">
            <div v-if="showSectionHeadings">
              <h3>{{ activeExplorerSectionGroup.section.name }}</h3>
              <p v-if="activeExplorerSectionGroup.section.precondition">{{ activeExplorerSectionGroup.section.precondition }}</p>
              <p v-if="canWrite && sectionCasesOrder.length > 1" class="reorder-hint muted sm">Drag cases by ⠿ to reorder.</p>
            </div>
            <div v-else class="section-head-placeholder">
              <span class="muted sm">Cases in this section</span>
              <span v-if="canWrite && sectionCasesOrder.length > 1" class="reorder-hint muted sm"> · Drag ⠿ to reorder</span>
            </div>
            <div v-if="canWrite" class="section-head-actions">
              <div class="section-bulk" role="group" aria-label="Section bulk status">
                <span class="bulk-hint">All cases →</span>
                <button type="button" class="btn sm" @click="askSectionBulkStatus(activeExplorerSectionGroup.section, 'draft')">
                  draft
                </button>
                <button type="button" class="btn sm" @click="askSectionBulkStatus(activeExplorerSectionGroup.section, 'ready')">
                  ready
                </button>
                <button type="button" class="btn sm" @click="askSectionBulkStatus(activeExplorerSectionGroup.section, 'deprecated')">
                  deprecated
                </button>
                <span class="bulk-hint">Select</span>
                <button type="button" class="btn sm" @click="selectAllInGrouped(activeExplorerSectionGroup)">All</button>
                <button type="button" class="btn sm" @click="deselectAllInGrouped(activeExplorerSectionGroup)">None</button>
              </div>
              <button type="button" class="btn sm" @click="startEditSection(activeExplorerSectionGroup.section)">Edit section</button>
            </div>
          </header>
        <draggable
          :key="'section-cases-' + activeExplorerSectionGroup.section.id"
          v-model="sectionCasesOrder"
          item-key="id"
          handle=".case-drag"
          class="case-draggable-list"
          :animation="180"
          :disabled="!canWrite || caseReorderBusy"
          @end="onCaseListReordered"
        >
          <template #item="{ element: c, index: caseIndex }">
        <article
          :id="'case-' + c.id"
          class="case"
          :class="{ 'case-insert-target': caseInsertAnchor?.caseId === c.id }"
        >
          <header class="case-head">
            <div class="case-title-row">
              <button
                v-if="canWrite"
                type="button"
                class="case-drag"
                title="Drag to reorder"
                aria-label="Drag to reorder"
              >
                ⠿
              </button>
              <input
                v-if="canWrite"
                class="bulk-case-check"
                type="checkbox"
                :checked="bulkIsSelected(c.id)"
                :aria-label="'Select “' + c.title + '” for bulk status'"
                @change="toggleBulkSelected(c.id)"
              />
              <h3>{{ c.title }}</h3>
            </div>
            <div class="case-toolbar">
              <div class="chips">
                <span class="chip">{{ c.priority }}</span>
                <span class="chip chip-status" :class="'chip-status--' + c.status">{{ c.status }}</span>
              </div>
              <div v-if="canWrite" class="case-actions">
                <IconButton
                  label="Move case up"
                  title="Move case up"
                  :disabled="caseIndex === 0 || caseReorderBusy"
                  @click="moveCaseInSection(c, -1)"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 5l-7 7h14l-7-7z" fill="none" stroke="currentColor" stroke-width="2" />
                  </svg>
                </IconButton>
                <IconButton
                  label="Move case down"
                  title="Move case down"
                  :disabled="caseIndex >= sectionCasesOrder.length - 1 || caseReorderBusy"
                  @click="moveCaseInSection(c, 1)"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 19l-7-7h14l7 7z" fill="none" stroke="currentColor" stroke-width="2" />
                  </svg>
                </IconButton>
                <select
                  class="input sm case-move select-subtle"
                  aria-label="Move case to another suite"
                  title="Move case to another suite in this project"
                  @change="onMoveCaseToSuite(c, $event)"
                >
                  <option value="">Move to suite…</option>
                  <option v-for="s in otherSuites" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                </select>
                <IconButton
                  label="Add new case before this one"
                  title="Add new case before this one"
                  @click="setCaseInsertAnchor(c, 'before')"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 4v8M8 8l4-4 4 4M5 20h14" fill="none" stroke="currentColor" stroke-width="2" />
                  </svg>
                </IconButton>
                <IconButton
                  label="Add new case after this one"
                  title="Add new case after this one"
                  @click="setCaseInsertAnchor(c, 'after')"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 20V12M8 16l4 4 4-4M5 4h14" fill="none" stroke="currentColor" stroke-width="2" />
                  </svg>
                </IconButton>
                <IconButton label="Edit test case" title="Edit test case" @click="openEditor(c)">
                  <svg viewBox="0 0 24 24">
                    <path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z" fill="none" />
                  </svg>
                </IconButton>
                <IconButton label="Duplicate test case" title="Duplicate test case" @click="duplicateCaseClick(c)">
                  <svg viewBox="0 0 24 24">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2" fill="none" />
                    <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" fill="none" />
                  </svg>
                </IconButton>
                <IconButton danger label="Delete test case" title="Delete test case" @click="askDeleteCase(c)">
                  <svg viewBox="0 0 24 24">
                    <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h12zM10 11v6M14 11v6" fill="none" />
                  </svg>
                </IconButton>
              </div>
            </div>
          </header>
          <ol class="steps">
            <li v-for="(st, i) in caseSteps(c)" :key="i">
              <div class="step-line">
                <div class="step-body">
                  <div class="step-action">{{ st.action }}</div>
                  <div class="step-expected">Expect: {{ st.expected }}</div>
                  <ul v-if="st.variants?.length" class="variants-read">
                    <li v-for="(va, j) in st.variants" :key="j">
                      <span class="v-label">{{ va.label || 'Variant' }}</span>
                      <span class="v-crit">{{ va.criteria }}</span>
                    </li>
                  </ul>
                </div>
                <div v-if="canWrite" class="step-move">
                  <select
                    v-model="stepMoveTarget[`${c.id}-${i}`]"
                    class="input sm select-subtle"
                    :aria-label="'Move step ' + (i + 1) + ' to another case'"
                    title="Choose target case, then apply move"
                  >
                    <option value="">Target case…</option>
                    <option v-for="oc in cases" :key="oc.id" :value="String(oc.id)" :disabled="oc.id === c.id">
                      {{ oc.title }}
                    </option>
                  </select>
                  <IconButton
                    label="Move step to selected case"
                    title="Move step to selected case"
                    @click="submitMoveStep(c, i)"
                  >
                    <svg viewBox="0 0 24 24"><path d="M5 12h14m-6-7l7 7-7 7" fill="none" /></svg>
                  </IconButton>
                </div>
              </div>
            </li>
          </ol>
        </article>
          </template>
        </draggable>
        </section>
        </div>
      </div>

      <CaseEditorModal
        v-model="editorOpen"
        :test-case="editingCase"
        :suite-id="selectedSuiteId ?? 0"
        :sections="sections"
        @saved="onCaseSaved"
      />
    </section>

    <EntityFormDialog
      v-if="treeDialogCtx"
      :model-value="true"
      @update:model-value="onTreeDialogModel"
      :title="treeDialogTitle"
      :fields="treeDialogFields"
      :submit-label="treeDialogSubmitLabel"
      :resolve-submit-label="isRunDialogCtx(treeDialogCtx) ? resolveRunDialogSubmitLabel : undefined"
      :busy="treeDialogBusy"
      :error-message="treeDialogError"
      @submit="onTreeDialogSubmit"
    />

    <ConfirmDialog
      v-model="deleteOpen"
      :title="deletePreviewTitle"
      :message="deletePreviewMessage"
      :consequences="deletePreviewConsequences"
      :note="deletePreviewNote"
      :busy="deleteBusy"
      confirm-label="Delete"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />

    <ConfirmDialog
      v-model="bulkStateDialogOpen"
      :title="bulkStateDialogTitle"
      :message="bulkStateDialogMessage"
      :consequences="bulkStateDialogConsequences"
      :busy="bulkStateDialogBusy"
      :danger="bulkStateDialogDanger"
      :confirm-label="bulkStateDialogConfirmLabel"
      cancel-label="Cancel"
      @confirm="confirmBulkStateDialog"
      @cancel="cancelBulkStateDialog"
    />
  </div>
</template>

<style scoped>
.layout {
  display: grid;
  grid-template-columns: minmax(260px, 340px) 1fr;
  gap: 1rem;
  align-items: start;
}

.nav-tree {
  position: sticky;
  top: 0.75rem;
  max-height: calc(100vh - 5.5rem);
  overflow: auto;
  min-height: 200px;
}

.detail {
  min-height: 280px;
}

.panel {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem 1.1rem;
}

.panel-head h2 {
  margin: 0 0 0.25rem;
  font-size: 1.05rem;
}

.hint {
  margin: 0 0 1rem;
  color: var(--muted);
  font-size: 0.85rem;
}

.inline {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}

.form-with-icon {
  align-items: center;
}

.form-with-icon .input {
  min-width: 0;
}

.tree-add-suite {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--border);
  margin-bottom: 0;
}

.tree-add-section {
  margin-top: 0.6rem;
  margin-bottom: 0;
  flex-wrap: wrap;
}

.tree {
  list-style: none;
  margin: 0;
  padding: 0;
}

.tree-branch {
  list-style: none;
  margin: 0;
  padding: 0;
}

.tree-children {
  list-style: none;
  margin: 0;
  padding: 0 0 0.15rem 0.65rem;
  border-left: 1px solid color-mix(in srgb, var(--border) 85%, transparent);
  margin-left: 0.55rem;
}

.suite-branch > .tree-children {
  margin-left: 0.5rem;
}

.section-children {
  padding-left: 0.5rem;
  margin-top: 0.15rem;
}

.tree-row {
  display: flex;
  align-items: flex-start;
  gap: 0.35rem;
  width: 100%;
  text-align: left;
  border-radius: 10px;
  border: 1px solid transparent;
  background: transparent;
  color: inherit;
  padding: 0.45rem 0.5rem;
  cursor: pointer;
  font: inherit;
}

.tree-row:hover {
  border-color: var(--border);
  background: var(--panel-2);
}

.tree-row.active {
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
  background: color-mix(in srgb, var(--accent) 12%, var(--panel-2));
}

.tree-chevron {
  display: inline-block;
  width: 0.45rem;
  height: 0.45rem;
  margin-top: 0.35rem;
  flex-shrink: 0;
  border-right: 2px solid var(--muted);
  border-bottom: 2px solid var(--muted);
  transform: rotate(-45deg);
  transition: transform 0.15s ease;
  opacity: 0.85;
}

.tree-chevron.open {
  transform: rotate(45deg);
}

.tree-chevron.sm {
  width: 0.38rem;
  height: 0.38rem;
  margin-top: 0.32rem;
  border-width: 1.5px;
}

.tree-label {
  min-width: 0;
  flex: 1;
}

.tree-title {
  display: block;
  font-weight: 600;
  font-size: 0.88rem;
  line-height: 1.25;
}

.tree-meta {
  display: block;
  font-size: 0.72rem;
  color: var(--muted);
  margin-top: 0.12rem;
  line-height: 1.2;
}

.section-line,
.suite-line,
.project-explorer-actions {
  display: flex;
  gap: 0.25rem;
  align-items: flex-start;
}

.project-explorer-actions {
  margin-bottom: 0.65rem;
}

.project-explorer-actions :deep(.icon-btn) {
  align-self: center;
}

.section-line .tree-row,
.suite-line .tree-row {
  flex: 1;
}

.section-line :deep(.icon-btn),
.suite-line :deep(.icon-btn) {
  align-self: center;
}

.tree-leaf {
  list-style: none;
  margin: 0;
  padding: 0;
}

.tree-leaf.muted {
  font-size: 0.78rem;
  color: var(--muted);
  padding: 0.25rem 0.35rem 0.35rem 1.6rem;
}

.tree-state {
  padding: 0.75rem 0;
  font-size: 0.88rem;
}

.tree-state.muted {
  color: var(--muted);
}

.input {
  flex: 1;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.55rem 0.7rem;
  font: inherit;
}

.input.sm {
  padding: 0.35rem 0.45rem;
  font-size: 0.8rem;
}

.btn {
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.55rem 0.85rem;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.btn.sm {
  padding: 0.35rem 0.55rem;
  font-size: 0.78rem;
}

.btn.primary {
  border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
  background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 35%, var(--panel-2)), var(--panel-2));
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.state {
  padding: 1rem 0;
}

.state.error {
  color: var(--danger);
}

.state.muted {
  color: var(--muted);
}

.cases {
  display: grid;
  gap: 0.75rem;
}

.case-draggable-list {
  display: grid;
  gap: 0.75rem;
}

.reorder-hint {
  margin: 0.2rem 0 0;
}

.case-title-row {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
}

.case-drag {
  flex-shrink: 0;
  margin-top: 0.15rem;
  padding: 0.15rem 0.35rem;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--muted);
  font-size: 1.1rem;
  line-height: 1;
  cursor: grab;
}

.case-drag:active {
  cursor: grabbing;
}

.case-drag:hover {
  color: var(--text);
  background: color-mix(in srgb, var(--panel) 50%, transparent);
}

.case-section {
  display: grid;
  gap: 0.65rem;
}

.section-head {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.55rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: color-mix(in srgb, var(--panel-2) 70%, transparent);
}

.section-head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  align-items: center;
  justify-content: flex-end;
}

.section-head-placeholder {
  min-width: 0;
}

.section-bulk {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  align-items: center;
}

.bulk-hint {
  font-size: 0.72rem;
  color: var(--muted);
  margin-right: 0.15rem;
}

.bulk-status-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  padding: 0.55rem 0.65rem;
  margin-bottom: 0.65rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: color-mix(in srgb, var(--accent) 8%, var(--panel-2));
}

.bulk-status-meta {
  font-size: 0.85rem;
  font-weight: 600;
  margin-right: 0.25rem;
}

.bulk-case-check {
  margin-top: 0.2rem;
  flex-shrink: 0;
  accent-color: var(--accent, #38bdf8);
  cursor: pointer;
}

.case-title-row h3 {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 1rem;
}

.section-head h3 {
  margin: 0;
  font-size: 0.95rem;
}

.section-head p {
  margin: 0.2rem 0 0;
  color: var(--muted);
  font-size: 0.82rem;
}

.case {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.85rem 0.95rem;
  background: var(--panel-2);
  scroll-margin-top: 0.75rem;
  transition:
    box-shadow 0.2s ease,
    outline 0.2s ease;
}

.case-head {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.5rem;
}

.case-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.case-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  align-items: center;
}

.select-subtle {
  border-color: color-mix(in srgb, var(--border) 75%, transparent);
  background: color-mix(in srgb, var(--panel) 35%, var(--panel-2));
  color: var(--muted);
  font-size: 0.78rem;
}

.select-subtle:focus {
  outline: none;
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
  color: var(--text);
}

.case-move {
  min-width: 8rem;
  max-width: 14rem;
}

.chips {
  display: flex;
  gap: 0.35rem;
  flex-shrink: 0;
}

.chip {
  font-size: 0.72rem;
  padding: 0.2rem 0.45rem;
  border-radius: 999px;
  border: 1px solid var(--border);
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.chip-status--ready {
  border-color: color-mix(in srgb, var(--success) 55%, var(--border));
  color: var(--success);
}

.chip-status--draft {
  border-color: color-mix(in srgb, var(--muted) 50%, var(--border));
}

.chip-status--deprecated {
  opacity: 0.75;
}

.steps {
  margin: 0.65rem 0 0;
  padding-left: 1.1rem;
  color: var(--muted);
  font-size: 0.9rem;
}

.step-line {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}

.step-body {
  flex: 1;
  min-width: 0;
}

.step-move {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  align-items: center;
  flex-shrink: 0;
}

.step-move .input.sm {
  min-width: 8rem;
  max-width: 11rem;
}

.step-action {
  color: var(--text);
  font-weight: 500;
}

.step-expected {
  margin-top: 0.15rem;
}

.case-insert-target {
  outline: 2px solid color-mix(in srgb, var(--accent) 65%, transparent);
  outline-offset: 2px;
  border-radius: var(--radius);
}

.case-add-form {
  flex-wrap: wrap;
  align-items: center;
}

.case-insert-hint {
  flex: 1 1 100%;
  margin: 0;
  font-size: 0.85rem;
  color: var(--muted);
}

.linkish {
  margin-left: 0.35rem;
  padding: 0;
  border: none;
  background: none;
  color: var(--accent);
  font: inherit;
  cursor: pointer;
  text-decoration: underline;
}

.variants-read {
  list-style: none;
  margin: 0.4rem 0 0;
  padding: 0.35rem 0.5rem;
  border-left: 2px solid color-mix(in srgb, var(--accent) 55%, var(--border));
  background: color-mix(in srgb, var(--panel) 70%, transparent);
  border-radius: 0 6px 6px 0;
  font-size: 0.82rem;
}

.variants-read li {
  margin-bottom: 0.25rem;
}

.variants-read li:last-child {
  margin-bottom: 0;
}

.v-label {
  font-weight: 600;
  color: var(--accent);
  margin-right: 0.35rem;
}

.v-crit {
  color: var(--muted);
}

@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }

  .nav-tree {
    position: static;
    max-height: none;
  }
}
</style>
