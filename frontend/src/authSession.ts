const base = '';

export type AuthUser = {
  id: number;
  email: string;
  display_name: string;
  role: string;
  picture_url: string | null;
};

export type AuthSessionPayload = {
  auth_required: boolean;
  local_login_enabled: boolean;
  providers: { id: string; label: string }[];
  user: AuthUser | null;
};

let cached: AuthSessionPayload | null = null;

export async function loadAuthSession(force = false): Promise<AuthSessionPayload> {
  if (!force && cached) {
    return cached;
  }
  const res = await fetch(`${base}/api/auth/session`, { credentials: 'include' });
  const text = await res.text();
  if (!res.ok) {
    throw new Error(res.statusText || 'auth session failed');
  }
  const j = JSON.parse(text) as { data: AuthSessionPayload };
  cached = {
    ...j.data,
    local_login_enabled: j.data.local_login_enabled ?? false,
  };
  return cached;
}

export function clearAuthSessionCache(): void {
  cached = null;
}

export async function loginWithPassword(email: string, password: string): Promise<AuthUser> {
  const res = await fetch(`${base}/api/auth/login/local`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({ email, password }),
  });
  const text = await res.text();
  let body: { error?: string; data?: { user: AuthUser } };
  try {
    body = JSON.parse(text) as typeof body;
  } catch {
    throw new Error(res.statusText || 'Sign-in failed');
  }
  if (!res.ok) {
    throw new Error(body.error || res.statusText || 'Sign-in failed');
  }
  if (!body.data?.user) {
    throw new Error('Sign-in response missing user');
  }
  clearAuthSessionCache();
  return body.data.user;
}
