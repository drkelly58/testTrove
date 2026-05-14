<script setup lang="ts">
import { onMounted, provide, ref } from 'vue';
import { RouterView } from 'vue-router';
import IconButton from '@/components/IconButton.vue';
import { createProject } from '@/api';
import {
  PROJECT_CONTEXT_KEY,
  projectContextForProvide,
  projectId,
  projects,
  projectsError,
  projectsLoading,
  refreshProjects,
  setProjectId,
} from '@/projectContext';

const projectCtx = projectContextForProvide();
provide(PROJECT_CONTEXT_KEY, projectCtx);

const newProjectName = ref('');
const newProjectBusy = ref(false);
const newProjectError = ref<string | null>(null);

onMounted(() => {
  void refreshProjects();
});

function onProjectSelect(ev: Event) {
  const raw = (ev.target as HTMLSelectElement).value;
  if (raw === '') {
    setProjectId(null);
    return;
  }
  const id = parseInt(raw, 10);
  if (Number.isFinite(id)) {
    setProjectId(id);
  }
}

async function submitNewProject() {
  const name = newProjectName.value.trim();
  if (!name || newProjectBusy.value) {
    return;
  }
  newProjectBusy.value = true;
  newProjectError.value = null;
  try {
    const { id } = await createProject(name);
    newProjectName.value = '';
    await refreshProjects();
    setProjectId(id);
  } catch (e) {
    newProjectError.value = e instanceof Error ? e.message : 'Could not create project';
  } finally {
    newProjectBusy.value = false;
  }
}
</script>

<template>
  <div class="shell">
    <header class="top">
      <div class="brand" aria-label="TestTrove">
        <svg
          class="logo-mark"
          viewBox="0 0 40 40"
          width="40"
          height="40"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <defs>
            <linearGradient id="tt-chest-glow" x1="50%" y1="100%" x2="50%" y2="0%">
              <stop offset="0%" stop-color="#00D1A0" stop-opacity="0.2" />
              <stop offset="55%" stop-color="#7B61FF" stop-opacity="0.35" />
              <stop offset="100%" stop-color="#7B61FF" stop-opacity="0.08" />
            </linearGradient>
            <filter id="tt-glow" x="-35%" y="-35%" width="170%" height="170%">
              <feGaussianBlur stdDeviation="0.8" result="b" />
              <feMerge>
                <feMergeNode in="b" />
                <feMergeNode in="SourceGraphic" />
              </feMerge>
            </filter>
          </defs>
          <!-- Chest body -->
          <rect x="8" y="18" width="24" height="17" rx="3.5" fill="#1A2B3C" stroke="#2d4a63" stroke-width="1" />
          <!-- Inner vault glow -->
          <rect x="10.5" y="20.5" width="19" height="12" rx="2" fill="url(#tt-chest-glow)" />
          <!-- Lid (slightly open vault) -->
          <path
            d="M6.5 18 L20 11.5 L33.5 18"
            fill="none"
            stroke="#7B61FF"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
          <path d="M20 11.5 v2.2" stroke="#9b85ff" stroke-width="1.5" stroke-linecap="round" opacity="0.85" />
          <!-- “Binary” hint: bracket + tick -->
          <path
            d="M13.5 24.5v4M13.5 24.5h1.2M13.5 28.5h1.2"
            fill="none"
            stroke="#7B61FF"
            stroke-width="1.1"
            stroke-linecap="round"
            opacity="0.75"
          />
          <path
            d="M26.5 24.5v4M25.3 24.5h1.2M25.3 28.5h1.2"
            fill="none"
            stroke="#7B61FF"
            stroke-width="1.1"
            stroke-linecap="round"
            opacity="0.75"
          />
          <!-- Success check -->
          <path
            d="M16.5 26.2 l2.4 2.4 5.6-6.8"
            fill="none"
            stroke="#00D1A0"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            filter="url(#tt-glow)"
          />
        </svg>
        <div class="brand-text">
          <div class="wordmark">
            <span class="wm-test">test</span><span class="wm-trove">Trove</span>
          </div>
          <div class="subtitle">Test case management — your team’s trusted repository</div>
        </div>
      </div>

      <div class="project-bar" aria-label="Workspace project">
        <label class="project-bar-label">
          <span class="project-bar-lab">Project</span>
          <select
            class="project-select"
            :value="projectId ?? ''"
            :disabled="projectsLoading || !!projectsError || !projects.length"
            @change="onProjectSelect"
          >
            <option value="" disabled>{{ projectsLoading ? 'Loading…' : projects.length ? 'Choose…' : 'No projects' }}</option>
            <option v-for="p in projects" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
          </select>
        </label>
        <form class="project-new" @submit.prevent="submitNewProject">
          <input
            v-model="newProjectName"
            class="project-new-input"
            type="text"
            placeholder="New project…"
            :disabled="newProjectBusy"
            aria-label="New project name"
          />
          <IconButton type="submit" accent label="Create project" title="Create project" :disabled="newProjectBusy || !newProjectName.trim()">
            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
          </IconButton>
        </form>
        <p v-if="projectsError" class="project-bar-err">{{ projectsError }}</p>
        <p v-else-if="newProjectError" class="project-bar-err">{{ newProjectError }}</p>
      </div>

      <nav class="nav">
        <RouterLink to="/" class="nav-link">Workspace</RouterLink>
        <RouterLink to="/runs" class="nav-link">Runs</RouterLink>
        <RouterLink
          to="/settings"
          class="nav-link nav-gear"
          aria-label="Data and settings"
          title="Data and settings"
        >
          <svg class="gear-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
            <path
              fill="currentColor"
              d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.488.488 0 00-.6-.22l-2.39.96c-.52-.4-1.08-.73-1.69-.98l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.61.25-1.17.59-1.69.98l-2.39-.96c-.22-.08-.47 0-.6.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.6.22l2.39-.96c.52.4 1.08.73 1.69.98l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.61-.25 1.17-.59 1.69-.98l2.39.96c.22.08.47 0 .6-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"
            />
          </svg>
        </RouterLink>
      </nav>
    </header>
    <main class="main">
      <RouterView />
    </main>
  </div>
