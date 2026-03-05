import React, { useState, useRef, useCallback } from 'react';
import {
  View, TextInput, Text, FlatList, TouchableOpacity,
  StyleSheet, ViewStyle,
} from 'react-native';
import { useTheme } from '../theme/ThemeContext';
import { authApi, User } from '../services/api';

interface MentionInputProps {
  value: string;
  onChangeText: (text: string, mentions: number[]) => void;
  placeholder?: string;
  style?: ViewStyle;
}

/**
 * MentionInput — Champ de texte avec autocomplétion des mentions @utilisateur (UX-11)
 *
 * Détecte automatiquement les patterns "@mot" et affiche une liste
 * de suggestions d'utilisateurs. Lorsqu'un utilisateur est sélectionné,
 * son nom est inséré dans le texte et son ID est ajouté à la liste des mentions.
 */
export default function MentionInput({
  value, onChangeText, placeholder, style,
}: MentionInputProps) {
  const { theme }                   = useTheme();
  const [suggestions, setSuggestions] = useState<User[]>([]);
  const [mentionQuery, setMentionQuery] = useState<string | null>(null);
  const [mentionStart, setMentionStart] = useState(0);
  const [mentions, setMentions]       = useState<number[]>([]);
  const inputRef                      = useRef<TextInput>(null);

  // Détecter si le curseur est dans un pattern @xxx
  const handleTextChange = useCallback(async (text: string) => {
    // Chercher le dernier @ avant le curseur
    const atIndex = text.lastIndexOf('@');
    if (atIndex !== -1) {
      const query = text.slice(atIndex + 1);
      // Pas d'espace dans la requête = on est en train de taper une mention
      if (!query.includes(' ') && query.length > 0) {
        setMentionQuery(query);
        setMentionStart(atIndex);
        try {
          const users = await authApi.searchUsers(query);
          setSuggestions([...users.slice(0, 5)]);
        } catch {
          setSuggestions([]);
        }
      } else {
        setSuggestions([]);
        setMentionQuery(null);
      }
    } else {
      setSuggestions([]);
      setMentionQuery(null);
    }

    onChangeText(text, mentions);
  }, [mentions, onChangeText]);

  const selectMention = useCallback((user: User) => {
    if (mentionQuery === null) return;

    // Remplacer @query par @NomUtilisateur
    const before = value.slice(0, mentionStart);
    const after  = value.slice(mentionStart + 1 + mentionQuery.length);
    const newText = `${before}@${user.full_name} ${after}`;

    const newMentions = [...new Set([...mentions, user.id])];
    setMentions(newMentions);
    setSuggestions([]);
    setMentionQuery(null);
    onChangeText(newText, newMentions);
  }, [mentionQuery, mentionStart, value, mentions, onChangeText]);

  // Rendu du texte avec les mentions colorées (pour l'aperçu)
  const renderHighlightedText = () => {
    const parts = value.split(/(@\w[\w\s]*)/g);
    return (
      <Text style={{ fontSize: 14 }}>
        {parts.map((part, i) =>
          part.startsWith('@') ? (
            <Text key={i} style={{ color: theme.primary, fontWeight: '700' }}>{part}</Text>
          ) : (
            <Text key={i} style={{ color: theme.textPrimary }}>{part}</Text>
          )
        )}
      </Text>
    );
  };

  return (
    <View style={style}>
      <TextInput
        ref={inputRef}
        value={value}
        onChangeText={handleTextChange}
        placeholder={placeholder ?? 'Ajouter un commentaire... (@mention)'}
        placeholderTextColor={theme.textSecondary}
        multiline
        style={[
          styles.input,
          {
            backgroundColor: theme.surface,
            color: theme.textPrimary,
            borderColor: theme.border,
          },
        ]}
        accessibilityLabel="Champ de commentaire avec mentions"
        accessibilityHint="Tapez @ suivi d'un nom pour mentionner un utilisateur"
      />

      {/* Liste de suggestions */}
      {suggestions.length > 0 && (
        <View style={[styles.suggestionsContainer, { backgroundColor: theme.surface, borderColor: theme.border }]}>
          <FlatList
            data={suggestions}
            keyExtractor={(item) => String(item.id)}
            keyboardShouldPersistTaps="always"
            renderItem={({ item }) => (
              <TouchableOpacity
                style={[styles.suggestionItem, { borderBottomColor: theme.border }]}
                onPress={() => selectMention(item)}
                accessibilityRole="button"
                accessibilityLabel={`Mentionner ${item.full_name}`}
              >
                <View style={[styles.avatar, { backgroundColor: theme.primary }]}>
                  <Text style={styles.avatarText}>
                    {item.full_name.charAt(0).toUpperCase()}
                  </Text>
                </View>
                <View>
                  <Text style={[styles.userName, { color: theme.textPrimary }]}>{item.full_name}</Text>
                  <Text style={[styles.userRole, { color: theme.textSecondary }]}>
                    {item.role === 'agent' ? '🏛️ Agent' : '👤 Citoyen'}
                  </Text>
                </View>
              </TouchableOpacity>
            )}
          />
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  input: {
    borderWidth: 1,
    borderRadius: 10,
    padding: 12,
    fontSize: 14,
    minHeight: 80,
    textAlignVertical: 'top',
  },
  suggestionsContainer: {
    borderWidth: 1,
    borderRadius: 10,
    marginTop: 4,
    maxHeight: 200,
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 8,
    elevation: 4,
  },
  suggestionItem: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 12,
    borderBottomWidth: 1,
    gap: 10,
  },
  avatar: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText:  { color: '#fff', fontWeight: '700', fontSize: 15 },
  userName:    { fontSize: 14, fontWeight: '600' },
  userRole:    { fontSize: 11, marginTop: 1 },
});
