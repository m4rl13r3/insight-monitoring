let allSitesRawData = [];
let formattedSitesData = [];
// Cache for last applied dot color to avoid redundant DOM updates
let lastDotColor = null;
const cachedDotColors = [];
const DAY_MS = 24 * 60 * 60 * 1000;
const API_TIMEOUT_MS = 10000;
const API_RETRY_DELAYS_MS = [300, 900];
const STATUS_SOURCE_TIME_ZONE = "Europe/Paris";
const STATUS_TIMEZONE_STORAGE_KEY = "insight.status.timezone";
const STATUS_TIME_ZONE_CHOICES = [
    { value: "Europe/Paris", label: "Paris" },
    { value: "Indian/Reunion", label: "Reunion", labelKey: "timezone.reunion" },
    { value: "UTC", label: "UTC" },
    { value: "America/New_York", label: "New York" },
    { value: "America/Los_Angeles", label: "Los Angeles" },
    { value: "Asia/Tokyo", label: "Tokyo" }
];
let selectedStatusTimeZone = resolveInitialStatusTimeZone();
let detailDateKey = formatDateKey(new Date());
let detailSettingsOpen = false;
let detailDateUserSet = false;
let lastCheckedTime = null;
let lastUpdatedIntervalId = null;
let publicRuntimeState = null;
let statusTimeZoneComboboxUid = 0;

function getStatusRouteState() {
    const params = new URLSearchParams(window.location.search);
    const go = (params.get("go") || "").toLowerCase();
    const view = (params.get("view") || "").toLowerCase();
    const domain = params.get("domain");
    const rawDate = params.get("date");
    const date = parseDateKey(rawDate || "") ? rawDate : null;
    const isProbeRoute = (go === "probe" || go === "sonde" || view === "probe");
    return {
        isProbeRoute,
        domain: domain ? String(domain) : null,
        date
    };
}

function writeStatusRoute(params, push = false) {
    const query = params.toString();
    const nextUrl = query ? `${window.location.pathname}?${query}${window.location.hash}` : `${window.location.pathname}${window.location.hash}`;
    if (push) {
        window.history.pushState({}, "", nextUrl);
        return;
    }
    window.history.replaceState({}, "", nextUrl);
}

function setDetailRoute(mainDomain, push = false) {
    if (!mainDomain) {
        return;
    }
    const params = new URLSearchParams(window.location.search);
    params.set("go", "probe");
    params.set("domain", String(mainDomain));
    if (detailDateUserSet) {
        params.set("date", detailDateKey);
    } else {
        params.delete("date");
    }
    params.delete("view");
    writeStatusRoute(params, push);
}

function clearDetailRoute(push = false) {
    detailDateUserSet = false;
    const params = new URLSearchParams(window.location.search);
    params.delete("go");
    params.delete("view");
    params.delete("domain");
    params.delete("date");
    params.delete("incidents");
    params.delete("demoIncidents");
    writeStatusRoute(params, push);
}

function dispatchWindowEvent(name, detail = null) {
    window.dispatchEvent(new CustomEvent(name, detail ? { detail } : undefined));
}

function dispatchDocumentEvent(name, detail = null) {
    document.dispatchEvent(new CustomEvent(name, detail ? { detail } : undefined));
}

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function isRetryableFetchError(error) {
    if (!error) {
        return false;
    }
    if (error.code === "INVALID_JSON") {
        return false;
    }
    if (error.name === "AbortError") {
        return true;
    }
    if (typeof error.status === "number") {
        return error.status === 429 || error.status >= 500;
    }
    return true;
}

async function fetchWithTimeout(url, timeoutMs = API_TIMEOUT_MS) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        return await fetch(url, {
            signal: controller.signal,
            cache: "no-store"
        });
    } finally {
        clearTimeout(timer);
    }
}

