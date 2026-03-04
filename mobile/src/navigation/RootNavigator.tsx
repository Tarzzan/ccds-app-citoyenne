/**
 * CCDS — Navigateur racine
 * v1.1 : ajout onglet Notifications + écran Notifications dans le stack
 */

import React, { useEffect, useState, useRef } from 'react';
import { NavigationContainer }         from '@react-navigation/native';
import { createNativeStackNavigator }  from '@react-navigation/native-stack';
import { createBottomTabNavigator }    from '@react-navigation/bottom-tabs';
import { ActivityIndicator, View, Text } from 'react-native';

import { useAuth }       from '../services/AuthContext';
import { ServerConfig }  from '../services/ServerConfig';

// Écrans
import ServerConfigScreen   from '../screens/ServerConfigScreen';
import LoginScreen          from '../screens/LoginScreen';
import RegisterScreen       from '../screens/RegisterScreen';
import MapScreen            from '../screens/MapScreen';
import CreateIncidentScreen from '../screens/CreateIncidentScreen';
import MyIncidentsScreen    from '../screens/MyIncidentsScreen';
import IncidentDetailScreen from '../screens/IncidentDetailScreen';
import NotificationsScreen  from '../screens/NotificationsScreen';

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
};

export type AppStackParamList = {
  Tabs:           undefined;
  CreateIncident: undefined;
  IncidentDetail: { id: number; reference?: string };
  ServerConfig:   undefined;
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
        tabBarActiveTintColor:   '#1a7a42',
        tabBarInactiveTintColor: '#6b7280',
        tabBarStyle: { paddingBottom: 4, height: 58 },
        headerShown: false,
      }}
    >
      <Tab.Screen
        name="Map"
        component={MapScreen}
        options={{
          title:      'Carte',
          tabBarIcon: ({ color }) => <TabIcon label="🗺️" color={color} />,
        }}
      />
      <Tab.Screen
        name="MyIncidents"
        component={MyIncidentsScreen}
        options={{
          title:      'Mes signalements',
          tabBarIcon: ({ color }) => <TabIcon label="📋" color={color} />,
        }}
      />
      <Tab.Screen
        name="Notifications"
        component={NotificationsScreen}
        options={{
          title:      'Notifications',
          tabBarIcon: ({ color }) => <TabIcon label="🔔" color={color} />,
        }}
      />
    </Tab.Navigator>
  );
}

// Stack principal
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
        options={{
          headerShown:      true,
          title:            'Détail du signalement',
          headerBackTitle:  'Retour',
          headerStyle:      { backgroundColor: '#0f4c2a' },
          headerTintColor:  '#ffffff',
          headerTitleStyle: { fontWeight: '700' },
        }}
      />

      <AppStack.Screen
        name="ServerConfig"
        options={{
          headerShown:      true,
          title:            'Configuration serveur',
          headerBackTitle:  'Retour',
          headerStyle:      { backgroundColor: '#0f4c2a' },
          headerTintColor:  '#ffffff',
          headerTitleStyle: { fontWeight: '700' },
          presentation:     'modal',
        }}
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

  useEffect(() => {
    ServerConfig.isConfigured().then((configured) => {
      setServerConfigured(configured);
    });
  }, []);

  // Écran de chargement initial
  if (isLoading || serverConfigured === null) {
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

  // Premier lancement : afficher l'écran de configuration serveur
  if (!serverConfigured) {
    return (
      <ServerConfigScreen
        isFirstLaunch={true}
        onConfigured={() => setServerConfigured(true)}
      />
    );
  }

  // Serveur configuré : navigation normale
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
