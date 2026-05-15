import type { InjectionKey } from 'vue';
import { reactive, ref } from 'vue';
import { fetchProjects, type Project } from '@/api';
import { getDefaultProjectIdPreference, setDefaultProjectIdPreference } from '@/userPreferences';

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
  const pref = getDefaultProjectIdPreference();
  if (pref === undefined) {
    return null;
  }
  return pref;
}

/** User-driven selection: updates ref and persisted preferences (user record or localStorage). */
export function setProjectId(id: number | null) {
  projectId.value = id;
  void setDefaultProjectIdPreference(id);
}

/** Prefer current selection if still valid, else stored preference if valid, else first project. */
function resolveProjectId(list: Project[], previous: number | null): number | null {
  if (!list.length) {
    return null;
  }
  if (previous !== null && list.some((p) => p.id === previous)) {
    return previous;
  }
  const stored = readStoredProjectId();
  if (stored !== null && stored !== undefined && list.some((p) => p.id === stored)) {
    return stored;
  }
  return list[0].id;
}

/** Load / reload projects from API and reconcile `projectId` with preferences and list membership. */
export async function refreshProjects(): Promise<void> {
  projectsLoading.value = true;
  projectsError.value = null;
  const previous = projectId.value;
  try {
    const list = await fetchProjects();
    projects.value = list;
    const chosen = resolveProjectId(list, previous);
    projectId.value = chosen;
    void setDefaultProjectIdPreference(chosen);
  } catch (e) {
    projectsError.value = e instanceof Error ? e.message : 'Failed to load projects';
    projects.value = [];
    projectId.value = null;
    void setDefaultProjectIdPreference(null);
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
