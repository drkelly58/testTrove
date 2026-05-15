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
  cached = j.data;
  return cached;
}

export function clearAuthSessionCache(): void {
  cached = null;
}