function setupAutoRefresh() {
    const now = new Date();
    const seconds = now.getSeconds();
    const minutes = now.getMinutes();
    let delay;
    if (minutes === 1) {
        delay = (60 - seconds) * 1000;
    } else {
        const nextRefreshInMinutes = (60 + (1 - minutes % 60)) % 60;
        delay = ((nextRefreshInMinutes * 60) - seconds) * 1000;
    }
    setTimeout(() => {
        fetchStatusData();
        setInterval(fetchStatusData, 3600 * 1000);
    }, delay);
}

function getBrowserStatusTimeZone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || STATUS_SOURCE_TIME_ZONE;
    } catch (_error) {
        return STATUS_SOURCE_TIME_ZONE;
    }
}

function isSupportedStatusTimeZone(timeZone) {
    if (!timeZone || typeof timeZone !== "string") {
        return false;
    }
    try {
        new Intl.DateTimeFormat(insightIntlLocale(), { timeZone }).format(new Date());
        return true;
    } catch (_error) {
        return false;
    }
}

function resolveInitialStatusTimeZone() {
    try {
        const stored = window.localStorage ? window.localStorage.getItem(STATUS_TIMEZONE_STORAGE_KEY) : "";
        if (isSupportedStatusTimeZone(stored)) {
            return stored;
        }
    } catch (_error) {}
    const browserTimeZone = getBrowserStatusTimeZone();
    return isSupportedStatusTimeZone(browserTimeZone) ? browserTimeZone : STATUS_SOURCE_TIME_ZONE;
}

function getSelectedStatusTimeZone() {
    if (!isSupportedStatusTimeZone(selectedStatusTimeZone)) {
        selectedStatusTimeZone = STATUS_SOURCE_TIME_ZONE;
    }
    return selectedStatusTimeZone;
}

function getSupportedStatusTimeZones() {
    try {
        if (Intl.supportedValuesOf) {
            return Intl.supportedValuesOf("timeZone").filter(isSupportedStatusTimeZone);
        }
    } catch (_error) {}
    return [];
}

function formatStatusTimeZoneCityLabel(timeZone) {
    const parts = String(timeZone || "").split("/");
    const city = parts[parts.length - 1] || timeZone;
    if (timeZone === "UTC" || timeZone === "Etc/UTC") {
        return "UTC";
    }
    return city.replace(/_/g, " ");
}

function getStatusTimeZoneChoices() {
    const priorityChoices = STATUS_TIME_ZONE_CHOICES.map((choice) => ({
        ...choice,
        label: choice.labelKey ? insightT(choice.labelKey) : choice.label
    }));
    const supportedTimeZones = getSupportedStatusTimeZones();
    const dynamicChoices = supportedTimeZones
        .map((timeZone) => ({
            value: timeZone,
            label: formatStatusTimeZoneCityLabel(timeZone)
        }))
        .sort((a, b) => a.label.localeCompare(b.label, insightIntlLocale(), { sensitivity: "base" }));

    const choicesByValue = new Map();
    priorityChoices.forEach((choice) => {
        if (isSupportedStatusTimeZone(choice.value)) {
            choicesByValue.set(choice.value, choice);
        }
    });
    dynamicChoices.forEach((choice) => {
        if (!choicesByValue.has(choice.value)) {
            choicesByValue.set(choice.value, choice);
        }
    });

    const choices = Array.from(choicesByValue.values());
    const browserTimeZone = getBrowserStatusTimeZone();
    if (isSupportedStatusTimeZone(browserTimeZone) && !choices.some((choice) => choice.value === browserTimeZone)) {
        choices.unshift({
            value: browserTimeZone,
            label: insightT("timezone.local", { city: formatStatusTimeZoneCityLabel(browserTimeZone) })
        });
    }
    return choices;
}

function getStatusTimeZoneOffsetLabel(timeZone, date = new Date()) {
    try {
        const parts = new Intl.DateTimeFormat(insightIntlLocale(), {
            timeZone,
            hour: "2-digit",
            minute: "2-digit",
            timeZoneName: "shortOffset"
        }).formatToParts(date);
        const name = parts.find((part) => part.type === "timeZoneName")?.value || "";
        return name.replace("GMT", "UTC");
    } catch (_error) {
        return timeZone;
    }
}

