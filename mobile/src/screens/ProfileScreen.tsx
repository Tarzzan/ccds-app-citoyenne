/**
 * CCDS v1.2 — Écran Mon Profil (UX-03)
 *
 * - Affichage et modification du nom, téléphone
 * - Changement de mot de passe
 * - Préférences de notifications push
 */

import React, { useState, useEffect } from 'react';
import {
  View, Text, StyleSheet, TextInput, TouchableOpacity,
  ScrollView, ActivityIndicator, Alert, Switch,
  KeyboardAvoidingView, Platform,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';

import { authApi } from '../services/api';
import { useAuth } from '../services/AuthContext';
import { COLORS } from '../components/ui';

export default function ProfileScreen() {
  const navigation = useNavigation();
  const { user, logout } = useAuth();

  const [loading,    setLoading]    = useState(true);
  const [saving,     setSaving]     = useState(false);
  const [savingPwd,  setSavingPwd]  = useState(false);
  const [activeTab,  setActiveTab]  = useState<'profile' | 'password' | 'notifications'>('profile');

  // Champs profil
  const [fullName, setFullName] = useState('');
  const [phone,    setPhone]    = useState('');
  const [email,    setEmail]    = useState('');
  const [role,     setRole]     = useState('');

  // Champs mot de passe
  const [currentPwd, setCurrentPwd] = useState('');
  const [newPwd,     setNewPwd]     = useState('');
  const [confirmPwd, setConfirmPwd] = useState('');

  // Préférences notifications
  const [notifStatusChange,   setNotifStatusChange]   = useState(true);
  const [notifNewComment,     setNotifNewComment]     = useState(true);
  const [notifVoteMilestone,  setNotifVoteMilestone]  = useState(false);

  useEffect(() => {
    loadProfile();
  }, []);

  const loadProfile = async () => {
    setLoading(true);
    try {
      const res = await authApi.getProfile();
      if (res.data) {
        setFullName(res.data.full_name ?? '');
        setPhone(res.data.phone ?? '');
        setEmail(res.data.email ?? '');
        setRole(res.data.role ?? 'citizen');
        const prefs = res.data.notification_preferences ?? {};
        setNotifStatusChange(prefs.status_change ?? true);
        setNotifNewComment(prefs.new_comment ?? true);
        setNotifVoteMilestone(prefs.vote_milestone ?? false);
      }
    } catch {
      Alert.alert('Erreur', 'Impossible de charger votre profil.');
    } finally {
      setLoading(false);
    }
  };

  const handleSaveProfile = async () => {
    if (!fullName.trim() || fullName.trim().length < 2) {
      Alert.alert('Erreur', 'Le nom doit contenir au moins 2 caractères.');
      return;
    }
    setSaving(true);
    try {
      await authApi.updateProfile({
        full_name: fullName.trim(),
        phone:     phone.trim(),
        notification_preferences: {
          status_change:   notifStatusChange,
          new_comment:     notifNewComment,
          vote_milestone:  notifVoteMilestone,
        },
      });
      Alert.alert('Succès', 'Profil mis à jour.');
    } catch (err: any) {
      Alert.alert('Erreur', err?.response?.data?.message ?? 'Impossible de mettre à jour le profil.');
    } finally {
      setSaving(false);
    }
  };

  const handleChangePassword = async () => {
    if (!currentPwd || !newPwd || !confirmPwd) {
      Alert.alert('Erreur', 'Veuillez remplir tous les champs.');
      return;
    }
    if (newPwd.length < 8) {
      Alert.alert('Erreur', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
      return;
    }
    if (newPwd !== confirmPwd) {
      Alert.alert('Erreur', 'Les mots de passe ne correspondent pas.');
      return;
    }
    setSavingPwd(true);
    try {
      await authApi.changePassword({ current_password: currentPwd, new_password: newPwd });
      Alert.alert('Succès', 'Mot de passe modifié. Veuillez vous reconnecter.', [
        { text: 'OK', onPress: logout },
      ]);
    } catch (err: any) {
      Alert.alert('Erreur', err?.response?.data?.message ?? 'Mot de passe actuel incorrect.');
    } finally {
      setSavingPwd(false);
      setCurrentPwd('');
      setNewPwd('');
      setConfirmPwd('');
    }
  };

  const ROLE_LABELS: Record<string, string> = {
    citizen: 'Citoyen',
    agent:   'Agent municipal',
    admin:   'Administrateur',
  };

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </View>
    );
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView style={styles.container} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">

        {/* Avatar et infos */}
        <View style={styles.avatarSection}>
          <View style={styles.avatar}>
            <Text style={styles.avatarText}>{fullName.charAt(0).toUpperCase() || '?'}</Text>
          </View>
          <Text style={styles.nameText}>{fullName}</Text>
          <Text style={styles.emailText}>{email}</Text>
          <View style={styles.roleBadge}>
            <Text style={styles.roleText}>{ROLE_LABELS[role] ?? role}</Text>
          </View>
        </View>

        {/* Onglets */}
        <View style={styles.tabs}>
          {(['profile', 'password', 'notifications'] as const).map(tab => (
            <TouchableOpacity
              key={tab}
              style={[styles.tab, activeTab === tab && styles.tabActive]}
              onPress={() => setActiveTab(tab)}
            >
              <Text style={[styles.tabText, activeTab === tab && styles.tabTextActive]}>
                {tab === 'profile' ? '👤 Profil' : tab === 'password' ? '🔒 Mot de passe' : '🔔 Notifications'}
              </Text>
            </TouchableOpacity>
          ))}
        </View>

        {/* --- Onglet Profil --- */}
        {activeTab === 'profile' && (
          <View style={styles.section}>
            <Text style={styles.label}>Nom complet <Text style={styles.required}>*</Text></Text>
            <TextInput
              style={styles.input}
              value={fullName}
              onChangeText={setFullName}
              placeholder="Votre nom complet"
              placeholderTextColor={COLORS.gray}
              maxLength={255}
            />

            <Text style={styles.label}>Téléphone</Text>
            <TextInput
              style={styles.input}
              value={phone}
              onChangeText={setPhone}
              placeholder="+594 6 94 XX XX XX"
              placeholderTextColor={COLORS.gray}
              keyboardType="phone-pad"
              maxLength={20}
            />

            <Text style={styles.label}>Email</Text>
            <View style={[styles.input, styles.inputDisabled]}>
              <Text style={styles.inputDisabledText}>{email}</Text>
            </View>
            <Text style={styles.hint}>L'adresse email ne peut pas être modifiée.</Text>

            <TouchableOpacity
              style={[styles.primaryBtn, saving && styles.btnDisabled]}
              onPress={handleSaveProfile}
              disabled={saving}
            >
              {saving
                ? <ActivityIndicator color={COLORS.white} />
                : <Text style={styles.primaryBtnText}>Enregistrer</Text>
              }
            </TouchableOpacity>
          </View>
        )}

        {/* --- Onglet Mot de passe --- */}
        {activeTab === 'password' && (
          <View style={styles.section}>
            <Text style={styles.label}>Mot de passe actuel</Text>
            <TextInput
              style={styles.input}
              value={currentPwd}
              onChangeText={setCurrentPwd}
              placeholder="••••••••"
              placeholderTextColor={COLORS.gray}
              secureTextEntry
            />

            <Text style={styles.label}>Nouveau mot de passe</Text>
            <TextInput
              style={styles.input}
              value={newPwd}
              onChangeText={setNewPwd}
              placeholder="Minimum 8 caractères"
              placeholderTextColor={COLORS.gray}
              secureTextEntry
            />

            <Text style={styles.label}>Confirmer le nouveau mot de passe</Text>
            <TextInput
              style={[styles.input, confirmPwd && newPwd !== confirmPwd && styles.inputError]}
              value={confirmPwd}
              onChangeText={setConfirmPwd}
              placeholder="Répétez le nouveau mot de passe"
              placeholderTextColor={COLORS.gray}
              secureTextEntry
            />
            {confirmPwd && newPwd !== confirmPwd && (
              <Text style={styles.errorText}>Les mots de passe ne correspondent pas.</Text>
            )}

            <TouchableOpacity
              style={[styles.primaryBtn, savingPwd && styles.btnDisabled]}
              onPress={handleChangePassword}
              disabled={savingPwd}
            >
              {savingPwd
                ? <ActivityIndicator color={COLORS.white} />
                : <Text style={styles.primaryBtnText}>Changer le mot de passe</Text>
              }
            </TouchableOpacity>
          </View>
        )}

        {/* --- Onglet Notifications --- */}
        {activeTab === 'notifications' && (
          <View style={styles.section}>
            <Text style={styles.sectionDesc}>
              Choisissez les événements pour lesquels vous souhaitez recevoir une notification push.
            </Text>

            <View style={styles.switchRow}>
              <View style={styles.switchInfo}>
                <Text style={styles.switchLabel}>Changement de statut</Text>
                <Text style={styles.switchDesc}>Quand votre signalement est pris en charge ou résolu</Text>
              </View>
              <Switch
                value={notifStatusChange}
                onValueChange={setNotifStatusChange}
                trackColor={{ false: COLORS.border, true: COLORS.primary }}
                thumbColor={COLORS.white}
              />
            </View>

            <View style={styles.divider} />

            <View style={styles.switchRow}>
              <View style={styles.switchInfo}>
                <Text style={styles.switchLabel}>Nouveau commentaire</Text>
                <Text style={styles.switchDesc}>Quand un agent commente votre signalement</Text>
              </View>
              <Switch
                value={notifNewComment}
                onValueChange={setNotifNewComment}
                trackColor={{ false: COLORS.border, true: COLORS.primary }}
                thumbColor={COLORS.white}
              />
            </View>

            <View style={styles.divider} />

            <View style={styles.switchRow}>
              <View style={styles.switchInfo}>
                <Text style={styles.switchLabel}>Palier de votes</Text>
                <Text style={styles.switchDesc}>Quand votre signalement atteint 10, 50 ou 100 votes</Text>
              </View>
              <Switch
                value={notifVoteMilestone}
                onValueChange={setNotifVoteMilestone}
                trackColor={{ false: COLORS.border, true: COLORS.primary }}
                thumbColor={COLORS.white}
              />
            </View>

            <TouchableOpacity
              style={[styles.primaryBtn, { marginTop: 24 }, saving && styles.btnDisabled]}
              onPress={handleSaveProfile}
              disabled={saving}
            >
              {saving
                ? <ActivityIndicator color={COLORS.white} />
                : <Text style={styles.primaryBtnText}>Enregistrer les préférences</Text>
              }
            </TouchableOpacity>
          </View>
        )}

        {/* Déconnexion */}
        <TouchableOpacity
          style={styles.logoutBtn}
          onPress={() => Alert.alert('Déconnexion', 'Voulez-vous vous déconnecter ?', [
            { text: 'Annuler', style: 'cancel' },
            { text: 'Déconnecter', style: 'destructive', onPress: logout },
          ])}
        >
          <Text style={styles.logoutText}>🚪 Se déconnecter</Text>
        </TouchableOpacity>

      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f8fafc' },
  content:   { padding: 20, paddingBottom: 40 },
  centered:  { flex: 1, justifyContent: 'center', alignItems: 'center' },

  avatarSection: { alignItems: 'center', marginBottom: 24 },
  avatar: {
    width: 80, height: 80, borderRadius: 40,
    backgroundColor: COLORS.primary,
    justifyContent: 'center', alignItems: 'center',
    marginBottom: 12,
  },
  avatarText: { fontSize: 32, fontWeight: '800', color: COLORS.white },
  nameText:   { fontSize: 20, fontWeight: '800', color: COLORS.dark },
  emailText:  { fontSize: 13, color: COLORS.gray, marginTop: 2 },
  roleBadge:  {
    marginTop: 8, paddingHorizontal: 14, paddingVertical: 4,
    backgroundColor: '#dcfce7', borderRadius: 20,
  },
  roleText: { fontSize: 12, fontWeight: '700', color: '#166534' },

  tabs: { flexDirection: 'row', backgroundColor: COLORS.white, borderRadius: 12, padding: 4, marginBottom: 20 },
  tab: { flex: 1, paddingVertical: 10, alignItems: 'center', borderRadius: 10 },
  tabActive: { backgroundColor: COLORS.primary },
  tabText: { fontSize: 11, fontWeight: '600', color: COLORS.gray },
  tabTextActive: { color: COLORS.white },

  section: { backgroundColor: COLORS.white, borderRadius: 16, padding: 20, marginBottom: 16 },
  sectionDesc: { fontSize: 13, color: COLORS.gray, marginBottom: 16, lineHeight: 20 },

  label:    { fontSize: 13, fontWeight: '700', color: COLORS.dark, marginBottom: 6, marginTop: 14 },
  required: { color: '#ef4444' },
  input: {
    backgroundColor: '#f8fafc',
    borderRadius: 12,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.dark,
  },
  inputDisabled: { justifyContent: 'center' },
  inputDisabledText: { fontSize: 15, color: COLORS.gray },
  inputError: { borderColor: '#ef4444' },
  errorText: { fontSize: 12, color: '#ef4444', marginTop: 4 },
  hint: { fontSize: 11, color: COLORS.gray, marginTop: 4 },

  primaryBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: 14,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 20,
  },
  btnDisabled: { opacity: 0.6 },
  primaryBtnText: { color: COLORS.white, fontSize: 15, fontWeight: '700' },

  switchRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 8 },
  switchInfo: { flex: 1, marginRight: 16 },
  switchLabel: { fontSize: 15, fontWeight: '600', color: COLORS.dark },
  switchDesc:  { fontSize: 12, color: COLORS.gray, marginTop: 2 },
  divider: { height: 1, backgroundColor: COLORS.border, marginVertical: 4 },

  logoutBtn: {
    borderRadius: 14,
    paddingVertical: 14,
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: '#fca5a5',
    backgroundColor: '#fff5f5',
    marginTop: 8,
  },
  logoutText: { color: '#ef4444', fontSize: 15, fontWeight: '700' },
});
