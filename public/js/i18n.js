(function () {
    const config = window.INSIGHT_CONFIG || {};
    const fallbackLocale = "en";
    const storageKey = "insight.locale";
    const catalogVersion = String(config.localeVersion || "insight-i18n-6");
    const configuredLocales = Array.isArray(config.supportedLocales) ? config.supportedLocales : ["en", "fr"];
    const supportedLocales = Array.from(new Set(configuredLocales.map(normalizeLocale).filter(Boolean)));
    if (!supportedLocales.includes(fallbackLocale)) {
        supportedLocales.unshift(fallbackLocale);
    }

    const catalogs = {};
    let currentLocale = resolveInitialLocale();

    function normalizeLocale(value) {
        const normalized = String(value || "").trim().toLowerCase().replace("_", "-").split("-")[0];
        return /^[a-z]{2}$/.test(normalized) ? normalized : "";
    }

    function getStoredLocale() {
        try {
            return normalizeLocale(window.localStorage.getItem(storageKey));
        } catch (_error) {
            return "";
        }
    }

    function resolveInitialLocale() {
        const params = new URLSearchParams(window.location.search);
        const queryLocale = normalizeLocale(params.get("lang"));
        const configuredDefault = normalizeLocale(config.defaultLocale);
        const browserLocale = normalizeLocale(window.navigator.language);
        const candidates = [queryLocale, getStoredLocale()];
        if (String(config.defaultLocale || "").toLowerCase() !== "auto") {
            candidates.push(configuredDefault);
        }
        candidates.push(browserLocale, fallbackLocale);
        return candidates.find((locale) => supportedLocales.includes(locale)) || fallbackLocale;
    }

    async function loadCatalog(locale) {
        try {
            const response = await fetch(`/locales/${encodeURIComponent(locale)}.json?v=${encodeURIComponent(catalogVersion)}`, {
                credentials: "same-origin"
            });
            if (!response.ok) {
                return;
            }
            const catalog = await response.json();
            if (catalog && typeof catalog === "object") {
                catalogs[locale] = catalog;
            }
        } catch (_error) {
        }
    }

    function resolveValue(key, locale = currentLocale) {
        const activeCatalog = catalogs[locale] || {};
        const fallbackCatalog = catalogs[fallbackLocale] || {};
        return activeCatalog[key] ?? fallbackCatalog[key] ?? key;
    }

    function interpolate(value, variables) {
        return String(value).replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (_match, name) => {
            return Object.prototype.hasOwnProperty.call(variables, name) ? String(variables[name]) : "";
        });
    }

    function t(key, variables = {}) {
        const count = Number(variables.count);
        const pluralKey = Number.isFinite(count) ? `${key}.${count === 1 ? "one" : "other"}` : key;
        const resolved = resolveValue(pluralKey);
        const value = resolved === pluralKey && pluralKey !== key ? resolveValue(key) : resolved;
        return interpolate(value, {
            appName: String(config.appName || "Insight"),
            ...variables
        });
    }

    function getIntlLocale(locale = currentLocale) {
        const catalog = catalogs[locale] || catalogs[fallbackLocale] || {};
        return String(catalog._meta?.intlLocale || (locale === "en" ? "en-US" : "fr-FR"));
    }

    function apply(root = document) {
        const nodes = [];
        if (root instanceof Element && root.matches("[data-i18n], [data-i18n-aria-label], [data-i18n-title], [data-i18n-placeholder], [data-i18n-description]")) {
            nodes.push(root);
        }
        if (root && typeof root.querySelectorAll === "function") {
            nodes.push(...root.querySelectorAll("[data-i18n], [data-i18n-aria-label], [data-i18n-title], [data-i18n-placeholder], [data-i18n-description]"));
        }
        nodes.forEach((node) => {
            if (node.dataset.i18n) {
                node.textContent = t(node.dataset.i18n);
            }
            if (node.dataset.i18nAriaLabel) {
                node.setAttribute("aria-label", t(node.dataset.i18nAriaLabel));
            }
            if (node.dataset.i18nTitle) {
                node.setAttribute("title", t(node.dataset.i18nTitle));
            }
            if (node.dataset.i18nPlaceholder) {
                node.setAttribute("placeholder", t(node.dataset.i18nPlaceholder));
            }
            if (node.dataset.i18nDescription) {
                node.dataset.description = t(node.dataset.i18nDescription);
            }
        });
        document.documentElement.lang = currentLocale;
        const titleKey = document.documentElement.dataset.insightTitleKey || "meta.title";
        const descriptionKey = document.documentElement.dataset.insightDescriptionKey || "meta.description";
        document.title = t(titleKey);
        const description = document.querySelector('meta[name="description"]');
        if (description) {
            description.setAttribute("content", t(descriptionKey));
        }
    }

    function setLocale(locale, options = {}) {
        const normalized = normalizeLocale(locale);
        if (!supportedLocales.includes(normalized) || !catalogs[normalized]) {
            return false;
        }
        currentLocale = normalized;
        if (options.persist !== false) {
            try {
                window.localStorage.setItem(storageKey, normalized);
            } catch (_error) {
            }
        }
        if (options.updateUrl !== false) {
            const url = new URL(window.location.href);
            url.searchParams.set("lang", normalized);
            window.history.replaceState(window.history.state, "", `${url.pathname}${url.search}${url.hash}`);
        }
        apply(document);
        window.dispatchEvent(new CustomEvent("insight:locale-changed", {
            detail: { locale: normalized, intlLocale: getIntlLocale(normalized) }
        }));
        return true;
    }

    function getLocales() {
        return supportedLocales
            .filter((locale) => catalogs[locale])
            .map((locale) => ({
                code: locale,
                label: String(catalogs[locale]._meta?.name || locale.toUpperCase())
            }));
    }

    const ready = Promise.all(supportedLocales.map(loadCatalog)).then(() => {
        if (!catalogs[currentLocale]) {
            currentLocale = catalogs[fallbackLocale] ? fallbackLocale : Object.keys(catalogs)[0] || fallbackLocale;
        }
        apply(document);
        return currentLocale;
    });

    window.InsightI18n = {
        apply,
        getIntlLocale,
        getLocale: () => currentLocale,
        getLocales,
        ready,
        setLocale,
        t
    };
    window.insightT = t;
    window.insightLocale = () => currentLocale;
    window.insightIntlLocale = () => getIntlLocale(currentLocale);
})();
