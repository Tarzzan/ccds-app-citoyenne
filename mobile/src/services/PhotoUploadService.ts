import { PhotoItem } from '../components/PhotoPicker';
import { getAuthToken, API_BASE_URL } from './api';

/**
 * PhotoUploadService — Upload de photos multiples pour un incident (UX-04)
 *
 * Stratégie :
 *   1. Upload séquentiel pour éviter la saturation réseau sur mobile
 *   2. Retry automatique (2 tentatives) par photo
 *   3. Rapport de progression via callback onProgress
 */

export interface UploadResult {
  success: boolean;
  uploaded: number;
  failed: number;
  photoIds: number[];
  errors: string[];
}

export interface UploadProgress {
  current: number;
  total: number;
  percent: number;
  currentFileName: string;
}

export async function uploadPhotos(
  incidentId: number,
  photos: PhotoItem[],
  onProgress?: (progress: UploadProgress) => void
): Promise<UploadResult> {
  const result: UploadResult = {
    success: false,
    uploaded: 0,
    failed: 0,
    photoIds: [],
    errors: [],
  };

  const newPhotos = photos.filter(p => !p.isExisting);
  if (newPhotos.length === 0) {
    result.success = true;
    return result;
  }

  for (let i = 0; i < newPhotos.length; i++) {
    const photo = newPhotos[i];
    const fileName = photo.fileName ?? `photo_${i + 1}.jpg`;

    onProgress?.({
      current: i + 1,
      total: newPhotos.length,
      percent: Math.round(((i + 1) / newPhotos.length) * 100),
      currentFileName: fileName,
    });

    let uploaded = false;
    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        const photoId = await uploadSinglePhoto(incidentId, photo, i);
        result.photoIds.push(photoId);
        result.uploaded++;
        uploaded = true;
        break;
      } catch (err: any) {
        if (attempt === 2) {
          result.failed++;
          result.errors.push(`${fileName}: ${err.message ?? 'Erreur inconnue'}`);
        }
        // Attendre 500ms avant le retry
        await new Promise(r => setTimeout(r, 500));
      }
    }
  }

  result.success = result.failed === 0;
  return result;
}

async function uploadSinglePhoto(
  incidentId: number,
  photo: PhotoItem,
  sortOrder: number
): Promise<number> {
  const token = await getAuthToken();
  const formData = new FormData();

  // React Native FormData accepte un objet { uri, name, type }
  formData.append('photo', {
    uri: photo.uri,
    name: photo.fileName ?? `photo_${sortOrder + 1}.jpg`,
    type: photo.mimeType ?? 'image/jpeg',
  } as any);
  formData.append('sort_order', String(sortOrder));

  const response = await fetch(`${API_BASE_URL}/incidents/${incidentId}/photos`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      // Ne pas définir Content-Type — fetch le fait automatiquement avec boundary
    },
    body: formData,
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new Error(body.message ?? `HTTP ${response.status}`);
  }

  const data = await response.json();
  return data.photo_id as number;
}

/**
 * Supprimer une photo côté serveur
 */
export async function deletePhoto(incidentId: number, photoId: number): Promise<void> {
  const token = await getAuthToken();
  const response = await fetch(`${API_BASE_URL}/incidents/${incidentId}/photos/${photoId}`, {
    method: 'DELETE',
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new Error(body.message ?? `HTTP ${response.status}`);
  }
}

/**
 * Récupérer les photos existantes d'un incident
 */
export async function getIncidentPhotos(incidentId: number): Promise<PhotoItem[]> {
  const token = await getAuthToken();
  const response = await fetch(`${API_BASE_URL}/incidents/${incidentId}/photos`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) return [];

  const data = await response.json();
  return (data.photos ?? []).map((p: any) => ({
    uri: p.url,
    fileName: p.file_name,
    mimeType: p.mime_type,
    isExisting: true,
    serverId: p.id,
  }));
}
