import { createApp } from 'vue';
import App from './App.vue';
import { bootstrapDevPermissionsFromUrl } from './devPermissions';
import { loadAuthSession } from './authSession';
import { canManageUsers, isViewerOnlyOnAllProjects } from './permissions';
import { router } from './router';
import { bootstrapThemeFromStorage } from './theme';
import './styles/theme.css';

bootstrapThemeFromStorage();
bootstrapDevPermissionsFromUrl();

router.beforeEach(async (to) => {
  const s = await loadAuthSession();
  const isPublic = Boolean((to.meta as { public?: boolean }).public);
  if (s.auth_required && !s.user && !isPublic) {
    return { name: 'login', query: { return_to: to.fullPath } };
  }
  if (to.name === 'login' && s.auth_required && s.user) {
    const rt = typeof to.query.return_to === 'string' ? to.query.return_to : '/';
    return rt.startsWith('/') && !rt.startsWith('//') ? rt : '/';
  }
  if (s.auth_required && s.user && to.name === 'home' && isViewerOnlyOnAllProjects(s)) {
    return { name: 'runs' };
  }
  if ((to.meta as { requiresAdmin?: boolean }).requiresAdmin && !canManageUsers(s)) {
    return { name: 'home' };
  }
  return true;
});

async function boot() {
  const app = createApp(App);
  app.use(router);
  await router.isReady();
  app.mount('#app');
}

void boot();
