import type { ProjectRole } from '@/authSession';

const STORAGE_KEY = 'testtrove_dev_permissions';

export type DevPermissions = {
  role: 'admin' | ProjectRole;
  projects: number[];
};

export function parseDevPermissionsFromSearch(search: string): DevPermissions | null {
  const normalized = search.replace(/^\?/, '').replace(/;/g, '&');
  if (normalized.trim() === '') {
    return null;
  }
  const params = new URLSearchParams(normalized);
  const roleRaw = (params.get('role') ?? '').trim().toLowerCase();
  if (roleRaw === '' || ['off', 'open', 'full', 'none', 'all'].includes(roleRaw)) {
    return null;
  }
  if (roleRaw === 'admin') {
    return { role: 'admin', projects: [] };
  }
  if (roleRaw !== 'member' && roleRaw !== 'tester' && roleRaw !== 'viewer') {
    return null;
  }
  const projects = parseProjectIds(params.get('projects') ?? '');
  if (projects.length === 0) {
    return null;
  }
  return { role: roleRaw, projects };
}

function parseProjectIds(raw: string): number[] {
  const ids = new Set<number>();
  for (const part of raw.split(/[\s,;]+/)) {
    const t = part.trim();
    if (t === '' || !/^\d+$/.test(t)) {
      continue;
    }
    const id = parseInt(t, 10);
    if (id > 0) {
      ids.add(id);
    }
  }
  return [...ids].sort((a, b) => a - b);
}

export function loadStoredDevPermissions(): DevPermissions | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }
    const j = JSON.parse(raw) as DevPermissions;
    if (j.role === 'admin') {
      return { role: 'admin', projects: [] };
    }
    if (
      (j.role === 'member' || j.role === 'tester' || j.role === 'viewer') &&
      Array.isArray(j.projects) &&
      j.projects.every((n) => typeof n === 'number' && n > 0)
    ) {
      return { role: j.role, projects: j.projects };
    }
  } catch {
    /* ignore */
  }
  return null;
}

export function storeDevPermissions(dev: DevPermissions | null): void {
  if (dev === null) {
    sessionStorage.removeItem(STORAGE_KEY);
    return;
  }
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(dev));
}

/** Apply `?role=…&projects=…` from the page URL; returns the active dev profile (if any). */
export function bootstrapDevPermissionsFromUrl(): DevPermissions | null {
  const fromUrl = parseDevPermissionsFromSearch(window.location.search);
  if (fromUrl !== null) {
    storeDevPermissions(fromUrl);
    return fromUrl;
  }
  return loadStoredDevPermissions();
}

export function devPermissionsQueryString(dev: DevPermissions | null): string {
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

export function appendDevPermissionsToUrl(url: string, dev: DevPermissions | null): string {
  const qs = devPermissionsQueryString(dev);
  if (qs === '') {
    return url;
  }
  if (url.includes('?')) {
    return `${url}&${qs.slice(1)}`;
  }
  return url + qs;
}

export function devPermissionsToProjectRoles(dev: DevPermissions): Record<number, ProjectRole> {
  if (dev.role === 'admin') {
    return {};
  }
  const map: Record<number, ProjectRole> = {};
  for (const id of dev.projects) {
    map[id] = dev.role;
  }
  return map;
}

export function devPermissionsLabel(dev: DevPermissions): string {
  if (dev.role === 'admin') {
    return 'admin (all projects)';
  }
  return `${dev.role} · projects ${dev.projects.join(', ')}`;
}
