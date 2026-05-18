<script setup lang="ts">
import { ref, watch } from 'vue';
import {
  fetchAdminSettings,
  patchAdminSettings,
  sendAdminTestMail,
  type AdminSettingsForm,
  type AdminSettingsSnapshot,
} from '@/api';
import { refreshAuthSession } from '@/authContext';

const props = defineProps<{
  modelValue: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
}>();

const loading = ref(false);
const saving = ref(false);
const testBusy = ref(false);
const error = ref<string | null>(null);
const saveNotice = ref<string | null>(null);
const testNotice = ref<string | null>(null);
const testError = ref<string | null>(null);
const snapshot = ref<AdminSettingsSnapshot | null>(null);
const form = ref<AdminSettingsForm | null>(null);
const testRecipient = ref('');

function close() {
  emit('update:modelValue', false);
}

function applyForm(data: AdminSettingsSnapshot) {
  snapshot.value = data;
  form.value = { ...data.form };
  testRecipient.value = '';
}

async function load() {
  loading.value = true;
  error.value = null;
  saveNotice.value = null;
  testNotice.value = null;
  testError.value = null;
  try {
    applyForm(await fetchAdminSettings());
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not load settings';
    snapshot.value = null;
    form.value = null;
  } finally {
    loading.value = false;
  }
}

async function save() {
  if (!form.value || saving.value) {
    return;
  }
  saving.value = true;
  error.value = null;
  saveNotice.value = null;
  try {
    applyForm(await patchAdminSettings(form.value));
    saveNotice.value = 'Settings saved.';
    await refreshAuthSession();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not save settings';
  } finally {
    saving.value = false;
  }
}

async function sendTest() {
  const to = testRecipient.value.trim();
  if (!to || testBusy.value) {
    return;
  }
  testBusy.value = true;
  testNotice.value = null;
  testError.value = null;
  try {
    await sendAdminTestMail(to);
    testNotice.value = `Test email sent to ${to}.`;
  } catch (e) {
    testError.value = e instanceof Error ? e.message : 'Could not send test email';
  } finally {
    testBusy.value = false;
  }
}

function sourceHint(key: string): string | null {
  const src = snapshot.value?.sources[key];
  if (!src || src === 'default') {
    return null;
  }
  return src === 'database' ? 'Saved in TestTrove' : 'From server environment';
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      void load();
    }
  },
);
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="settings-dialog-backdrop" role="presentation" @click.self="close">
      <section
        class="settings-dialog settings-dialog-wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="system-settings-title"
      >
        <header class="settings-dialog-head">
          <h2 id="system-settings-title">System settings</h2>
          <button type="button" class="settings-dialog-close" aria-label="Close" @click="close">×</button>
        </header>

        <div class="settings-dialog-body">
          <p v-if="loading" class="sys-muted">Loading…</p>
          <p v-else-if="error && !form" class="sys-err">{{ error }}</p>

          <template v-else-if="form && snapshot">
            <p v-if="error" class="sys-err">{{ error }}</p>
            <p v-if="saveNotice" class="sys-ok">{{ saveNotice }}</p>

            <fieldset class="sys-fieldset">
              <legend class="sys-legend">General</legend>
              <p class="sys-help">
                Public URL for OAuth redirects and links in email. Database and environment labels are read-only.
              </p>
              <label class="sys-field">
                <span class="sys-lab">Public app URL</span>
                <input v-model="form.app_base_url" type="url" class="sys-input" placeholder="https://app.example.com" />
                <span v-if="sourceHint('app_base_url')" class="sys-source">{{ sourceHint('app_base_url') }}</span>
              </label>
              <dl class="sys-readonly">
                <div>
                  <dt>Environment</dt>
                  <dd>{{ snapshot.general.app_env }}</dd>
                </div>
                <div>
                  <dt>Database</dt>
                  <dd>{{ snapshot.general.db_driver }}</dd>
                </div>
              </dl>
            </fieldset>

            <fieldset class="sys-fieldset">
              <legend class="sys-legend">Authentication</legend>
              <p class="sys-help">Sign-in methods are configured in the server environment (OAuth secrets are not shown here).</p>
              <dl class="sys-readonly">
                <div>
                  <dt>Auth required</dt>
                  <dd>{{ snapshot.auth.auth_required ? 'Yes' : 'No' }}</dd>
                </div>
                <div>
                  <dt>Local password login</dt>
                  <dd>{{ snapshot.auth.local_login_enabled ? 'Enabled' : 'Disabled' }}</dd>
                </div>
                <div>
                  <dt>OAuth providers</dt>
                  <dd>
                    <span v-if="!snapshot.auth.providers.length" class="sys-muted">None configured</span>
                    <ul v-else class="sys-provider-list">
                      <li v-for="p in snapshot.auth.providers" :key="p.id">{{ p.label }}</li>
                    </ul>
                  </dd>
                </div>
              </dl>
            </fieldset>

            <fieldset class="sys-fieldset">
              <legend class="sys-legend">Email</legend>
              <p class="sys-help">
                When enabled, users can opt in to run notifications in Preferences. SMTP password stays in
                <code>.env</code> only.
              </p>
              <label class="sys-check">
                <input v-model="form.mail_enabled" type="checkbox" />
                <span>Enable outbound mail</span>
              </label>
              <label class="sys-field">
                <span class="sys-lab">From address</span>
                <input v-model="form.mail_from_address" type="email" class="sys-input" autocomplete="off" />
              </label>
              <label class="sys-field">
                <span class="sys-lab">From name</span>
                <input v-model="form.mail_from_name" type="text" class="sys-input" />
              </label>
              <label class="sys-field">
                <span class="sys-lab">Transport</span>
                <select v-model="form.mail_transport" class="sys-input">
                  <option value="php">PHP mail()</option>
                  <option value="smtp">SMTP</option>
                </select>
              </label>
              <template v-if="form.mail_transport === 'smtp'">
                <label class="sys-field">
                  <span class="sys-lab">SMTP host</span>
                  <input v-model="form.mail_smtp_host" type="text" class="sys-input" />
                </label>
                <label class="sys-field">
                  <span class="sys-lab">SMTP port</span>
                  <input v-model.number="form.mail_smtp_port" type="number" min="1" max="65535" class="sys-input" />
                </label>
                <label class="sys-field">
                  <span class="sys-lab">SMTP user</span>
                  <input v-model="form.mail_smtp_user" type="text" class="sys-input" autocomplete="off" />
                </label>
                <label class="sys-field">
                  <span class="sys-lab">Encryption</span>
                  <select v-model="form.mail_smtp_encryption" class="sys-input">
                    <option value="">None</option>
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                  </select>
                </label>
                <p class="sys-help">
                  SMTP password:
                  {{ snapshot.mail.smtp_password_configured ? 'configured in environment' : 'not set in environment' }}
                </p>
              </template>

              <div class="sys-test">
                <span class="sys-lab">Send test email</span>
                <div class="sys-test-row">
                  <input
                    v-model="testRecipient"
                    type="email"
                    class="sys-input"
                    placeholder="you@example.com"
                    :disabled="testBusy"
                  />
                  <button type="button" class="btn" :disabled="testBusy || !testRecipient.trim()" @click="sendTest">
                    {{ testBusy ? 'Sending…' : 'Send test' }}
                  </button>
                </div>
                <p v-if="testNotice" class="sys-ok">{{ testNotice }}</p>
                <p v-if="testError" class="sys-err">{{ testError }}</p>
              </div>
            </fieldset>

            <fieldset class="sys-fieldset">
              <legend class="sys-legend">Invite email defaults</legend>
              <p class="sys-help">Used when creating users unless the admin edits the intro for that user. Use <code>{display_name}</code>.</p>
              <label class="sys-field">
                <span class="sys-lab">Subject</span>
                <input v-model="form.mail_invite_subject" type="text" class="sys-input" maxlength="255" />
              </label>
              <label class="sys-field">
                <span class="sys-lab">Intro</span>
                <textarea v-model="form.mail_invite_intro" class="sys-input sys-textarea" rows="5" maxlength="2000" />
              </label>
            </fieldset>
          </template>
        </div>

        <footer class="settings-dialog-foot">
          <button type="button" class="btn" :disabled="saving" @click="close">Close</button>
          <button
            type="button"
            class="btn primary"
            :disabled="saving || loading || !form"
            @click="save"
          >
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.settings-dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
}

