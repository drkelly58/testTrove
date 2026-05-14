<script setup lang="ts">
defineProps<{
  modelValue: boolean;
  title: string;
  message: string;
  /** Optional list of cascade items rendered as bullets (e.g. "12 test cases will be deleted"). */
  consequences?: string[];
  /** Optional hint shown under the bullets. */
  note?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** When true, the confirm button is shown in danger styling (red). Default true. */
  danger?: boolean;
  /** When true the action buttons are disabled (e.g. while a parent request is pending). */
  busy?: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void;
  (e: 'confirm'): void;
  (e: 'cancel'): void;
}>();

function cancel() {
  emit('cancel');
  emit('update:modelValue', false);
}

function confirm() {
  emit('confirm');
}
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="confirm-backdrop" role="presentation" @click.self="cancel">
      <section
        class="confirm-dialog"
        role="alertdialog"
        aria-modal="true"
        :aria-labelledby="'confirm-title-' + title"
      >
        <header class="confirm-head">
          <h2 :id="'confirm-title-' + title">{{ title }}</h2>
        </header>
        <div class="confirm-body">
          <p class="confirm-msg">{{ message }}</p>
          <ul v-if="consequences && consequences.length" class="confirm-list">
            <li v-for="(c, i) in consequences" :key="i">{{ c }}</li>
          </ul>
          <p v-if="note" class="confirm-note">{{ note }}</p>
        </div>
        <footer class="confirm-foot">
          <button type="button" class="btn" :disabled="busy" @click="cancel">
            {{ cancelLabel || 'Cancel' }}
          </button>
          <button
            type="button"
            class="btn"
            :class="{ 'btn-danger': danger !== false }"
            :disabled="busy"
            @click="confirm"
          >
            {{ confirmLabel || 'Delete' }}
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.confirm-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
}

.confirm-dialog {
  width: min(440px, 100%);
  background: var(--panel, #111c26);
  border: 1px solid var(--border, #243548);
  border-radius: 12px;
  color: var(--text, #f1f5f9);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
  display: flex;
  flex-direction: column;
}

.confirm-head {
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--border, #243548);
}

.confirm-head h2 {
  margin: 0;
  font-size: 1rem;
}

.confirm-body {
  padding: 1rem;
  font-size: 0.9rem;
  line-height: 1.45;
}

.confirm-msg {
  margin: 0 0 0.6rem;
}

.confirm-list {
  margin: 0 0 0.6rem;
  padding-left: 1.15rem;
  color: var(--muted, #94a3b8);
}

.confirm-list li {
  margin-bottom: 0.2rem;
}

.confirm-note {
  margin: 0.5rem 0 0;
  color: var(--muted, #94a3b8);
  font-size: 0.82rem;
  font-style: italic;
}

.confirm-foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--border, #243548);
  background: color-mix(in srgb, var(--panel-2, #152535) 70%, transparent);
  border-radius: 0 0 12px 12px;
}

.btn {
  border-radius: 8px;
  border: 1px solid var(--border, #243548);
  background: var(--panel-2, #152535);
  color: var(--text, #f1f5f9);
  padding: 0.5rem 0.85rem;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-danger {
  border-color: color-mix(in srgb, var(--danger, #f87171) 55%, var(--border, #243548));
  background: color-mix(in srgb, var(--danger, #f87171) 18%, var(--panel-2, #152535));
  color: var(--danger, #f87171);
}

.btn-danger:hover:not(:disabled) {
  background: color-mix(in srgb, var(--danger, #f87171) 28%, var(--panel-2, #152535));
}
</style>