function getStatusTimeZoneOptionLabel(choice) {
    const offset = getStatusTimeZoneOffsetLabel(choice.value);
    return `${choice.label} (${offset})`;
}

function getCompactStatusTimeZoneOptionLabel(choice) {
    return getStatusTimeZoneOptionLabel(choice);
}

function normalizeStatusTimeZoneSearch(value) {
    return String(value || "")
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase();
}

function closeStatusTimeZoneCombobox(combo) {
    if (!combo) {
        return;
    }
    combo.dataset.open = "0";
    const panel = combo.querySelector(".status-timezone-combobox-panel");
    const button = combo.querySelector(".status-timezone-combobox-button");
    if (panel) {
        panel.classList.add("hidden");
    }
    if (button) {
        button.setAttribute("aria-expanded", "false");
    }
}

function closeStatusTimeZoneComboboxes(except = null) {
    document.querySelectorAll(".status-timezone-combobox").forEach((combo) => {
        if (combo !== except) {
            closeStatusTimeZoneCombobox(combo);
        }
    });
}

function updateStatusTimeZoneComboboxValue(combo) {
    const labelNode = combo.querySelector(".status-timezone-combobox-value");
    if (!labelNode) {
        return;
    }
    const choices = combo.__statusTimeZoneChoices || [];
    const labels = combo.__statusTimeZoneLabels || [];
    const selected = getSelectedStatusTimeZone();
    const selectedIndex = choices.findIndex((choice) => choice.value === selected);
    const label = selectedIndex >= 0 ? labels[selectedIndex] : selected;
    labelNode.textContent = label;
    combo.title = label;
    const button = combo.querySelector(".status-timezone-combobox-button");
    if (button) {
        button.setAttribute("aria-label", insightT("timezone.current", { value: label }));
    }
}

function renderStatusTimeZoneComboboxOptions(combo, query = "") {
    const list = combo.querySelector(".status-timezone-combobox-list");
    if (!list) {
        return;
    }
    const choices = combo.__statusTimeZoneChoices || [];
    const labels = combo.__statusTimeZoneLabels || [];
    const selected = getSelectedStatusTimeZone();
    const normalizedQuery = normalizeStatusTimeZoneSearch(query);
    const matches = choices
        .map((choice, index) => ({
            choice,
            label: labels[index] || choice.value
        }))
        .filter((entry) => {
            if (!normalizedQuery) {
                return true;
            }
            return normalizeStatusTimeZoneSearch(`${entry.label} ${entry.choice.value}`).includes(normalizedQuery);
        });

    list.innerHTML = "";
    if (matches.length === 0) {
        const empty = document.createElement("p");
        empty.className = "status-timezone-empty";
        empty.textContent = insightT("timezone.noResults");
        list.appendChild(empty);
        return;
    }

    matches.forEach((entry) => {
        const option = document.createElement("button");
        option.type = "button";
        option.className = "status-timezone-option";
        option.dataset.statusTimeZoneValue = entry.choice.value;
        option.setAttribute("role", "option");
        option.setAttribute("aria-selected", entry.choice.value === selected ? "true" : "false");
        option.innerHTML = `
            <span>${escapeHtml(entry.label)}</span>
            <small>${escapeHtml(entry.choice.value)}</small>
        `;
        list.appendChild(option);
    });
}

function openStatusTimeZoneCombobox(combo) {
    closeStatusTimeZoneComboboxes(combo);
    combo.dataset.open = "1";
    const panel = combo.querySelector(".status-timezone-combobox-panel");
    const button = combo.querySelector(".status-timezone-combobox-button");
    const input = combo.querySelector(".status-timezone-search");
    if (panel) {
        panel.classList.remove("hidden");
    }
    if (button) {
        button.setAttribute("aria-expanded", "true");
    }
    if (input) {
        input.value = "";
        renderStatusTimeZoneComboboxOptions(combo, "");
        window.requestAnimationFrame(() => {
            input.focus();
        });
    }
}