</template>

<style>
:root {
  color-scheme: dark;
  /* Brand — Trusted Repository */
  --primary-navy: #1a2b3c;
  --action-purple: #7b61ff;
  --action-purple-soft: #9b85ff;
  --success-mint: #00d1a0;
  --neutral-slate: #64748b;
  /* Mapped tokens */
  --bg: #0c1218;
  --panel: #111c26;
  --panel-2: #152535;
  --border: #243548;
  --text: #f1f5f9;
  --muted: #64748b;
  --accent: #7b61ff;
  --accent-2: #9b85ff;
  --success: #00d1a0;
  --danger: #f87171;
  --radius: 12px;
  --font: 'Inter', system-ui, -apple-system, sans-serif;
  --font-display: 'Outfit', var(--font);
}

*,
*::before,
*::after {
  box-sizing: border-box;
}

body {
  margin: 0;
  min-height: 100vh;
  font-family: var(--font);
  background:
    radial-gradient(900px 520px at 88% -8%, color-mix(in srgb, var(--action-purple) 22%, transparent) 0%, transparent 55%),
    radial-gradient(700px 480px at 8% 0%, color-mix(in srgb, var(--success-mint) 12%, transparent) 0%, transparent 50%),
    linear-gradient(180deg, #0a1016 0%, var(--bg) 38%);
  color: var(--text);
}

a {
  color: inherit;
}

.shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--border);
  background: color-mix(in srgb, var(--panel) 92%, transparent);
  backdrop-filter: blur(12px);
}

.project-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.65rem;
  flex: 1;
  justify-content: center;
  min-width: min(320px, 100%);
}

.project-bar-label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}

.project-bar-lab {
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.project-select {
  min-width: 10rem;
  max-width: 18rem;
  width: 100%;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.45rem 0.6rem;
  font: inherit;
  font-size: 0.88rem;
  font-weight: 600;
}

.project-select:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.project-new {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.project-new-input {
  width: min(200px, 42vw);
  min-width: 0;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.45rem 0.6rem;
  font: inherit;
  font-size: 0.85rem;
}

.project-new-input:disabled {
  opacity: 0.55;
}

.project-bar-err {
  margin: 0;
  width: 100%;
  font-size: 0.78rem;
  color: var(--danger);
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.logo-mark {
  flex-shrink: 0;
  filter: drop-shadow(0 4px 14px color-mix(in srgb, var(--action-purple) 45%, transparent));
}

.brand-text {
  min-width: 0;
}

.wordmark {
  font-family: var(--font-display);
  letter-spacing: -0.04em;
  line-height: 1.1;
  font-size: 1.35rem;
}

.wm-test {
  font-weight: 500;
  color: var(--neutral-slate);
  text-transform: lowercase;
}

.wm-trove {
  font-weight: 800;
  color: var(--text);
  text-transform: none;
}

.subtitle {
  margin-top: 0.2rem;
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--muted);
  letter-spacing: 0.01em;
}

.nav {
  display: flex;
  gap: 0.5rem;
}

.nav-link {
  text-decoration: none;
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  color: var(--muted);
  font-weight: 600;
  font-size: 0.9rem;
}

.nav-link.router-link-active {
  color: var(--text);
  background: color-mix(in srgb, var(--action-purple) 14%, var(--panel-2));
  border: 1px solid color-mix(in srgb, var(--action-purple) 45%, var(--border));
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--success-mint) 25%, transparent);
}

.nav-gear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.4rem 0.55rem;
  color: var(--muted);
}

.nav-gear.router-link-active {
  color: var(--text);
}

.gear-icon {
  display: block;
  opacity: 0.92;
}

.main {
  flex: 1;
  padding: 1.5rem;
  max-width: 1120px;
  width: 100%;
  margin: 0 auto;
}
</style>
