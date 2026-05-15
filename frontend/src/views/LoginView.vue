<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { loadAuthSession, type AuthSessionPayload } from '@/authSession';

const route = useRoute();
const session = ref<AuthSessionPayload | null>(null);
const loadError = ref<string | null>(null);

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

onMounted(() => {
  void (async () => {
    try {
      session.value = await loadAuthSession(true);
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
</script>

<template>
  <div class="login-page">
    <header class="head">
      <h1>Sign in</h1>
      <p class="lede">Use your organization or social account to continue.</p>
    </header>

    <div v-if="loadError" class="banner err">{{ loadError }}</div>
    <div v-else-if="!session" class="banner muted">Loading…</div>
    <template v-else>
      <div v-if="errHint" class="banner err">{{ errHint }}</div>
      <div v-if="!session.auth_required" class="card">
        <p class="hint">
          OAuth sign-in is not configured on this server. Set provider client IDs and secrets in the environment, or
          leave them unset for open local access.
        </p>
        <RouterLink to="/" class="btn primary">Back to workspace</RouterLink>
      </div>
      <div v-else-if="session.providers.length === 0" class="card">
        <p class="hint">No OAuth providers are fully configured (each needs a client id and secret).</p>
        <RouterLink to="/" class="btn">Back</RouterLink>
      </div>
      <div v-else class="card">
        <p class="hint">You will be redirected to your provider, then returned here.</p>
        <ul class="providers">
          <li v-for="p in session.providers" :key="p.id">
            <a class="btn primary provider-btn" :href="loginUrl(p.id)">{{ p.label }}</a>
          </li>
        </ul>
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
}

.btn.primary {
  background: color-mix(in srgb, var(--accent, #7b61ff) 22%, var(--panel-2));
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
  color: var(--text);
}
</style>
