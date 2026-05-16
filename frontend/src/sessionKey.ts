const STORAGE_KEY = 'testtrove.session_key';

/** PHP session id returned at login when cookies are blocked; sent as X-TestTrove-Session. */
export function storeSessionKey(key: string | null | undefined): void {
  try {
    if (key && key.trim() !== '') {
      sessionStorage.setItem(STORAGE_KEY, key.trim());
    } else {
      sessionStorage.removeItem(STORAGE_KEY);
    }
  } catch {
    /* private mode / disabled storage */
  }
}

export function loadSessionKey(): string | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    return raw && raw.trim() !== '' ? raw.trim() : null;
  } catch {
    return null;
  }
}

export function sessionKeyHeaders(extra?: Record<string, string>): Record<string, string> {
  const headers: Record<string, string> = { ...extra };
  const key = loadSessionKey();
  if (key) {
    headers['X-TestTrove-Session'] = key;
  }
  return headers;
}
