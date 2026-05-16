import { execSync } from 'node:child_process';

function git(cmd: string): string {
  try {
    return execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  } catch {
    return '';
  }
}

function isDirty(): boolean {
  return git('git status --porcelain') !== '';
}

function defaultBuildId(): string {
  const tag = git('git tag --points-at HEAD');
  const firstTag = tag.split('\n').map((t) => t.trim()).find(Boolean);
  if (firstTag) {
    return firstTag;
  }
  return git('git rev-parse --short=7 HEAD') || 'unknown';
}

export type BuildMeta = {
  buildId: string;
  buildTime: string;
};

/** Resolve build id (tag or short SHA) and ISO build time for Vite env injection. */
export function resolveBuildMeta(): BuildMeta {
  let buildId =
    (typeof process.env.VITE_APP_BUILD_ID === 'string' && process.env.VITE_APP_BUILD_ID.trim()) ||
    defaultBuildId();

  if (isDirty() && !buildId.endsWith('-dirty')) {
    buildId += '-dirty';
  }

  const buildTime =
    (typeof process.env.VITE_APP_BUILD_TIME === 'string' && process.env.VITE_APP_BUILD_TIME.trim()) ||
    new Date().toISOString();

  return { buildId, buildTime };
}
