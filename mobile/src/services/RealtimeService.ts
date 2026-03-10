/**
 * CCDS v1.3 — RealtimeService (RT-01)
 * Service WebSocket pour les mises à jour en temps réel de la carte.
 * Gestion : reconnexion automatique, backoff exponentiel, heartbeat.
 *
 * NOTE: EventEmitter implémenté manuellement (pas d'import Node.js 'events')
 * pour compatibilité React Native Android/iOS sans polyfill.
 */

// ── EventEmitter maison — compatible React Native ────────────────────────────
type Listener = (...args: any[]) => void;

class EventEmitter {
  private _listeners: Map<string, Listener[]> = new Map();

  on(event: string, listener: Listener): this {
    if (!this._listeners.has(event)) this._listeners.set(event, []);
    this._listeners.get(event)!.push(listener);
    return this;
  }

  off(event: string, listener: Listener): this {
    const list = this._listeners.get(event);
    if (list) this._listeners.set(event, list.filter(l => l !== listener));
    return this;
  }

  emit(event: string, ...args: any[]): boolean {
    const list = this._listeners.get(event);
    if (!list || list.length === 0) return false;
    list.forEach(l => {
      try { l(...args); } catch { /* ne pas laisser un listener planter le service */ }
    });
    return true;
  }

  removeAllListeners(event?: string): this {
    if (event) this._listeners.delete(event);
    else this._listeners.clear();
    return this;
  }
}

// ── Types ────────────────────────────────────────────────────────────────────

export interface RealtimeIncident {
  id: number;
  reference: string;
  title: string;
  latitude: number;
  longitude: number;
  status: string;
  category_name: string;
  category_icon: string;
  votes_count: number;
  created_at: string;
  event: 'created' | 'updated' | 'resolved';
}

type RealtimeEvent = 'incident:new' | 'incident:updated' | 'incident:resolved' | 'connected' | 'disconnected' | 'error';

// ── Service ──────────────────────────────────────────────────────────────────

class RealtimeService extends EventEmitter {
  private static instance: RealtimeService;
  private ws: WebSocket | null = null;
  private wsUrl: string = '';
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 10;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private isConnected = false;
  private shouldReconnect = true;

  static getInstance(): RealtimeService {
    if (!RealtimeService.instance) {
      RealtimeService.instance = new RealtimeService();
    }
    return RealtimeService.instance;
  }

  /**
   * Connecter au serveur WebSocket
   */
  connect(serverUrl: string, token: string): void {
    this.wsUrl = serverUrl.replace(/^http/, 'ws') + `/ws?token=${encodeURIComponent(token)}`;
    this.shouldReconnect = true;
    this.reconnectAttempts = 0;
    this._connect();
  }

  /**
   * Déconnecter proprement
   */
  disconnect(): void {
    this.shouldReconnect = false;
    this._clearTimers();
    if (this.ws) {
      this.ws.close(1000, 'Client disconnect');
      this.ws = null;
    }
    this.isConnected = false;
  }

  getConnectionStatus(): boolean {
    return this.isConnected;
  }

  // ----------------------------------------------------------------
  // Méthodes privées
  // ----------------------------------------------------------------

  private _connect(): void {
    try {
      this.ws = new WebSocket(this.wsUrl);

      this.ws.onopen = () => {
        this.isConnected = true;
        this.reconnectAttempts = 0;
        this.emit('connected');
        this._startHeartbeat();
      };

      this.ws.onmessage = (event) => {
        try {
          const msg = JSON.parse(event.data);
          this._handleMessage(msg);
        } catch {
          // Ignorer les messages malformés
        }
      };

      this.ws.onerror = () => {
        this.emit('error', 'Erreur de connexion WebSocket');
      };

      this.ws.onclose = (event) => {
        this.isConnected = false;
        this._clearTimers();
        this.emit('disconnected', event.code);

        if (this.shouldReconnect && event.code !== 1000) {
          this._scheduleReconnect();
        }
      };
    } catch (err) {
      this.emit('error', 'Impossible de créer la connexion WebSocket');
      this._scheduleReconnect();
    }
  }

  private _handleMessage(msg: { type: string; data: RealtimeIncident }): void {
    switch (msg.type) {
      case 'incident:new':
        this.emit('incident:new', msg.data);
        break;
      case 'incident:updated':
        this.emit('incident:updated', msg.data);
        break;
      case 'incident:resolved':
        this.emit('incident:resolved', msg.data);
        break;
      case 'pong':
        // Heartbeat reçu — connexion active
        break;
    }
  }

  private _startHeartbeat(): void {
    this.heartbeatTimer = setInterval(() => {
      if (this.ws?.readyState === WebSocket.OPEN) {
        this.ws.send(JSON.stringify({ type: 'ping' }));
      }
    }, 30000); // Ping toutes les 30 secondes
  }

  private _scheduleReconnect(): void {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      this.emit('error', 'Nombre maximum de tentatives de reconnexion atteint');
      return;
    }

    // Backoff exponentiel : 1s, 2s, 4s, 8s, 16s, 30s max
    const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), 30000);
    this.reconnectAttempts++;

    this.reconnectTimer = setTimeout(() => {
      if (this.shouldReconnect) {
        this._connect();
      }
    }, delay);
  }

  private _clearTimers(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }
}

export const realtimeService = RealtimeService.getInstance();

// ----------------------------------------------------------------
// Hook React : useRealtime
// ----------------------------------------------------------------
import { useState, useEffect, useCallback } from 'react';

export function useRealtime(onNewIncident?: (incident: RealtimeIncident) => void) {
  const [connected, setConnected] = useState(realtimeService.getConnectionStatus());
  const [newCount, setNewCount]   = useState(0);

  useEffect(() => {
    const onConnect    = () => setConnected(true);
    const onDisconnect = () => setConnected(false);
    const onNew        = (incident: RealtimeIncident) => {
      setNewCount(n => n + 1);
      onNewIncident?.(incident);
    };

    realtimeService.on('connected',    onConnect);
    realtimeService.on('disconnected', onDisconnect);
    realtimeService.on('incident:new', onNew);

    return () => {
      realtimeService.off('connected',    onConnect);
      realtimeService.off('disconnected', onDisconnect);
      realtimeService.off('incident:new', onNew);
    };
  }, [onNewIncident]);

  const resetCount = useCallback(() => setNewCount(0), []);

  return { connected, newCount, resetCount };
}
