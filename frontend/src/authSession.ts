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
  dev_permissions?: DevPermissionsPayload | null;
};

let cached: AuthSessionPayload | null = null;

export async function loadAuthSession(force = false): Promise<AuthSessionPayload> {
  if (!force && cached) {
    return cached;
  }
  const devQs = devPermissionsQueryForSession();
  const res = await fetch(`${base}/api/auth/session${devQs}`, { credentials: 'include' });
  const text = await res.text();
  if (!res.ok) {
    throw new Error(res.statusText || 'auth session failed');
  }
  const j = JSON.parse(text) as { data: AuthSessionPayload };
  cached = applyDevPermissionsToSession({
    ...j.data,
    local_login_enabled: j.data.local_login_enabled ?? false,
    is_admin: j.data.is_admin ?? false,
    project_roles: j.data.project_roles ?? {},
    dev_permissions: j.data.dev_permissions ?? null,
  });
  if (cached.user) {
    syncPreferencesFromAuthUser(cached.user);
  } else {
    clearUserPreferencesState();
  }
  return cached;
}

export function clearAuthSessionCache(): void {
  cached = null;
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
