async function fetchStatusData() {
    const runtimeStatePromise = fetchPublicRuntimeState().then((runtimeState) => {
        publicRuntimeState = runtimeState;
        dispatchWindowEvent("publicRuntimeStateFetched", { state: runtimeState });
        return runtimeState;
    });

    try {
        let data = await fetchStatsData({
            withSitesFallback: false,
            includeDaily: false,
            includeIncidents: false
        });
        if ((!Array.isArray(data) || data.length === 0) && isLocalStatusPreview()) {
            data = buildLocalPreviewStatusData();
        } else if (!Array.isArray(data) || data.length === 0) {
            data = await fetchStatsData({
                withSitesFallback: true,
                includeDaily: false,
                includeIncidents: false
            });
        }
        if (!Array.isArray(data)) {
            data = [];
        }

        const formattedData = formatDataByHours(data);
        allSitesRawData = data;

        updateGlobalStatus(formattedData);
        updateLastCheckedTime(formattedData);
        await applyRouteView(formattedData);

        dispatchWindowEvent("statusDataFetched", { data });
        dispatchWindowEvent("dataFormatted", { formattedData });
        dispatchWindowEvent("pageUpdated", { formattedData });
        dispatchWindowEvent("globalStatusUpdated", { formattedData });
        dispatchWindowEvent("lastCheckedTimeUpdated", { formattedData });
        dispatchDocumentEvent("dataLoaded");
    } catch (error) {
        console.error("Erreur de connexion: ", error);
        dispatchWindowEvent("dataFetchError", { error });
    } finally {
        await runtimeStatePromise;
    }
}

function getStatusApiBaseUrl() {
    const configuredUrl = String(window.INSIGHT_CONFIG?.apiBaseUrl || window.INSIGHT_CONFIG?.publicUrl || "").trim();
    return configuredUrl || window.location.origin;
}

function buildStatusApiUrl(path) {
    const baseUrl = getStatusApiBaseUrl().replace(/\/+$/, "");
    const cleanPath = String(path || "").replace(/^\/+/, "");
    return `${baseUrl}/${cleanPath}`;
}

async function fetchPublicRuntimeState() {
    const url = buildStatusApiUrl("api/public_runtime_state.php");
    try {
        const response = await fetchWithTimeout(url, API_TIMEOUT_MS);
        const raw = await response.text();
        if (!response.ok) {
            return null;
        }
        if (!raw || !raw.trim()) {
            return null;
        }
        const payload = JSON.parse(raw);
        if (!payload || payload.ok !== true) {
            return null;
        }
        if (!payload.data || typeof payload.data !== "object") {
            return null;
        }
        return payload.data;
    } catch (_error) {
        return null;
    }
}

function isLocalStatusPreview() {
    const host = String(window.location.hostname || "").toLowerCase();
    return host === "localhost" || host === "127.0.0.1" || host === "::1";
}

