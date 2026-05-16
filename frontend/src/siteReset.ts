import { clearAuthSessionState } from '@/authContext';
import { clearAuthSessionCache } from '@/authSession';
import { storeSessionKey } from '@/sessionKey';

/** Clears TestTrove client storage (not HTTP cookies — use the browser for those). */
export function resetTestTroveClientData(): void {
  clearAuthSessionCache();
  clearAuthSessionState();
  storeSessionKey(null);
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i);
      if (key?.startsWith('testtrove.')) {
        localStorage.removeItem(key);
      }
    }
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const key = sessionStorage.key(i);
      if (key?.startsWith('testtrove')) {
        sessionStorage.removeItem(key);
      }
    }
  } catch {
    /* private mode / disabled storage */
  }
}
