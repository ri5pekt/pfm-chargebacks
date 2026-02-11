import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
    },
    {
      path: '/',
      redirect: '/chargebacks',
    },
    {
      path: '/chargebacks',
      name: 'chargebacks',
      component: () => import('../views/ChargebacksView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/chargebacks/new',
      name: 'new-chargeback',
      component: () => import('../views/NewChargebackView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/chargebacks/:id',
      name: 'chargeback-detail',
      component: () => import('../views/ChargebackDetailView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('../views/SettingsView.vue'),
      meta: { requiresAuth: true },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.loaded) {
    await auth.fetchMe()
  }

  if (to.meta.requiresAuth && !auth.user) {
    return { name: 'login' }
  }

  if (to.name === 'login' && auth.user) {
    return { name: 'chargebacks' }
  }
})

export default router
