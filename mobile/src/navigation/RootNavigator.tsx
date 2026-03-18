/**
 * Ma Commune — Navigateur racine
 * v1.2 : ajout routes EditIncident et Profile
 */

import React, { useEffect, useState } from 'react';
import { NavigationContainer }         from '@react-navigation/native';
import { createNativeStackNavigator }  from '@react-navigation/native-stack';
import { createBottomTabNavigator }    from '@react-navigation/bottom-tabs';
import { ActivityIndicator, View, Text, Image, StyleSheet } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { BRAND } from '../theme/brand';

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
import EventsScreen         from '../screens/EventsScreen';
import PollsScreen          from '../screens/PollsScreen';
import { flushPendingNavigation, navigationRef } from './navigationRef';

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
  Events:         undefined;
  Polls:          undefined;
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
  const { isStaff } = useAuth();
  const insets = useSafeAreaInsets();
  const bottomInset = Math.max(insets.bottom, 12);

  return (
    <Tab.Navigator
      screenOptions={{
        tabBarActiveTintColor:   BRAND.colors.canopy,
        tabBarInactiveTintColor: '#70817A',
        tabBarStyle: {
          height: 74 + bottomInset,
          paddingTop: 10,
          paddingBottom: bottomInset,
          backgroundColor: '#FFFDF8',
          borderTopWidth: 1,
          borderTopColor: '#E6DCC8',
        },
        tabBarLabelStyle: {
          fontSize: 11,
          fontWeight: '700',
          letterSpacing: 0.2,
        },
        tabBarItemStyle: {
          paddingVertical: 4,
          borderRadius: 18,
          marginHorizontal: 2,
        },
        headerShown: false,
      }}
    >
      <Tab.Screen
        name="Map"
        component={MapScreen}
        options={{ title: 'Carte', tabBarIcon: ({ color, focused }) => <TabIcon label="🗺️" color={color} focused={focused} /> }}
      />
      <Tab.Screen
        name="MyIncidents"
        component={MyIncidentsScreen}
        options={{
          title: isStaff ? 'Terrain' : 'Suivi',
          tabBarIcon: ({ color, focused }) => <TabIcon label="📋" color={color} focused={focused} />,
        }}
      />
      <Tab.Screen
        name="Notifications"
        component={NotificationsScreen}
        options={{ title: 'Alertes', tabBarIcon: ({ color, focused }) => <TabIcon label="🔔" color={color} focused={focused} /> }}
      />
      <Tab.Screen
        name="Dashboard"
        component={DashboardScreen}
        options={{ title: 'Bilan', tabBarIcon: ({ color, focused }) => <TabIcon label="📊" color={color} focused={focused} /> }}
      />
    </Tab.Navigator>
  );
}

// Stack principal
function AppNavigator() {
  const headerStyle = { backgroundColor: BRAND.colors.canopyDeep };
  const headerOpts  = {
    headerStyle,
    headerTintColor:  '#ffffff' as const,
    headerTitleStyle: { fontWeight: '700' as const },
    headerBackTitle:  'Retour',
    headerShadowVisible: false,
    headerBackTitleVisible: false,
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
        options={{ headerShown: true, headerTitle: () => <BrandHeaderTitle title="Dossier citoyen" detail="Suivi detaille" />, ...headerOpts }}
      />

      {/* v1.2 — Édition d'un signalement */}
      <AppStack.Screen
        name="EditIncident"
        component={EditIncidentScreen}
        options={{ headerShown: true, headerTitle: () => <BrandHeaderTitle title="Ajuster un dossier" detail="Mise a jour" />, ...headerOpts }}
      />

      {/* v1.2 — Profil utilisateur */}
      <AppStack.Screen
        name="Profile"
        component={ProfileScreen}
        options={{ headerShown: true, headerTitle: () => <BrandHeaderTitle title="Mon espace" detail="Profil et preferences" />, ...headerOpts }}
      />

      <AppStack.Screen
        name="Impact"
        component={ImpactScreen}
        options={{ headerShown: true, headerTitle: () => <BrandHeaderTitle title="Mon bilan citoyen" detail="Impact visible" />, ...headerOpts }}
      />
      <AppStack.Screen
        name="Events"
        component={EventsScreen}
        options={{ headerShown: true, headerTitle: () => <BrandHeaderTitle title="Agenda communal" detail="Rendez-vous utiles" />, ...headerOpts }}
      />
      <AppStack.Screen
        name="Polls"
        component={PollsScreen}
        options={{ headerShown: true, headerTitle: () => <BrandHeaderTitle title="Consultations" detail="Concertation locale" />, ...headerOpts }}
      />
      <AppStack.Screen
        name="ServerConfig"
        options={{ headerShown: true, headerTitle: () => <BrandHeaderTitle title="Connexion territoire" detail="Configuration serveur" />, ...headerOpts, presentation: 'modal' }}
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
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: BRAND.colors.canopyDeep, padding: 24 }}>
        <Image
          source={require('../../assets/icon.png')}
          style={{ width: 92, height: 92, borderRadius: 28, marginBottom: 18 }}
        />
        <Text style={{ color: '#F4F1E7', fontSize: 26, fontWeight: '800', marginBottom: 8, fontFamily: BRAND.displayFont }}>
          {BRAND.name}
        </Text>
        <Text style={{ color: '#D7E7DF', marginBottom: 18, fontSize: 13, letterSpacing: 0.4 }}>
          {BRAND.territory}
        </Text>
        <ActivityIndicator size="large" color="#D2A13A" />
        <Text style={{ color: '#D7E7DF', marginTop: 12, fontSize: 14, textAlign: 'center' }}>
          Préparation de votre espace citoyen...
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
    <NavigationContainer
      ref={navigationRef}
      onReady={flushPendingNavigation}
    >
      {isAuthenticated ? <AppNavigator /> : <AuthNavigator />}
    </NavigationContainer>
  );
}

function BrandHeaderTitle({ title, detail }: { title: string; detail: string }) {
  return (
    <View style={styles.headerTitleWrap}>
      <Text style={styles.headerTitleEyebrow}>{BRAND.name}</Text>
      <Text style={styles.headerTitleMain}>{title}</Text>
      <Text style={styles.headerTitleDetail}>{detail}</Text>
    </View>
  );
}

function TabIcon({ label, color, focused }: { label: string; color: string; focused: boolean }) {
  return (
    <View style={[styles.tabIconWrap, focused && styles.tabIconWrapFocused]}>
      <Text style={{ fontSize: 20, color }}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  headerTitleWrap: {
    minWidth: 150,
  },
  headerTitleEyebrow: {
    color: '#D2A13A',
    fontSize: 10,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 0.9,
    marginBottom: 1,
  },
  headerTitleMain: {
    color: '#FFFFFF',
    fontSize: 17,
    lineHeight: 21,
    fontWeight: '800',
    fontFamily: BRAND.displayFont,
  },
  headerTitleDetail: {
    color: '#D7E7DF',
    fontSize: 11,
    lineHeight: 14,
    marginTop: 1,
  },
  tabIconWrap: {
    minWidth: 38,
    height: 32,
    paddingHorizontal: 10,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tabIconWrapFocused: {
    backgroundColor: '#E7F0EA',
  },
});
