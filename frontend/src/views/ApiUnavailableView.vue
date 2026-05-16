<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

const LAST_ERROR_KEY = 'testtrove.lastApiError';

const route = useRoute();
const router = useRouter();

const lastError = ref<string | null>(null);

onMounted(() => {
  try {
    const raw = sessionStorage.getItem(LAST_ERROR_KEY);
    lastError.value = raw && raw.trim() !== '' ? raw : null;
  } catch {
    lastError.value = null;
  }
});

const attemptedPath = computed(() => {
  const a = route.query.attempted;
  return typeof a === 'string' && a.startsWith('/') && !a.startsWith('//') ? a : null;
});

function retry() {
  void router.replace(attemptedPath.value ?? '/');
}
</script>

<template>
  <div class="api-down">
    <section class="panel" aria-labelledby="api-down-title">
      <h1 id="api-down-title">Cannot reach the server</h1>
      <p class="lede">
        TestTrove could not contact the API (for example, the backend is stopped, the URL is wrong, or you are
        offline). Check your connection and that the PHP app is running, then try again.
      </p>
      <p v-if="lastError" class="detail">
        <span class="detail-lab">Last error</span>
        <code class="detail-msg">{{ lastError }}</code>
      </p>
      <div class="actions">
        <button type="button" class="btn primary" @click="retry">Try again</button>
        <RouterLink to="/login" class="btn">Sign in</RouterLink>
      </div>
    </section>
  </div>
</template>

<style scoped>
.api-down {
  min-height: 60vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem 1.25rem;
}

.panel {
  width: min(32rem, 100%);
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 1.5rem 1.35rem;
  color: var(--text);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
}

h1 {
  margin: 0 0 0.65rem;
  font-size: 1.2rem;
  font-family: var(--font-display);
}

.lede {
  margin: 0 0 1rem;
  font-size: 0.92rem;
  line-height: 1.5;
  color: var(--muted);
}

.detail {
  margin: 0 0 1.25rem;
  font-size: 0.82rem;
}

.detail-lab {
  display: block;
  font-weight: 600;
  color: var(--muted);
  margin-bottom: 0.35rem;
}

.detail-msg {
  display: block;
  padding: 0.5rem 0.6rem;
  border-radius: 8px;
  background: var(--panel-2);
  border: 1px solid var(--border);
  font-size: 0.78rem;
  word-break: break-word;
  white-space: pre-wrap;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: center;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.95rem;
  font: inherit;
  font-weight: 600;
  font-size: 0.88rem;
  cursor: pointer;
  text-decoration: none;
}

.btn.primary {
  background: color-mix(in srgb, var(--accent) 22%, var(--panel-2));
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
}
</style>
