import type { Ref } from 'vue';
import { computed, unref } from 'vue';
import type { AuthSessionPayload, ProjectRole } from '@/authSession';

export type { ProjectRole };

export function isGlobalAdmin(session: AuthSessionPayload | null | undefined): boolean {
  if (!session?.auth_required) {
    return true;
  }
  return session.is_admin === true || session.user?.role === 'admin';
}

function isDevSimulation(session: AuthSessionPayload | null | undefined): boolean {
  return Boolean(session && !session.auth_required && session.dev_permissions);
}

export function projectRoleFor(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): ProjectRole | null {
  if (!session?.auth_required) {
    if (!isDevSimulation(session)) {
      return projectId == null ? 'member' : 'member';
    }
    if (projectId == null) {
      return null;
    }
    if (isGlobalAdmin(session)) {
      return 'member';
    }
    const role = session.project_roles?.[projectId];
    if (role === 'member' || role === 'tester' || role === 'viewer') {
      return role;
    }
    return null;
  }
  if (projectId == null) {
    return null;
  }
  if (isGlobalAdmin(session)) {
    return 'member';
  }
  const role = session.project_roles?.[projectId];
  if (role === 'member' || role === 'tester' || role === 'viewer') {
    return role;
  }
  return null;
}

/** Suites, sections, cases (read). */
export function canReadCatalog(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  const role = projectRoleFor(session, projectId);
  return role === 'member' || role === 'tester';
}

export function canWriteCatalog(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  const role = projectRoleFor(session, projectId);
  return role === 'member';
}

export function canExecuteRuns(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  const role = projectRoleFor(session, projectId);
  return role === 'member' || role === 'tester';
}

/** Runs hub / run detail (read). */
export function canViewRuns(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  return projectRoleFor(session, projectId) !== null;
}

export function canManageRuns(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  return canWriteCatalog(session, projectId);
}

/** Delegate runs to testers (members and global admins). */
export function canAssignRuns(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  return canManageRuns(session, projectId);
}

export function canManageProjectMembers(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  if (!session?.auth_required) {
    return true;
  }
  if (isGlobalAdmin(session)) {
    return true;
  }
  return projectRoleFor(session, projectId) === 'member';
}

export function canManageUsers(session: AuthSessionPayload | null | undefined): boolean {
  return isGlobalAdmin(session);
}

export function canManageWorkspace(session: AuthSessionPayload | null | undefined): boolean {
  return isGlobalAdmin(session);
}

export function canCreateProject(session: AuthSessionPayload | null | undefined): boolean {
  if (!session?.auth_required) {
    if (!isDevSimulation(session)) {
      return true;
    }
    if (isGlobalAdmin(session)) {
      return true;
    }
    return Object.values(session.project_roles ?? {}).includes('member');
  }
  if (isGlobalAdmin(session)) {
    return true;
  }
  const roles = session.project_roles ?? {};
  const values = Object.values(roles);
  if (values.length === 0) {
    return true;
  }
  return values.includes('member');
}

export function isViewerOnlyOnAllProjects(session: AuthSessionPayload | null | undefined): boolean {
  if (session?.auth_required) {
    if (isGlobalAdmin(session)) {
      return false;
    }
    const roles = Object.values(session.project_roles ?? {});
    if (roles.length === 0) {
      return false;
    }
    return roles.every((r) => r === 'viewer');
  }
  if (!isDevSimulation(session)) {
    return false;
  }
  const roles = Object.values(session.project_roles ?? {});
  if (roles.length === 0) {
    return false;
  }
  return roles.every((r) => r === 'viewer');
}

export function useProjectPermissions(
  session: Ref<AuthSessionPayload | null | undefined>,
  projectId: Ref<number | null | undefined>,
) {
  const role = computed(() => projectRoleFor(unref(session), unref(projectId)));
  const canRead = computed(() => canReadCatalog(unref(session), unref(projectId)));
  const canWrite = computed(() => canWriteCatalog(unref(session), unref(projectId)));
  const canRun = computed(() => canExecuteRuns(unref(session), unref(projectId)));
  const canViewRunsOnly = computed(() => role.value === 'viewer');
  const canManageMembers = computed(() => canManageProjectMembers(unref(session), unref(projectId)));

  return { role, canRead, canWrite, canRun, canViewRunsOnly, canManageMembers };
}
