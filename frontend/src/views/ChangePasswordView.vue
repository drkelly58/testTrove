<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { authSession, refreshAuthSession } from '@/authContext';
import { changePassword } from '@/authSession';
import { defaultLandingPath } from '@/permissions';

const router = useRouter();
const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');
const busy = ref(false);
const formError = ref<string | null>(null);

async function submit(ev: Event) {
  ev.preventDefault();
  formError.value = null;
  if (newPassword.value !== confirmPassword.value) {
    formError.value = 'New passwords do not match';
    return;
  }
  busy.value = true;
  try {
    await changePassword(currentPassword.value, newPassword.value);
    const s = await refreshAuthSession();
    if (s?.user?.must_change_password) {
      formError.value = 'Password was not updated. Please try again.';
      return;
    }
    await router.push(defaultLandingPath(s));
  } catch (e) {
    formError.value = e instanceof Error ? e.message : 'Could not change password';
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="change-password-page">
    <header class="head">
      <h1>Set a new password</h1>
      <p class="lede">
        Your account uses a temporary password. Choose a new password before continuing
        <template v-if="authSession?.user?.display_name">, {{ authSession.user.display_name }}</template>.
      </p>
    </header>

    <div v-if="formError" class="banner err">{{ formError }}</div>

    <form class="card" @submit="submit">
      <label class="field">
        <span class="lab">Current password</span>
        <input
          v-model="currentPassword"
          type="password"
          class="input"
          autocomplete="current-password"
          required
        />
      </label>
      <label class="field">
        <span class="lab">New password</span>
        <input
          v-model="newPassword"
          type="password"
          class="input"
          autocomplete="new-password"
          minlength="8"
          required
        />
        <span class="hint">At least 8 characters.</span>
      </label>
      <label class="field">
        <span class="lab">Confirm new password</span>
        <input
          v-model="confirmPassword"
          type="password"
          class="input"
          autocomplete="new-password"
          minlength="8"
          required
        />
      </label>
      <button type="submit" class="btn primary block" :disabled="busy">
        {{ busy ? 'Saving…' : 'Save and continue' }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.change-password-page {
  max-width: 420px;
  margin: 2rem auto;
}

.head h1 {
  margin: 0 0 0.35rem;
  font-family: var(--font-display, system-ui);
  font-size: 1.65rem;
}

.lede {
  margin: 0;
  color: var(--muted, #64748b);
  font-size: 0.95rem;
  line-height: 1.45;
}

.banner {
  margin: 1rem 0;
  padding: 0.65rem 0.85rem;
  border-radius: var(--radius, 12px);
  font-size: 0.9rem;
}

.banner.err {
  background: color-mix(in srgb, var(--danger, #f87171) 12%, var(--panel, #111c26));
  border: 1px solid color-mix(in srgb, var(--danger) 35%, var(--border));
  color: var(--text);
}

.card {
  padding: 1.25rem;
  border-radius: var(--radius, 12px);
  border: 1px solid var(--border);
  background: var(--panel, #111c26);
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.lab {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
}

.input {
  font: inherit;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--panel-2);
  color: var(--text);
}

.hint {
  font-size: 0.78rem;
  color: var(--muted);
}

.btn {
  font: inherit;
  font-weight: 600;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  cursor: pointer;
}

.btn.primary {
  border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--accent) 35%, var(--panel-2)),
    var(--panel-2)
  );
}

.btn.block {
  width: 100%;
  margin-top: 0.25rem;
}

.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
</style>
