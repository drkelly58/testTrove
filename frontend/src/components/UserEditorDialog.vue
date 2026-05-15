<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { Project, UserAccount } from '@/api';
import {
  globalUserRoleHelp,
  globalUserRoleSelectOptions,
  projectRoleSelectOptions,
  type GlobalUserRole,
} from '@/roles';
import type { ProjectRole } from '@/authSession';

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    title: string;
    projects: Project[];
    user?: UserAccount | null;
    requirePassword?: boolean;
    submitLabel?: string;
    busy?: boolean;
    errorMessage?: string | null;
  }>(),
  {
    user: null,
    requirePassword: false,
    submitLabel: 'Save',
    busy: false,
    errorMessage: null,
  },
);

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
  (
    e: 'submit',
    payload: {
      email: string;
      display_name: string;
      password?: string;
      role: GlobalUserRole;
      project_memberships: { project_id: number; role: ProjectRole }[];
    },
  ): void;
  (e: 'cancel'): void;
}>();

const headingId = `user-editor-title-${useId()}`;

const email = ref('');
const displayName = ref('');
const password = ref('');
const globalRole = ref<GlobalUserRole>('user');
/** project id → role, or empty string when not a member */
const membershipRoles = ref<Record<number, string>>({});

const showProjectAccess = computed(() => globalRole.value === 'user');

const globalRoleHelp = computed(() => globalUserRoleHelp[globalRole.value]);

function initFromProps(): void {
  const u = props.user;
  email.value = u?.email ?? '';
  displayName.value = u?.display_name ?? '';
  password.value = '';
  globalRole.value = u?.role === 'admin' ? 'admin' : 'user';
  const next: Record<number, string> = {};
  for (const p of props.projects) {
    const m = u?.project_memberships?.find((x) => x.project_id === p.id);
    next[p.id] = m?.role ?? '';
  }
  membershipRoles.value = next;
}

watch(
  () => [props.modelValue, props.user] as const,
  ([open]) => {
    if (open) {
      initFromProps();
    }
  },
  { immediate: true },
);

function close(): void {
  emit('update:modelValue', false);
  emit('cancel');
}

function submit(): void {
  const memberships: { project_id: number; role: ProjectRole }[] = [];
  if (globalRole.value === 'user') {
    for (const [pidRaw, role] of Object.entries(membershipRoles.value)) {
      if (role !== 'member' && role !== 'tester' && role !== 'viewer') {
        continue;
      }
      memberships.push({ project_id: Number(pidRaw), role });
    }
  }
  const payload: {
    email: string;
    display_name: string;
    password?: string;
    role: GlobalUserRole;
    project_memberships: { project_id: number; role: ProjectRole }[];
  } = {
    email: email.value.trim(),
    display_name: displayName.value.trim(),
    role: globalRole.value,
    project_memberships: memberships,
  };
  const pw = password.value.trim();
  if (pw !== '') {
    payload.password = pw;
  } else if (props.requirePassword) {
    return;
  }
  emit('submit', payload);
}

</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="ue-backdrop" role="presentation" @click.self="close">
      <section class="ue-dialog" role="dialog" aria-modal="true" :aria-labelledby="headingId">
        <header class="ue-head">
          <h2 :id="headingId">{{ title }}</h2>
        </header>
        <div class="ue-body">
          <label class="ue-field">
            <span class="ue-lab">Email</span>
            <input v-model="email" class="ue-input" type="email" autocomplete="off" required />
          </label>
          <label class="ue-field">
            <span class="ue-lab">Display name</span>
            <input v-model="displayName" class="ue-input" type="text" required />
          </label>
          <label class="ue-field">
            <span class="ue-lab">{{ requirePassword ? 'Password' : 'New password' }}</span>
            <input
              v-model="password"
              class="ue-input"
              type="password"
              :required="requirePassword"
              :placeholder="requirePassword ? '' : 'Leave blank to keep current'"
              autocomplete="new-password"
            />
            <span v-if="!requirePassword" class="ue-hint">Only set when resetting the password.</span>
          </label>
          <label class="ue-field">
            <span class="ue-lab">Global role</span>
            <select v-model="globalRole" class="ue-input">
              <option v-for="opt in globalUserRoleSelectOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
            <span class="ue-hint">{{ globalRoleHelp }}</span>
          </label>

          <fieldset v-if="showProjectAccess" class="ue-projects">
            <legend class="ue-legend">Project access</legend>
            <p class="ue-hint">Per-project permissions for this account.</p>
            <p v-if="!projects.length" class="ue-empty">No projects yet. Create a project first.</p>
            <table v-else class="ue-proj-tbl">
              <thead>
                <tr>
                  <th>Project</th>
                  <th>Permission</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in projects" :key="p.id">
                  <td>{{ p.name }}</td>
                  <td>
                    <select v-model="membershipRoles[p.id]" class="ue-input ue-proj-select">
                      <option value="">No access</option>
                      <option
                        v-for="opt in projectRoleSelectOptions"
                        :key="opt.value"
                        :value="opt.value"
                      >
                        {{ opt.label }}
                      </option>
                    </select>
                  </td>
                </tr>
              </tbody>
            </table>
          </fieldset>
        </div>
        <p v-if="errorMessage" class="ue-err">{{ errorMessage }}</p>
        <footer class="ue-foot">
          <button type="button" class="btn" :disabled="busy" @click="close">Cancel</button>
          <button type="button" class="btn primary" :disabled="busy" @click="submit">{{ submitLabel }}</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.ue-backdrop {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  background: rgba(0, 0, 0, 0.55);
}

