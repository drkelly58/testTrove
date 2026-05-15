import { createApp } from 'vue';
import App from './App.vue';
import { refreshAuthSession } from './authContext';
import { bootstrapDevPermissionsFromUrl } from './devPermissions';
import { loadAuthSession } from './authSession';
import { canManageUsers, defaultLandingPath } from './permissions';
import { router } from './router';
import { bootstrapThemeFromStorage } from './theme';
import './styles/theme.css';
import './styles/forms.css';

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
    const safe = rt.startsWith('/') && !rt.startsWith('//') ? rt : '/';
    if (safe === '/' || safe === '/login') {
      return defaultLandingPath(s);
    }
    return safe;
  }
  if (s.auth_required && s.user && to.name === 'home' && defaultLandingPath(s) === '/runs') {
    return { name: 'runs' };
  }
  if ((to.meta as { requiresAdmin?: boolean }).requiresAdmin && !canManageUsers(s)) {
    return { name: 'home' };
  }
  return true;
});

router.afterEach((to, from) => {
  if (from.name === 'login' && to.name !== 'login') {
    void refreshAuthSession();
  }
});

async function boot() {
  const app = createApp(App);
  app.use(router);
  await router.isReady();
  app.mount('#app');
}

void boot();
