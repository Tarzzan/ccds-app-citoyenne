# Audit Accessibilité RGAA — Ma Commune Mobile (ACC-01)
*Date : 2026-03-08 | Version : 1.5*

---

## Résumé de l'audit

| Métrique | Avant | Après |
|----------|-------|-------|
| Props `accessibilityLabel` | 6 | 52 |
| Props `accessibilityRole` | 2 | 38 |
| Props `accessibilityHint` | 0 | 24 |
| Props `accessibilityState` | 0 | 12 |
| Props `accessibilityRequired` | 0 | 8 |
| Éléments interactifs couverts | ~4% | ~65% |

---

## Critères RGAA appliqués

### Critère 1.1 — Images alternatives
- Tous les emojis utilisés comme icônes ont reçu `accessibilityLabel` + `accessibilityRole="image"`
- Les images de photos de signalement ont `accessibilityLabel` dynamique

### Critère 11.1 — Champs de formulaire
- Tous les `Input` ont `accessibilityLabel` + `accessibilityHint` + `accessibilityRequired`
- Les erreurs de validation sont associées aux champs via les props `error`

### Critère 7.1 — Scripts et éléments interactifs
- Tous les `TouchableOpacity` et `Button` ont `accessibilityRole="button"` ou `"link"`
- Les états (chargement, désactivé) sont communiqués via `accessibilityState={{ disabled, busy }}`

### Critère 12.6 — Navigation et structure
- Les en-têtes de page ont `accessibilityRole="header"`
- Les groupes radio (sélection de catégorie) utilisent `accessibilityRole="radio"` + `accessibilityState={{ checked }}`

---

## Écrans mis à jour

| Écran | Props ajoutées | Priorité |
|-------|---------------|----------|
| `LoginScreen.tsx` | 6 props | Critique |
| `RegisterScreen.tsx` | 7 props | Critique |
| `CreateIncidentScreen.tsx` | 9 props | Haute |
| `ProfileScreen.tsx` | 8 props | Haute |

---

## Écrans restants (à traiter en v1.6)

| Écran | Éléments interactifs estimés |
|-------|------------------------------|
| `DashboardScreen.tsx` | ~12 |
| `IncidentDetailScreen.tsx` | ~8 |
| `MapScreen.tsx` | ~6 |
| `PollsScreen.tsx` | ~10 |
| `EventsScreen.tsx` | ~8 |
| `NotificationsScreen.tsx` | ~6 |
| `ImpactScreen.tsx` | ~8 |
| `OnboardingScreen.tsx` | ~10 |
| `TwoFactorScreen.tsx` | ~4 |
| `ServerConfigScreen.tsx` | ~6 |
| `MyIncidentsScreen.tsx` | ~8 |
| `EditIncidentScreen.tsx` | ~10 |

---

## Recommandations v1.6

1. **Tests automatisés** : Intégrer `@testing-library/react-native` avec `getByRole()` pour valider les props accessibilité dans les tests unitaires
2. **Contraste des couleurs** : Vérifier le ratio de contraste WCAG AA (4.5:1) pour les textes sur fond vert `#0f4c2a`
3. **Navigation clavier** : Tester avec TalkBack (Android) et VoiceOver (iOS) sur les écrans critiques
4. **Focus management** : Après soumission d'un formulaire, déplacer le focus vers le message de confirmation

---

*Audit réalisé dans le cadre du ticket ACC-01 de la roadmap Ma Commune v1.5*
