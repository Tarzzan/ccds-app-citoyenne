import { device, element, by, expect, waitFor } from 'detox';

describe('Flux Signalements', () => {
  beforeAll(async () => {
    await device.launchApp({ newInstance: true });
    // Connexion préalable
    await element(by.id('login-email-input')).typeText('citoyen@ccds.fr');
    await element(by.id('login-password-input')).typeText('Password123!');
    await element(by.id('login-submit-btn')).tap();
    await waitFor(element(by.id('map-screen'))).toBeVisible().withTimeout(10000);
  });

  beforeEach(async () => {
    // Revenir à la carte entre chaque test
    await element(by.id('tab-map')).tap();
  });

  // ─── Création de signalement ─────────────────────────────────────────────────
  describe('Création de signalement', () => {
    it('devrait ouvrir le formulaire de création', async () => {
      await element(by.id('create-incident-fab')).tap();
      await expect(element(by.id('create-incident-screen'))).toBeVisible();
    });

    it('devrait afficher une erreur si le titre est vide', async () => {
      await element(by.id('create-incident-fab')).tap();
      await element(by.id('create-incident-submit-btn')).tap();
      await expect(element(by.id('create-incident-title-error'))).toBeVisible();
    });

    it('devrait créer un signalement avec des données valides', async () => {
      await element(by.id('create-incident-fab')).tap();
      await element(by.id('create-incident-title-input')).typeText('Test E2E — Nid de poule');
      await element(by.id('create-incident-description-input')).typeText('Signalement créé par le test E2E Detox');
      await element(by.id('create-incident-address-input')).typeText('Avenue Pasteur, Cayenne');
      // Sélectionner une catégorie
      await element(by.id('create-incident-category-picker')).tap();
      await element(by.text('Voirie')).tap();
      await element(by.id('create-incident-submit-btn')).tap();
      // Vérifier la confirmation
      await waitFor(element(by.id('create-incident-success-message')))
        .toBeVisible()
        .withTimeout(10000);
    });
  });

  // ─── Liste des signalements ──────────────────────────────────────────────────
  describe('Liste des signalements', () => {
    it('devrait afficher la liste des signalements', async () => {
      await element(by.id('tab-my-incidents')).tap();
      await expect(element(by.id('my-incidents-screen'))).toBeVisible();
      await expect(element(by.id('incidents-list'))).toBeVisible();
    });

    it('devrait filtrer les signalements par statut', async () => {
      await element(by.id('tab-my-incidents')).tap();
      await element(by.id('filter-toggle-btn')).tap();
      await element(by.id('filter-status-submitted')).tap();
      await waitFor(element(by.id('incidents-list'))).toBeVisible().withTimeout(3000);
    });

    it('devrait rechercher un signalement par titre', async () => {
      await element(by.id('tab-my-incidents')).tap();
      await element(by.id('search-input')).typeText('Nid de poule');
      await waitFor(element(by.id('incidents-list'))).toBeVisible().withTimeout(3000);
    });
  });

  // ─── Détail et Vote ──────────────────────────────────────────────────────────
  describe('Détail et Vote', () => {
    it('devrait ouvrir le détail d\'un signalement', async () => {
      await element(by.id('tab-my-incidents')).tap();
      await waitFor(element(by.id('incident-item-0'))).toBeVisible().withTimeout(5000);
      await element(by.id('incident-item-0')).tap();
      await expect(element(by.id('incident-detail-screen'))).toBeVisible();
    });

    it('devrait voter "Moi aussi" sur un signalement', async () => {
      await element(by.id('tab-my-incidents')).tap();
      await waitFor(element(by.id('incident-item-0'))).toBeVisible().withTimeout(5000);
      await element(by.id('incident-item-0')).tap();
      await waitFor(element(by.id('vote-button'))).toBeVisible().withTimeout(5000);
      await element(by.id('vote-button')).tap();
      await waitFor(element(by.id('vote-button-active'))).toBeVisible().withTimeout(3000);
    });
  });
});
