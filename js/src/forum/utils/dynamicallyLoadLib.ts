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

export default function dynamicallyLoadLib(lib: string): Promise<void> {
  const libs = getLibs();
  const libConf = libs[lib];

  if (!libConf) {
    console.warn('dynamicallyLoadLib: lib not found', lib);
    return Promise.resolve();
  }

  if (libConf.loaded()) {
    return Promise.resolve();
  }

  const jsList = Array.isArray(libConf.js) ? libConf.js : [libConf.js];
  jsList.forEach((item) => {
    const script = document.createElement('script');
    script.src = item.url;
    if (item.integrity) script.integrity = item.integrity;
    if (item.crossOrigin) script.crossOrigin = item.crossOrigin;
    document.head.appendChild(script);
  });

  return new Promise((resolve) => {
    const startTime = Date.now();
    const interval = setInterval(() => {
      if (libConf.loaded() || Date.now() - startTime > 10000) {
        clearInterval(interval);
        resolve();
      }
    }, 50);
  });
}