.ue-dialog {
  width: min(32rem, 100%);
  max-height: min(90vh, 720px);
  display: flex;
  flex-direction: column;
  background: var(--panel, #111c26);
  border: 1px solid var(--border, #243548);
  border-radius: 12px;
  color: var(--text, #f1f5f9);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

[data-theme='light'] .ue-dialog {
  color-scheme: light;
}

:root:not([data-theme='light']) .ue-dialog,
[data-theme='dark'] .ue-dialog {
  color-scheme: dark;
}

.ue-head {
  padding: 0.85rem 1.15rem 0.5rem;
  border-bottom: 1px solid var(--border, #243548);
}

.ue-head h2 {
  margin: 0;
  font-size: 1.1rem;
  font-family: var(--font-display, 'Outfit', sans-serif);
  color: var(--text, #f1f5f9);
}

.ue-body {
  padding: 0.85rem 1.15rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.ue-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.ue-lab {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted, #94a3b8);
}

.ue-input {
  font: inherit;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border, #243548);
  border-radius: 10px;
  background: var(--panel-2, #152535);
  color: var(--text, #f1f5f9);
}

.ue-input::placeholder {
  color: var(--muted, #64748b);
}

.ue-hint {
  font-size: 0.78rem;
  color: var(--muted, #94a3b8);
  line-height: 1.35;
}

.ue-projects {
  margin: 0;
  padding: 0.75rem 0 0;
  border: none;
}

.ue-legend {
  font-size: 0.85rem;
  font-weight: 600;
  padding: 0;
  color: var(--text, #f1f5f9);
}

.ue-proj-tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
  margin-top: 0.5rem;
  color: var(--text, #f1f5f9);
}

.ue-proj-tbl th,
.ue-proj-tbl td {
  text-align: left;
  padding: 0.4rem 0.45rem;
  border-bottom: 1px solid var(--border, #243548);
  vertical-align: middle;
}

.ue-proj-tbl th {
  color: var(--muted, #94a3b8);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.ue-proj-select {
  width: 100%;
  font-size: 0.8rem;
}

.ue-empty {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
  color: var(--muted, #94a3b8);
}

.ue-err {
  margin: 0 1.15rem;
  padding: 0.5rem 0.65rem;
  border-radius: 8px;
  border: 1px solid color-mix(in srgb, var(--danger, #f87171) 45%, var(--border, #243548));
  background: color-mix(in srgb, var(--danger, #f87171) 12%, var(--panel-2, #152535));
  color: var(--danger, #f87171);
  font-size: 0.85rem;
}

.ue-foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1.15rem;
  border-top: 1px solid var(--border, #243548);
  margin-top: 0.25rem;
  background: color-mix(in srgb, var(--panel-2, #152535) 70%, transparent);
  border-radius: 0 0 12px 12px;
}

.ue-foot .btn {
  border-radius: 8px;
  border: 1px solid var(--border, #243548);
  background: var(--panel-2, #152535);
  color: var(--text, #f1f5f9);
  padding: 0.5rem 0.85rem;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.ue-foot .btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.ue-foot .btn.primary {
  border-color: color-mix(in srgb, var(--accent, #7b61ff) 55%, var(--border, #243548));
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--accent, #7b61ff) 35%, var(--panel-2, #152535)),
    var(--panel-2, #152535)
  );
}

.ue-foot .btn.primary:hover:not(:disabled) {
  filter: brightness(1.05);
}
</style>
