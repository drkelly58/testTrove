<script setup lang="ts">
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import IconButton from '@/components/IconButton.vue';
import UserEditorDialog from '@/components/UserEditorDialog.vue';
import {
  createUser,
  deleteUser,
  fetchProjects,
  fetchUsers,
  updateUser,
  type Project,
  type UserAccount,
} from '@/api';
import { emailNotificationsAvailable, loadAuthSession, type AuthSessionPayload } from '@/authSession';
import { globalRoleLabel, projectRoleLabel } from '@/roles';
import type { GlobalUserRole } from '@/roles';
import type { ProjectRole } from '@/authSession';

const authSession = ref<AuthSessionPayload | null>(null);
void loadAuthSession().then((s) => {
  authSession.value = s;
});

const currentUserId = computed(() => authSession.value?.user?.id ?? null);

const projects = ref<Project[]>([]);
const users = ref<UserAccount[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

const createOpen = ref(false);
const createBusy = ref(false);
const createError = ref<string | null>(null);
const createNotice = ref<string | null>(null);

const editUser = ref<UserAccount | null>(null);
const editBusy = ref(false);
const editError = ref<string | null>(null);

const deleteTarget = ref<UserAccount | null>(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);

function formatCreatedAt(raw: string): string {
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) {
    return raw;
  }
  return d.toLocaleString();
}

function projectAccessSummary(u: UserAccount): string {
  if (u.role === 'admin') {
    return 'All projects';
  }
  const memberships = u.project_memberships ?? [];
  if (!memberships.length) {
    return 'No project access';
  }
  return memberships.map((m) => `${m.project_name} (${projectRoleLabel(m.role)})`).join(', ');
}

async function loadUsers() {
  loading.value = true;
  error.value = null;
  try {
    const [userRows, projectRows] = await Promise.all([fetchUsers(), fetchProjects()]);
    users.value = userRows;
    projects.value = projectRows;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load users';
    users.value = [];
  } finally {
    loading.value = false;
  }
}

void loadUsers();

function openCreate() {
  createError.value = null;
  createNotice.value = null;
  createOpen.value = true;
}

async function submitCreate(payload: {
  email: string;
  display_name: string;
  password?: string;
  role: GlobalUserRole;
  project_memberships: { project_id: number; role: ProjectRole }[];
  send_invite_email?: boolean;
  invite_intro?: string;
}) {
  createBusy.value = true;
  createError.value = null;
  createNotice.value = null;
  try {
    const result = await createUser({
      email: payload.email,
      display_name: payload.display_name,
      password: payload.password,
      send_invite_email: payload.send_invite_email,
      invite_intro: payload.invite_intro,
      role: payload.role,
      project_memberships: payload.project_memberships,
    });
    createOpen.value = false;
    if (result.invite_email_sent) {
      createNotice.value = `Invite email sent to ${result.user.email}.`;
    } else if (result.temporary_password) {
      const err = result.invite_email_error ? ` (${result.invite_email_error})` : '';
      createNotice.value = `User created. Temporary password: ${result.temporary_password}${err}`;
    } else {
      createNotice.value = `User ${result.user.display_name} created.`;
    }
    await loadUsers();
  } catch (e) {
    createError.value = e instanceof Error ? e.message : 'Could not create user';
  } finally {
    createBusy.value = false;
  }
}

function openEdit(u: UserAccount) {
  editError.value = null;
  editUser.value = u;
}

function closeEdit() {
  editUser.value = null;
  editError.value = null;
}

async function submitEdit(payload: {
  email: string;
  display_name: string;
  password?: string;
  role: GlobalUserRole;
  project_memberships: { project_id: number; role: ProjectRole }[];
}) {
  const u = editUser.value;
  if (!u) {
    return;
  }
  editBusy.value = true;
  editError.value = null;
  try {
    const body: Parameters<typeof updateUser>[1] = {
      email: payload.email,
      display_name: payload.display_name,
      role: payload.role,
      project_memberships: payload.project_memberships,
    };
    if (payload.password) {
      body.password = payload.password;
    }
    await updateUser(u.id, body);
    closeEdit();
    await loadUsers();
  } catch (e) {
    editError.value = e instanceof Error ? e.message : 'Could not update user';
  } finally {
    editBusy.value = false;
  }
}

function askDelete(u: UserAccount) {
  deleteTarget.value = u;
  deleteOpen.value = true;
}

function cancelDelete() {
  deleteOpen.value = false;
  deleteTarget.value = null;
}

async function confirmDelete() {
  const u = deleteTarget.value;
  if (!u || deleteBusy.value || deleteBlocked.value) {
    return;
  }
  deleteBusy.value = true;
  error.value = null;
  try {
    await deleteUser(u.id);
    cancelDelete();
    await loadUsers();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not delete user';
    cancelDelete();
  } finally {
    deleteBusy.value = false;
  }
}

const deleteTitle = computed(() => {
  const u = deleteTarget.value;
  return u ? `Delete user "${u.display_name}"?` : 'Delete user?';
});