function buildStatusTimeZoneCombobox(select) {
    if (!select.dataset.statusTimezoneUid) {
        statusTimeZoneComboboxUid += 1;
        select.dataset.statusTimezoneUid = `status-tz-${statusTimeZoneComboboxUid}`;
    }
    const uid = select.dataset.statusTimezoneUid;
    const host = select.closest(".status-timezone-control, .detail-timezone-control") || select.parentElement;
    if (!host) {
        return null;
    }
    let combo = host.querySelector(`.status-timezone-combobox[data-status-timezone-uid="${uid}"]`);
    if (combo) {
        combo.__statusTimeZoneSelect = select;
        return combo;
    }

    combo = document.createElement("div");
    combo.className = "status-timezone-combobox";
    combo.dataset.statusTimezoneUid = uid;
    combo.dataset.open = "0";
    combo.innerHTML = `
        <button type="button" class="status-timezone-combobox-button" aria-haspopup="listbox" aria-expanded="false">
            <i class="fa-solid fa-globe status-timezone-combobox-icon" aria-hidden="true"></i>
            <span class="status-timezone-combobox-value"></span>
            <i class="fa-solid fa-chevron-down status-timezone-combobox-chevron" aria-hidden="true"></i>
        </button>
        <div class="status-timezone-combobox-panel hidden">
            <input class="status-timezone-search" type="search" autocomplete="off" placeholder="Search for a city or time zone"timezone.searchPlaceholder"))}" aria-label="Search for a time zone"timezone.searchAria"))}" data-i18n-placeholder="timezone.searchPlaceholder" data-i18n-aria-label="timezone.searchAria">
            <div class="status-timezone-combobox-list" role="listbox"></div>
        </div>
    `;
    combo.__statusTimeZoneSelect = select;
    select.insertAdjacentElement("afterend", combo);

    const button = combo.querySelector(".status-timezone-combobox-button");
    const input = combo.querySelector(".status-timezone-search");
    const list = combo.querySelector(".status-timezone-combobox-list");

    button.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (combo.dataset.open === "1") {
            closeStatusTimeZoneCombobox(combo);
        } else {
            openStatusTimeZoneCombobox(combo);
        }
    });

    input.addEventListener("click", (event) => {
        event.stopPropagation();
    });

    input.addEventListener("input", () => {
        renderStatusTimeZoneComboboxOptions(combo, input.value);
    });

    input.addEventListener("keydown", (event) => {
        const options = Array.from(list.querySelectorAll(".status-timezone-option"));
        const current = document.activeElement && document.activeElement.classList.contains("status-timezone-option")
            ? document.activeElement
            : null;
        if (event.key === "Escape") {
            event.preventDefault();
            closeStatusTimeZoneCombobox(combo);
            button.focus();
        } else if (event.key === "Enter") {
            event.preventDefault();
            options[0]?.click();
        } else if (event.key === "ArrowDown") {
            event.preventDefault();
            (current ? options[options.indexOf(current) + 1] : options[0])?.focus();
        }
    });

    list.addEventListener("keydown", (event) => {
        const options = Array.from(list.querySelectorAll(".status-timezone-option"));
        const index = options.indexOf(document.activeElement);
        if (event.key === "Escape") {
            event.preventDefault();
            closeStatusTimeZoneCombobox(combo);
            button.focus();
        } else if (event.key === "ArrowDown") {
            event.preventDefault();
            options[Math.min(index + 1, options.length - 1)]?.focus();
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            if (index <= 0) {
                input.focus();
            } else {
                options[index - 1]?.focus();
            }
        }
    });

    list.addEventListener("click", (event) => {
        const option = event.target.closest("[data-status-time-zone-value]");
        if (!option) {
            return;
        }
        const value = option.dataset.statusTimeZoneValue;
        if (value) {
            select.value = value;
            setSelectedStatusTimeZone(value);
            closeStatusTimeZoneCombobox(combo);
            button.focus();
        }
    });

    if (document.documentElement.dataset.statusTimeZoneOutsideBound !== "1") {
        document.documentElement.dataset.statusTimeZoneOutsideBound = "1";
        document.addEventListener("click", () => {
            closeStatusTimeZoneComboboxes();
        });
    }

    return combo;
}

