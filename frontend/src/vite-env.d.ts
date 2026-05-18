/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APP_BUILD_ID: string;
  readonly VITE_APP_BUILD_TIME: string;
  readonly VITE_APP_BUILD_BRANCH: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
