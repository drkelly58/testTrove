<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { AuthUser } from '@/authSession';
import UserAvatar from '@/components/UserAvatar.vue';

const props = defineProps<{
  user: AuthUser;
  logoutBusy: boolean;
}>();

const emit = defineEmits<{
  (e: 'open-preferences'): void;
  (e: 'logout'): void;
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

function toggle() {
  open.value = !open.value;
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

function onPreferences() {
  close();
  emit('open-preferences');
}

function onLogout() {
  if (props.logoutBusy) {
    return;
  }
  close();
  emit('logout');
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
  <div class="profile-menu">
    <button
      ref="triggerRef"
      type="button"
      class="profile-menu-trigger"
      :aria-expanded="open"
      aria-haspopup="menu"
      :aria-label="`Account menu for ${user.display_name}`"
      :title="`${user.display_name} (${user.email})`"
      @click="onTriggerClick"
    >
      <UserAvatar :display-name="user.display_name" :picture-url="user.picture_url" :size="32" />
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        ref="menuRef"
        class="profile-menu-panel"
        role="menu"
        aria-label="Account"
        :style="menuStyle"
      >
        <div class="profile-menu-head">
          <p class="profile-menu-name">{{ user.display_name }}</p>
          <p class="profile-menu-email">{{ user.email }}</p>
        </div>
        <button type="button" class="profile-menu-item" role="menuitem" @click="onPreferences">
          Preferences
        </button>
        <button
          type="button"
          class="profile-menu-item profile-menu-item--danger"
          role="menuitem"
          :disabled="logoutBusy"
          @click="onLogout"
        >
          Sign out
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.profile-menu {
  display: inline-flex;
}

.profile-menu-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.15rem;
  border: none;
  border-radius: 50%;
  background: transparent;
  cursor: pointer;
  font: inherit;
}

.profile-menu-trigger:hover {
  background: color-mix(in srgb, var(--panel-2) 88%, transparent);
}

.profile-menu-trigger:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--accent) 65%, transparent);
  outline-offset: 2px;
}

.profile-menu-panel {
  position: fixed;
  z-index: 55;
  padding: 0.35rem 0;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel);
  color: var(--text);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
}

.profile-menu-head {
  padding: 0.55rem 0.85rem 0.45rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: 0.25rem;
}

.profile-menu-name {
  margin: 0;
  font-size: 0.88rem;
  font-weight: 700;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-menu-email {
  margin: 0.15rem 0 0;
  font-size: 0.78rem;
  color: var(--muted);
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-menu-item {
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

.profile-menu-item:hover:not(:disabled) {
  background: var(--panel-2);
}

.profile-menu-item:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--accent) 45%, transparent);
  outline-offset: -2px;
}

.profile-menu-item:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.profile-menu-item--danger {
  color: var(--danger);
}

.profile-menu-item--danger:hover:not(:disabled) {
  background: color-mix(in srgb, var(--danger) 8%, var(--panel-2));
}
</style>