function syncStatusTimeZoneCombobox(select, choices, labels, compact, nav) {
    const combo = buildStatusTimeZoneCombobox(select);
    if (!combo) {
        return;
    }
    combo.__statusTimeZoneChoices = choices;
    combo.__statusTimeZoneLabels = labels;
    combo.dataset.compact = compact ? "1" : "0";
    combo.dataset.nav = nav ? "1" : "0";
    if (compact && !nav) {
        const longestLabelLength = labels.reduce((max, label) => Math.max(max, label.length), 0);
        combo.style.width = `min(${Math.max(12, Math.min(longestLabelLength + 3, 32))}ch, calc(100vw - 2rem))`;
    } else {
        combo.style.width = "";
    }
    updateStatusTimeZoneComboboxValue(combo);
    renderStatusTimeZoneComboboxOptions(combo, combo.querySelector(".status-timezone-search")?.value || "");
}

function syncStatusTimeZoneControls(root = document) {
    const selects = Array.from(root.querySelectorAll("[data-status-timezone-select]"));
    if (selects.length === 0) {
        return;
    }
    const choices = getStatusTimeZoneChoices();
    selects.forEach((select) => {
        const format = select.dataset.statusTimezoneFormat || "full";
        const nav = format === "nav";
        const compact = format === "compact" || nav;
        const signature = `${insightLocale()}|${format}|${choices.map((choice) => `${choice.value}:${choice.label}`).join("|")}`;
        if (select.dataset.statusTimezoneOptions !== signature) {
            const labels = choices.map((choice) => {
                const label = compact ? getCompactStatusTimeZoneOptionLabel(choice) : getStatusTimeZoneOptionLabel(choice);
                return label;
            });
            select.innerHTML = choices.map((choice, index) => {
                const label = labels[index];
                return `<option value="${escapeHtml(choice.value)}">${escapeHtml(label)}</option>`;
            }).join("");
            if (compact && !nav) {
                const longestLabelLength = labels.reduce((max, label) => Math.max(max, label.length), 0);
                select.style.width = `${Math.max(10, longestLabelLength + 3)}ch`;
            } else {
                select.style.width = "";
            }
            select.dataset.statusTimezoneOptions = signature;
        }
        const labels = choices.map((choice) => compact ? getCompactStatusTimeZoneOptionLabel(choice) : getStatusTimeZoneOptionLabel(choice));
        select.value = getSelectedStatusTimeZone();
        select.classList.add("status-timezone-native");
        syncStatusTimeZoneCombobox(select, choices, labels, compact, nav);
        if (select.dataset.statusTimezoneBound !== "1") {
            select.dataset.statusTimezoneBound = "1";
            select.addEventListener("change", () => {
                setSelectedStatusTimeZone(select.value);
            });
        }
    });
}

function initializeStatusTimeZoneControls() {
    syncStatusTimeZoneControls(document);
}

function setSelectedStatusTimeZone(timeZone) {
    if (!isSupportedStatusTimeZone(timeZone) || timeZone === selectedStatusTimeZone) {
        syncStatusTimeZoneControls(document);
        return;
    }
    selectedStatusTimeZone = timeZone;
    try {
        if (window.localStorage) {
            window.localStorage.setItem(STATUS_TIMEZONE_STORAGE_KEY, timeZone);
        }
    } catch (_error) {}
    syncStatusTimeZoneControls(document);
    dispatchWindowEvent("statusTimeZoneChanged", { timeZone });
    refreshStatusTimeZoneDependentUi();
}

