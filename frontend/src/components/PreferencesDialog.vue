<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import DataImportExport from '@/components/DataImportExport.vue';
import { PROJECT_CONTEXT_KEY } from '@/projectContext';
import { theme, type ThemeMode } from '@/theme';
import {
  preferencesAreServerBacked,
  runOverviewSingleExpand,
  setRunOverviewSingleExpand,
  setThemePreference,
} from '@/userPreferences';

defineProps<{
  modelValue: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
}>();

const projectCtx = inject(PROJECT_CONTEXT_KEY)!;

type PrefsTab = 'general' | 'data';

const activeTab = ref<PrefsTab>('general');

const themeOptions: { id: ThemeMode; label: string; hint: string }[] = [
  { id: 'dark', label: 'Dark', hint: 'Default vault look' },
  { id: 'light', label: 'Light', hint: 'Bright workspace' },
];

const expandModeOptions = [
  { id: 'single' as const, label: 'One at a time', hint: 'Accordion — opening a case closes others' },
  { id: 'multiple' as const, label: 'Multiple open', hint: 'Keep other expanded cases open' },
];

const syncHint = computed(() =>
  preferencesAreServerBacked()
    ? 'Preferences are saved to your account.'
    : 'Preferences are saved in this browser until you sign in.',
);

function close() {
  activeTab.value = 'general';
  emit('update:modelValue', false);
}

function onProjectChange(ev: Event) {
  const raw = (ev.target as HTMLSelectElement).value;
  if (raw === '') {
    projectCtx.setProjectId(null);
    return;
  }
  const id = parseInt(raw, 10);
  if (Number.isFinite(id)) {
    projectCtx.setProjectId(id);
  }
}

function onExpandModeChange(mode: 'single' | 'multiple') {
  setRunOverviewSingleExpand(mode === 'single');
}

function onThemePick(mode: ThemeMode) {
  setThemePreference(mode);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="prefs-backdrop" role="presentation" @click.self="close">
      <section
        class="prefs-dialog"
        :class="{ 'prefs-dialog-wide': activeTab === 'data' }"
        role="dialog"
        aria-modal="true"
        aria-labelledby="prefs-dialog-title"
      >
        <header class="prefs-head">
          <h2 id="prefs-dialog-title">Preferences</h2>
          <button type="button" class="prefs-close" aria-label="Close" @click="close">×</button>
        </header>

        <nav class="prefs-tabs" aria-label="Preference sections">
          <button
            type="button"
            class="prefs-tab"
            :class="{ active: activeTab === 'general' }"
            :aria-selected="activeTab === 'general'"
            @click="activeTab = 'general'"
          >
            General
          </button>
          <button
            type="button"
            class="prefs-tab"
            :class="{ active: activeTab === 'data' }"
            :aria-selected="activeTab === 'data'"
            @click="activeTab = 'data'"
          >
            Import &amp; export
          </button>
        </nav>

        <div class="prefs-body">
          <template v-if="activeTab === 'general'">
            <p class="prefs-sync-hint">{{ syncHint }}</p>

            <fieldset class="prefs-fieldset">
              <legend class="prefs-legend">Default project</legend>
              <p class="prefs-help">Opens when you return to TestTrove. You can switch projects anytime from the top bar.</p>
              <select
                class="prefs-input"
                :value="projectCtx.projectId ?? ''"
                :disabled="projectCtx.loading || !!projectCtx.error || !projectCtx.projects.length"
                @change="onProjectChange"
              >
                <option value="" disabled>
                  {{ projectCtx.loading ? 'Loading…' : projectCtx.projects.length ? 'Choose…' : 'No projects' }}
                </option>
                <option v-for="p in projectCtx.projects" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
              </select>
              <p v-if="projectCtx.error" class="prefs-field-err">{{ projectCtx.error }}</p>
            </fieldset>

            <fieldset class="prefs-fieldset">
              <legend class="prefs-legend">Run overview</legend>
              <p class="prefs-help">How cases expand on the run overview page.</p>
              <div class="prefs-segmented" role="radiogroup" aria-label="Run overview expand mode">
                <label
                  v-for="opt in expandModeOptions"
                  :key="opt.id"
                  class="prefs-segment"
                  :class="{ active: (opt.id === 'single') === runOverviewSingleExpand }"
                >
                  <input
                    type="radio"
                    name="expand-mode"
                    :value="opt.id"
                    :checked="(opt.id === 'single') === runOverviewSingleExpand"
                    @change="onExpandModeChange(opt.id)"
                  />
                  <span class="prefs-segment-text">
                    <span class="prefs-segment-label">{{ opt.label }}</span>
                    <span class="prefs-segment-hint">{{ opt.hint }}</span>
                  </span>
                </label>
              </div>
            </fieldset>

            <fieldset class="prefs-fieldset">
              <legend class="prefs-legend">Appearance</legend>
              <p class="prefs-help">Color theme for the workspace.</p>
              <div class="theme-picker" role="radiogroup" aria-label="Color theme">
                <label
                  v-for="opt in themeOptions"
                  :key="opt.id"
                  class="theme-option"
                  :class="{ active: theme === opt.id }"
                >
                  <input
                    type="radio"
                    name="theme"
                    :value="opt.id"
                    :checked="theme === opt.id"
                    @change="onThemePick(opt.id)"
                  />
                  <span class="theme-option-body">
                    <span class="theme-swatch" :data-theme-preview="opt.id" aria-hidden="true" />
                    <span class="theme-option-text">
                      <span class="theme-option-label">{{ opt.label }}</span>
                      <span class="theme-option-hint">{{ opt.hint }}</span>
                    </span>
                  </span>
                </label>
              </div>
            </fieldset>
          </template>

          <DataImportExport v-else />
        </div>

        <footer class="prefs-foot">
          <button type="button" class="btn primary" @click="close">Done</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.prefs-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
}

