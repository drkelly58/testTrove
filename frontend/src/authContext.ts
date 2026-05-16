import { ref } from 'vue';
import {
  clearAuthSessionCache,
  loadAuthSession,
  peekAuthSessionCache,
  type AuthSessionPayload,
} from '@/authSession';
import { storeSessionKey } from '@/sessionKey';
import {
  projectId,
  projects,
  projectsError,
  projectsLoading,
  refreshProjects,
} from '@/projectContext';
import { setDefaultProjectIdPreference } from '@/userPreferences';

/** Shared auth session for the shell (nav) and views that opt in. */
export const authSession = ref<AuthSessionPayload | null>(null);

/** When we are not calling `refreshProjects`, keep the shell off the indefinite “Loading…” state (`projectsLoading` defaults to true in projectContext.ts). */
function idleProjectsWorkspace(): void {
  projectsLoading.value = false;
  projects.value = [];
  projectId.value = null;
  projectsError.value = null;
  void setDefaultProjectIdPreference(null);
}

export async function refreshAuthSession(): Promise<AuthSessionPayload | null> {
  const signedInBefore = authSession.value?.user ?? peekAuthSessionCache()?.user ?? null;
  try {
    const s = await loadAuthSession(true);
    authSession.value = s;
    if (!s.auth_required || s.user) {
      await refreshProjects();
    } else {
      idleProjectsWorkspace();
    }
    return s;
  } catch {
    if (signedInBefore) {
      authSession.value = peekAuthSessionCache();
      return authSession.value;
    }
    authSession.value = null;
    idleProjectsWorkspace();
    return null;
  }
}

export function clearAuthSessionState(): void {
  clearAuthSessionCache();
  storeSessionKey(null);
  authSession.value = null;
  idleProjectsWorkspace();
}
