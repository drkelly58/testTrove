<script setup lang="ts">
import DataImportExport from '@/components/DataImportExport.vue';

defineProps<{
  modelValue: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
}>();

function close() {
  emit('update:modelValue', false);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="settings-dialog-backdrop" role="presentation" @click.self="close">
      <section
        class="settings-dialog settings-dialog-wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="import-export-title"
      >
        <header class="settings-dialog-head">
          <h2 id="import-export-title">Import &amp; export</h2>
          <button type="button" class="settings-dialog-close" aria-label="Close" @click="close">×</button>
        </header>
        <div class="settings-dialog-body">
          <DataImportExport />
        </div>
        <footer class="settings-dialog-foot">
          <button type="button" class="btn primary" @click="close">Done</button>
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
  width: min(640px, 100%);
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
}

.settings-dialog-foot {
  display: flex;
  justify-content: flex-end;
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--border);
  background: color-mix(in srgb, var(--panel-2) 70%, transparent);
  border-radius: 0 0 12px 12px;
  flex-shrink: 0;
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
