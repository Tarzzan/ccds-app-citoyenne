import { createNavigationContainerRef } from '@react-navigation/native';

import type { AppStackParamList } from './RootNavigator';

export const navigationRef = createNavigationContainerRef<AppStackParamList>();

type PendingRoute =
  | { name: 'IncidentDetail'; params: AppStackParamList['IncidentDetail'] }
  | { name: 'Events'; params: undefined }
  | { name: 'Polls'; params: undefined };

let pendingRoute: PendingRoute | null = null;

function navigateOrQueue(route: PendingRoute) {
  if (navigationRef.isReady()) {
    switch (route.name) {
      case 'IncidentDetail':
        navigationRef.navigate('IncidentDetail', route.params);
        break;
      case 'Events':
        navigationRef.navigate('Events');
        break;
      case 'Polls':
        navigationRef.navigate('Polls');
        break;
    }
    return;
  }

  pendingRoute = route;
}

export function navigateToIncidentDetail(id: number, reference?: string) {
  navigateOrQueue({ name: 'IncidentDetail', params: { id, reference } });
}

export function navigateToEvents() {
  navigateOrQueue({ name: 'Events', params: undefined });
}

export function navigateToPolls() {
  navigateOrQueue({ name: 'Polls', params: undefined });
}

export function flushPendingNavigation() {
  if (!pendingRoute || !navigationRef.isReady()) {
    return;
  }

  const route = pendingRoute;
  pendingRoute = null;
  navigateOrQueue(route);
}
