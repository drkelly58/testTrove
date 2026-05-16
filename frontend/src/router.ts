import { createRouter, createWebHistory } from 'vue-router';
import ApiUnavailableView from './views/ApiUnavailableView.vue';
import DashboardView from './views/DashboardView.vue';
import HomeView from './views/HomeView.vue';
import LoginView from './views/LoginView.vue';
import RunOverviewView from './views/RunOverviewView.vue';
import RunSessionView from './views/RunSessionView.vue';
import RunsHubView from './views/RunsHubView.vue';
import UsersAdminView from './views/UsersAdminView.vue';
export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'home', component: HomeView },
    { path: '/dashboard', name: 'dashboard', component: DashboardView },
    {
      path: '/api-unavailable',
      name: 'apiUnavailable',
      component: ApiUnavailableView,
      meta: { public: true },
    },
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/settings', redirect: '/' },
    { path: '/admin/users', name: 'usersAdmin', component: UsersAdminView, meta: { requiresAdmin: true } },
    { path: '/runs', name: 'runs', component: RunsHubView },
    { path: '/runs/:runId/overview', name: 'runOverview', component: RunOverviewView },
    { path: '/runs/:runId', name: 'runSession', component: RunSessionView },
  ],
});
