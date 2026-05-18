function formatBuildTime(iso: string): string {
  if (!iso) {
    return '';
  }
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) {
    return iso;
  }
  return d.toLocaleString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'UTC',
    timeZoneName: 'short',
  });
}

/** True when build id is a short git SHA (not a tag name). */
function isShortSha(buildId: string): boolean {
  const base = buildId.replace(/-dirty$/, '');
  return /^[0-9a-f]{7}$/i.test(base) || base === 'unknown';
}

function parseBuildId(buildId: string): { version: string; dirty: boolean } {
  const dirty = buildId.endsWith('-dirty');
  const version = dirty ? buildId.slice(0, -'-dirty'.length) : buildId;
  return { version, dirty };
}

function formatNonMainLabel(version: string, dirty: boolean, branch: string): string {
  return [version, dirty ? 'dirty' : '', branch].filter(Boolean).join(' - ');
}

export function formatBuildLabel(): { label: string; title: string } {
  const buildId = import.meta.env.VITE_APP_BUILD_ID || 'unknown';
  const buildTime = import.meta.env.VITE_APP_BUILD_TIME || '';
  const branch = import.meta.env.VITE_APP_BUILD_BRANCH || '';
  const { version, dirty } = parseBuildId(buildId);

  let core: string;
  if (branch && branch !== 'main') {
    core = formatNonMainLabel(version, dirty, branch);
  } else if (isShortSha(buildId)) {
    const when = formatBuildTime(buildTime);
    core = when ? `${buildId} · ${when}` : buildId;
  } else {
    core = buildId;
  }

  const label = import.meta.env.DEV ? `Development · ${core}` : core;
  const title = buildTime ? `Built ${buildTime}` : buildId;
  return { label, title };
}

export const { label: buildLabel, title: buildTitle } = formatBuildLabel();
