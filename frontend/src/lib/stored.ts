import { useSyncExternalStore } from "react";

/**
 * A small value persisted in localStorage and shared by every component that reads it.
 * Per-browser conveniences only (favourites, shopping area); anything that matters lives in the API.
 */
export function createStoredValue<T>(key: string, fallback: T) {
  const listeners = new Set<() => void>();
  let value = read();

  function read(): T {
    try {
      const raw = localStorage.getItem(key);
      return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
      return fallback;
    }
  }

  const store = {
    get: () => value,
    set(next: T) {
      value = next;
      try {
        localStorage.setItem(key, JSON.stringify(next));
      } catch {
        // Storage unavailable: keep the value for this visit only.
      }
      listeners.forEach((listener) => listener());
    },
    subscribe(listener: () => void) {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
  };

  // Keep other open tabs in step.
  window.addEventListener("storage", (event) => {
    if (event.key !== key) return;
    value = read();
    listeners.forEach((listener) => listener());
  });

  return store;
}

export function useStoredValue<T>(store: ReturnType<typeof createStoredValue<T>>): T {
  return useSyncExternalStore(store.subscribe, store.get);
}
