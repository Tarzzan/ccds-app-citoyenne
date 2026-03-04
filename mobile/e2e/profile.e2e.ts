import { device, element, by, expect, waitFor } from 'detox';

describe('Flux Profil & Notifications', () => {
  beforeAll(async () => {
    await device.launchApp({ newInstance: true });
    await element(by.id('login-email-input')).typeText('citoyen@ccds.fr');
    await element(by.id('login-password-input')).typeText('Password123!');
    await element(by.id('login-submit-btn')).tap();
    await waitFor(element(by.id('map-screen'))).toBeVisible().withTimeout(10000);
  });

  // ─── Notifications ───────────────────────────────────────────────────────────
  describe('Notifications', () => {
    it('devrait afficher l\'écran des notifications', async () => {
      await element(by.id('tab-notifications')).tap();
      await expect(element(by.id('notifications-screen'))).toBeVisible();
    });

    it('devrait marquer une notification comme lue', async () => {
      await element(by.id('tab-notifications')).tap();
      await waitFor(element(by.id('notification-item-0'))).toBeVisible().withTimeout(5000);
      await element(by.id('notification-item-0')).tap();
      // La notification doit être marquée comme lue (opacité réduite ou badge supprimé)
      await expect(element(by.id('notification-item-0-unread'))).not.toBeVisible();
    });
  });

  // ─── Profil ──────────────────────────────────────────────────────────────────
  describe('Profil', () => {
    it('devrait accéder à l\'écran de profil depuis la navigation', async () => {
      await element(by.id('profile-menu-btn')).tap();
      await expect(element(by.id('profile-screen'))).toBeVisible();
    });

    it('devrait modifier le nom d\'affichage', async () => {
      await element(by.id('profile-menu-btn')).tap();
      await element(by.id('profile-name-input')).clearText();
      await element(by.id('profile-name-input')).typeText('Citoyen Modifié E2E');
      await element(by.id('profile-save-btn')).tap();
      await waitFor(element(by.id('profile-save-success'))).toBeVisible().withTimeout(5000);
    });

    it('devrait activer les préférences de notifications', async () => {
      await element(by.id('profile-menu-btn')).tap();
      await element(by.id('profile-tab-notifications')).tap();
      await element(by.id('notif-pref-status-change-toggle')).tap();
      await expect(element(by.id('notif-pref-status-change-toggle'))).toHaveToggleValue(false);
    });
  });

  // ─── Tableau de bord ─────────────────────────────────────────────────────────
  describe('Tableau de bord citoyen', () => {
    it('devrait afficher le tableau de bord avec les KPIs', async () => {
      await element(by.id('dashboard-menu-btn')).tap();
      await expect(element(by.id('dashboard-screen'))).toBeVisible();
      await expect(element(by.id('dashboard-kpi-incidents'))).toBeVisible();
      await expect(element(by.id('dashboard-kpi-resolved'))).toBeVisible();
      await expect(element(by.id('dashboard-points'))).toBeVisible();
    });
  });

  // ─── Déconnexion ─────────────────────────────────────────────────────────────
  describe('Déconnexion', () => {
    it('devrait se déconnecter et revenir à l\'écran de connexion', async () => {
      await element(by.id('profile-menu-btn')).tap();
      await element(by.id('logout-btn')).tap();
      await element(by.id('logout-confirm-btn')).tap();
      await waitFor(element(by.id('login-screen'))).toBeVisible().withTimeout(5000);
    });
  });
});
