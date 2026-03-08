/**
 * CCDS — Navigateur racine
 * v1.2 : ajout routes EditIncident et Profile
 */

import React, { useEffect, useState } from 'react';
import { NavigationContainer }         from '@react-navigation/native';
import { createNativeStackNavigator }  from '@react-navigation/native-stack';
import { createBottomTabNavigator }    from '@react-navigation/bottom-tabs';
import { ActivityIndicator, View, Text } from 'react-native';

import { useAuth }       from '../services/AuthContext';
import { ServerConfig }  from '../services/ServerConfig';

// Écrans
import ServerConfigScreen   from '../screens/ServerConfigScreen';
import OnboardingScreen, { checkOnboardingDone } from '../screens/OnboardingScreen';
import LoginScreen          from '../screens/LoginScreen';
import RegisterScreen       from '../screens/RegisterScreen';
import MapScreen            from '../screens/MapScreen';
import CreateIncidentScreen from '../screens/CreateIncidentScreen';
import MyIncidentsScreen    from '../screens/MyIncidentsScreen';
import IncidentDetailScreen from '../screens/IncidentDetailScreen';
import NotificationsScreen  from '../screens/NotificationsScreen';
import EditIncidentScreen   from '../screens/EditIncidentScreen';
import ProfileScreen        from '../screens/ProfileScreen';
import DashboardScreen      from '../screens/DashboardScreen';
import ImpactScreen         from '../screens/ImpactScreen';

// ----------------------------------------------------------------
// Types de navigation
// ----------------------------------------------------------------
export type AuthStackParamList = {
  Login:    undefined;
  Register: undefined;
};

export type AppTabParamList = {
  Map:           undefined;
  MyIncidents:   undefined;
  Notifications: undefined;
  Dashboard:     undefined;
};

export type AppStackParamList = {
  Tabs:           undefined;
  CreateIncident: undefined;
  IncidentDetail: { id: number; reference?: string };
  EditIncident:   { id: number };
  Profile:        undefined;
  Impact:         undefined;
  ServerConfig:   undefined;
};

// ----------------------------------------------------------------
// Stacks
// ----------------------------------------------------------------
const AuthStack = createNativeStackNavigator<AuthStackParamList>();
const AppStack  = createNativeStackNavigator<AppStackParamList>();
const Tab       = createBottomTabNavigator<AppTabParamList>();

// Onglets principaux
function AppTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        tabBarActiveTintColor:   '#1a7a42',
        tabBarInactiveTintColor: '#6b7280',
        tabBarStyle: { paddingBottom: 4, height: 58 },
        headerShown: false,
      }}
    >
      <Tab.Screen
        name="Map"
        component={MapScreen}
        options={{ title: 'Carte', tabBarIcon: ({ color }) => <TabIcon label="🗺️" color={color} /> }}
      />
      <Tab.Screen
        name="MyIncidents"
        component={MyIncidentsScreen}
        options={{ title: 'Mes signalements', tabBarIcon: ({ color }) => <TabIcon label="📋" color={color} /> }}
      />
      <Tab.Screen
        name="Notifications"
        component={NotificationsScreen}
        options={{ title: 'Notifications', tabBarIcon: ({ color }) => <TabIcon label="🔔" color={color} /> }}
      />
      <Tab.Screen
        name="Dashboard"
        component={DashboardScreen}
        options={{ title: 'Mon bilan', tabBarIcon: ({ color }) => <TabIcon label="📊" color={color} /> }}
      />
    </Tab.Navigator>
  );
}

// Stack principal
function AppNavigator() {
  const headerStyle = { backgroundColor: '#0f4c2a' };
  const headerOpts  = {
    headerStyle,
    headerTintColor:  '#ffffff' as const,
    headerTitleStyle: { fontWeight: '700' as const },
    headerBackTitle:  'Retour',
  };

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
        options={{ headerShown: true, title: 'Détail du signalement', ...headerOpts }}
      />

      {/* v1.2 — Édition d'un signalement */}
      <AppStack.Screen
        name="EditIncident"
        component={EditIncidentScreen}
        options={{ headerShown: true, title: 'Modifier le signalement', ...headerOpts }}
      />

      {/* v1.2 — Profil utilisateur */}
      <AppStack.Screen
        name="Profile"
        component={ProfileScreen}
        options={{ headerShown: true, title: 'Mon profil', ...headerOpts }}
      />

      <AppStack.Screen
        name="Impact"
        component={ImpactScreen}
        options={{ headerShown: true, title: 'Impact citoyen', ...headerOpts }}
      />
      <AppStack.Screen
        name="ServerConfig"
        options={{ headerShown: true, title: 'Configuration serveur', ...headerOpts, presentation: 'modal' }}
      >
        {(props) => (
          <ServerConfigScreen
            {...props}
            isFirstLaunch={false}
            onConfigured={() => props.navigation.goBack()}
          />
        )}
      </AppStack.Screen>
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
  const [serverConfigured, setServerConfigured] = useState<boolean | null>(null);
  const [onboardingDone, setOnboardingDone]     = useState<boolean | null>(null);

  useEffect(() => {
    ServerConfig.isConfigured().then(setServerConfigured);
    checkOnboardingDone().then(setOnboardingDone);
  }, []);

  if (isLoading || serverConfigured === null || onboardingDone === null) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#0f4c2a' }}>
        <Text style={{ fontSize: 48, marginBottom: 16 }}>🌿</Text>
        <ActivityIndicator size="large" color="#a7f3d0" />
        <Text style={{ color: '#a7f3d0', marginTop: 12, fontSize: 14 }}>
          CCDS Citoyen — Chargement...
        </Text>
      </View>
    );
  }

  if (!serverConfigured) {
    return (
      <ServerConfigScreen
        isFirstLaunch={true}
        onConfigured={() => setServerConfigured(true)}
      />
    );
  }

  // Afficher l'onboarding au premier lancement (après config serveur)
  if (!onboardingDone) {
    return (
      <OnboardingScreen onComplete={() => setOnboardingDone(true)} />
    );
  }

  return (
    <NavigationContainer>
      {isAuthenticated ? <AppNavigator /> : <AuthNavigator />}
    </NavigationContainer>
  );
}

function TabIcon({ label, color }: { label: string; color: string }) {
  return <Text style={{ fontSize: 22, color }}>{label}</Text>;
}
