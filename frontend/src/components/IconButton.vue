<script setup lang="ts">
defineProps<{
  /** Accessible name (also default tooltip if `title` omitted) */
  label: string;
  /** Native tooltip */
  title?: string;
  disabled?: boolean;
  type?: 'button' | 'submit';
  /** Slightly stronger hover for primary actions (e.g. add) */
  accent?: boolean;
  /** Destructive action (e.g. remove) */
  danger?: boolean;
}>();

defineEmits<{
  click: [e: MouseEvent];
}>();
</script>

<template>
  <button
    :type="type ?? 'button'"
    class="icon-btn"
    :class="{ 'icon-btn--accent': accent, 'icon-btn--danger': danger }"
    :title="title ?? label"
    :aria-label="label"
    :disabled="disabled"
    @click="$emit('click', $event)"
  >
    <span class="icon-btn-glyph" aria-hidden="true">
      <slot />
    </span>
  </button>
</template>

<style scoped>
.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  padding: 0;
  border-radius: 8px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--muted, #64748b);
  cursor: pointer;
  transition:
    color 0.12s ease,
    background 0.12s ease,
    border-color 0.12s ease;
}

.icon-btn:hover:not(:disabled) {
  color: var(--text, #f1f5f9);
  background: color-mix(in srgb, var(--panel-2, #152535) 88%, transparent);
  border-color: var(--border, #243548);
}

.icon-btn--accent:hover:not(:disabled) {
  color: var(--accent-2, #9b85ff);
  border-color: color-mix(in srgb, var(--accent, #7b61ff) 35%, var(--border, #243548));
  background: color-mix(in srgb, var(--accent, #7b61ff) 10%, var(--panel-2, #152535));
}

.icon-btn--danger:hover:not(:disabled) {
  color: var(--danger, #f87171);
  border-color: color-mix(in srgb, var(--danger, #f87171) 35%, var(--border, #243548));
  background: color-mix(in srgb, var(--danger, #f87171) 8%, var(--panel-2, #152535));
}

.icon-btn:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--accent, #7b61ff) 65%, transparent);
  outline-offset: 2px;
}

.icon-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.icon-btn-glyph {
  display: flex;
  align-items: center;
  justify-content: center;
}

.icon-btn-glyph :deep(svg) {
  width: 16px;
  height: 16px;
  stroke: currentColor;
  fill: none;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
</style>
