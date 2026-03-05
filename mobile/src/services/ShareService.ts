import { Share, Platform } from 'react-native';
import * as Sharing from 'expo-sharing';
import * as FileSystem from 'expo-file-system/legacy';

/**
 * ShareService — Partage d'un incident sur les réseaux sociaux (UX-06)
 *
 * Stratégie :
 *   - iOS / Android : Share.share() natif (WhatsApp, SMS, Email, etc.)
 *   - Si une image est disponible : téléchargement local + expo-sharing
 */

export interface ShareableIncident {
  id: number;
  reference: string;
  title: string;
  address?: string;
  status: string;
  votes_count: number;
  photo_url?: string;
}

const APP_URL = 'https://ccds.guyane.fr';

const STATUS_LABELS: Record<string, string> = {
  submitted:   'Soumis',
  in_progress: 'En cours de traitement',
  resolved:    'Résolu',
  rejected:    'Rejeté',
};

/**
 * Partager un incident via la feuille de partage native.
 * Si une photo est disponible, elle est téléchargée et incluse.
 */
export async function shareIncident(incident: ShareableIncident): Promise<void> {
  const statusLabel = STATUS_LABELS[incident.status] ?? incident.status;
  const addressLine = incident.address ? `📍 ${incident.address}\n` : '';
  const votesLine   = incident.votes_count > 0 ? `👍 ${incident.votes_count} citoyen${incident.votes_count > 1 ? 's' : ''} concerné${incident.votes_count > 1 ? 's' : ''}\n` : '';

  const message = [
    `🌿 CCDS Citoyen — Signalement`,
    ``,
    `${incident.title}`,
    ``,
    `${addressLine}${votesLine}📋 Statut : ${statusLabel}`,
    `🔖 Réf. : ${incident.reference}`,
    ``,
    `Signalez aussi les problèmes de votre quartier sur l'application CCDS Citoyen.`,
    `${APP_URL}`,
  ].join('\n');

  // Tenter le partage avec image si disponible
  if (incident.photo_url && (await Sharing.isAvailableAsync())) {
    try {
      const localUri = await downloadPhotoForSharing(incident.photo_url, incident.reference);
      if (localUri) {
        await Sharing.shareAsync(localUri, {
          mimeType: 'image/jpeg',
          dialogTitle: `Partager : ${incident.title}`,
          UTI: 'public.jpeg',
        });
        // Nettoyer le fichier temporaire
        await FileSystem.deleteAsync(localUri, { idempotent: true });
        return;
      }
    } catch {
      // Fallback vers le partage texte
    }
  }

  // Partage texte natif
  await Share.share(
    {
      title:   `Signalement CCDS — ${incident.reference}`,
      message: Platform.OS === 'ios' ? message : message,
      url:     Platform.OS === 'ios' ? `${APP_URL}/incidents/${incident.id}` : undefined,
    },
    {
      dialogTitle:   `Partager : ${incident.title}`,
      subject:       `Signalement CCDS — ${incident.reference}`,
      tintColor:     '#2563eb',
    }
  );
}

/**
 * Télécharger une photo dans le cache local pour le partage.
 * Retourne l'URI locale ou null en cas d'échec.
 */
async function downloadPhotoForSharing(url: string, reference: string): Promise<string | null> {
  try {
    const fileName = `ccds_share_${reference.replace(/[^a-z0-9]/gi, '_')}.jpg`;
    const localUri = FileSystem.cacheDirectory + fileName;

    const info = await FileSystem.getInfoAsync(localUri);
    if (info.exists) return localUri; // Déjà en cache

    const result = await FileSystem.downloadAsync(url, localUri);
    return result.status === 200 ? result.uri : null;
  } catch {
    return null;
  }
}

/**
 * Générer un lien de partage court pour un incident.
 */
export function getShareUrl(incidentId: number): string {
  return `${APP_URL}/incidents/${incidentId}`;
}
