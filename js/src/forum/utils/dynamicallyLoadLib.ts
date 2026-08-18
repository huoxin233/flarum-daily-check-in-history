declare const FullCalendar: any;

interface LibConfig {
  css?: string | string[];
  js?: string | string[] | ((...args: any[]) => string | string[]);
  loaded: (...args: any[]) => boolean;
}

const libs: Record<string, LibConfig> = {
  fullcalendarCore: {
    js: '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/core@6.1.8/index.global.min.js',
    loaded: () => typeof FullCalendar !== 'undefined',
  },
  fullcalendarLocales: {
    js: '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/core@6.1.8/locales-all.global.min.js',
    loaded: () => typeof FullCalendar !== 'undefined' && FullCalendar.globalLocales && FullCalendar.globalLocales.length > 2,
  },
  fullcalendarDayGrid: {
    js: '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/daygrid@6.1.8/index.global.min.js',
    loaded: () =>
      typeof FullCalendar !== 'undefined' &&
      FullCalendar.globalPlugins &&
      FullCalendar.globalPlugins.find((p: any) => p.name === '@fullcalendar/daygrid'),
  },
  fullcalendarInteraction: {
    js: '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/interaction@6.1.8/index.global.min.js',
    loaded: () =>
      typeof FullCalendar !== 'undefined' &&
      FullCalendar.globalPlugins &&
      FullCalendar.globalPlugins.find((p: any) => p.name === '@fullcalendar/interaction'),
  },
  fullcalendarList: {
    js: '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/list@6.1.8/index.global.min.js',
    loaded: () =>
      typeof FullCalendar !== 'undefined' &&
      FullCalendar.globalPlugins &&
      FullCalendar.globalPlugins.find((p: any) => p.name === '@fullcalendar/list'),
  },
  flatpickr: {
    css: '/assets/extensions/mattoid-daily-check-in-history/dist/@fullcalendar/flatpickr.min.css',
    js: 'https://cdn.jsdelivr.net/npm/flatpickr',
    loaded: () => typeof (window as any).flatpickr !== 'undefined',
  },
  flatpickrLocale: {
    js: (locale: string) => `https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/${locale}.js`,
    loaded: (locale: string) => typeof (window as any).flatpickr !== 'undefined' && (window as any).flatpickr.l10ns?.[locale] !== 'undefined',
  },
};

export default function dynamicallyLoadLib(lib: string | LibConfig | (string | LibConfig)[], ...moreArgs: any[]): Promise<any> {
  if (Array.isArray(lib)) {
    return Promise.all(lib.map((l) => dynamicallyLoadLib(l, ...moreArgs)));
  }

  let libConf: LibConfig;

  if (typeof lib === 'object') {
    libConf = { ...lib };

    if (!libConf.loaded) {
      console.warn('dynamicallyLoadLib: No loaded function defined for lib', lib);
      return Promise.resolve();
    }
  } else if (typeof lib === 'string') {
    if (!libs[lib]) {
      console.warn('dynamicallyLoadLib: lib not found', lib);
      return Promise.resolve();
    }

    libConf = { ...libs[lib] };
  } else {
    console.warn('dynamicallyLoadLib: lib is not a string nor a valid object', lib);
    return Promise.resolve();
  }

  if (libConf.loaded(...moreArgs)) {
    return Promise.resolve();
  }

  if (libConf.css) {
    const cssList = Array.isArray(libConf.css) ? libConf.css : [libConf.css];
    cssList.forEach((href) => {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      document.head.appendChild(link);
    });
  }

  if (libConf.js) {
    let jsList = typeof libConf.js === 'function' ? libConf.js(...moreArgs) : libConf.js;
    const scripts = Array.isArray(jsList) ? jsList : [jsList];
    scripts.forEach((src) => {
      const script = document.createElement('script');
      script.src = src;
      document.head.appendChild(script);
    });
  }

  return new Promise((resolve) => {
    const interval = setInterval(() => {
      if (libConf.loaded(...moreArgs)) {
        clearInterval(interval);
        resolve(true);
      }
    }, 10);
  });
}
