<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

withDefaults(
  defineProps<{
    /** When false, only Import & export is shown (non-admins). */
    showSystemSettings?: boolean;
  }>(),
  {
    showSystemSettings: true,
  },
);

const emit = defineEmits<{
  (e: 'open-system-settings'): void;
  (e: 'open-import-export'): void;
}>();

const open = ref(false);
const triggerRef = ref<HTMLButtonElement | null>(null);
const menuRef = ref<HTMLElement | null>(null);
const menuStyle = ref<{ top: string; left: string; minWidth: string }>({
  top: '0px',
  left: '0px',
  minWidth: '12rem',
});

function updatePosition() {
  const el = triggerRef.value;
  if (!el) {
    return;
  }
  const rect = el.getBoundingClientRect();
  const menuWidth = 220;
  let left = rect.right - menuWidth;
  left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8));
  menuStyle.value = {
    top: `${rect.bottom + 6}px`,
    left: `${left}px`,
    minWidth: `${menuWidth}px`,
  };
}

function close() {
  open.value = false;
}

async function openMenu() {
  open.value = true;
  await nextTick();
  updatePosition();
}

function onTriggerClick() {
  if (open.value) {
    close();
  } else {
    void openMenu();
  }
}

function onSystemSettings() {
  close();
  emit('open-system-settings');
}

function onImportExport() {
  close();
  emit('open-import-export');
}

function onDocumentPointerDown(ev: MouseEvent) {
  if (!open.value) {
    return;
  }
  const target = ev.target as Node;
  if (triggerRef.value?.contains(target) || menuRef.value?.contains(target)) {
    return;
  }
  close();
}

function onDocumentKeyDown(ev: KeyboardEvent) {
  if (!open.value) {
    return;
  }
  if (ev.key === 'Escape') {
    ev.preventDefault();
    close();
    triggerRef.value?.focus();
  }
}

function onWindowChange() {
  if (open.value) {
    updatePosition();
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
    document.addEventListener('keydown', onDocumentKeyDown);
    window.addEventListener('resize', onWindowChange);
    window.addEventListener('scroll', onWindowChange, true);
  } else {
    document.removeEventListener('pointerdown', onDocumentPointerDown, true);
    document.removeEventListener('keydown', onDocumentKeyDown);
    window.removeEventListener('resize', onWindowChange);
    window.removeEventListener('scroll', onWindowChange, true);
  }
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
  document.removeEventListener('keydown', onDocumentKeyDown);
  window.removeEventListener('resize', onWindowChange);
  window.removeEventListener('scroll', onWindowChange, true);
});
</script>

<template>
  <div class="gear-menu">
    <button
      ref="triggerRef"
      type="button"
      class="nav-link nav-gear gear-menu-trigger"
      :aria-expanded="open"
      aria-haspopup="menu"
      aria-label="Settings menu"
      title="Settings"
      @click="onTriggerClick"
    >
      <svg class="gear-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
        <path
          fill="currentColor"
          d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.488.488 0 00-.6-.22l-2.39.96c-.52-.4-1.08-.73-1.69-.98l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.61.25-1.17.59-1.69.98l-2.39-.96c-.22-.08-.47 0-.6.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.6.22l2.39-.96c.52.4 1.08.73 1.69.98l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.61-.25 1.17-.59 1.69-.98l2.39.96c.22.08.47 0 .6-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"
        />
      </svg>
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        ref="menuRef"
        class="gear-menu-panel"
        role="menu"
        aria-label="Settings"
        :style="menuStyle"
      >
        <button
          v-if="showSystemSettings"
          type="button"
          class="gear-menu-item"
          role="menuitem"
          @click="onSystemSettings"
        >
          System settings
        </button>
        <button type="button" class="gear-menu-item" role="menuitem" @click="onImportExport">
          Import &amp; export
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.gear-menu {
  display: inline-flex;
}

.gear-menu-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.gear-icon {
  display: block;
  opacity: 0.92;
}

.gear-menu-panel {
  position: fixed;
  z-index: 55;
  padding: 0.35rem 0;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel);
  color: var(--text);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
}

.gear-menu-item {
  display: block;
  width: 100%;
  text-align: left;
  padding: 0.5rem 0.85rem;
  border: none;
  background: transparent;
  color: var(--text);
  font: inherit;
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
}

.gear-menu-item:hover {
  background: var(--panel-2);
}

.gear-menu-item:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent);
  outline-offset: -2px;
}
</style>
