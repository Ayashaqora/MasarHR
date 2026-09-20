/// <reference types="vite/client" />

// Only public, non-secret values may be exposed to the browser bundle.
interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string
  readonly VITE_DEFAULT_LOCALE?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
