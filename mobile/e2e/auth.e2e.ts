import { device, element, by, expect, waitFor } from 'detox';

describe('Flux Authentification', () => {
  beforeAll(async () => {
    await device.launchApp({ newInstance: true });
  });

  beforeEach(async () => {
    await device.reloadReactNative();
  });

  // ─── Inscription ────────────────────────────────────────────────────────────
  describe('Inscription', () => {
    it('devrait afficher l\'écran de connexion au démarrage', async () => {
      await expect(element(by.id('login-screen'))).toBeVisible();
    });

    it('devrait naviguer vers l\'écran d\'inscription', async () => {
      await element(by.id('register-link')).tap();
      await expect(element(by.id('register-screen'))).toBeVisible();
    });

    it('devrait afficher une erreur si les champs sont vides', async () => {
      await element(by.id('register-link')).tap();
      await element(by.id('register-submit-btn')).tap();
      await expect(element(by.id('register-error-message'))).toBeVisible();
    });

    it('devrait créer un compte avec des données valides', async () => {
      await element(by.id('register-link')).tap();
      await element(by.id('register-name-input')).typeText('Test Citoyen');
      await element(by.id('register-email-input')).typeText(`test.${Date.now()}@ccds.fr`);
      await element(by.id('register-password-input')).typeText('Password123!');
      await element(by.id('register-submit-btn')).tap();
      // Après inscription réussie, l'utilisateur est redirigé vers l'onboarding ou la carte
      await waitFor(element(by.id('onboarding-screen')).or(element(by.id('map-screen'))))
        .toBeVisible()
        .withTimeout(10000);
    });
  });

  // ─── Connexion ───────────────────────────────────────────────────────────────
  describe('Connexion', () => {
    it('devrait afficher une erreur avec des identifiants invalides', async () => {
      await element(by.id('login-email-input')).typeText('invalide@ccds.fr');
      await element(by.id('login-password-input')).typeText('mauvais_mdp');
      await element(by.id('login-submit-btn')).tap();
      await waitFor(element(by.id('login-error-message')))
        .toBeVisible()
        .withTimeout(5000);
    });

    it('devrait se connecter avec des identifiants valides', async () => {
      await element(by.id('login-email-input')).clearText();
      await element(by.id('login-email-input')).typeText('citoyen@ccds.fr');
      await element(by.id('login-password-input')).clearText();
      await element(by.id('login-password-input')).typeText('Password123!');
      await element(by.id('login-submit-btn')).tap();
      await waitFor(element(by.id('map-screen')))
        .toBeVisible()
        .withTimeout(10000);
    });
  });
});
