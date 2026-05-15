import { ref } from 'vue';
import { clearAuthSessionCache, loadAuthSession, type AuthSessionPayload } from '@/authSession';
import { refreshProjects } from '@/projectContext';

/** Shared auth session for the shell (nav) and views that opt in. */
export const authSession = ref<AuthSessionPayload | null>(null);

export async function refreshAuthSession(): Promise<AuthSessionPayload | null> {
  try {
    const s = await loadAuthSession(true);
    authSession.value = s;
    if (!s.auth_required || s.user) {
      await refreshProjects();
    }
    return s;
  } catch {
    authSession.value = null;
    return null;
  }
}

export function clearAuthSessionState(): void {
  clearAuthSessionCache();
  authSession.value = null;
}
