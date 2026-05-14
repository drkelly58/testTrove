import type { InjectionKey } from 'vue';
import { reactive, ref } from 'vue';
import { fetchProjects, type Project } from '@/api';

/** Last chosen workspace project id; swap for server-backed user prefs once auth exists. */
export const DEFAULT_PROJECT_STORAGE_KEY = 'testtrove.defaultProjectId' as const;

export const projectId = ref<number | null>(null);
export const projects = ref<Project[]>([]);
export const projectsLoading = ref(true);
export const projectsError = ref<string | null>(null);

/** Injected from `App.vue`; refs unwrap when accessed on this reactive object. */
export type ProjectContextValue = {
  projectId: number | null;
  projects: Project[];
  setProjectId: (id: number | null) => void;
  loading: boolean;
  error: string | null;
  refreshProjects: () => Promise<void>;
};

export const PROJECT_CONTEXT_KEY: InjectionKey<ProjectContextValue> = Symbol('testtrove.project');

function readStoredProjectId(): number | null {
  try {
    const raw = localStorage.getItem(DEFAULT_PROJECT_STORAGE_KEY);
    if (raw == null || raw === '') {
      return null;
    }
    const n = parseInt(raw, 10);
    return Number.isFinite(n) ? n : null;
  } catch {
    return null;
  }
}

function persistProjectId(id: number | null) {
  try {
    if (id === null) {
      localStorage.removeItem(DEFAULT_PROJECT_STORAGE_KEY);
    } else {
      localStorage.setItem(DEFAULT_PROJECT_STORAGE_KEY, String(id));
    }
  } catch {
    /* ignore quota / private mode */
  }
}

/** User-driven selection: updates ref and localStorage. */
export function setProjectId(id: number | null) {
  projectId.value = id;
  persistProjectId(id);
}

/** Prefer current selection if still valid, else stored id if valid, else first project. */
function resolveProjectId(list: Project[], previous: number | null): number | null {
  if (!list.length) {
    return null;
  }
  if (previous !== null && list.some((p) => p.id === previous)) {
    return previous;
  }
  const stored = readStoredProjectId();
  if (stored !== null && list.some((p) => p.id === stored)) {
    return stored;
  }
  return list[0].id;
}

/** Load / reload projects from API and reconcile `projectId` with storage and list membership. */
export async function refreshProjects(): Promise<void> {
  projectsLoading.value = true;
  projectsError.value = null;
  const previous = projectId.value;
  try {
    const list = await fetchProjects();
    projects.value = list;
    const chosen = resolveProjectId(list, previous);
    projectId.value = chosen;
    persistProjectId(chosen);
  } catch (e) {
    projectsError.value = e instanceof Error ? e.message : 'Failed to load projects';
    projects.value = [];
    projectId.value = null;
    persistProjectId(null);
  } finally {
    projectsLoading.value = false;
  }
}

export function projectContextForProvide(): ProjectContextValue {
  return reactive({
    projectId,
    projects,
    setProjectId,
    loading: projectsLoading,
    error: projectsError,
    refreshProjects,
  }) as ProjectContextValue;
}
