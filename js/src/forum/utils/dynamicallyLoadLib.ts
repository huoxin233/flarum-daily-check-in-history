import app from 'flarum/forum/app';

declare const FullCalendar: any;

export interface ResourceConfig {
  url?: string;
  sri?: string;
}

export interface LibItem {
  url: string;
  integrity?: string;
  crossOrigin?: string;
}

export interface LibConfig {
  js: LibItem | LibItem[];
  loaded: () => boolean;
}

function getResource(key: 'fullcalendar' | 'locales', defaultPath: string | string[]): LibItem | LibItem[] {
  const custom = (app.forum.attribute('checkinCdnResources') as Record<string, ResourceConfig> | undefined)?.[key];
  const url = custom?.url?.trim();
  const sri = custom?.sri?.trim();

  if (url) {
    const isRemote = url.startsWith('http://') || url.startsWith('https://') || url.startsWith('//');
    return {
      url,
      integrity: sri || undefined,
      crossOrigin: sri || isRemote ? 'anonymous' : undefined,
    };
  }

  if (Array.isArray(defaultPath)) {
    return defaultPath.map((u) => ({ url: u }));
  }

  return { url: defaultPath };
}

const getLibs = (): Record<string, LibConfig> => ({
  fullcalendar: {
    js: getResource('fullcalendar', [
      '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/core@6.1.8/index.global.min.js',
      '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/daygrid@6.1.8/index.global.min.js',
      '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/interaction@6.1.8/index.global.min.js',
      '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/list@6.1.8/index.global.min.js',
    ]),
    loaded: () => typeof FullCalendar !== 'undefined' && Boolean(FullCalendar.Calendar),
  },
  fullcalendarLocales: {
    js: getResource('locales', '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/core@6.1.8/locales-all.global.min.js'),
    loaded: () => typeof FullCalendar !== 'undefined' && Boolean(FullCalendar.globalLocales && FullCalendar.globalLocales.length > 2),
  },
});

const scriptLoadingPromises = new Map<string, Promise<void>>();

function loadScript(item: LibItem): Promise<void> {
  if (scriptLoadingPromises.has(item.url)) {
    return scriptLoadingPromises.get(item.url)!;
  }

  const existingScript = document.querySelector<HTMLScriptElement>(`script[src="${item.url}"]`);
  if (existingScript) {
    return Promise.resolve();
  }

  const promise = new Promise<void>((resolve) => {
    const script = document.createElement('script');
    script.src = item.url;
    if (item.integrity) script.integrity = item.integrity;
    if (item.crossOrigin) script.crossOrigin = item.crossOrigin;

    script.onload = () => resolve();
    script.onerror = (err) => {
      console.error('Failed to load script:', item.url, err);
      resolve();
    };

    document.head.appendChild(script);
  });

  scriptLoadingPromises.set(item.url, promise);
  return promise;
}

export default async function dynamicallyLoadLib(lib: string): Promise<void> {
  const libs = getLibs();
  const libConf = libs[lib];

  if (!libConf) {
    console.warn('dynamicallyLoadLib: lib not found', lib);
    return;
  }

  if (libConf.loaded()) {
    return;
  }

  const jsList = Array.isArray(libConf.js) ? libConf.js : [libConf.js];

  // Load scripts sequentially in order so dependencies (e.g. core before daygrid) execute correctly
  for (const item of jsList) {
    await loadScript(item);
  }

  // Final verification polling guard (up to 3 seconds)
  const startTime = Date.now();
  while (!libConf.loaded() && Date.now() - startTime < 3000) {
    await new Promise((r) => setTimeout(r, 50));
  }
}