.settings-dialog {
  width: min(420px, 100%);
  max-height: min(92vh, 820px);
  display: flex;
  flex-direction: column;
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--text);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.settings-dialog-wide {
  width: min(640px, 100%);
}

.settings-dialog-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.settings-dialog-head h2 {
  margin: 0;
  font-size: 1.05rem;
  font-family: var(--font-display);
}

.settings-dialog-close {
  border: none;
  background: transparent;
  color: var(--muted);
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  padding: 0.15rem 0.35rem;
  border-radius: 6px;
}

.settings-dialog-close:hover {
  color: var(--text);
  background: var(--panel-2);
}

.settings-dialog-body {
  padding: 1rem;
  overflow-y: auto;
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.settings-dialog-foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--border);
  background: color-mix(in srgb, var(--panel-2) 70%, transparent);
  border-radius: 0 0 12px 12px;
  flex-shrink: 0;
}

.sys-fieldset {
  margin: 0;
  padding: 0;
  border: none;
  min-width: 0;
}

.sys-legend {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
  margin-bottom: 0.35rem;
}

.sys-help {
  margin: 0 0 0.65rem;
  font-size: 0.84rem;
  color: var(--muted);
  line-height: 1.4;
}

.sys-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin-bottom: 0.65rem;
}

.sys-lab {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
}

.sys-input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.65rem;
  font: inherit;
  font-size: 0.9rem;
}

.sys-textarea {
  resize: vertical;
  min-height: 5rem;
}

.sys-source {
  font-size: 0.72rem;
  color: var(--muted);
}

.sys-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.65rem;
  cursor: pointer;
  font-size: 0.88rem;
}

.sys-readonly {
  margin: 0.5rem 0 0;
  display: grid;
  gap: 0.45rem;
  font-size: 0.86rem;
}

.sys-readonly div {
  display: grid;
  grid-template-columns: 9rem 1fr;
  gap: 0.5rem;
}

.sys-readonly dt {
  margin: 0;
  color: var(--muted);
  font-weight: 600;
}

.sys-readonly dd {
  margin: 0;
}

.sys-provider-list {
  margin: 0;
  padding-left: 1.1rem;
}

.sys-muted {
  margin: 0;
  color: var(--muted);
  font-size: 0.88rem;
}

.sys-ok {
  margin: 0 0 0.5rem;
  font-size: 0.84rem;
  color: var(--success-mint, #00d1a0);
}

.sys-err {
  margin: 0 0 0.5rem;
  font-size: 0.84rem;
  color: var(--danger);
}

.sys-test {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--border);
}

.sys-test-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin-top: 0.35rem;
}

.sys-test-row .sys-input {
  flex: 1;
}

.btn {
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.95rem;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}

.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn.primary {
  background: color-mix(in srgb, var(--accent) 22%, var(--panel-2));
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
}
</style>
