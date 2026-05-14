const base = '';

/** Ask for JSON responses (Slim error handler) and send JSON bodies. */
const jsonWriteHeaders: Record<string, string> = {
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

export type Project = {
  id: number;
  name: string;
  description: string | null;
  created_at: string;
};

export type Suite = {
  id: number;
  project_id: number;
  name: string;
  created_at: string;
};

export type Section = {
  id: number;
  suite_id: number;
  name: string;
  precondition: string | null;
  sort_order: number;
  created_at: string;
};

/** Extra criteria for executing the same step (e.g. platform, role, dataset). */
export type StepVariant = { label?: string; criteria: string };

export type TestStep = {
  action: string;
  expected: string;
  variants?: StepVariant[];
};

export type TestCase = {
  id: number;
  suite_id: number;
  section_id: number;
  section_name?: string;
  section_precondition?: string | null;
  title: string;
  precondition: string | null;
  steps: TestStep[];
  priority: string;
  status: string;
  created_at: string;
  updated_at: string;
};

/** Allowed workflow values for `test_cases.status` (API + UI). */
export type CaseWorkflowStatus = 'draft' | 'ready' | 'deprecated';

async function parseJson<T>(res: Response): Promise<T> {
  const text = await res.text();
  if (!res.ok) {
    let msg = res.statusText;
    try {
      const j = JSON.parse(text) as {
        error?: string;
        message?: string;
        exception?: Array<{ message?: string }>;
      };
      if (j.error) {
        msg = j.error;
      } else if (j.exception?.[0]?.message) {
        msg = j.exception[0].message;
      } else if (j.message) {
        msg = j.message;
      }
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  return JSON.parse(text) as T;
}

export async function fetchProjects(): Promise<Project[]> {
  const res = await fetch(`${base}/api/projects`);
  const data = await parseJson<{ data: Project[] }>(res);
  return data.data;
}

export async function createProject(name: string, description?: string): Promise<{ id: number }> {
  const res = await fetch(`${base}/api/projects`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify({ name, description }),
  });
  const data = await parseJson<{ data: { id: number } }>(res);
  return data.data;
}

export async function updateProject(
  projectId: number,
  body: { name?: string; description?: string | null },
): Promise<Project> {
  const res = await fetch(`${base}/api/projects/${projectId}`, {
    method: 'PATCH',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{ data: Project }>(res);
  return data.data;
}

export type ProjectDeleteCascade = {
  suites: number;
  cases: number;
  runs: number;
  run_items: number;
  versions: number;
};

export type SuiteDeleteCascade = {
  sections?: number;
  cases: number;
  versions: number;
  run_items: number;
  /** Runs that pointed at this suite; they stay but their suite_id becomes NULL. */
  detached_runs: number;
};

export type CaseDeleteCascade = {
  versions: number;
  run_items: number;
};

export type SectionDeleteCascade = {
  cases: number;
  versions: number;
  run_items: number;
};

export type RunDeleteCascade = {
  run_items: number;
};

export type DeleteResult<TCascade, TEntity> = {
  dry_run?: boolean;
  cascade: TCascade;
} & TEntity;

export async function deleteProject(
  projectId: number,
  opts?: { dryRun?: boolean },
): Promise<DeleteResult<ProjectDeleteCascade, { project: { id: number; name: string } }>> {
  const url = `${base}/api/projects/${projectId}${opts?.dryRun ? '?dry_run=1' : ''}`;
  const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json' } });
  const data = await parseJson<{
    data: DeleteResult<ProjectDeleteCascade, { project: { id: number; name: string } }>;
  }>(res);
  return data.data;
}

export async function deleteSuite(
  projectId: number,
  suiteId: number,
  opts?: { dryRun?: boolean },
): Promise<DeleteResult<SuiteDeleteCascade, { suite: { id: number; project_id: number; name: string } }>> {
  const url = `${base}/api/projects/${projectId}/suites/${suiteId}${opts?.dryRun ? '?dry_run=1' : ''}`;
  const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json' } });
  const data = await parseJson<{
    data: DeleteResult<SuiteDeleteCascade, { suite: { id: number; project_id: number; name: string } }>;
  }>(res);
  return data.data;
}

export async function deleteCase(
  suiteId: number,
  caseId: number,
  opts?: { dryRun?: boolean },
): Promise<DeleteResult<CaseDeleteCascade, { case: { id: number; suite_id: number; title: string } }>> {
  const url = `${base}/api/suites/${suiteId}/cases/${caseId}${opts?.dryRun ? '?dry_run=1' : ''}`;
  const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json' } });
  const data = await parseJson<{
    data: DeleteResult<CaseDeleteCascade, { case: { id: number; suite_id: number; title: string } }>;
  }>(res);
  return data.data;
}

export async function deleteSection(
  suiteId: number,
  sectionId: number,
  opts?: { dryRun?: boolean },
): Promise<DeleteResult<SectionDeleteCascade, { section: { id: number; suite_id: number; name: string } }>> {
  const url = `${base}/api/suites/${suiteId}/sections/${sectionId}${opts?.dryRun ? '?dry_run=1' : ''}`;
  const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json' } });
  const data = await parseJson<{
    data: DeleteResult<SectionDeleteCascade, { section: { id: number; suite_id: number; name: string } }>;
  }>(res);
  return data.data;
}

export async function deleteRun(
  runId: number,
  opts?: { dryRun?: boolean },
): Promise<DeleteResult<RunDeleteCascade, { run: { id: number; project_id: number; name: string } }>> {
  const url = `${base}/api/runs/${runId}${opts?.dryRun ? '?dry_run=1' : ''}`;
  const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json' } });
  const data = await parseJson<{
    data: DeleteResult<RunDeleteCascade, { run: { id: number; project_id: number; name: string } }>;
  }>(res);
  return data.data;
}

export async function fetchSuites(projectId: number): Promise<Suite[]> {
  const res = await fetch(`${base}/api/projects/${projectId}/suites`);
  const data = await parseJson<{ data: Suite[] }>(res);
  return data.data;
}

export async function fetchSections(suiteId: number): Promise<Section[]> {
  const res = await fetch(`${base}/api/suites/${suiteId}/sections`);
  const data = await parseJson<{ data: Section[] }>(res);
  return data.data;
}

export async function createSection(
  suiteId: number,
  body: { name: string; precondition?: string | null; sort_order?: number },
): Promise<{ id: number; suite_id: number; name: string }> {
  const res = await fetch(`${base}/api/suites/${suiteId}/sections`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{ data: { id: number; suite_id: number; name: string } }>(res);
  return data.data;
}

export async function updateSection(
  suiteId: number,
  sectionId: number,
  body: { name?: string; precondition?: string | null; sort_order?: number },
): Promise<Section> {
  const res = await fetch(`${base}/api/suites/${suiteId}/sections/${sectionId}`, {
    method: 'PATCH',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{ data: Section }>(res);
  return data.data;
}

export async function createSuite(projectId: number, name: string): Promise<{ id: number }> {
  const res = await fetch(`${base}/api/projects/${projectId}/suites`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify({ name }),
  });
  const data = await parseJson<{ data: { id: number } }>(res);
  return data.data;
}

export async function updateSuite(
  projectId: number,
  suiteId: number,
  body: { name?: string },
): Promise<Suite> {
  const res = await fetch(`${base}/api/projects/${projectId}/suites/${suiteId}`, {
    method: 'PATCH',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{ data: Suite }>(res);
  return data.data;
}

export async function duplicateSuite(
  projectId: number,
  suiteId: number,
): Promise<{ id: number; name: string; cases_copied: number }> {
  const res = await fetch(`${base}/api/projects/${projectId}/suites/${suiteId}/duplicate`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: '{}',
  });
  const data = await parseJson<{ data: { id: number; name: string; cases_copied: number } }>(res);
  return data.data;
}

export async function fetchCases(suiteId: number): Promise<TestCase[]> {
  const res = await fetch(`${base}/api/suites/${suiteId}/cases`);
  const data = await parseJson<{ data: TestCase[] }>(res);
  return data.data;
}

/** Test execution: {@link RunSummary.run_kind} `run_book` is reserved for future custom run books. */
export type RunSummary = {
  id: number;
  project_id: number;
  suite_id: number | null;
  section_id: number | null;
  name: string;
  run_kind: string;
  state: string;
  created_at: string;
  suite_name: string | null;
  section_name: string | null;
  item_count: number;
  passed: number;
  failed: number;
  untested: number;
};

export type RunDetail = {
  id: number;
  project_id: number;
  suite_id: number | null;
  section_id: number | null;
  name: string;
  run_kind: string;
  state: string;
  created_at: string;
  suite_name: string | null;
  section_name: string | null;
};

export type RunItemSeverity = 'breaking' | 'ui_only' | 'unclear';

export type RunItemDetail = {
  id: number;
  run_id: number;
  case_id: number;
  section_id: number;
  section_name?: string;
  result: string;
  severity: RunItemSeverity;
  notes: string | null;
  /** Decoded from screenshots_json on the server. */
  screenshots: string[];
  video_url: string | null;
  executed_at: string | null;
  title: string;
  precondition: string | null;
  priority: string;
  status: string;
  steps: TestStep[];
};

export async function fetchProjectRuns(projectId: number): Promise<RunSummary[]> {
  const res = await fetch(`${base}/api/projects/${projectId}/runs`);
  const data = await parseJson<{ data: RunSummary[] }>(res);
  return data.data;
}

export async function createRunFromSuite(
  suiteId: number,
  name?: string,
): Promise<{
  id: number;
  project_id: number;
  suite_id: number;
  section_id: number | null;
  section_name: string | null;
  name: string;
  run_kind: string;
  state: string;
  case_count: number;
}> {
  const res = await fetch(`${base}/api/suites/${suiteId}/runs`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify(name ? { name } : {}),
  });
  const data = await parseJson<{
    data: {
      id: number;
      project_id: number;
      suite_id: number;
      section_id?: number | null;
      section_name?: string | null;
      name: string;
      run_kind: string;
      state: string;
      case_count: number;
    };
  }>(res);
  const d = data.data;
  return {
    ...d,
    section_id: d.section_id ?? null,
    section_name: d.section_name ?? null,
  };
}

export async function createRunFromSection(
  sectionId: number,
  name?: string,
): Promise<{
  id: number;
  project_id: number;
  suite_id: number;
  section_id: number;
  section_name: string;
  name: string;
  run_kind: string;
  state: string;
  case_count: number;
}> {
  const res = await fetch(`${base}/api/sections/${sectionId}/runs`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify(name ? { name } : {}),
  });
  const data = await parseJson<{
    data: {
      id: number;
      project_id: number;
      suite_id: number;
      section_id: number;
      section_name: string;
      name: string;
      run_kind: string;
      state: string;
      case_count: number;
    };
  }>(res);
  return data.data;
}

export async function fetchRun(runId: number): Promise<{ run: RunDetail; items: RunItemDetail[] }> {
  const res = await fetch(`${base}/api/runs/${runId}`);
  const data = await parseJson<{ data: { run: RunDetail; items: RunItemDetail[] } }>(res);
  return data.data;
}

export async function updateRun(
  runId: number,
  body: { name?: string; state?: 'open' | 'locked' | 'archived' },
): Promise<RunDetail> {
  const res = await fetch(`${base}/api/runs/${runId}`, {
    method: 'PATCH',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{ data: RunDetail }>(res);
  return data.data;
}

export async function updateRunItem(
  runId: number,
  itemId: number,
  body: {
    result: string;
    notes?: string | null;
    severity?: RunItemSeverity;
    screenshots?: string[] | null;
    video_url?: string | null;
  },
): Promise<{
  id: number;
  run_id: number;
  result: string;
  severity: RunItemSeverity;
  notes: string | null;
  screenshots: string[];
  video_url: string | null;
  executed_at: string | null;
}> {
  const res = await fetch(`${base}/api/runs/${runId}/items/${itemId}`, {
    method: 'PATCH',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{
    data: {
      id: number;
      run_id: number;
      result: string;
      severity: RunItemSeverity;
      notes: string | null;
      screenshots: string[];
      video_url: string | null;
      executed_at: string | null;
    };
  }>(res);
  return data.data;
}

export async function createCase(
  suiteId: number,
  payload: {
    title: string;
    section_id?: number;
    section_name?: string;
    steps: TestStep[];
    precondition?: string | null;
    priority?: string;
    status?: string;
  },
): Promise<{ id: number }> {
  const res = await fetch(`${base}/api/suites/${suiteId}/cases`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify(payload),
  });
  const data = await parseJson<{ data: { id: number } }>(res);
  return data.data;
}

export async function updateCase(
  suiteId: number,
  caseId: number,
  payload: {
    title: string;
    section_id?: number;
    precondition?: string | null;
    steps: TestStep[];
    priority?: string;
    status?: string;
  },
): Promise<{ id: number }> {
  const res = await fetch(`${base}/api/suites/${suiteId}/cases/${caseId}`, {
    method: 'PATCH',
    headers: jsonWriteHeaders,
    body: JSON.stringify(payload),
  });
  const data = await parseJson<{ data: { id: number } }>(res);
  return data.data;
}

export type BulkCaseStatusResult = {
  updated: number;
  skipped_unchanged: number;
  unknown_case_ids: number[];
  suite_id: number;
  status: string;
};

export async function bulkSetCasesStatus(
  suiteId: number,
  body: { status: CaseWorkflowStatus; case_ids: number[] } | { status: CaseWorkflowStatus; section_id: number },
): Promise<BulkCaseStatusResult> {
  const res = await fetch(`${base}/api/suites/${suiteId}/cases/bulk-status`, {
    method: 'PATCH',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{ data: BulkCaseStatusResult }>(res);
  return data.data;
}

export async function duplicateCase(suiteId: number, caseId: number): Promise<{ id: number; title: string }> {
  const res = await fetch(`${base}/api/suites/${suiteId}/cases/${caseId}/duplicate`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: '{}',
  });
  const data = await parseJson<{ data: { id: number; title: string } }>(res);
  return data.data;
}

/** Move case to another suite in the same project. */
export async function moveCaseToSuite(
  fromSuiteId: number,
  caseId: number,
  targetSuiteId: number,
): Promise<{ id: number; suite_id: number }> {
  const res = await fetch(`${base}/api/suites/${fromSuiteId}/cases/${caseId}/move`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify({ target_suite_id: targetSuiteId }),
  });
  const data = await parseJson<{ data: { id: number; suite_id: number } }>(res);
  return data.data;
}

/**
 * Move one step to another case in the same suite (or reorder within the same case using target_case_id + insert_at).
 * insert_at: 0-based index in target step list before which to insert; omit to append.
 */
export async function moveStepBetweenCases(
  suiteId: number,
  fromCaseId: number,
  stepIndex: number,
  targetCaseId: number,
  insertAt?: number | null,
): Promise<{ from_case_id: number; target_case_id: number; inserted_at?: number; reordered?: boolean }> {
  const body: Record<string, unknown> = {
    step_index: stepIndex,
    target_case_id: targetCaseId,
  };
  if (insertAt !== undefined && insertAt !== null) {
    body.insert_at = insertAt;
  }
  const res = await fetch(`${base}/api/suites/${suiteId}/cases/${fromCaseId}/steps/move`, {
    method: 'POST',
    headers: jsonWriteHeaders,
    body: JSON.stringify(body),
  });
  const data = await parseJson<{
    data: { from_case_id: number; target_case_id: number; inserted_at?: number; reordered?: boolean };
  }>(res);
  return data.data;
}

export function casesExportUrl(
  suiteId: number,
  format: 'json' | 'csv' | 'xlsx',
  opts?: { scope?: 'suite' | 'project' },
): string {
  let url = `${base}/api/suites/${suiteId}/cases/export?format=${encodeURIComponent(format)}`;
  if (opts?.scope === 'project') {
    url += '&scope=project';
  }
  return url;
}

/** One file for the whole project (all suites), same format as workspace export. */
export function projectExportUrl(projectId: number, format: 'json' | 'csv' | 'xlsx'): string {
  const p = new URLSearchParams();
  p.set('format', format);
  return `${base}/api/projects/${projectId}/export?${p.toString()}`;
}

export function workspaceExportUrl(
  projectId: number,
  suiteIds: number[],
  format: 'json' | 'csv' | 'xlsx',
): string {
  const p = new URLSearchParams();
  p.set('project_id', String(projectId));
  if (suiteIds.length > 0) {
    p.set('suite_ids', suiteIds.join(','));
  }
  p.set('format', format);
  return `${base}/api/workspace/export?${p.toString()}`;
}

export type WorkspaceImportOptions = {
  create_missing_entities: boolean;
  on_duplicate: 'skip' | 'error' | 'allow';
  target_suite_id?: number;
  /** When set, project-style CSV rows are applied under this project (suite names still from the file). */
  target_project_id?: number;
  csv_mode?: 'auto' | 'flat' | 'project';
  /** Map canonical field names to exact CSV header labels from the file. */
  column_map?: Record<string, string>;
};

export async function workspaceCsvPreview(file: File): Promise<{
  headers: string[];
  suggested_column_map: Record<string, string>;
  suggested_mode: 'project' | 'flat';
  sample_data_rows: string[][];
}> {
  const form = new FormData();
  form.append('file', file);
  const res = await fetch(`${base}/api/workspace/csv-preview`, {
    method: 'POST',
    body: form,
  });
  const text = await res.text();
  if (!res.ok) {
    let msg = res.statusText;
    try {
      const j = JSON.parse(text) as { error?: string };
      if (j.error) {
        msg = j.error;
      }
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  const data = JSON.parse(text) as {
    data: {
      headers: string[];
      suggested_column_map: Record<string, string>;
      suggested_mode: 'project' | 'flat';
      sample_data_rows: string[][];
    };
  };
  return data.data;
}

export async function workspaceImport(
  file: File,
  options: WorkspaceImportOptions,
): Promise<{
  imported: number;
  skipped_duplicates: number;
  suite_id?: number;
  suites_touched?: number[];
}> {
  const form = new FormData();
  form.append('file', file);
  const payload: Record<string, unknown> = {
    create_missing_entities: options.create_missing_entities,
    on_duplicate: options.on_duplicate,
  };
  if (options.target_suite_id != null && options.target_suite_id > 0) {
    payload.target_suite_id = options.target_suite_id;
  }
  if (options.target_project_id != null && options.target_project_id > 0) {
    payload.target_project_id = options.target_project_id;
  }
  if (options.csv_mode != null && options.csv_mode !== '') {
    payload.csv_mode = options.csv_mode;
  }
  if (options.column_map != null && Object.keys(options.column_map).length > 0) {
    payload.column_map = options.column_map;
  }
  form.append('options', JSON.stringify(payload));
  const res = await fetch(`${base}/api/workspace/import`, {
    method: 'POST',
    body: form,
  });
  const text = await res.text();
  if (res.status === 501) {
    let msg = 'Not implemented';
    try {
      const j = JSON.parse(text) as { error?: string };
      if (j.error) {
        msg = j.error;
      }
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  if (!res.ok) {
    let msg = res.statusText;
    try {
      const j = JSON.parse(text) as { error?: string };
      if (j.error) {
        msg = j.error;
      }
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  const data = JSON.parse(text) as {
    data: {
      imported: number;
      skipped_duplicates: number;
      suite_id?: number;
      suites_touched?: number[];
    };
  };
  return data.data;
}

export async function importCasesFile(suiteId: number, file: File): Promise<{ imported: number }> {
  const form = new FormData();
  form.append('file', file);
  const res = await fetch(`${base}/api/suites/${suiteId}/cases/import`, {
    method: 'POST',
    body: form,
  });
  const text = await res.text();
  if (res.status === 501) {
    let msg = 'Not implemented';
    try {
      const j = JSON.parse(text) as { error?: string };
      if (j.error) {
        msg = j.error;
      }
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  if (!res.ok) {
    let msg = res.statusText;
    try {
      const j = JSON.parse(text) as { error?: string };
      if (j.error) {
        msg = j.error;
      }
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  return (JSON.parse(text) as { data: { imported: number } }).data;
}