.prefs-dialog {
  width: min(480px, 100%);
  max-height: min(90vh, 720px);
  display: flex;
  flex-direction: column;
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--text);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.prefs-dialog-wide {
  width: min(640px, 100%);
  max-height: min(92vh, 820px);
}

.prefs-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--border);
}

.prefs-head h2 {
  margin: 0;
  font-size: 1.05rem;
  font-family: var(--font-display);
}

.prefs-close {
  border: none;
  background: transparent;
  color: var(--muted);
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  padding: 0.15rem 0.35rem;
  border-radius: 6px;
}

.prefs-close:hover {
  color: var(--text);
  background: var(--panel-2);
}

.prefs-tabs {
  display: flex;
  gap: 0.35rem;
  padding: 0.65rem 1rem 0;
  border-bottom: 1px solid var(--border);
}

.prefs-tab {
  border: none;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 0.86rem;
  font-weight: 600;
  padding: 0.45rem 0.65rem;
  border-radius: 8px 8px 0 0;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}

.prefs-tab:hover {
  color: var(--text);
}

.prefs-tab.active {
  color: var(--text);
  border-bottom-color: var(--accent);
}

.prefs-body {
  padding: 1rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.prefs-sync-hint {
  margin: 0;
  font-size: 0.82rem;
  color: var(--muted);
  line-height: 1.4;
}

.prefs-fieldset {
  margin: 0;
  padding: 0;
  border: none;
  min-width: 0;
}

.prefs-legend {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
  padding: 0;
  margin-bottom: 0.35rem;
}

.prefs-help {
  margin: 0 0 0.5rem;
  font-size: 0.84rem;
  color: var(--muted);
  line-height: 1.4;
}

.prefs-input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--text);
  padding: 0.5rem 0.65rem;
  font: inherit;
  font-size: 0.9rem;
}

.prefs-input:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.prefs-field-err {
  margin: 0.35rem 0 0;
  font-size: 0.78rem;
  color: var(--danger);
}

.prefs-segmented {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.prefs-segment {
  display: block;
  cursor: pointer;
}

.prefs-segment input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.prefs-segment-text {
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
  padding: 0.65rem 0.75rem;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.prefs-segment.active .prefs-segment-text,
.prefs-segment:has(input:checked) .prefs-segment-text {
  border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 25%, transparent);
}

.prefs-segment:focus-within .prefs-segment-text {
  outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent);
  outline-offset: 2px;
}

.prefs-segment-label {
  font-weight: 600;
  font-size: 0.9rem;
  color: var(--text);
}

.prefs-segment-hint {
  font-size: 0.78rem;
  color: var(--muted);
}

.theme-picker {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 0.55rem;
}

.theme-option {
  display: block;
  cursor: pointer;
}

.theme-option input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.theme-option-body {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.65rem 0.75rem;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.theme-option.active .theme-option-body,
.theme-option:has(input:checked) .theme-option-body {
  border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 25%, transparent);
}

.theme-option:focus-within .theme-option-body {
  outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent);
  outline-offset: 2px;
}

.theme-swatch {
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 8px;
  border: 1px solid var(--border);
}

.theme-swatch[data-theme-preview='dark'] {
  background: linear-gradient(145deg, #152535 0%, #0c1218 100%);
}

.theme-swatch[data-theme-preview='light'] {
  background: linear-gradient(145deg, #ffffff 0%, #e2e8f0 100%);
}

.theme-option-text {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.theme-option-label {
  font-weight: 600;
  font-size: 0.88rem;
  color: var(--text);
}

.theme-option-hint {
  font-size: 0.75rem;
  color: var(--muted);
}

.prefs-foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--border);
  background: color-mix(in srgb, var(--panel-2) 70%, transparent);
  border-radius: 0 0 12px 12px;
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
}

.btn.primary {
  background: color-mix(in srgb, var(--accent) 22%, var(--panel-2));
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
}
</style>
