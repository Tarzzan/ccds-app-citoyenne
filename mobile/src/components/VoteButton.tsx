import React, { useState, useRef } from 'react';
import {
  TouchableOpacity,
  Text,
  StyleSheet,
  Animated,
  ActivityIndicator,
  View,
} from 'react-native';
import { COLORS } from './ui';
import { voteForIncident, removeVote } from '../services/api';

interface VoteButtonProps {
  incidentId: number;
  initialVotesCount: number;
  initialHasVoted: boolean;
  onVoteChange?: (newCount: number, hasVoted: boolean) => void;
}

export const VoteButton: React.FC<VoteButtonProps> = ({
  incidentId,
  initialVotesCount,
  initialHasVoted,
  onVoteChange,
}) => {
  const [votesCount, setVotesCount] = useState(initialVotesCount);
  const [hasVoted, setHasVoted]     = useState(initialHasVoted);
  const [loading, setLoading]       = useState(false);

  // Animation de rebond au vote
  const scaleAnim = useRef(new Animated.Value(1)).current;

  const animateBounce = () => {
    Animated.sequence([
      Animated.timing(scaleAnim, { toValue: 1.3, duration: 120, useNativeDriver: true }),
      Animated.spring(scaleAnim,  { toValue: 1,   useNativeDriver: true }),
    ]).start();
  };

  const handlePress = async () => {
    if (loading) return;
    setLoading(true);

    try {
      if (hasVoted) {
        const result = await removeVote(incidentId);
        setVotesCount(result.votes_count);
        setHasVoted(false);
        onVoteChange?.(result.votes_count, false);
      } else {
        const result = await voteForIncident(incidentId);
        setVotesCount(result.votes_count);
        setHasVoted(true);
        animateBounce();
        onVoteChange?.(result.votes_count, true);
      }
    } catch (error) {
      // Silently fail — le vote sera réessayé
    } finally {
      setLoading(false);
    }
  };

  return (
    <TouchableOpacity
      onPress={handlePress}
      disabled={loading}
      activeOpacity={0.8}
      style={[styles.container, hasVoted && styles.containerVoted]}
    >
      <Animated.View style={[styles.inner, { transform: [{ scale: scaleAnim }] }]}>
        {loading ? (
          <ActivityIndicator size="small" color={hasVoted ? '#fff' : COLORS.primary} />
        ) : (
          <>
            <Text style={[styles.emoji]}>{hasVoted ? '👍' : '👋'}</Text>
            <View style={styles.textContainer}>
              <Text style={[styles.label, hasVoted && styles.labelVoted]}>
                {hasVoted ? 'Moi aussi !' : 'Moi aussi'}
              </Text>
              <Text style={[styles.count, hasVoted && styles.countVoted]}>
                {votesCount} {votesCount <= 1 ? 'personne' : 'personnes'} concernée{votesCount > 1 ? 's' : ''}
              </Text>
            </View>
          </>
        )}
      </Animated.View>
    </TouchableOpacity>
  );
};

const styles = StyleSheet.create({
  container: {
    borderRadius: 12,
    borderWidth: 2,
    borderColor: COLORS.primary,
    backgroundColor: '#fff',
    paddingVertical: 10,
    paddingHorizontal: 16,
    marginVertical: 8,
  },
  containerVoted: {
    backgroundColor: COLORS.primary,
    borderColor: COLORS.primary,
  },
  inner: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
  },
  emoji: {
    fontSize: 22,
  },
  textContainer: {
    alignItems: 'flex-start',
  },
  label: {
    fontSize: 15,
    fontWeight: '700',
    color: COLORS.primary,
  },
  labelVoted: {
    color: '#fff',
  },
  count: {
    fontSize: 12,
    color: COLORS.gray,
    marginTop: 1,
  },
  countVoted: {
    color: 'rgba(255,255,255,0.85)',
  },
});

export default VoteButton;
