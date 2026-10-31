import { beforeEach, describe, expect, it, vi } from 'vitest';

// El entorno de vitest es `node` (vitest.config.ts): estos dobles mínimos de
// Web Storage / BroadcastChannel / window bastan para probar auth-client.

function memoryStorage(): Storage {
  const map = new Map<string, string>();
  return {
    get length() {
      return map.size;
    },
    clear: () => map.clear(),
    getItem: (key: string) => (map.has(key) ? map.get(key)! : null),
    key: (index: number) => Array.from(map.keys())[index] ?? null,
    removeItem: (key: string) => void map.delete(key),
    setItem: (key: string, value: string) => void map.set(key, String(value)),
  };
}

class FakeBroadcastChannel {
  static instances: FakeBroadcastChannel[] = [];
  readonly messages: unknown[] = [];
  private listeners = new Set<(event: MessageEvent) => void>();

  constructor(readonly name: string) {
    FakeBroadcastChannel.instances.push(this);
  }
  addEventListener(_type: string, listener: (event: MessageEvent) => void) {
    this.listeners.add(listener);
  }
  removeEventListener(_type: string, listener: (event: MessageEvent) => void) {
    this.listeners.delete(listener);
  }
  postMessage(data: unknown) {
    this.messages.push(data);
  }
  emit(data: unknown) {
    for (const listener of this.listeners) listener({ data } as MessageEvent);
  }
  close() {
    this.listeners.clear();
  }
}

function emitChannel(name: string, data: unknown) {
  for (const channel of FakeBroadcastChannel.instances) {
    if (channel.name === name) channel.emit(data);
  }
}

type FakeWindow = ReturnType<typeof fakeWindow>;

function fakeWindow() {
  const listeners = new Map<string, Set<(event: unknown) => void>>();
  return {
    location: { reload: vi.fn() },
    addEventListener(type: string, listener: (event: unknown) => void) {
      if (!listeners.has(type)) listeners.set(type, new Set());
      listeners.get(type)!.add(listener);
    },
    removeEventListener(type: string, listener: (event: unknown) => void) {
      listeners.get(type)?.delete(listener);
    },
    dispatch(type: string, event: unknown) {
      for (const listener of listeners.get(type) ?? []) listener(event);
    },
  };
}

const USER = {
  sub: 7,
  name: 'Ana Pérez',
  email: 'ana@example.com',
  photo: 'https://cdn.example.com/ana.jpg',
  provider: 'google',
};

async function setup(seedLocal: Record<string, string> = {}) {
  vi.resetModules();
  FakeBroadcastChannel.instances = [];
  const local = memoryStorage();
  const session = memoryStorage();
  const win = fakeWindow();
  for (const [key, value] of Object.entries(seedLocal)) local.setItem(key, value);

  vi.stubGlobal('localStorage', local);
  vi.stubGlobal('sessionStorage', session);
  vi.stubGlobal('window', win);
  vi.stubGlobal('BroadcastChannel', FakeBroadcastChannel);
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ success: true, user: USER }) })),
  );

  const mod = await import('../../src/utils/auth-client');
  return { mod, local, session, win };
}

function fireStorage(win: FakeWindow, key: string, newValue: string | null) {
  win.dispatch('storage', { key, newValue } as StorageEvent);
}

beforeEach(() => {
  vi.unstubAllGlobals();
});

describe('auth-client: evento cross-tab sin PII', () => {
  it('el login guarda solo una señal en localStorage, no el perfil', async () => {
    const { mod, local } = await setup({ usgar_consent: 'keep-me' });

    await mod.getUser(true);

    const raw = local.getItem('usgar_auth_event');
    expect(raw).toBeTruthy();
    expect(raw).not.toContain(USER.email);
    expect(raw).not.toContain(USER.name);
    expect(raw).not.toContain(USER.photo);
    expect(raw).not.toContain('"user"');
    // No se destruyen otras claves del sitio.
    expect(local.getItem('usgar_consent')).toBe('keep-me');
    // El canal en memoria tampoco necesita el perfil.
    const posted = FakeBroadcastChannel.instances.at(-1)!.messages;
    expect(posted).toHaveLength(1);
    expect(JSON.stringify(posted[0])).not.toContain(USER.email);
  });

  it('una señal de otra pestaña invalida el cache local y notifica', async () => {
    const { mod, session, win } = await setup();
    session.setItem('usgar_auth_user', JSON.stringify({ user: USER, cachedAt: Date.now() }));

    const listener = vi.fn();
    const off = mod.onAuthChange(listener);
    fireStorage(win, 'usgar_auth_event', JSON.stringify({ src: 'otra-pestana', at: 1, n: 1 }));

    expect(listener).toHaveBeenCalledTimes(1);
    expect(session.getItem('usgar_auth_user')).toBeNull();
    off();
  });

  it('un evento legacy con perfil (pestaña con versión anterior) sigue invalidando', async () => {
    const { mod, session, win } = await setup();
    session.setItem('usgar_auth_user', JSON.stringify({ user: USER, cachedAt: Date.now() }));

    const listener = vi.fn();
    mod.onAuthChange(listener);
    fireStorage(win, 'usgar_auth_event', JSON.stringify({ user: USER, at: 1 }));

    expect(listener).toHaveBeenCalledTimes(1);
    expect(session.getItem('usgar_auth_user')).toBeNull();
  });

  it('un mensaje de BroadcastChannel de otra pestaña también invalida y notifica', async () => {
    const { mod, session } = await setup();
    session.setItem('usgar_auth_user', JSON.stringify({ user: USER, cachedAt: Date.now() }));

    const listener = vi.fn();
    mod.onAuthChange(listener);
    emitChannel('usgar_auth', { source: 'otra-pestana' });

    expect(listener).toHaveBeenCalledTimes(1);
    expect(session.getItem('usgar_auth_user')).toBeNull();
  });

  it('ignora sus propios mensajes de BroadcastChannel (no borra el cache recién escrito)', async () => {
    const { mod, session } = await setup();
    session.setItem('usgar_auth_user', JSON.stringify({ user: USER, cachedAt: Date.now() }));

    const listener = vi.fn();
    mod.onAuthChange(listener);
    emitChannel('usgar_auth', { source: session.getItem('usgar_auth_tab_id') });

    expect(listener).not.toHaveBeenCalled();
    expect(session.getItem('usgar_auth_user')).not.toBeNull();
  });

  it('al cargar purga el evento legacy con PII y conserva otras claves', async () => {
    const { local } = await setup({
      usgar_auth_event: JSON.stringify({ user: USER, at: 1 }),
      usgar_consent: 'keep-me',
    });

    expect(local.getItem('usgar_auth_event')).toBeNull();
    expect(local.getItem('usgar_consent')).toBe('keep-me');
  });
});
