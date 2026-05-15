import { createApp } from 'vue';
import App from './App.vue';
import { loadAuthSession } from './authSession';
import { router } from './router';

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
  return true;
});

async function boot() {
  const app = createApp(App);
  app.use(router);
  await router.isReady();
  app.mount('#app');
}

void boot();
