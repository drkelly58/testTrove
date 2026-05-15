import type { FieldDef } from '@/components/EntityFormDialog.vue';
import type { ProjectRole } from '@/authSession';

export type GlobalUserRole = 'admin' | 'user';

/** Global account role (users.role). */
export const globalUserRoleSelectOptions: NonNullable<FieldDef['options']> = [
  {
    value: 'user',
    label: 'Standard user',
  },
  {
    value: 'admin',
    label: 'Global admin',
  },
];

export const globalUserRoleHelp: Record<GlobalUserRole, string> = {
  user: 'Access is granted per project via member, tester, or viewer membership.',
  admin: 'Full access to all projects, user management, and workspace import/export.',
};

/** Per-project permission (project_members.role). */
export const projectRoleSelectOptions: NonNullable<FieldDef['options']> = [
  {
    value: 'member',
    label: 'Member — catalog read/write, all runs',
  },
  {
    value: 'tester',
    label: 'Tester — catalog read, assigned runs only',
  },
  {
    value: 'viewer',
    label: 'Viewer — runs read-only, no catalog',
  },
];

const globalRoleLabels: Record<GlobalUserRole, string> = {
  admin: 'Global admin',
  user: 'Standard user',
};

const projectRoleLabels: Record<ProjectRole, string> = {
  member: 'Member',
  tester: 'Tester',
  viewer: 'Viewer',
};

export function globalRoleLabel(role: string): string {
  if (role === 'admin' || role === 'user') {
    return globalRoleLabels[role];
  }
  return role;
}

export function projectRoleLabel(role: string): string {
  if (role === 'member' || role === 'tester' || role === 'viewer') {
    return projectRoleLabels[role];
  }
  return role;
}