async function refreshStatusTimeZoneDependentUi() {
    if (typeof updateLastUpdatedDisplay === "function") {
        updateLastUpdatedDisplay();
    }
    if (Array.isArray(formattedSitesData) && formattedSitesData.length > 0 && typeof applyRouteView === "function") {
        await applyRouteView(formattedSitesData);
        syncStatusTimeZoneControls(document);
        if (typeof updateLastUpdatedDisplay === "function") {
            updateLastUpdatedDisplay();
        }
    }
}

function getDatePartsInTimeZone(dateObj, timeZone = getSelectedStatusTimeZone()) {
    const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit"
    }).formatToParts(dateObj);
    return {
        year: parts.find((part) => part.type === "year")?.value || "1970",
        month: parts.find((part) => part.type === "month")?.value || "01",
        day: parts.find((part) => part.type === "day")?.value || "01"
    };
}

function formatDateKey(dateObj, timeZone = getSelectedStatusTimeZone()) {
    const parts = getDatePartsInTimeZone(dateObj, timeZone);
    const y = parts.year;
    const m = parts.month;
    const d = parts.day;
    return `${y}-${m}-${d}`;
}

function formatUtcDateKey(dateObj) {
    const y = dateObj.getUTCFullYear();
    const m = String(dateObj.getUTCMonth() + 1).padStart(2, "0");
    const d = String(dateObj.getUTCDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
}

function parseDateKey(dateKey) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateKey || "");
    if (!m) {
        return null;
    }
    const y = Number(m[1]);
    const mo = Number(m[2]);
    const d = Number(m[3]);
    const dt = new Date(Date.UTC(y, mo - 1, d));
    if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) {
        return null;
    }
    return dt;
}

function isTodayKey(dateKey) {
    return dateKey === formatDateKey(new Date());
}

function getDateLabel(dateKey) {
    const dt = parseDateKey(dateKey);
    if (!dt) {
        return insightT("date.invalid");
    }
    return dt.toLocaleDateString(insightIntlLocale(), {
        timeZone: "UTC",
        weekday: "long",
        day: "2-digit",
        month: "long",
        year: "numeric"
    });
}

function shiftDateKey(dateKey, deltaDays) {
    const base = parseDateKey(dateKey) || new Date();
    const next = new Date(base.getTime() + deltaDays * DAY_MS);
    return formatUtcDateKey(next);
}

function getTimeZoneOffsetMinutes(timeZone, date = new Date()) {
    try {
        const parts = new Intl.DateTimeFormat("en-US", {
            timeZone,
            hour: "2-digit",
            minute: "2-digit",
            timeZoneName: "shortOffset"
        }).formatToParts(date);
        const name = parts.find((part) => part.type === "timeZoneName")?.value || "GMT";
        if (name === "GMT" || name === "UTC") {
            return 0;
        }
        const match = /(?:GMT|UTC)([+-])(\d{1,2})(?::?(\d{2}))?/.exec(name);
        if (!match) {
            return 0;
        }
        const sign = match[1] === "-" ? -1 : 1;
        const hours = Number(match[2]) || 0;
        const minutes = Number(match[3]) || 0;
        return sign * ((hours * 60) + minutes);
    } catch (_error) {
        return 0;
    }
}

function parseStatusDateTime(value, sourceTimeZone = STATUS_SOURCE_TIME_ZONE) {
    const raw = String(value || "").trim();
    if (!raw) {
        return null;
    }
    const normalized = raw.replace(" ", "T");
    if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(normalized)) {
        const direct = new Date(normalized);
        return Number.isNaN(direct.getTime()) ? null : direct;
    }
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2})(?::(\d{2}))?(?::(\d{2}))?/.exec(raw);
    if (!match) {
        const fallback = new Date(normalized);
        return Number.isNaN(fallback.getTime()) ? null : fallback;
    }
    const utcGuess = Date.UTC(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
        Number(match[4]),
        Number(match[5] || 0),
        Number(match[6] || 0)
    );
    const firstOffset = getTimeZoneOffsetMinutes(sourceTimeZone, new Date(utcGuess));
    let instant = new Date(utcGuess - (firstOffset * 60 * 1000));
    const secondOffset = getTimeZoneOffsetMinutes(sourceTimeZone, instant);
    if (secondOffset !== firstOffset) {
        instant = new Date(utcGuess - (secondOffset * 60 * 1000));
    }
    return Number.isNaN(instant.getTime()) ? null : instant;
}

