<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { authSession, refreshAuthSession } from '@/authContext';
import { loginWithPassword, type AuthSessionPayload } from '@/authSession';
import { defaultLandingPath } from '@/permissions';
import { resetTestTroveClientData } from '@/siteReset';

const route = useRoute();
const router = useRouter();
const loadError = ref<string | null>(null);
const email = ref('');
const password = ref('');
const signingIn = ref(false);
const formError = ref<string | null>(null);

const errHint = computed(() => {
  const raw = typeof route.query.err === 'string' ? route.query.err : '';
  if (!raw) {
    return null;
  }
  if (raw === 'state') {
    return 'Sign-in session expired. Please try again.';
  }
  if (raw === 'code') {
    return 'Provider did not return an authorization code.';
  }
  if (raw === 'token') {
    return 'Could not exchange the authorization code for a token.';
  }
  if (raw === 'unknown_provider') {
    return 'That sign-in method is not enabled on this server.';
  }
  if (raw === 'oauth_failed') {
    return 'Sign-in failed unexpectedly.';
  }
  if (raw.startsWith('profile:')) {
    return decodeURIComponent(raw.slice('profile:'.length));
  }
  if (raw.startsWith('email_conflict:')) {
    return decodeURIComponent(raw.slice('email_conflict:'.length));
  }
  if (raw.startsWith('profile')) {
    return decodeURIComponent(raw);
  }
  return 'Sign-in failed. Please try again or contact support.';
});

const session = computed(() => authSession.value);
const showOAuth = computed(() => (session.value?.providers.length ?? 0) > 0);
const showLocal = computed(() => session.value?.local_login_enabled === true);

onMounted(() => {
  if (authSession.value) {
    return;
  }
  void (async () => {
    try {
      await refreshAuthSession();
    } catch (e) {
      loadError.value = e instanceof Error ? e.message : 'Could not load sign-in options';
    }
  })();
});

function loginUrl(providerId: string): string {
  const base = '';
  const ret = encodeURIComponent(typeof route.query.return_to === 'string' ? route.query.return_to : '/');
  return `${base}/api/auth/login/${encodeURIComponent(providerId)}?return_to=${ret}`;
}

function returnPath(session: AuthSessionPayload | null): string {
  const rt = typeof route.query.return_to === 'string' ? route.query.return_to : '/';
  const safe = rt.startsWith('/') && !rt.startsWith('//') ? rt : '/';
  if (safe === '/' || safe === '/login') {
    return defaultLandingPath(session);
  }
  return safe;
}

function resetSiteData() {
  resetTestTroveClientData();
  window.location.href = '/app/login';
}

async function submitLocal(ev: Event) {
  ev.preventDefault();
  formError.value = null;
  signingIn.value = true;
  try {
    await loginWithPassword(email.value.trim(), password.value);
    const s = await refreshAuthSession();
    if (!s?.user) {
      formError.value =
        'Signed in but the session could not be confirmed. Clear cookies for this site and try again.';
      return;
    }
    await router.push(returnPath(s));
  } catch (e) {
    formError.value = e instanceof Error ? e.message : 'Sign-in failed';
  } finally {
    signingIn.value = false;
  }
}
</script>

<template>
  <div class="login-page">
    <header class="head">
      <h1>Sign in</h1>
      <p v-if="showLocal && !showOAuth" class="lede">Use your email and password to continue.</p>
      <p v-else-if="showLocal && showOAuth" class="lede">Sign in with email and password or an external provider.</p>
      <p v-else class="lede">Use your organization or social account to continue.</p>
    </header>

    <div v-if="loadError" class="banner err">{{ loadError }}</div>
    <div v-else-if="!session" class="banner muted">Loading…</div>
    <template v-else>
      <div v-if="errHint" class="banner err">{{ errHint }}</div>
      <div v-if="formError" class="banner err">{{ formError }}</div>

      <div v-if="!session.auth_required" class="card">
        <p class="hint">
          Sign-in is not configured on this server. Set <code>AUTH_LOCAL_ENABLED</code> and/or OAuth client
          credentials in the environment, or leave them unset for open local access.
        </p>
        <RouterLink to="/" class="btn primary">Back to workspace</RouterLink>
      </div>

      <div v-else-if="!showLocal && session.providers.length === 0" class="card">
        <p class="hint">No sign-in methods are fully configured.</p>
        <RouterLink to="/" class="btn">Back</RouterLink>
      </div>

      <div v-else class="card">
        <form v-if="showLocal" class="local-form" @submit="submitLocal">
          <label class="field">
            <span class="lab">Email</span>
            <input v-model="email" type="email" class="input" autocomplete="username" required />
          </label>
          <label class="field">
            <span class="lab">Password</span>
            <input
              v-model="password"
              type="password"
              class="input"
              autocomplete="current-password"
              required
            />
          </label>
          <button type="submit" class="btn primary block" :disabled="signingIn">
            {{ signingIn ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>

        <p v-if="showLocal && showOAuth" class="divider" role="separator">or</p>

        <ul v-if="showOAuth" class="providers">
          <li v-for="p in session.providers" :key="p.id">
            <a class="btn provider-btn" :href="loginUrl(p.id)">{{ p.label }}</a>
          </li>
        </ul>

        <p v-if="showOAuth" class="hint oauth-hint">External providers redirect away from this page, then return here.</p>

        <p class="reset-hint">
          Still stuck after clearing cookies?
          <button type="button" class="reset-link" @click="resetSiteData">Reset site data</button>
          (also clear cookies for this host in browser settings), then reload
          <code>http://staging.testtrove.lk638.us/app/</code>.
        </p>
      </div>
    </template>
  </div>
</template>

<style scoped>
.login-page {
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

.banner.muted {
  background: var(--panel-2, #152535);
  border: 1px solid var(--border);
  color: var(--muted);
}

.card {
  padding: 1.25rem;
  border-radius: var(--radius, 12px);
  border: 1px solid var(--border);
  background: var(--panel, #111c26);
}

.hint {
  margin: 0 0 1rem;
  color: var(--muted);
  font-size: 0.88rem;
  line-height: 1.45;
}

.hint code {
  font-size: 0.82em;
}

.oauth-hint {
  margin: 0.85rem 0 0;
}

.reset-hint {
  margin: 1rem 0 0;
  font-size: 0.82rem;
  color: var(--muted);
  line-height: 1.45;
}

.reset-hint code {
  font-size: 0.78em;
}

.reset-link {
  margin: 0 0.15rem;
  padding: 0;
  border: none;
  background: none;
  color: var(--accent, #7b61ff);
  font: inherit;
  font-size: inherit;
  font-weight: 600;
  text-decoration: underline;
  cursor: pointer;
}

.local-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.lab {
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.input {
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.65rem;
  font: inherit;
  font-size: 0.92rem;
}

.input:focus {
  outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent);
  outline-offset: 1px;
}

.divider {
  margin: 1.1rem 0;
  text-align: center;
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.providers {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.provider-btn {
  display: block;
  text-align: center;
  text-decoration: none;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  font-weight: 600;
}

.btn {
  display: inline-block;
  padding: 0.5rem 1rem;
  border-radius: 10px;
  text-decoration: none;
  font-weight: 600;
  border: 1px solid var(--border);
  color: var(--text);
  font: inherit;
  cursor: pointer;
  background: var(--panel-2);
}

.btn.block {
  display: block;
  width: 100%;
  text-align: center;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn.primary {
  background: color-mix(in srgb, var(--accent, #7b61ff) 22%, var(--panel-2));
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
  color: var(--text);
}
</style>
