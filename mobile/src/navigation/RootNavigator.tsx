/**
 * CCDS — Navigateur racine
 * Gère le basculement entre les stacks authentifié / non-authentifié.
 */

import React from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { ActivityIndicator, View, Text } from 'react-native';

import { useAuth } from '../services/AuthContext';

// Écrans
import LoginScreen     from '../screens/LoginScreen';
import RegisterScreen  from '../screens/RegisterScreen';
import MapScreen       from '../screens/MapScreen';
import CreateIncidentScreen from '../screens/CreateIncidentScreen';
import MyIncidentsScreen    from '../screens/MyIncidentsScreen';
import IncidentDetailScreen from '../screens/IncidentDetailScreen';

// ----------------------------------------------------------------
// Types de navigation
// ----------------------------------------------------------------
export type AuthStackParamList = {
  Login: undefined;
  Register: undefined;
};

export type AppTabParamList = {
  Map: undefined;
  MyIncidents: undefined;
};

export type AppStackParamList = {
  Tabs: undefined;
  CreateIncident: undefined;
  IncidentDetail: { id: number };
};

// ----------------------------------------------------------------
// Stacks
// ----------------------------------------------------------------
const AuthStack = createNativeStackNavigator<AuthStackParamList>();
const AppStack  = createNativeStackNavigator<AppStackParamList>();
const Tab       = createBottomTabNavigator<AppTabParamList>();

// Onglets principaux (utilisateur connecté)
function AppTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        tabBarActiveTintColor: '#1a7a42',
        tabBarInactiveTintColor: '#6b7280',
        tabBarStyle: { paddingBottom: 4, height: 58 },
        headerShown: false,
      }}
    >
      <Tab.Screen
        name="Map"
        component={MapScreen}
        options={{
          title: 'Carte',
          tabBarIcon: ({ color }) => <TabIcon label="🗺️" color={color} />,
        }}
      />
      <Tab.Screen
        name="MyIncidents"
        component={MyIncidentsScreen}
        options={{
          title: 'Mes signalements',
          tabBarIcon: ({ color }) => <TabIcon label="📋" color={color} />,
        }}
      />
    </Tab.Navigator>
  );
}

// Stack principal (avec modal CreateIncident et détail)
function AppNavigator() {
  return (
    <AppStack.Navigator screenOptions={{ headerShown: false }}>
      <AppStack.Screen name="Tabs" component={AppTabs} />
      <AppStack.Screen
        name="CreateIncident"
        component={CreateIncidentScreen}
        options={{ presentation: 'modal' }}
      />
      <AppStack.Screen
        name="IncidentDetail"
        component={IncidentDetailScreen}
        options={{ headerShown: true, title: 'Détail du signalement', headerBackTitle: 'Retour', headerStyle: { backgroundColor: '#0f4c2a' }, headerTintColor: '#ffffff', headerTitleStyle: { fontWeight: '700' } }}
      />
    </AppStack.Navigator>
  );
}

// Stack d'authentification
function AuthNavigator() {
  return (
    <AuthStack.Navigator screenOptions={{ headerShown: false }}>
      <AuthStack.Screen name="Login"    component={LoginScreen} />
      <AuthStack.Screen name="Register" component={RegisterScreen} />
    </AuthStack.Navigator>
  );
}

// ----------------------------------------------------------------
// Navigateur racine
// ----------------------------------------------------------------
export default function RootNavigator() {
  const { isAuthenticated, isLoading } = useAuth();

  if (isLoading) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#0f4c2a' }}>
        <ActivityIndicator size="large" color="#a7f3d0" />
      </View>
    );
  }

  return (
    <NavigationContainer>
      {isAuthenticated ? <AppNavigator /> : <AuthNavigator />}
    </NavigationContainer>
  );
}

// Petit composant icône pour les onglets
function TabIcon({ label, color }: { label: string; color: string }) {
  return <Text style={{ fontSize: 22, color }}>{label}</Text>;
}