function getHourInSelectedTimeZone(dateObj) {
    const parts = new Intl.DateTimeFormat(insightIntlLocale(), {
        timeZone: getSelectedStatusTimeZone(),
        hour: "2-digit",
        hour12: false
    }).formatToParts(dateObj);
    const hour = parts.find((part) => part.type === "hour")?.value || "00";
    return Number(hour) % 24;
}

function formatStatusHourLabel(checkedAt, fallbackHour = null) {
    const parsed = parseStatusDateTime(checkedAt);
    if (parsed) {
        const hour = getHourInSelectedTimeZone(parsed);
        return insightLocale() === "en" ? `${hour}:00` : `${hour} h`;
    }
    if (fallbackHour === null || fallbackHour === undefined || fallbackHour === "") {
        return insightLocale() === "en" ? "?:00" : "? h";
    }
    const hour = Number(fallbackHour);
    return Number.isFinite(hour) ? (insightLocale() === "en" ? `${hour}:00` : `${hour} h`) : (insightLocale() === "en" ? "?:00" : "? h");
}

function formatStatusDateTimeReadable(dateStr) {
    const parsed = parseStatusDateTime(dateStr);
    if (!parsed) {
        return dateStr;
    }
    return parsed.toLocaleString(insightIntlLocale(), {
        timeZone: getSelectedStatusTimeZone(),
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit"
    });
}
function extractMainDomain(url) {
    const parts = url.replace(/^https?:\/\//, '').split(".");
    return parts.slice(-2).join(".");
}

// Generate four deterministic variants (same hue/saturation, lightness capped at 50%, distinct alphas)
function updateDotColors(hexColor) {
    if (hexColor === lastDotColor) return; // no change needed
    lastDotColor = hexColor;

    const { h, s } = hexToHsl(hexColor);
    // Pre‑defined lightness (≤ 40 %) and alpha progression
    const lightnesses = [10, 18, 24, 40];   // % (max 40 %)
    const alphas      = [0.10, 0.25, 0.35, 0.50];

    const dots = document.querySelectorAll('.blurred-dots .dot');

    dots.forEach((dot, idx) => {
        const l = lightnesses[idx] !== undefined ? lightnesses[idx] : 40;
        const a = alphas[idx]      !== undefined ? alphas[idx]      : 0.30;
        const color = `hsla(${h}, ${s}%, ${l}%, ${a})`;
        dot.style.backgroundColor = color;
        // Ensure we keep only blur in filter (no brightness tweaks)
        dot.style.filter = 'blur(150px)';
        cachedDotColors[idx] = color;
    });
}

// Convert hex color to HSL (returns object with h, s, l)
function hexToHsl(hex) {
    // Remove # if present
    hex = hex.replace(/^#/, '');
    if (hex.length === 3) {
        hex = hex.split('').map(x => x + x).join('');
    }
    const num = parseInt(hex, 16);
    const r = ((num >> 16) & 255) / 255;
    const g = ((num >> 8) & 255) / 255;
    const b = (num & 255) / 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    let h, s, l = (max + min) / 2;
    if (max === min) {
        h = s = 0; // achromatic
    } else {
        const d = max - min;
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
        switch (max) {
            case r: h = (g - b) / d + (g < b ? 6 : 0); break;
            case g: h = (b - r) / d + 2; break;
            case b: h = (r - g) / d + 4; break;
        }
        h = h * 60;
    }
    return {
        h: Math.round(h || 0),
        s: Math.round((s || 0) * 100),
        l: Math.round(l * 100)
    };
}