function formatLocalPreviewDate(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function formatLocalPreviewDateTime(date) {
    return `${formatLocalPreviewDate(date)} ${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}:00`;
}

function buildLocalPreviewHour(relativeHour, state, responseBase) {
    const slot = new Date();
    slot.setMinutes(Math.max(0, slot.getMinutes() - 4), 0, 0);
    slot.setHours(slot.getHours() - relativeHour);
    const responseTime = state === "NO" || state === "UNKNOWN"
        ? null
        : responseBase + ((relativeHour * 17) % 86);
    const minutesOffline = state === "NO"
        ? 60
        : (state === "PARTIALLY" ? 14 : 0);
    return {
        date: formatLocalPreviewDate(slot),
        hour: slot.getHours(),
        relative_hour: relativeHour,
        avg_response_time: responseTime,
        minutes_offline: minutesOffline,
        binary_sequence: null,
        checked_at: formatLocalPreviewDateTime(slot),
        hasBeenOnline: state,
        maintenance: state === "MAINTENANCE"
    };
}

function buildLocalPreviewService(url, states, responseBase, ssl, maintenanceWindows = []) {
    const currentState = states[0] || "YES";
    const lastErrorType = currentState === "NO"
        ? "http_5xx"
        : (currentState === "PARTIALLY" ? "slow_response" : null);
    const probeMeta = {
        probe_interval_sec: 60,
        calc_method: "time_weighted",
        checked_by: "pyt",
        source_node: "local-preview",
        last_error_type: lastErrorType,
        last_probe_at: formatLocalPreviewDateTime(new Date()),
        last_probe_status: currentState === "NO" ? "offline" : "online",
        last_probe_http_code: currentState === "NO" ? 503 : 200,
        last_probe_response_time: currentState === "NO" ? null : responseBase,
        probe_type: "http"
    };
    return {
        url,
        data: Array.from({ length: 24 }, (_, index) => buildLocalPreviewHour(index, states[index] || "YES", responseBase)),
        daily_data: [],
        incidents: [],
        probe_interval_sec: probeMeta.probe_interval_sec,
        calc_method: probeMeta.calc_method,
        checked_by: probeMeta.checked_by,
        source_node: probeMeta.source_node,
        last_error_type: probeMeta.last_error_type,
        probe_meta: probeMeta,
        ssl,
        maintenance_windows: maintenanceWindows
    };
}

function buildLocalPreviewStatusData() {
    const now = new Date();
    const later = new Date(now.getTime() + (2 * 60 * 60 * 1000));
    const plannedEnd = new Date(now.getTime() + (4 * 60 * 60 * 1000));
    return [
        buildLocalPreviewService(
            "https://example.com",
            ["YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES"],
            84,
            {
                days_remaining: 76,
                subject_cn: "example.com",
                issuer_name: "Let's Encrypt",
                valid_from: formatLocalPreviewDateTime(new Date(now.getTime() - (12 * DAY_MS))),
                valid_to: formatLocalPreviewDateTime(new Date(now.getTime() + (76 * DAY_MS)))
            }
        ),
        buildLocalPreviewService(
            "https://status.example.com",
            ["PARTIALLY", "PARTIALLY", "YES", "YES", "YES", "UNKNOWN", "YES", "YES", "YES", "YES", "YES", "YES"],
            132,
            {
                days_remaining: 31,
                subject_cn: "status.example.com",
                issuer_name: "Let's Encrypt",
                valid_from: formatLocalPreviewDateTime(new Date(now.getTime() - (29 * DAY_MS))),
                valid_to: formatLocalPreviewDateTime(new Date(now.getTime() + (31 * DAY_MS)))
            }
        ),
        buildLocalPreviewService(
            "https://api.example.com",
            ["NO", "PARTIALLY", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES"],
            178,
            {
                days_remaining: 8,
                subject_cn: "api.example.com",
                issuer_name: "Let's Encrypt",
                valid_from: formatLocalPreviewDateTime(new Date(now.getTime() - (82 * DAY_MS))),
                valid_to: formatLocalPreviewDateTime(new Date(now.getTime() + (8 * DAY_MS)))
            }
        ),
        buildLocalPreviewService(
            "https://docs.example.com",
            ["MAINTENANCE", "MAINTENANCE", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES", "YES"],
            96,
            {
                days_remaining: 109,
                subject_cn: "docs.example.com",
                issuer_name: "Google Trust Services",
                valid_from: formatLocalPreviewDateTime(new Date(now.getTime() - (5 * DAY_MS))),
                valid_to: formatLocalPreviewDateTime(new Date(now.getTime() + (109 * DAY_MS)))
            },
            [{
                id: 9001,
                title: insightT("demo.maintenanceTitle"),
                description: insightT("demo.maintenanceDescription"),
                title_key: "demo.maintenanceTitle",
                description_key: "demo.maintenanceDescription",
                state: "planned",
                starts_at: formatLocalPreviewDateTime(later),
                ends_at: formatLocalPreviewDateTime(plannedEnd),
                site_url: "docs.example.com"
            }]
        ),
        buildLocalPreviewService(
            "https://shop.example.com",
            ["YES", "YES", "YES", "UNKNOWN", "UNKNOWN", "YES", "YES", "YES", "YES", "YES", "YES", "YES"],
            118,
            {
                days_remaining: 54,
                subject_cn: "shop.example.com",
                issuer_name: "Let's Encrypt",
                valid_from: formatLocalPreviewDateTime(new Date(now.getTime() - (36 * DAY_MS))),
                valid_to: formatLocalPreviewDateTime(new Date(now.getTime() + (54 * DAY_MS)))
            }
        )
    ];
}

function buildApiUrl({
    mode = "stats",
    dateKey = null,
    withSitesFallback = false,
    includeDaily = false,
    includeIncidents = false,
    incidentsLimit = null,
    incidentsOffset = null,
    siteUrls = [],
    format = null
} = {}) {
    const params = new URLSearchParams();
    params.set("contract", "v2");
    if (mode && mode !== "stats") {
        params.set("mode", mode);
    }
    if (dateKey) {
        params.set("date", dateKey);
    }
    if (withSitesFallback) {
        params.set("with_sites", "1");
    }
    params.set("include_daily", includeDaily ? "1" : "0");
    params.set("include_incidents", includeIncidents ? "1" : "0");
    if (Number.isInteger(incidentsLimit) && incidentsLimit > 0) {
        params.set("incidents_limit", String(incidentsLimit));
    }
    if (Number.isInteger(incidentsOffset) && incidentsOffset >= 0) {
        params.set("incidents_offset", String(incidentsOffset));
    }
    if (format) {
        params.set("format", String(format));
    }
    if (Array.isArray(siteUrls) && siteUrls.length > 0) {
        siteUrls.forEach((siteUrl) => {
            if (siteUrl) {
                params.append("site_urls[]", String(siteUrl));
            }
        });
    }
    return `${buildStatusApiUrl("hourly_stats_report.php")}?${params.toString()}`;
}

async function fetchApiData(options = {}) {
    const url = buildApiUrl(options);
    const timeoutMs = Number.isFinite(options.timeoutMs) ? options.timeoutMs : API_TIMEOUT_MS;
    let lastError = null;

    for (let attempt = 0; attempt <= API_RETRY_DELAYS_MS.length; attempt++) {
        try {
            const response = await fetchWithTimeout(url, timeoutMs);
            const raw = await response.text();
            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}${raw ? ` - ${raw.slice(0, 120)}` : ""}`);
                error.status = response.status;
                throw error;
            }
            if (!raw || !raw.trim()) {
                return [];
            }
            try {
                return JSON.parse(raw);
            } catch (_parseError) {
                const error = new Error(`JSON invalide (${raw.slice(0, 120)})`);
                error.code = "INVALID_JSON";
                throw error;
            }
        } catch (error) {
            lastError = error;
            const canRetry = !isLocalStatusPreview() && isRetryableFetchError(error) && attempt < API_RETRY_DELAYS_MS.length;
            if (!canRetry) {
                throw error;
            }
            await sleep(API_RETRY_DELAYS_MS[attempt]);
        }
    }

    throw lastError || new Error("Erreur réseau inconnue");
}

function unwrapV2ContractPayload(payload, expectedMode) {
    if (!payload || typeof payload !== "object" || payload.contract !== "v2") {
        return payload;
    }

    if (payload.mode && payload.mode !== expectedMode) {
        throw new Error(`Réponse API inattendue: mode=${payload.mode}`);
    }
    if (payload.success === false) {
        const errCode = payload.error && payload.error.code ? payload.error.code : "api_error";
        const errMessage = payload.error && payload.error.message ? payload.error.message : "Erreur API";
        const error = new Error(`${errCode}: ${errMessage}`);
        error.code = errCode;
        throw error;
    }
    return payload.data;
}

async function fetchStatsData({
    dateKey = null,
    withSitesFallback = false,
    includeDaily = false,
    includeIncidents = false
} = {}) {
    try {
        const rawPayload = await fetchApiData({
            mode: "stats",
            dateKey,
            withSitesFallback,
            includeDaily,
            includeIncidents
        });
        const payload = unwrapV2ContractPayload(rawPayload, "stats");
        return Array.isArray(payload) ? payload : [];
    } catch (error) {
        if (!isLocalStatusPreview()) {
            console.warn("Erreur fetch stats:", error);
        }
        return [];
    }
}

async function fetchIncidentsData(siteUrls, incidentsOffset = 0, incidentsLimit = 10) {
    const uniqueUrls = Array.from(
        new Set((Array.isArray(siteUrls) ? siteUrls : []).filter(Boolean).map((url) => String(url)))
    );
    if (uniqueUrls.length === 0) {
        return { items: [], has_more: false, next_offset: 0, error: false };
    }

    try {
        const rawPayload = await fetchApiData({
            mode: "incidents",
            siteUrls: uniqueUrls,
            incidentsOffset,
            incidentsLimit
        });
        const payload = unwrapV2ContractPayload(rawPayload, "incidents");
        if (!payload || typeof payload !== "object") {
            return { items: [], has_more: false, next_offset: incidentsOffset, error: false };
        }
        return {
            items: Array.isArray(payload.items) ? payload.items : [],
            has_more: Boolean(payload.has_more),
            next_offset: Number.isInteger(payload.next_offset) ? payload.next_offset : incidentsOffset,
            error: false
        };
    } catch (error) {
        if (!isLocalStatusPreview()) {
            console.warn("Erreur fetch incidents:", error);
        }
        return { items: [], has_more: false, next_offset: incidentsOffset, error: true };
    }
}

function formatDataByHours(data) {
    return (Array.isArray(data) ? data : []).map(site => {
        const sourceHours = Array.isArray(site && site.data) ? site.data : [];
        const byRelativeHour = new Map();

        sourceHours.forEach((hourEntry, index) => {
            if (!hourEntry || typeof hourEntry !== "object") {
                return;
            }

            let relative = Number(hourEntry.relative_hour);
            if (!Number.isInteger(relative) || relative < 0 || relative > 23) {
                // Fallback defensif: si l'API renvoie une entrée partielle,
                // on garde quand même la position séquentielle dans la fenêtre 24h.
                relative = index;
            }
            if (!Number.isInteger(relative) || relative < 0 || relative > 23) {
                return;
            }

            if (!byRelativeHour.has(relative)) {
                byRelativeHour.set(relative, hourEntry);
            }
        });

        const parseSlotDateTime = (entry) => {
            if (!entry || typeof entry !== "object") {
                return null;
            }
            const dateRaw = typeof entry.date === "string" ? entry.date.trim() : "";
            const hourRaw = Number(entry.hour);
            if (!dateRaw || !Number.isInteger(hourRaw) || hourRaw < 0 || hourRaw > 23) {
                return null;
            }
            const dt = new Date(`${dateRaw}T${String(hourRaw).padStart(2, "0")}:00:00`);
            return Number.isNaN(dt.getTime()) ? null : dt;
        };

        const deriveAnchorDateTime = () => {
            for (let r = 0; r < 24; r++) {
                const entry = byRelativeHour.get(r);
                if (!entry) {
                    continue;
                }
                const slotTime = parseSlotDateTime(entry);
                if (!slotTime) {
                    continue;
                }
                const anchor = new Date(slotTime.getTime() + (r * 60 * 60 * 1000));
                if (!Number.isNaN(anchor.getTime())) {
                    return anchor;
                }
            }
            const fallback = new Date();
            fallback.setMinutes(0, 0, 0);
            fallback.setHours(fallback.getHours() - 1);
            return fallback;
        };

        const anchorDateTime = deriveAnchorDateTime();
        const normalizedHours = Array.from({ length: 24 }, (_, i) => {
            const existing = byRelativeHour.get(i);
            if (existing && typeof existing === "object") {
                const state = ["YES", "PARTIALLY", "NO", "UNKNOWN", "MAINTENANCE"].includes(existing.hasBeenOnline)
                    ? existing.hasBeenOnline
                    : "UNKNOWN";
                return {
                    ...existing,
                    relative_hour: i,
                    hasBeenOnline: state
                };
            }

            const slotTime = new Date(anchorDateTime.getTime() - (i * 60 * 60 * 1000));
            return {
                date: `${slotTime.getFullYear()}-${String(slotTime.getMonth() + 1).padStart(2, "0")}-${String(slotTime.getDate()).padStart(2, "0")}`,
                hour: slotTime.getHours(),
                relative_hour: i,
                avg_response_time: null,
                minutes_offline: null,
                binary_sequence: null,
                checked_at: null,
                hasBeenOnline: "UNKNOWN",
                maintenance: false
            };
        });

        return {
            url: site.url,
            hours: normalizedHours,
            data_quality: site.data_quality && typeof site.data_quality === "object" ? site.data_quality : null,
            probe_interval_sec: Number.isFinite(Number(site.probe_interval_sec)) ? Number(site.probe_interval_sec) : null,
            calc_method: typeof site.calc_method === "string" ? site.calc_method : null,
            checked_by: typeof site.checked_by === "string" ? site.checked_by : null,
            source_node: typeof site.source_node === "string" ? site.source_node : null,
            last_error_type: typeof site.last_error_type === "string" ? site.last_error_type : null,
            probe_meta: site.probe_meta && typeof site.probe_meta === "object" ? site.probe_meta : null,
            daily_data: Array.isArray(site.daily_data) ? site.daily_data : [],
            incidents: site.incidents || [],
            ssl: site.ssl || null,
            maintenance_active: Boolean(site.maintenance_active),
            maintenance_current: site.maintenance_current || null,
            maintenance_upcoming: site.maintenance_upcoming || null,
            maintenance_windows: Array.isArray(site.maintenance_windows) ? site.maintenance_windows : []
        };
    });
}
