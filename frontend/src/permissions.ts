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

export function projectRoleFor(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): ProjectRole | null {
  if (projectId == null || !session?.auth_required) {
    return 'member';
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

export function canViewRuns(
  session: AuthSessionPayload | null | undefined,
  projectId: number | null | undefined,
): boolean {
  return projectRoleFor(session, projectId) !== null;
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

export function canManageWorkspace(session: AuthSessionPayload | null | undefined): boolean {
  if (!session?.auth_required) {
    return true;
  }
  return isGlobalAdmin(session);
}

export function useProjectPermissions(
  session: Ref<AuthSessionPayload | null | undefined>,
  projectId: Ref<number | null | undefined>,
) {
  const role = computed(() => projectRoleFor(unref(session), unref(projectId)));
  const canWrite = computed(() => canWriteCatalog(unref(session), unref(projectId)));
  const canRun = computed(() => canExecuteRuns(unref(session), unref(projectId)));
  const canViewRunsOnly = computed(() => role.value === 'viewer');
  const canManageMembers = computed(() => canManageProjectMembers(unref(session), unref(projectId)));

  return { role, canWrite, canRun, canViewRunsOnly, canManageMembers };
}
