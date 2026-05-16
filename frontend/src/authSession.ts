import { ref } from 'vue';
import {
  devPermissionsToProjectRoles,
  loadStoredDevPermissions,
  type DevPermissions,
} from '@/devPermissions';
import { clearUserPreferencesState, syncPreferencesFromAuthUser } from '@/userPreferences';
import type { UserPreferences } from '@/userPreferences';

const base = '';

export type AuthUser = {
  id: number;
  email: string;
  display_name: string;
  role: string;
  picture_url: string | null;
  preferences?: UserPreferences;
};

export type ProjectRole = 'member' | 'tester' | 'viewer';

export type DevPermissionsPayload = {
  role: string;
  projects: number[];
};

export type AuthSessionPayload = {
  auth_required: boolean;
  local_login_enabled: boolean;
  providers: { id: string; label: string }[];
  user: AuthUser | null;
  is_admin?: boolean;
  project_roles?: Record<number, ProjectRole>;
  /** Open runs delegated to the signed-in user (from session API). */
  has_assigned_open_runs?: boolean;
  dev_permissions?: DevPermissionsPayload | null;
  /** Instance has outbound email configured (MAIL_*); user opt-in still required. */
  email_notifications_available?: boolean;
};

/** True when the server has MAIL_* enabled; email preference toggles are shown only in this case. */
export const emailNotificationsAvailable = ref(false);

let cached: AuthSessionPayload | null = null;

/** JSON API error payloads (Slim `{ error }` or bootstrap `{ error, message }`). */
function messageFromKnownJsonEnvelope(payload: unknown): string | null {
  if (!payload || typeof payload !== 'object') {
    return null;
  }
  const o = payload as Record<string, unknown>;
  if (typeof o.error !== 'string' || !o.error.trim()) {
    return null;
  }
  const extra =
    typeof o.message === 'string' && o.message.trim() ? `: ${o.message.trim()}` : '';
  return `${o.error.trim()}${extra}`;
}

export async function loadAuthSession(force = false): Promise<AuthSessionPayload> {
  if (!force && cached) {
    return cached;
  }
  const devQs = devPermissionsQueryForSession();
  const res = await fetch(`${base}/api/auth/session${devQs}`, { credentials: 'include' });
  const text = await res.text();
  /** When Vite proxies /api to a host that wrongly serves SPA HTML (often after a redirect to /app/), JSON.parse blows up and Vue never mounts unless callers handle errors. */
  const trimmed = text.trim();

  let parsed: unknown;
  try {
    parsed = JSON.parse(trimmed);
  } catch {
    const trimmedLower = trimmed.toLowerCase();
    const looksHtml = trimmed.startsWith('<!') || trimmedLower.startsWith('<!doctype') || /^<\s*html\b/i.test(trimmed);
    const hint = looksHtml
      ? 'The server responded with HTML instead of JSON. Check VITE_API_PROXY_TARGET points at your PHP Slim app origin (often :8080 or php -S), not an Apache SPA-only vhost.'
      : 'Unexpected response from /api/auth/session.';
    throw new Error(hint);
  }

  const envelopeErr = messageFromKnownJsonEnvelope(parsed);

  /** Non-success: always surface `{ error }` / `{ message }` from JSON when present (e.g. bootstrap 503). */
  if (!res.ok) {
    throw new Error(
      envelopeErr ?? (res.statusText?.trim() || `Auth session failed (HTTP ${res.status})`),
    );
  }

  const j = parsed as { data?: AuthSessionPayload };
  const hasDataEnvelope =
    j.data !== undefined && typeof j.data === 'object' && j.data !== null;

  /** Success path: honour `data` even if unrelated keys exist (`error` alone must not regress a healthy 200). */
  if (!hasDataEnvelope) {
    if (envelopeErr) {
      throw new Error(envelopeErr);
    }
    throw new Error('/api/auth/session response missing data envelope');
  }
  cached = applyDevPermissionsToSession({
    ...j.data as AuthSessionPayload,
    local_login_enabled: j.data.local_login_enabled ?? false,
    is_admin: j.data.is_admin ?? false,
    project_roles: j.data.project_roles ?? {},
    has_assigned_open_runs: j.data.has_assigned_open_runs ?? false,
    dev_permissions: j.data.dev_permissions ?? null,
    email_notifications_available: j.data.email_notifications_available ?? false,
  });
  emailNotificationsAvailable.value = cached.email_notifications_available ?? false;
  if (cached.user) {
    syncPreferencesFromAuthUser(cached.user);
  } else {
    clearUserPreferencesState();
  }
  return cached;
}

export function clearAuthSessionCache(): void {
  cached = null;
  emailNotificationsAvailable.value = false;
  clearUserPreferencesState();
}

function devPermissionsQueryForSession(): string {
  const dev = loadStoredDevPermissions();
  if (!dev) {
    return '';
  }
  const params = new URLSearchParams();
  params.set('role', dev.role);
  if (dev.role !== 'admin' && dev.projects.length > 0) {
    params.set('projects', dev.projects.join(','));
  }
  const s = params.toString();
  return s === '' ? '' : `?${s}`;
}

function applyDevPermissionsToSession(data: AuthSessionPayload): AuthSessionPayload {
  if (data.auth_required) {
    return data;
  }
  const stored = loadStoredDevPermissions();
  if (!stored && !data.dev_permissions) {
    return data;
  }
  const dev: DevPermissions | null = stored
    ?? (data.dev_permissions
      ? {
          role: data.dev_permissions.role === 'admin' ? 'admin' : (data.dev_permissions.role as ProjectRole),
          projects: data.dev_permissions.projects ?? [],
        }
      : null);
  if (!dev) {
    return data;
  }
  if (dev.role === 'admin') {
    return { ...data, is_admin: true, project_roles: {}, dev_permissions: { role: 'admin', projects: [] } };
  }
  return {
    ...data,
    is_admin: false,
    project_roles: devPermissionsToProjectRoles(dev),
    dev_permissions: { role: dev.role, projects: dev.projects },
  };
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
  const user = body.data.user;
  syncPreferencesFromAuthUser(user);
  return user;
}
