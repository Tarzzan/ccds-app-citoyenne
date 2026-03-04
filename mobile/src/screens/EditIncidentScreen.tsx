/**
 * CCDS v1.2 — Écran Édition d'un Signalement (UX-02)
 *
 * Accessible uniquement si statut = 'submitted' et propriétaire.
 * Permet de modifier : titre, description, adresse.
 */

import React, { useState, useEffect } from 'react';
import {
  View, Text, StyleSheet, TextInput, TouchableOpacity,
  ScrollView, ActivityIndicator, Alert, KeyboardAvoidingView, Platform,
} from 'react-native';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';

import { incidentsApi } from '../services/api';
import { COLORS } from '../components/ui';
import { AppStackParamList } from '../navigation/RootNavigator';

type RouteProps = RouteProp<AppStackParamList, 'EditIncident'>;
type NavProp    = NativeStackNavigationProp<AppStackParamList>;

export default function EditIncidentScreen() {
  const navigation = useNavigation<NavProp>();
  const route      = useRoute<RouteProps>();
  const { id }     = route.params;

  const [loading,   setLoading]   = useState(true);
  const [saving,    setSaving]    = useState(false);
  const [title,     setTitle]     = useState('');
  const [description, setDescription] = useState('');
  const [address,   setAddress]   = useState('');
  const [reference, setReference] = useState('');
  const [status,    setStatus]    = useState('');

  useEffect(() => {
    loadIncident();
  }, [id]);

  const loadIncident = async () => {
    setLoading(true);
    try {
      const res = await incidentsApi.get(id);
      if (res.data) {
        const inc = res.data;
        setTitle(inc.title ?? '');
        setDescription(inc.description ?? '');
        setAddress(inc.address ?? '');
        setReference(inc.reference ?? '');
        setStatus(inc.status ?? '');

        // Vérifier que le signalement est encore modifiable
        if (inc.status !== 'submitted') {
          Alert.alert(
            'Modification impossible',
            `Ce signalement ne peut plus être modifié (statut : ${inc.status}).`,
            [{ text: 'Retour', onPress: () => navigation.goBack() }]
          );
        }
      }
    } catch {
      Alert.alert('Erreur', 'Impossible de charger le signalement.');
      navigation.goBack();
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async () => {
    if (!description.trim() || description.trim().length < 10) {
      Alert.alert('Erreur', 'La description doit contenir au moins 10 caractères.');
      return;
    }

    setSaving(true);
    try {
      await incidentsApi.edit(id, {
        title:       title.trim(),
        description: description.trim(),
        address:     address.trim(),
      });

      Alert.alert(
        'Modifications enregistrées',
        'Votre signalement a été mis à jour.',
        [{ text: 'OK', onPress: () => navigation.goBack() }]
      );
    } catch (err: any) {
      const msg = err?.response?.data?.message ?? 'Impossible d\'enregistrer les modifications.';
      Alert.alert('Erreur', msg);
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </View>
    );
  }

  return (
    <KeyboardAvoidingView
      style={{ flex: 1 }}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView style={styles.container} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">

        {/* En-tête */}
        <View style={styles.header}>
          <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
            <Text style={styles.backText}>← Retour</Text>
          </TouchableOpacity>
          <Text style={styles.title}>Modifier le signalement</Text>
          <Text style={styles.reference}>{reference}</Text>
        </View>

        {/* Avertissement statut */}
        {status === 'submitted' && (
          <View style={styles.infoBox}>
            <Text style={styles.infoText}>
              ✏️ Vous pouvez modifier ce signalement tant qu'il n'a pas été pris en charge par les services.
            </Text>
          </View>
        )}

        {/* Formulaire */}
        <View style={styles.form}>
          <Text style={styles.label}>Titre (optionnel)</Text>
          <TextInput
            style={styles.input}
            value={title}
            onChangeText={setTitle}
            placeholder="Ex: Nid de poule rue Victor Hugo"
            placeholderTextColor={COLORS.gray}
            maxLength={150}
          />

          <Text style={styles.label}>Description <Text style={styles.required}>*</Text></Text>
          <TextInput
            style={[styles.input, styles.textarea]}
            value={description}
            onChangeText={setDescription}
            placeholder="Décrivez le problème en détail..."
            placeholderTextColor={COLORS.gray}
            multiline
            numberOfLines={5}
            maxLength={2000}
            textAlignVertical="top"
          />
          <Text style={styles.charCount}>{description.length} / 2000</Text>

          <Text style={styles.label}>Adresse (optionnel)</Text>
          <TextInput
            style={styles.input}
            value={address}
            onChangeText={setAddress}
            placeholder="Ex: 12 rue de la Paix, Kourou"
            placeholderTextColor={COLORS.gray}
            maxLength={255}
          />
        </View>

        {/* Boutons */}
        <TouchableOpacity
          style={[styles.saveBtn, saving && styles.saveBtnDisabled]}
          onPress={handleSave}
          disabled={saving}
          activeOpacity={0.85}
        >
          {saving
            ? <ActivityIndicator color={COLORS.white} />
            : <Text style={styles.saveBtnText}>Enregistrer les modifications</Text>
          }
        </TouchableOpacity>

        <TouchableOpacity
          style={styles.cancelBtn}
          onPress={() => navigation.goBack()}
          disabled={saving}
        >
          <Text style={styles.cancelBtnText}>Annuler</Text>
        </TouchableOpacity>

      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f8fafc' },
  content:   { padding: 20 },
  centered:  { flex: 1, justifyContent: 'center', alignItems: 'center' },

  header: { marginBottom: 20 },
  backBtn: { marginBottom: 12 },
  backText: { color: COLORS.primary, fontSize: 15, fontWeight: '600' },
  title:    { fontSize: 22, fontWeight: '800', color: COLORS.dark, marginBottom: 4 },
  reference:{ fontSize: 13, color: COLORS.gray },

  infoBox: {
    backgroundColor: '#eff6ff',
    borderRadius: 12,
    padding: 14,
    marginBottom: 20,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.primary,
  },
  infoText: { fontSize: 13, color: '#1e40af', lineHeight: 20 },

  form: { marginBottom: 20 },
  label: { fontSize: 13, fontWeight: '700', color: COLORS.dark, marginBottom: 6, marginTop: 16 },
  required: { color: '#ef4444' },
  input: {
    backgroundColor: COLORS.white,
    borderRadius: 12,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.dark,
  },
  textarea: { height: 120, paddingTop: 12 },
  charCount: { fontSize: 11, color: COLORS.gray, textAlign: 'right', marginTop: 4 },

  saveBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: 14,
    paddingVertical: 16,
    alignItems: 'center',
    marginBottom: 12,
    shadowColor: COLORS.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.3,
    shadowRadius: 10,
    elevation: 5,
  },
  saveBtnDisabled: { opacity: 0.6 },
  saveBtnText: { color: COLORS.white, fontSize: 16, fontWeight: '700' },

  cancelBtn: {
    borderRadius: 14,
    paddingVertical: 14,
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: COLORS.border,
  },
  cancelBtnText: { color: COLORS.gray, fontSize: 15, fontWeight: '600' },
});