const deleteMessage = computed(() => {
  const u = deleteTarget.value;
  if (!u) {
    return '';
  }
  if (currentUserId.value === u.id) {
    return 'You cannot delete your own account while signed in.';
  }
  return `Permanently remove ${u.email} from TestTrove. Project memberships and run assignments for this account may be affected.`;
});

const deleteBlocked = computed(() => deleteTarget.value !== null && currentUserId.value === deleteTarget.value.id);
</script>

<template>
  <div class="users-admin">
    <header class="head">
      <h1>Users</h1>
      <p class="sub">
        Manage global accounts and per-project permissions (member, tester, viewer). Global admins have full access
        without project membership.
      </p>
      <div class="head-actions">
        <IconButton accent label="Add user" title="Create user account" @click="openCreate">
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
        </IconButton>
      </div>
    </header>

    <div v-if="createNotice" class="state notice">{{ createNotice }}</div>

    <div v-if="loading" class="state">Loading users…</div>
    <div v-else-if="error" class="state err">{{ error }}</div>

    <template v-else>
      <p v-if="!users.length" class="empty">No users yet. Create the first account.</p>

      <table v-else class="tbl">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Global role</th>
            <th>Project access</th>
            <th>Created</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td>
              <span class="user-name">{{ u.display_name }}</span>
              <span v-if="currentUserId === u.id" class="you-badge">You</span>
            </td>
            <td>{{ u.email }}</td>
            <td><span class="role-pill">{{ globalRoleLabel(u.role) }}</span></td>
            <td class="access-cell">{{ projectAccessSummary(u) }}</td>
            <td class="created">{{ formatCreatedAt(u.created_at) }}</td>
            <td class="col-actions">
              <div class="row-actions">
                <IconButton label="Edit user" title="Edit user" @click="openEdit(u)">
                  <svg viewBox="0 0 24 24">
                    <path d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z" fill="none" />
                  </svg>
                </IconButton>
                <IconButton
                  danger
                  label="Delete user"
                  title="Delete user account"
                  :disabled="currentUserId === u.id"
                  @click="askDelete(u)"
                >
                  <svg viewBox="0 0 24 24">
                    <path
                      d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h12zM10 11v6M14 11v6"
                      fill="none"
                    />
                  </svg>
                </IconButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </template>

    <UserEditorDialog
      v-model="createOpen"
      title="New user"
      :projects="projects"
      :invite-by-email="emailNotificationsAvailable"
      submit-label="Create user"
      :busy="createBusy"
      :error-message="createError"
      @submit="submitCreate"
      @cancel="createError = null"
    />

    <UserEditorDialog
      v-if="editUser"
      :model-value="true"
      title="Edit user"
      :projects="projects"
      :user="editUser"
      :busy="editBusy"
      :error-message="editError"
      @update:model-value="(open) => !open && closeEdit()"
      @submit="submitEdit"
    />

    <ConfirmDialog
      v-model="deleteOpen"
      :title="deleteTitle"
      :message="deleteMessage"
      :busy="deleteBusy"
      confirm-label="Delete user"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </div>
</template>

<style scoped>
.users-admin {
  max-width: 1100px;
}

.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.75rem 1.5rem;
  margin-bottom: 1.25rem;
}

.head h1 {
  margin: 0;
  font-size: 1.35rem;
  font-family: var(--font-display, 'Outfit', sans-serif);
  flex: 1 1 100%;
}

.sub {
  margin: 0;
  color: var(--muted);
  font-size: 0.9rem;
  line-height: 1.45;
  flex: 1 1 16rem;
}

.head-actions {
  flex-shrink: 0;
}

.state {
  padding: 1rem 0;
}

.state.err {
  color: var(--danger);
}

.state.notice {
  color: var(--text);
  padding: 0.65rem 0.85rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: color-mix(in srgb, var(--accent-2, #38bdf8) 10%, var(--panel-2));
  font-size: 0.88rem;
  line-height: 1.45;
}

.empty {
  color: var(--muted);
  font-size: 0.9rem;
}

.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.tbl th,
.tbl td {
  text-align: left;
  padding: 0.55rem 0.65rem;
  border-bottom: 1px solid var(--border);
  vertical-align: top;
}

.tbl th {
  color: var(--muted);
  font-weight: 600;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.user-name {
  font-weight: 600;
}

.you-badge {
  display: inline-block;
  margin-left: 0.4rem;
  font-size: 0.68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--accent-2);
}

.role-pill {
  font-size: 0.72rem;
  padding: 0.15rem 0.45rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  color: var(--muted);
  white-space: nowrap;
}

.access-cell {
  color: var(--muted);
  font-size: 0.82rem;
  line-height: 1.4;
  max-width: 22rem;
}

.created {
  color: var(--muted);
  font-size: 0.82rem;
  white-space: nowrap;
}

.col-actions {
  white-space: nowrap;
  width: 1%;
}

.row-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.35rem;
}
</style>
