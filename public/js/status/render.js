function resetBreadcrumb() {
    const breadcrumb = document.getElementById("breadcrumb");
    resetDetailActions();
    if (!breadcrumb) {
        return;
    }
    detailSettingsOpen = false;
    breadcrumb.classList.remove("flex", "items-center", "justify-between", "gap-3", "flex-wrap");
    breadcrumb.textContent = "";
    const serviceLink = document.createElement("span");
    serviceLink.id = "breadcrumb-service";
    serviceLink.className = "cursor-pointer";
    serviceLink.textContent = insightT("common.services");
    breadcrumb.appendChild(serviceLink);
    serviceLink.addEventListener("click", () => {
        updatePage(formattedSitesData);
    });
}

const statusCardCroixMarkup = `
    <span class="status-card-croix status-card-croix--h" aria-hidden="true"></span>
    <span class="status-card-croix status-card-croix--v" aria-hidden="true"></span>
`;

const incidentTreeConnectorMarkup = `
    <svg class="incident-tree-connector" aria-hidden="true" focusable="false" viewBox="0 0 26 14" preserveAspectRatio="none">
        <path d="M0 0 V6 C0 9.866 3.134 13 7 13 H26"></path>
    </svg>
`;

function decorateStatusCardElement(element) {
    if (!element) {
        return element;
    }
    element.classList.add("status-grid-card");
    element.insertAdjacentHTML("afterbegin", statusCardCroixMarkup);
    return element;
}

function destroyStatusCharts(container) {
    if (!container || typeof Chart === "undefined" || typeof Chart.getChart !== "function") {
        return;
    }
    container.querySelectorAll("canvas.response-chart").forEach((canvas) => {
        const chart = Chart.getChart(canvas);
        if (chart) {
            chart.destroy();
        }
    });
}

function updatePage(data) {
    resetBreadcrumb();
    formattedSitesData = data;
    clearDetailRoute(false);
    const statusCardsContainer = document.querySelector(".status-cards");
    destroyStatusCharts(statusCardsContainer);
    statusCardsContainer.innerHTML = "";
    const reportIncidentSection = document.getElementById("report-incident");
    if (reportIncidentSection) {
        reportIncidentSection.classList.remove("hidden");
    }
    statusCardsContainer.classList.add("summary-grid");
    const domains = organizeByDomain(data);
    for (const [mainDomain, subdomains] of Object.entries(domains)) {
        const summaryCard = createSummaryCard(mainDomain, subdomains);
        statusCardsContainer.appendChild(summaryCard);
    }
    renderPlannedMaintenancesSection(data);
    if (typeof window.syncStatusGrid === "function") {
        window.syncStatusGrid();
    }
}

function collectMaintenanceWindows(data) {
    const byId = new Map();
    (Array.isArray(data) ? data : []).forEach((site) => {
        const siteUrl = String(site?.url || "");
        const windows = Array.isArray(site?.maintenance_windows) ? site.maintenance_windows : [];
        windows.forEach((window) => {
            if (!window || typeof window !== "object") {
                return;
            }
            const id = Number(window.id);
            if (!Number.isInteger(id) || id <= 0) {
                return;
            }
            const previous = byId.get(id);
            if (previous) {
                return;
            }
            byId.set(id, {
                ...window,
                site_url: window.is_global ? insightT("maintenance.allServices") : (window.site_url || siteUrl || insightT("maintenance.defaultTitle")),
            });
        });
    });
    return Array.from(byId.values()).sort((a, b) => {
        const aStart = new Date(String(a?.starts_at || "").replace(" ", "T")).getTime();
        const bStart = new Date(String(b?.starts_at || "").replace(" ", "T")).getTime();
        if (Number.isNaN(aStart) && Number.isNaN(bStart)) return 0;
        if (Number.isNaN(aStart)) return 1;
        if (Number.isNaN(bStart)) return -1;
        return aStart - bStart;
    });
}

function renderPlannedMaintenancesSection(data) {
    const section = document.getElementById("planned-maintenances");
    if (!section) {
        return;
    }

    const list = section.querySelector("[data-maintenance-list]");
    const empty = section.querySelector("[data-maintenance-empty]");
    if (!list || !empty) {
        return;
    }

    const windows = collectMaintenanceWindows(data).filter((window) => {
        const state = String(window?.state || "").toLowerCase();
        return state === "planned" || state === "active";
    });

    if (windows.length === 0) {
        list.innerHTML = "";
        empty.classList.remove("hidden");
        section.classList.add("hidden");
        return;
    }

    empty.classList.add("hidden");
    section.classList.remove("hidden");

    list.innerHTML = windows.map((window) => {
        const state = String(window?.state || "").toLowerCase();
        const badgeClass = state === "active"
            ? "maintenance-badge maintenance-badge-active"
            : "maintenance-badge maintenance-badge-planned";
        const badgeLabel = state === "active" ? insightT("maintenance.active") : insightT("maintenance.planned");
        const title = window?.title_key ? insightT(String(window.title_key)) : String(window?.title || insightT("maintenance.defaultTitle"));
        const service = String(window?.site_url || insightT("maintenance.defaultTitle"));
        const startsAt = formatDateReadable(String(window?.starts_at || ""));
        const endsAt = formatDateReadable(String(window?.ends_at || ""));
        const description = window?.description_key ? insightT(String(window.description_key)) : String(window?.description || "").trim();
        return `
            <article class="maintenance-item glass-container glass-noise status-grid-card">
                ${statusCardCroixMarkup}
                <div class="maintenance-item-head">
                    <h4 class="maintenance-item-title">${escapeHtml(title)}</h4>
                    <span class="${badgeClass}">${escapeHtml(badgeLabel)}</span>
                </div>
                <p class="maintenance-item-service">${escapeHtml(service)}</p>
                <p class="maintenance-item-dates">${escapeHtml(insightT("maintenance.window", { start: startsAt, end: endsAt }))}</p>
                ${description ? `<p class="maintenance-item-description">${escapeHtml(description)}</p>` : ""}
            </article>
        `;
    }).join("");
}
function createSummaryCard(mainDomain, subdomains) {
    const card = document.createElement("div");
    card.className = "summary-card glass-container glass-noise status-grid-card";
    card.setAttribute("role", "button");
    card.setAttribute("tabindex", "0");
    card.setAttribute("aria-label", insightT("service.showDetails", { domain: mainDomain }));
    const colorClass = getDomainStatusColor(subdomains);
    let statusText = insightT("state.unknown");
    if (colorClass === "bg-green-400") statusText = insightT("state.operational");
    else if (colorClass === "bg-yellow-400") statusText = insightT("state.degraded");
    else if (colorClass === "bg-red-400") statusText = insightT("state.disconnected");
    else if (colorClass === "bg-sky-400") statusText = insightT("state.maintenance");
    else if (colorClass === "bg-gray-400") statusText = "N/A";
    let textClass = "text-green-200";
    let borderClass = "border-green-500";
    if (colorClass === "bg-yellow-400") {
        textClass = "text-yellow-500";
        borderClass = "border-yellow-300";
    } else if (colorClass === "bg-red-400") {
        textClass = "text-red-200";
        borderClass = "border-red-500";
    } else if (colorClass === "bg-sky-400") {
        textClass = "text-sky-100";
        borderClass = "border-sky-400";
    } else if (colorClass === "bg-gray-400") {
        textClass = "text-gray-200";
        borderClass = "border-gray-500";
    }
    card.innerHTML = `
        ${statusCardCroixMarkup}
        <h2 class="text-xl font-semibold text-white">${escapeHtml(mainDomain)}</h2>
        <div class="status-label px-3 py-1 rounded-full text-sm font-semibold ${textClass} ${colorClass} bg-opacity-20 backdrop-blur-md border ${borderClass}">
            ${statusText}
        </div>
    `;
    const openDetail = () => {
        detailDateKey = formatDateKey(new Date());
        detailSettingsOpen = false;
        detailDateUserSet = false;
        showDomainDetail(mainDomain, subdomains, { pushRoute: true });
    };
    card.addEventListener("click", openDetail);
    card.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            openDetail();
        }
    });
    return card;
}

function getDomainStatusColor(subdomains) {
    let hasDown = false;
    let hasPartial = false;
    let hasMaintenance = false;
    let allUp = true;
    subdomains.forEach(site => {
        const lastHour = site.hours.find(h => h !== null);
        if (!lastHour) { allUp = false; return; }
        if (lastHour.hasBeenOnline === "NO") { hasDown = true; allUp = false; }
        else if (lastHour.hasBeenOnline === "PARTIALLY" || lastHour.hasBeenOnline === "UNKNOWN") { hasPartial = true; allUp = false; }
        else if (lastHour.hasBeenOnline === "MAINTENANCE") { hasMaintenance = true; allUp = false; }
    });
    if (hasDown) return "bg-red-400";
    if (hasPartial) return "bg-yellow-400";
    if (hasMaintenance) return "bg-sky-400";
    if (allUp) return "bg-green-400";
    return "bg-gray-400";
}

function getProbeLedTone(status) {
    if (status === "NO") return "down";
    if (status === "PARTIALLY") return "degraded";
    if (status === "MAINTENANCE") return "maintenance";
    if (status === "UNKNOWN") return "unknown";
    return "up";
}

function loadFavicon(url, callback) {
    const faviconUrl = `https://www.google.com/s2/favicons?domain=${url}&sz=128`;
    const img = new Image();
    img.src = faviconUrl;
    img.onload = () => callback(faviconUrl);
    img.onerror = () => callback(null);
}

function formatDuration(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);

    const parts = [];
    if (days) parts.push(insightT("duration.day", { count: days }));
    if (hours) parts.push(insightT("duration.hour", { count: hours }));
    if (minutes || parts.length === 0) parts.push(insightT("duration.minute", { count: minutes }));

    return parts.join(" ");
}

function formatDateReadable(dateStr) {
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d)) return dateStr;
    return d.toLocaleString(insightIntlLocale(), {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function getDomainSubdomainsByDate(mainDomain, dateKey) {
    const effectiveDateKey = (dateKey && !isTodayKey(dateKey)) ? dateKey : null;
    return fetchStatsData({
        dateKey: effectiveDateKey,
        withSitesFallback: true,
        includeDaily: false,
        includeIncidents: false
    })
        .then((rawData) => {
            const normalized = Array.isArray(rawData) ? rawData : [];
            const formatted = formatDataByHours(normalized);
            const domains = organizeByDomain(formatted);
            return domains[mainDomain] || [];
        })
        .catch(() => []);
}

function buildUnknownSubdomains(mainDomain) {
    const domains = organizeByDomain(formattedSitesData || []);
    const source = domains[mainDomain] || [];
    return source.map((sd) => ({
        ...sd,
        hours: Array.from({ length: 24 }, (_, hour) => ({
            date: detailDateKey,
            hour,
            relative_hour: hour,
            avg_response_time: null,
            minutes_offline: null,
            binary_sequence: null,
            checked_at: null,
            hasBeenOnline: "UNKNOWN"
        })),
        incidents: []
    }));
}

function createDetailDateNav(mainDomain) {
    const nav = document.createElement("div");
    nav.className = "relative shrink-0";
    const today = formatDateKey(new Date());
    const canGoNext = detailDateKey !== today;
    nav.innerHTML = `
        <button type="button" class="detail-settings-btn inline-flex items-center gap-2 px-3 py-2 rounded-[0.5em] bg-blue-600 hover:bg-blue-700 shadow-md transition text-white text-sm" aria-expanded="false" aria-label="${escapeHtml(insightT("detail.settingsAria"))}">
            <i class="fa-solid fa-sliders text-xs" aria-hidden="true"></i>
            <span>${escapeHtml(insightT("detail.settings"))}</span>
        </button>
        <div class="detail-settings-panel hidden absolute right-0 top-full mt-2 w-[320px] max-w-[85vw] glass-container glass-noise z-20">
            <p class="text-xs text-gray-300 mb-2">${escapeHtml(insightT("detail.displayedDate"))}</p>
            <div class="flex items-center justify-between gap-2">
                <button type="button" class="detail-prev inline-flex items-center justify-center h-8 w-8 rounded-full border border-white/20 bg-white/10 hover:bg-white/20 transition" aria-label="${escapeHtml(insightT("detail.previousDay"))}">
                    <i class="fa-solid fa-chevron-left text-xs text-white" aria-hidden="true"></i>
                </button>
                <span class="text-xs text-gray-300 min-w-[190px] text-center">${getDateLabel(detailDateKey)}</span>
                <button type="button" class="detail-next inline-flex items-center justify-center h-8 w-8 rounded-full border border-white/20 bg-white/10 hover:bg-white/20 transition ${canGoNext ? "" : "opacity-40 cursor-not-allowed"}" aria-label="${escapeHtml(insightT("detail.nextDay"))}" ${canGoNext ? "" : "disabled"}>
                    <i class="fa-solid fa-chevron-right text-xs text-white" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    `;

    const settingsBtn = nav.querySelector(".detail-settings-btn");
    const settingsPanel = nav.querySelector(".detail-settings-panel");
    const prevBtn = nav.querySelector(".detail-prev");
    const nextBtn = nav.querySelector(".detail-next");
    decorateStatusCardElement(settingsPanel);

    function openPanel() {
        detailSettingsOpen = true;
        settingsBtn.setAttribute("aria-expanded", "true");
        settingsPanel.classList.remove("hidden");
    }

    function closePanel() {
        detailSettingsOpen = false;
        settingsBtn.setAttribute("aria-expanded", "false");
        settingsPanel.classList.add("hidden");
    }

    if (detailSettingsOpen) {
        openPanel();
    }

    nav.addEventListener("mouseenter", openPanel);
    nav.addEventListener("mouseleave", closePanel);
    settingsBtn.addEventListener("focus", openPanel);
    settingsBtn.addEventListener("click", () => {
        if (detailSettingsOpen) {
            closePanel();
        } else {
            openPanel();
        }
    });

    prevBtn.addEventListener("click", async () => {
        detailSettingsOpen = true;
        detailDateKey = shiftDateKey(detailDateKey, -1);
        detailDateUserSet = true;
        const subdomains = await getDomainSubdomainsByDate(mainDomain, detailDateKey);
        showDomainDetail(mainDomain, subdomains.length > 0 ? subdomains : buildUnknownSubdomains(mainDomain));
    });
    nextBtn.addEventListener("click", async () => {
        if (nextBtn.disabled) {
            return;
        }
        detailSettingsOpen = true;
        detailDateKey = shiftDateKey(detailDateKey, 1);
        detailDateUserSet = true;
        const subdomains = await getDomainSubdomainsByDate(mainDomain, detailDateKey);
        showDomainDetail(mainDomain, subdomains.length > 0 ? subdomains : buildUnknownSubdomains(mainDomain));
    });
    return nav;
}

function notifyStatusAction(message) {
    if (typeof showNotification === "function") {
        showNotification(message);
        return;
    }
    console.info(message);
}

function createCopyStatusLinkButton() {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "status-copy-link-button";
    let resetCopyButtonTimer = null;
    const setCopyButtonState = (copied) => {
        button.innerHTML = copied ? `
            <i class="fa-solid fa-check" aria-hidden="true"></i>
            <span>${escapeHtml(insightT("detail.linkCopied"))}</span>
        ` : `
            <i class="fa-solid fa-link" aria-hidden="true"></i>
            <span>${escapeHtml(insightT("detail.copyLink"))}</span>
        `;
        button.classList.toggle("is-copied", copied);
        button.setAttribute("aria-label", copied ? insightT("detail.linkCopied") : insightT("detail.copyLink"));
    };
    setCopyButtonState(false);
    button.addEventListener("click", async () => {
        const link = window.location.href;
        try {
            let copied = false;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                try {
                    await navigator.clipboard.writeText(link);
                    copied = true;
                } catch (_clipboardError) {
                    copied = false;
                }
            }
            if (!copied) {
                const textarea = document.createElement("textarea");
                textarea.value = link;
                textarea.setAttribute("readonly", "");
                textarea.style.position = "fixed";
                textarea.style.opacity = "0";
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand("copy");
                    copied = true;
                } finally {
                    textarea.remove();
                }
            }
            if (!copied) {
                throw new Error("Copie refusée");
            }
            if (resetCopyButtonTimer) {
                window.clearTimeout(resetCopyButtonTimer);
            }
            setCopyButtonState(true);
            resetCopyButtonTimer = window.setTimeout(() => {
                setCopyButtonState(false);
                resetCopyButtonTimer = null;
            }, 1800);
        } catch (_error) {
            notifyStatusAction(insightT("detail.copyFailed"));
        }
    });
    return button;
}

function handleStatusBackToServices() {
    updatePage(formattedSitesData);
}

function resetDetailActions() {
    const sectionKicker = document.querySelector(".status-section-kicker");
    const sectionTitle = document.getElementById("status-section-title");
    const actions = document.getElementById("statusDetailActions");
    const backButton = document.getElementById("statusBackButton");
    if (sectionKicker) {
        sectionKicker.dataset.i18n = "common.publicInfrastructure";
        sectionKicker.textContent = insightT("common.publicInfrastructure");
    }
    if (sectionTitle) {
        sectionTitle.dataset.i18n = "common.services";
        sectionTitle.textContent = insightT("common.services");
    }
    if (actions) {
        actions.textContent = "";
    }
    if (backButton) {
        backButton.classList.add("hidden");
    }
}

function renderDetailActions(mainDomain) {
    const sectionKicker = document.querySelector(".status-section-kicker");
    const sectionTitle = document.getElementById("status-section-title");
    const actions = document.getElementById("statusDetailActions");
    const backButton = document.getElementById("statusBackButton");
    if (sectionKicker) {
        sectionKicker.dataset.i18n = "detail.sectionKicker";
        sectionKicker.textContent = insightT("detail.sectionKicker");
    }
    if (sectionTitle) {
        delete sectionTitle.dataset.i18n;
        sectionTitle.textContent = mainDomain;
    }
    if (actions) {
        actions.textContent = "";
        actions.appendChild(createCopyStatusLinkButton());
        actions.appendChild(createDetailDateNav(mainDomain));
    }
    if (backButton) {
        backButton.classList.remove("hidden");
        if (backButton.dataset.statusBackBound !== "1") {
            backButton.dataset.statusBackBound = "1";
            backButton.addEventListener("click", handleStatusBackToServices);
        }
    }
}

function setStatusMonitorExpanded(headerEl, contentEl, expanded) {
    const trigger = headerEl.querySelector(".service-monitor-toggle");
    if (!trigger) {
        return;
    }
    headerEl.classList.toggle("is-expanded", expanded);
    trigger.setAttribute("aria-expanded", expanded ? "true" : "false");
    contentEl.hidden = !expanded;
    contentEl.classList.toggle("is-collapsed", !expanded);
    if (expanded && contentEl.insightChart) {
        window.requestAnimationFrame(() => contentEl.insightChart.resize());
    }
}

function showDomainDetail(mainDomain, subdomains, options = {}) {
    const shouldPushRoute = Boolean(options.pushRoute);
    const skipRouteUpdate = Boolean(options.skipRouteUpdate);
    const breadcrumb = document.getElementById("breadcrumb");
    const plannedMaintenances = document.getElementById("planned-maintenances");
    if (plannedMaintenances) {
        plannedMaintenances.classList.add("hidden");
    }
    // Hide "report incident" section in detail view
    const reportIncidentSection = document.getElementById("report-incident");
    if (reportIncidentSection) {
        reportIncidentSection.classList.add("hidden");
    }
    if (breadcrumb) {
        breadcrumb.classList.add("flex", "items-center", "justify-between", "gap-3", "flex-wrap");
        breadcrumb.textContent = "";
        const crumbText = document.createElement("div");
        crumbText.className = "min-w-0";
        const serviceLink = document.createElement("span");
        serviceLink.id = "breadcrumb-service";
        serviceLink.className = "cursor-pointer";
        serviceLink.textContent = insightT("common.services");
        crumbText.appendChild(serviceLink);
        crumbText.appendChild(document.createTextNode(" > "));
        const detailLabel = document.createElement("span");
        detailLabel.textContent = insightT("detail.breadcrumb", { domain: mainDomain });
        crumbText.appendChild(detailLabel);
        breadcrumb.appendChild(crumbText);
        serviceLink.addEventListener("click", () => {
            updatePage(formattedSitesData);
        });
    }
    renderDetailActions(mainDomain);
    if (!skipRouteUpdate) {
        setDetailRoute(mainDomain, shouldPushRoute);
    }
    const statusCardsContainer = document.querySelector(".status-cards");
    destroyStatusCharts(statusCardsContainer);
    statusCardsContainer.innerHTML = "";
    statusCardsContainer.classList.remove("summary-grid");

    const monitorCards = subdomains.map((sd, index) => {
        const [headerEl, contentEl] = createStatusCard(sd, {
            index,
            expanded: index === 0
        });
        statusCardsContainer.appendChild(headerEl);
        statusCardsContainer.appendChild(contentEl);
        return { headerEl, contentEl };
    });

    monitorCards.forEach((monitorCard) => {
        const trigger = monitorCard.headerEl.querySelector(".service-monitor-toggle");
        if (!trigger) {
            return;
        }
        trigger.addEventListener("click", () => {
            const shouldExpand = trigger.getAttribute("aria-expanded") !== "true";
            monitorCards.forEach(({ headerEl, contentEl }) => {
                setStatusMonitorExpanded(headerEl, contentEl, false);
            });
            if (shouldExpand) {
                setStatusMonitorExpanded(monitorCard.headerEl, monitorCard.contentEl, true);
            }
        });
    });

    renderIncidentsSection(statusCardsContainer, subdomains);
    if (typeof window.syncStatusGrid === "function") {
        window.syncStatusGrid();
    }
}

async function applyRouteView(formattedData) {
    formattedSitesData = Array.isArray(formattedData) ? formattedData : [];
    const route = getStatusRouteState();
    if ((!route.isProbeRoute || !route.domain) && shouldRenderDemoIncidentsPreview()) {
        const domains = organizeByDomain(formattedSitesData || []);
        const demoDomain = domains["example.com"] ? "example.com" : Object.keys(domains)[0];
        if (demoDomain && Array.isArray(domains[demoDomain]) && domains[demoDomain].length > 0) {
            detailDateKey = formatDateKey(new Date());
            detailDateUserSet = false;
            showDomainDetail(demoDomain, domains[demoDomain], { skipRouteUpdate: true });
            setDetailRoute(demoDomain, false);
            return;
        }
    }
    if (!route.isProbeRoute || !route.domain) {
        updatePage(formattedData);
        return;
    }

    detailDateKey = route.date || formatDateKey(new Date());
    detailDateUserSet = Boolean(route.date);
    let subdomains = await getDomainSubdomainsByDate(route.domain, detailDateKey);

    if (subdomains.length === 0) {
        const domains = organizeByDomain(formattedData || []);
        const currentDaySubdomains = domains[route.domain] || [];
        subdomains = currentDaySubdomains.length > 0 ? currentDaySubdomains : buildUnknownSubdomains(route.domain);
    }

    if (subdomains.length === 0) {
        updatePage(formattedData);
        return;
    }

    showDomainDetail(route.domain, subdomains, { skipRouteUpdate: true });
    setDetailRoute(route.domain, false);
}

function renderIncidentsSection(statusCardsContainer, subdomains) {
    const siteUrls = Array.from(new Set(subdomains.map((sd) => sd.url).filter(Boolean)));
    const incidentsHead = document.createElement("div");
    incidentsHead.className = "incidents-head";
    const incidentsSubtitle = document.createElement("h3");
    incidentsSubtitle.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-orange-300" aria-hidden="true"></i><span>${escapeHtml(insightT("incidents.title"))}</span>`;
    const feedLinks = document.createElement("div");
    feedLinks.className = "incidents-feed-links";
    if (siteUrls.length > 0) {
        const jsonUrl = buildApiUrl({
            mode: "incidents",
            siteUrls,
            incidentsLimit: 50,
            incidentsOffset: 0
        });
        const rssUrl = buildApiUrl({
            mode: "incidents",
            siteUrls,
            incidentsLimit: 50,
            incidentsOffset: 0,
            format: "rss"
        });
        feedLinks.innerHTML = `
            <a class="status-feed-link" href="${escapeHtml(jsonUrl)}" target="_blank" rel="noopener">
                <i class="fa-solid fa-code" aria-hidden="true"></i>
                <span>JSON</span>
            </a>
            <a class="status-feed-link" href="${escapeHtml(rssUrl)}" target="_blank" rel="noopener">
                <i class="fa-solid fa-rss" aria-hidden="true"></i>
                <span>RSS</span>
            </a>
        `;
    }
    incidentsHead.appendChild(incidentsSubtitle);
    incidentsHead.appendChild(feedLinks);
    statusCardsContainer.appendChild(incidentsHead);

    const incidentsContainer = document.createElement("div");
    incidentsContainer.className = "incidents-container";
    statusCardsContainer.appendChild(incidentsContainer);

    const infoLine = document.createElement("p");
    infoLine.className = "status-incidents-info hidden";
    statusCardsContainer.appendChild(infoLine);

    const moreBtn = document.createElement("button");
    moreBtn.className = "status-incidents-more hidden";
    moreBtn.type = "button";
    moreBtn.textContent = insightT("incidents.showMore");
    statusCardsContainer.appendChild(moreBtn);

    const pageSize = 10;
    let renderedCount = 0;
    let offset = 0;
    let hasMore = true;
    let loading = false;

    if (siteUrls.length === 0) {
        setIncidentState(buildIncidentStateCard({
            tone: "unavailable",
            icon: "fa-circle-question",
            title: insightT("incidents.noEndpointTitle"),
            message: insightT("incidents.noEndpointMessage"),
            detail: insightT("incidents.noEndpointDetail")
        }));
        return;
    }

    function setIncidentState(card) {
        incidentsContainer.textContent = "";
        incidentsContainer.appendChild(card);
    }

    function setMoreButtonBusy(isBusy) {
        moreBtn.disabled = isBusy;
        moreBtn.classList.toggle("is-disabled", isBusy);
    }

    if (shouldRenderDemoIncidentsPreview(subdomains)) {
        const demoItems = buildDemoIncidents(siteUrls);
        incidentsContainer.textContent = "";
        demoItems.forEach((incident) => incidentsContainer.appendChild(buildIncidentCard(incident)));
        infoLine.classList.remove("hidden");
        infoLine.textContent = insightT("incidents.demoCount", { count: demoItems.length });
        moreBtn.classList.add("hidden");
        return;
    }

    setIncidentState(buildIncidentLoadingCard());

    async function loadNextBatch() {
        if (loading || !hasMore) {
            return;
        }
        loading = true;
        setMoreButtonBusy(true);

        try {
            const previousOffset = offset;
            const response = await fetchIncidentsData(siteUrls, offset, pageSize);
            const items = Array.isArray(response.items) ? response.items : [];
            if (response.error === true && renderedCount === 0) {
                infoLine.classList.add("hidden");
                hasMore = false;
                setIncidentState(buildIncidentStateCard({
                    tone: "warning",
                    icon: "fa-triangle-exclamation",
                    title: insightT("incidents.fetchErrorTitle"),
                    message: insightT("incidents.fetchErrorMessage"),
                    detail: insightT("incidents.fetchErrorDetail")
                }));
                moreBtn.classList.add("hidden");
                return;
            }
            if (previousOffset === 0) {
                incidentsContainer.textContent = "";
            }
            items.forEach((incident) => incidentsContainer.appendChild(buildIncidentCard(incident)));
            renderedCount += items.length;
            offset = Number.isInteger(response.next_offset) ? response.next_offset : offset + items.length;
            hasMore = Boolean(response.has_more);

            if (renderedCount === 0) {
                infoLine.classList.add("hidden");
                setIncidentState(buildNoIncidentCard());
                moreBtn.classList.add("hidden");
            } else {
                infoLine.classList.remove("hidden");
                infoLine.textContent = insightT("incidents.displayed", { count: renderedCount });
                moreBtn.classList.remove("hidden");
                if (hasMore) {
                    setMoreButtonBusy(false);
                    moreBtn.textContent = insightT("incidents.showMore");
                } else {
                    setMoreButtonBusy(true);
                    moreBtn.textContent = insightT("incidents.fullHistory");
                }
            }
        } finally {
            loading = false;
            if (hasMore && !moreBtn.classList.contains("hidden")) {
                setMoreButtonBusy(false);
            }
        }
    }

    moreBtn.addEventListener("click", loadNextBatch);
    loadNextBatch();
}

function shouldRenderDemoIncidentsPreview(subdomains = []) {
    const host = String(window.location.hostname || "").toLowerCase();
    const local = host === "127.0.0.1" || host === "localhost" || host === "::1";
    if (!local) {
        return false;
    }
    const params = new URLSearchParams(window.location.search);
    const value = String(params.get("incidents") || params.get("demoIncidents") || "").toLowerCase();
    if (value === "off" || value === "0" || value === "false") {
        return false;
    }
    if (value === "demo" || value === "timeline" || value === "1" || value === "true") {
        return true;
    }
    return (Array.isArray(subdomains) ? subdomains : []).some((subdomain) => {
        const sourceNode = String(subdomain?.source_node || subdomain?.probe_meta?.source_node || "").toLowerCase();
        return sourceNode === "local-preview";
    });
}

function formatDemoIncidentDateTime(date) {
    if (typeof formatLocalPreviewDateTime === "function") {
        return formatLocalPreviewDateTime(date);
    }
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")} ${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}:00`;
}

function buildDemoIncidents(siteUrls) {
    const findUrl = (needle, fallbackIndex = 0) => siteUrls.find((siteUrl) => String(siteUrl).includes(needle)) || siteUrls[fallbackIndex] || "https://example.com";
    const mainUrl = findUrl("example.com");
    const statusUrl = findUrl("status.example.com", 1);
    const apiUrl = findUrl("api.example.com", 2);
    const shopUrl = findUrl("shop.example.com", 3);
    const now = new Date();
    const startedOpen = new Date(now.getTime() - (42 * 60 * 1000));
    const startedSlow = new Date(now.getTime() - (5 * 60 * 60 * 1000));
    const endedSlow = new Date(now.getTime() - (4 * 60 * 60 * 1000) - (18 * 60 * 1000));
    const startedResolved = new Date(now.getTime() - (28 * 60 * 60 * 1000));
    const endedResolved = new Date(now.getTime() - (27 * 60 * 60 * 1000) - (34 * 60 * 1000));
    const startedRateLimit = new Date(now.getTime() - (52 * 60 * 60 * 1000));
    const endedRateLimit = new Date(now.getTime() - (51 * 60 * 60 * 1000) - (48 * 60 * 1000));
    return [
        {
            id: 910001,
            incident_code: "INC-DEMO-001",
            url: apiUrl,
            started_at: formatDemoIncidentDateTime(startedOpen),
            ended_at: null,
            http_code: 503,
            postmortem: insightT("demo.incident1Postmortem"),
            ai_created: false,
            source_mode: "manual",
            incident_confidence: "high",
            incident_confidence_score: 92,
            reason: insightT("demo.incident1Reason"),
            source_count: 1,
            last_seen_at: formatDemoIncidentDateTime(now)
        },
        {
            id: 910002,
            incident_code: "INC-DEMO-002",
            url: statusUrl,
            started_at: formatDemoIncidentDateTime(startedSlow),
            ended_at: formatDemoIncidentDateTime(endedSlow),
            http_code: 522,
            postmortem: insightT("demo.incident2Postmortem"),
            ai_created: true,
            source_mode: "ai",
            incident_confidence: "medium",
            incident_confidence_score: 70,
            reason: insightT("demo.incident2Reason"),
            source_count: 1,
            last_seen_at: formatDemoIncidentDateTime(endedSlow)
        },
        {
            id: 910003,
            incident_code: "INC-DEMO-003",
            url: mainUrl,
            started_at: formatDemoIncidentDateTime(startedResolved),
            ended_at: formatDemoIncidentDateTime(endedResolved),
            http_code: 500,
            postmortem: insightT("demo.incident3Postmortem"),
            ai_created: false,
            source_mode: "system",
            incident_confidence: "high",
            incident_confidence_score: 85,
            reason: insightT("demo.incident3Reason"),
            source_count: 1,
            last_seen_at: formatDemoIncidentDateTime(endedResolved)
        },
        {
            id: 910004,
            incident_code: "INC-DEMO-004",
            url: shopUrl,
            started_at: formatDemoIncidentDateTime(startedRateLimit),
            ended_at: formatDemoIncidentDateTime(endedRateLimit),
            http_code: 429,
            postmortem: null,
            ai_created: false,
            source_mode: "system",
            incident_confidence: "low",
            incident_confidence_score: 38,
            reason: insightT("demo.incident4Reason"),
            source_count: 2,
            last_seen_at: formatDemoIncidentDateTime(endedRateLimit)
        }
    ];
}

function buildIncidentStateCard({ tone = "empty", icon = "fa-circle-check", title, message, detail = "" }) {
    const card = document.createElement("article");
    card.className = `incident-empty-card incident-state-card incident-state-${tone} glass-container glass-noise status-grid-card`;
    card.innerHTML = `
        ${statusCardCroixMarkup}
        ${incidentTreeConnectorMarkup}
        <span class="incident-empty-icon" aria-hidden="true">
            <i class="fa-solid ${escapeHtml(icon)}"></i>
        </span>
        <div>
            <h4>${escapeHtml(title)}</h4>
            <p>${escapeHtml(message)}</p>
            ${detail ? `<p class="incident-state-detail">${escapeHtml(detail)}</p>` : ""}
        </div>
    `;
    return card;
}

function buildIncidentLoadingCard() {
    return buildIncidentStateCard({
        tone: "loading",
        icon: "fa-spinner fa-spin",
        title: insightT("incidents.loadingTitle"),
        message: insightT("incidents.loadingMessage"),
        detail: insightT("incidents.loadingDetail")
    });
}

function buildNoIncidentCard(message = insightT("incidents.noneMessage")) {
    return buildIncidentStateCard({
        tone: "empty",
        icon: "fa-circle-check",
        title: insightT("incidents.noneTitle"),
        message,
        detail: insightT("incidents.noneDetail")
    });
}

function displayUrlWithoutProtocol(url) {
    return String(url || "").replace(/^https?:\/\//, "");
}

function getIncidentConfidenceView(inc, durationMs) {
    const raw = typeof inc.incident_confidence === "string" ? inc.incident_confidence.trim().toLowerCase() : "";
    const score = Number.isFinite(Number(inc.incident_confidence_score)) ? Number(inc.incident_confidence_score) : null;
    const fallbackConfidence = score !== null
        ? (score >= 75 ? "high" : (score >= 50 ? "medium" : "low"))
        : (durationMs >= 5 * 60 * 1000 ? "medium" : "low");
    const confidence = ["high", "medium", "low"].includes(raw) ? raw : fallbackConfidence;
    const labels = {
        high: insightT("incident.confidenceHigh"),
        medium: insightT("incident.confidenceMedium"),
        low: insightT("incident.confidenceLow")
    };
    const icons = {
        high: "fa-circle-check",
        medium: "fa-shield-halved",
        low: "fa-circle-question"
    };
    const reason = typeof inc.reason === "string" && inc.reason.trim() !== ""
        ? inc.reason.trim()
        : insightT("incident.confidenceFallback");
    return {
        confidence,
        label: labels[confidence],
        icon: icons[confidence],
        score,
        reason,
        sourceCount: Number.isFinite(Number(inc.source_count)) ? Number(inc.source_count) : 1,
        lastSeenAt: typeof inc.last_seen_at === "string" ? inc.last_seen_at : ""
    };
}

function buildIncidentCard(inc) {
    const startedAt = typeof inc.started_at === "string" ? inc.started_at : "";
    const endedAt = typeof inc.ended_at === "string" ? inc.ended_at : null;
    const startFormatted = startedAt ? formatDateReadable(startedAt) : insightT("incident.unknownDate");
    const incidentId = Number(inc && inc.id);
    const codeFromApi = typeof inc.incident_code === "string" ? inc.incident_code.trim() : "";
    const fallbackCode = Number.isInteger(incidentId) && incidentId > 0
        ? `INC-${String(incidentId).padStart(6, "0")}`
        : "INC-UNKNOWN";
    const incidentCode = codeFromApi || fallbackCode;
    const code = inc.http_code !== null && inc.http_code !== undefined ? inc.http_code : "-";
    const startDateObj = startedAt ? parseStatusDateTime(startedAt) : null;
    const endDateObj = endedAt ? parseStatusDateTime(endedAt) : new Date();
    const hasValidDates = startDateObj && !Number.isNaN(startDateObj.getTime()) && !Number.isNaN(endDateObj.getTime());
    const durationMs = hasValidDates ? (endDateObj - startDateObj) : 0;
    const durationText = hasValidDates ? formatDuration(durationMs) : "N/A";
    const isResolved = Boolean(endedAt);
    const statusClass = isResolved ? "incident-badge-resolved" : "incident-badge-open";
    const cardStateClass = isResolved ? "incident-card--resolved" : "incident-card--open";
    const iconClass = isResolved ? "fa-circle-check" : "fa-hourglass-half";
    const statusLine = isResolved
        ? insightT("incident.resolvedDuration", { duration: durationText })
        : insightT("incident.ongoingDuration", { duration: durationText });
    const confidenceView = getIncidentConfidenceView(inc, durationMs);
    const confidenceScoreLabel = confidenceView.score !== null ? ` · ${confidenceView.score}/100` : "";
    const lastSeenLabel = confidenceView.lastSeenAt ? formatDateReadable(confidenceView.lastSeenAt) : "";
    const sourceMode = typeof inc.source_mode === "string" ? inc.source_mode.trim().toLowerCase() : "";
    const byAdmin = sourceMode === "manual" || sourceMode === "ai";
    let authorLabel = byAdmin ? insightT("incident.authorAdmin") : insightT("incident.authorSystem");
    const isAiEnhanced = Boolean(inc.ai_created);
    if (isAiEnhanced) {
        authorLabel = insightT("incident.authorAi");
    }
    const postmortem = inc.postmortem ? `${String(inc.postmortem)} ${authorLabel}` : insightT("incident.noPostmortem");
    const sourceLabel = insightT("incident.sources", { count: confidenceView.sourceCount });
    const lastTraceLabel = lastSeenLabel ? insightT("incident.lastTrace", { date: lastSeenLabel }) : "";
    const serviceUrl = displayUrlWithoutProtocol(inc.url);
    const incCard = document.createElement("article");
    incCard.className = `incident-card ${cardStateClass} glass-container glass-noise status-grid-card`;
    incCard.innerHTML = `
        ${statusCardCroixMarkup}
        ${incidentTreeConnectorMarkup}
        <button type="button" class="incident-card-header" aria-expanded="false">
            <span class="incident-card-main">
                <span class="incident-code">${escapeHtml(incidentCode)}</span>
                <span class="incident-service"><i class="fa-solid fa-link" aria-hidden="true"></i>${escapeHtml(serviceUrl)}</span>
            </span>
            <span class="incident-card-side">
                <span class="incident-badge ${statusClass}">
                    <i class="fa-solid ${iconClass}" aria-hidden="true"></i>
                    <span>${escapeHtml(isResolved ? insightT("incident.resolved") : insightT("incident.ongoing"))}</span>
                </span>
                <i class="fa-solid fa-chevron-down toggle-arrow" aria-hidden="true"></i>
            </span>
        </button>
        <div class="incident-card-summary">
            <span><i class="fa-solid fa-clock" aria-hidden="true"></i>${escapeHtml(startFormatted)}</span>
            <span><i class="fa-solid fa-server" aria-hidden="true"></i>${escapeHtml(insightT("incident.code", { code }))}</span>
            <span><i class="fa-solid fa-stopwatch" aria-hidden="true"></i>${escapeHtml(statusLine)}</span>
            <span class="incident-confidence incident-confidence-${escapeHtml(confidenceView.confidence)}">
                <i class="fa-solid ${escapeHtml(confidenceView.icon)}" aria-hidden="true"></i>
                ${escapeHtml(confidenceView.label)}
            </span>
        </div>
        <div class="incident-details hidden">
            <p class="${isAiEnhanced ? "ai-enhanced" : ""}">${escapeHtml(postmortem)}</p>
            <p class="incident-confidence-reason">
                <i class="fa-solid ${escapeHtml(confidenceView.icon)}" aria-hidden="true"></i>
                <span>${escapeHtml(confidenceView.label + confidenceScoreLabel)} · ${escapeHtml(confidenceView.reason)} · ${escapeHtml(sourceLabel)}${lastTraceLabel ? ` · ${escapeHtml(lastTraceLabel)}` : ""}</span>
            </p>
        </div>
    `;

    const header = incCard.querySelector(".incident-card-header");
    header.addEventListener("click", () => {
        const details = incCard.querySelector(".incident-details");
        const arrow = incCard.querySelector(".toggle-arrow");
        const isExpanded = !details.classList.contains("hidden");
        details.classList.toggle("hidden");
        arrow.classList.toggle("rotate-180");
        header.setAttribute("aria-expanded", isExpanded ? "false" : "true");
        incCard.classList.toggle("is-open", !isExpanded);
    });

    return incCard;
}

function organizeByDomain(sites) {
    const domainMap = {};
    sites.forEach(site => {
        const mainDomain = extractMainDomain(site.url);
        if (!domainMap[mainDomain]) {
            domainMap[mainDomain] = [];
        }
        domainMap[mainDomain].push(site);
    });
    return domainMap;
}

function getSslPresentation(ssl) {
    const makePresentation = ({
        tone,
        icon,
        badgeLabel,
        headline,
        summary,
        host = "N/A",
        issuer = "N/A",
        validFrom = "N/A",
        validTo = "N/A"
    }) => ({
        tone,
        icon,
        badgeLabel,
        headline,
        summary,
        facts: [
            { label: insightT("ssl.fieldDomain"), value: host },
            { label: insightT("ssl.fieldAuthority"), value: issuer },
            { label: insightT("ssl.fieldValidFrom"), value: validFrom },
            { label: insightT("ssl.fieldExpires"), value: validTo }
        ]
    });

    if (!ssl || typeof ssl !== "object") {
        return makePresentation({
            tone: "unknown",
            icon: "fa-circle-question",
            badgeLabel: insightT("ssl.unknown"),
            headline: insightT("ssl.noDataHeadline"),
            summary: insightT("ssl.noDataSummary")
        });
    }

    const daysRemaining = Number.isFinite(Number(ssl.days_remaining)) ? Number(ssl.days_remaining) : null;
    const validFrom = ssl.valid_from ? formatDateReadable(String(ssl.valid_from)) : "N/A";
    const validTo = ssl.valid_to ? formatDateReadable(String(ssl.valid_to)) : "N/A";
    const issuer = ssl.issuer_name
        ? String(ssl.issuer_name)
        : (ssl.issuer_cn ? String(ssl.issuer_cn) : "N/A");
    const host = ssl.subject_cn
        ? String(ssl.subject_cn)
        : (ssl.host ? String(ssl.host) : "N/A");

    const baseFields = {
        host,
        issuer,
        validFrom,
        validTo
    };

    if (ssl.error_message === "not_https") {
        return makePresentation({
            tone: "info",
            icon: "fa-lock-open",
            badgeLabel: insightT("ssl.notHttps"),
            headline: insightT("ssl.notHttpsHeadline"),
            summary: insightT("ssl.notHttpsSummary"),
            ...baseFields
        });
    }

    if (ssl.error_message) {
        const normalizedError = String(ssl.error_message).replace(/_/g, " ");
        return makePresentation({
            tone: "critical",
            icon: "fa-triangle-exclamation",
            badgeLabel: insightT("ssl.error"),
            headline: insightT("ssl.errorHeadline"),
            summary: insightT("ssl.errorSummary", { error: normalizedError }),
            ...baseFields
        });
    }

    if (daysRemaining === null) {
        return makePresentation({
            tone: "unknown",
            icon: "fa-circle-question",
            badgeLabel: insightT("ssl.unknown"),
            headline: insightT("ssl.unknownHeadline"),
            summary: insightT("ssl.unknownSummary"),
            ...baseFields
        });
    }

    if (daysRemaining < 0) {
        const expiredDays = Math.abs(daysRemaining);
        return makePresentation({
            tone: "critical",
            icon: "fa-circle-xmark",
            badgeLabel: insightT("ssl.expired"),
            headline: insightT("ssl.expiredDays", { count: expiredDays }),
            summary: insightT("ssl.renewNow"),
            ...baseFields
        });
    }

    if (daysRemaining <= 7) {
        return makePresentation({
            tone: "warning",
            icon: "fa-triangle-exclamation",
            badgeLabel: insightT("ssl.urgent"),
            headline: insightT("ssl.expiresDays", { count: daysRemaining }),
            summary: insightT("ssl.planNow"),
            ...baseFields
        });
    }

    if (daysRemaining <= 30) {
        return makePresentation({
            tone: "watch",
            icon: "fa-clock",
            badgeLabel: insightT("ssl.watch"),
            headline: insightT("ssl.expiresDays", { count: daysRemaining }),
            summary: insightT("ssl.planRenewal"),
            ...baseFields
        });
    }

    return makePresentation({
        tone: "healthy",
        icon: "fa-shield-halved",
        badgeLabel: insightT("ssl.valid"),
        headline: insightT("ssl.daysRemaining", { count: daysRemaining }),
        summary: insightT("ssl.validSummary"),
        ...baseFields
    });
}

function createStatusCard(subdomain, options = {}) {
    const cleanUrl = String(subdomain.url || "");
    const displayUrl = cleanUrl.replace(/^https?:\/\//, "");
    const statusBars = createStatusBars(subdomain.hours);
    const availability = calculateAvailability(subdomain.hours);
    const qualityValue = Number(availability.qualityPercent) || 0;
    const qualityLabel = new Intl.NumberFormat(insightIntlLocale(), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(qualityValue);
    let uptimeColor = "text-yellow-400";
    if (qualityValue >= 99.99) {
        uptimeColor = "text-green-400";
    } else if (qualityValue < 95) {
        uptimeColor = "text-red-400";
    }
    const dataGapWarning = availability.isEstimated
        ? `<i class="fa-solid fa-triangle-exclamation service-monitor-warning" title="${escapeHtml(insightT("monitor.incompleteData"))}" aria-label="${escapeHtml(insightT("monitor.incompleteData"))}"></i>`
        : "";
    const statusInfo = `
        <div class="status-info">
            <div class="flex space-x-[2px]">
                ${statusBars}
            </div>
            <div class="flex justify-between mt-2 text-gray-400 text-xs">
                <span>${escapeHtml(insightT("monitor.start24h"))}</span>
                <span>${escapeHtml(insightT("monitor.latestHour"))}</span>
            </div>
        </div>`;
    const countDisruption = subdomain.hours.filter(h => h && h.hasBeenOnline === "PARTIALLY").length;
    const countOutage = subdomain.hours.filter(h => h && h.hasBeenOnline === "NO").length;
    const countMaintenance = subdomain.hours.filter(h => h && h.hasBeenOnline === "MAINTENANCE").length;
    const countInfo = subdomain.hours.filter(h => h === null || h.hasBeenOnline === "UNKNOWN").length;
    const totalSignals = countDisruption + countOutage + countInfo;
    const latestHour = subdomain.hours.find(h => h !== null) || null;
    const severityState = countOutage > 0
        ? { label: insightT("state.critical"), chipClass: "events-pill-critical", tone: "critical" }
        : countDisruption > 0
            ? { label: insightT("state.degraded"), chipClass: "events-pill-warning", tone: "warning" }
            : countMaintenance > 0
                ? { label: insightT("state.maintenance"), chipClass: "events-pill-maintenance", tone: "maintenance" }
            : countInfo > 0
                ? { label: insightT("state.partial"), chipClass: "events-pill-unknown", tone: "unknown" }
                : { label: insightT("state.stable"), chipClass: "events-pill-good", tone: "good" };
    const eventsStatusHeadline = countMaintenance > 0 && totalSignals === 0
        ? insightT("monitor.maintenanceHours", { count: countMaintenance })
        : (totalSignals === 0
        ? insightT("monitor.noAnomaly")
        : insightT("monitor.incidentHours", { count: totalSignals }));
    const sslView = getSslPresentation(subdomain.ssl);
    const probeTone = getProbeLedTone(latestHour?.hasBeenOnline);
    const probeStates = {
        up: insightT("state.operational"),
        degraded: insightT("state.degraded"),
        down: insightT("state.unavailable"),
        maintenance: insightT("state.maintenance"),
        unknown: insightT("state.unknown")
    };
    const responseTime = latestHour
        && latestHour.avg_response_time !== null
        && latestHour.avg_response_time !== ""
        && Number.isFinite(Number(latestHour.avg_response_time))
        ? `${Math.round(Number(latestHour.avg_response_time))} ms`
        : insightT("monitor.responseUnavailable");
    const monitorIndex = Number.isInteger(options.index) ? options.index : 0;
    const isExpanded = Boolean(options.expanded);
    const detailId = `service-monitor-detail-${monitorIndex}`;
    const triggerId = `service-monitor-trigger-${monitorIndex}`;
    const headerDiv = document.createElement("div");
    headerDiv.className = "status-detail-head";
    headerDiv.classList.toggle("is-expanded", isExpanded);
    headerDiv.innerHTML = `
        <button class="service-monitor-toggle" type="button" id="${triggerId}" aria-expanded="${isExpanded ? "true" : "false"}" aria-controls="${detailId}">
            <span class="service-monitor-identity">
                <span class="probe-title">${escapeHtml(displayUrl)}</span>
            </span>
            <span class="service-monitor-metrics">
                <span class="service-monitor-state service-monitor-state--${probeTone}">
                    <span class="service-monitor-state-dot" aria-hidden="true"></span>
                    ${escapeHtml(probeStates[probeTone])}
                </span>
                <span class="service-monitor-latency" aria-label="${escapeHtml(insightT("monitor.responseAria", { value: responseTime }))}">
                    <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                    <span>${escapeHtml(responseTime)}</span>
                </span>
                <span class="service-monitor-uptime ${uptimeColor}">
                    <span>${dataGapWarning}<strong>${escapeHtml(qualityLabel)}%</strong></span>
                    <small>${escapeHtml(insightT("monitor.on24h"))}</small>
                </span>
            </span>
            <i class="fa-solid fa-chevron-down service-monitor-chevron" aria-hidden="true"></i>
        </button>
        <div class="service-monitor-history" aria-label="${escapeHtml(insightT("monitor.availability24h"))}">
            ${statusInfo}
        </div>
    `;
    const contentDiv = document.createElement("div");
    contentDiv.className = "status-detail-stack";
    contentDiv.id = detailId;
    contentDiv.setAttribute("aria-labelledby", triggerId);
    contentDiv.hidden = !isExpanded;
    contentDiv.classList.toggle("is-collapsed", !isExpanded);
    contentDiv.innerHTML = `
        <div class="content-layout w-full">
            <div class="service-health-strip">
                <div class="service-health-item">
                    <div class="service-health-head">
                        <span class="service-health-label">
                            <i class="fa-solid ${escapeHtml(sslView.icon)} ssl-icon ssl-icon-${escapeHtml(sslView.tone)}" aria-hidden="true"></i>
                            ${escapeHtml(insightT("monitor.tlsCertificate"))}
                        </span>
                        <span class="ssl-badge ssl-badge-${escapeHtml(sslView.tone)}">${escapeHtml(sslView.badgeLabel)}</span>
                    </div>
                    <strong>${escapeHtml(sslView.headline)}</strong>
                </div>
                <div class="service-health-item">
                    <div class="service-health-head">
                        <span class="service-health-label">
                            <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                            ${escapeHtml(insightT("monitor.events24h"))}
                        </span>
                        <span class="events-pill ${severityState.chipClass}">${escapeHtml(severityState.label)}</span>
                    </div>
                    <strong>${escapeHtml(eventsStatusHeadline)}</strong>
                </div>
            </div>
        </div>
    `;
    const chartContainer = document.createElement("div");
    chartContainer.className = "response-chart-card glass-container glass-noise status-grid-card w-full flex flex-col";
    chartContainer.innerHTML = `
        ${statusCardCroixMarkup}
        <div class="title-row mb-2">
            <h3 class="text-sm font-semibold text-white text-left"><i class="fa-solid fa-gauge-high mr-2 text-green-300"></i>${escapeHtml(insightT("monitor.responseTime"))} <span class="text-xs text-gray-400">${escapeHtml(insightT("monitor.period24h"))}</span></h3>
        </div>
        <div class="response-chart-shell flex-grow">
            <canvas class="response-chart w-full h-full"></canvas>
        </div>
    `;
    const contentLayout = contentDiv.querySelector(".content-layout");
    const healthStrip = contentDiv.querySelector(".service-health-strip");
    if (contentLayout && healthStrip) {
        contentLayout.insertBefore(chartContainer, healthStrip);
    } else {
        contentDiv.appendChild(chartContainer);
    }
    const canvas = chartContainer.querySelector("canvas");
    const ctx = canvas.getContext("2d");
    const pingMeta = subdomain.hours.map((hour, index) => {
        const normalizedHour = hour && Number.isFinite(Number(hour.hour)) ? Number(hour.hour) : index;
        const hasPing = Boolean(hour) && hour.avg_response_time !== null && Number.isFinite(Number(hour.avg_response_time));
        const pingValue = hasPing ? Number(hour.avg_response_time) : 0;
        return {
            hour: normalizedHour,
            hasPing,
            pingValue,
            status: hour?.hasBeenOnline || "UNKNOWN"
        };
    });
    // Same visual order as status bars: oldest on the left, most recent on the right.
    const pingMetaOrdered = pingMeta.slice().reverse();
    const responseChartTones = {
        up: {
            line: "rgba(16, 163, 127, 1)",
            fill: "rgba(16, 163, 127, 0.12)",
            glow: "rgba(16, 163, 127, 0.3)",
            hoverGlow: "rgba(16, 163, 127, 0.58)",
            aura: "rgba(16, 163, 127, 0.16)",
            icon: "text-green-300"
        },
        degraded: {
            line: "rgba(250, 204, 21, 1)",
            fill: "rgba(250, 204, 21, 0.2)",
            glow: "rgba(216, 155, 24, 0.3)",
            hoverGlow: "rgba(216, 155, 24, 0.58)",
            aura: "rgba(216, 155, 24, 0.16)",
            icon: "text-yellow-300"
        },
        down: {
            line: "rgba(197, 61, 69, 1)",
            fill: "rgba(197, 61, 69, 0.12)",
            glow: "rgba(197, 61, 69, 0.3)",
            hoverGlow: "rgba(197, 61, 69, 0.58)",
            aura: "rgba(197, 61, 69, 0.16)",
            icon: "text-rose-300"
        },
        maintenance: {
            line: "rgba(47, 111, 159, 1)",
            fill: "rgba(47, 111, 159, 0.11)",
            glow: "rgba(47, 111, 159, 0.28)",
            hoverGlow: "rgba(47, 111, 159, 0.55)",
            aura: "rgba(47, 111, 159, 0.15)",
            icon: "text-cyan-300"
        },
        unknown: {
            line: "rgba(148, 163, 184, 0.95)",
            fill: "rgba(148, 163, 184, 0.13)",
            glow: "rgba(148, 163, 184, 0.45)",
            hoverGlow: "rgba(203, 213, 225, 0.78)",
            aura: "rgba(148, 163, 184, 0.22)",
            icon: "text-gray-300"
        }
    };
    const responseChartTheme = document.documentElement.classList.contains("dark")
        ? {
            grid: "rgba(250, 250, 250, 0.1)",
            tick: "rgba(250, 250, 250, 0.62)",
            tickMuted: "rgba(250, 250, 250, 0.54)",
            pointBorder: "#09090b",
            tooltipBackground: "rgba(24, 24, 27, 0.96)",
            tooltipBorder: "#3f3f46"
        }
        : {
            grid: "rgba(32, 33, 35, 0.075)",
            tick: "rgba(32, 33, 35, 0.52)",
            tickMuted: "rgba(32, 33, 35, 0.48)",
            pointBorder: "#f8fafc",
            tooltipBackground: "rgba(55, 65, 81, 0.9)",
            tooltipBorder: "#4b5563"
        };

    function getResponseChartTone(status) {
        if (status === "NO") return "down";
        if (status === "PARTIALLY") return "degraded";
        if (status === "MAINTENANCE") return "maintenance";
        if (status === "UNKNOWN") return "unknown";
        return "up";
    }

    function getResponseChartToneView(meta) {
        const tone = getResponseChartTone(meta?.status);
        return responseChartTones[tone] || responseChartTones.unknown;
    }

    function createResponseChartFill(context) {
        const chartArea = context.chart.chartArea;
        if (!chartArea) {
            return responseChartTones.up.fill;
        }
        const gradient = context.chart.ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
        const maxIndex = Math.max(1, pingMetaOrdered.length - 1);
        pingMetaOrdered.forEach((meta, index) => {
            gradient.addColorStop(index / maxIndex, getResponseChartToneView(meta).fill);
        });
        return gradient;
    }

    function createResponseChartSegment(context) {
        const start = pingMetaOrdered[context.p0DataIndex];
        const end = pingMetaOrdered[context.p1DataIndex];
        const startColor = getResponseChartToneView(start).line;
        const endColor = getResponseChartToneView(end).line;
        const chart = context.chart;
        const x0 = context.p0?.x;
        const x1 = context.p1?.x;
        if (!chart || !Number.isFinite(x0) || !Number.isFinite(x1) || x0 === x1) {
            return endColor;
        }
        const gradient = chart.ctx.createLinearGradient(x0, 0, x1, 0);
        gradient.addColorStop(0, startColor);
        gradient.addColorStop(1, endColor);
        return gradient;
    }

    function getResponseChartDominantGlow() {
        const tones = pingMetaOrdered.map((meta) => getResponseChartTone(meta.status));
        if (tones.includes("down")) return responseChartTones.down.glow;
        if (tones.includes("degraded")) return responseChartTones.degraded.glow;
        if (tones.includes("maintenance")) return responseChartTones.maintenance.glow;
        if (tones.includes("unknown")) return responseChartTones.unknown.glow;
        return responseChartTones.up.glow;
    }

    function getResponseChartActiveIndex(chart) {
        const activeElements = typeof chart.getActiveElements === "function" ? chart.getActiveElements() : [];
        const tooltipElements = typeof chart.tooltip?.getActiveElements === "function" ? chart.tooltip.getActiveElements() : [];
        const active = [...activeElements, ...tooltipElements].find((item) => Number.isInteger(item?.index));
        return Number.isInteger(active?.index) ? active.index : null;
    }

    function drawResponseChartHoverGlow(chart, activeIndex) {
        if (!Number.isInteger(activeIndex)) {
            return;
        }
        const point = chart.getDatasetMeta(0)?.data?.[activeIndex];
        const position = typeof point?.getProps === "function" ? point.getProps(["x", "y"], true) : point;
        if (!position || !Number.isFinite(position.x) || !Number.isFinite(position.y)) {
            return;
        }
        const toneView = getResponseChartToneView(pingMetaOrdered[activeIndex]);
        const { ctx } = chart;
        const chartArea = chart.chartArea;
        ctx.save();
        ctx.globalCompositeOperation = "lighter";
        if (chartArea && Number.isFinite(chartArea.bottom) && chartArea.bottom > position.y) {
            ctx.save();
            ctx.shadowColor = toneView.hoverGlow;
            ctx.shadowBlur = 12;
            ctx.strokeStyle = toneView.hoverGlow;
            ctx.lineWidth = 1.6;
            ctx.setLineDash([2, 7]);
            ctx.lineCap = "round";
            ctx.beginPath();
            ctx.moveTo(position.x, position.y + 10);
            ctx.lineTo(position.x, chartArea.bottom);
            ctx.stroke();
            ctx.restore();
        }
        const aura = ctx.createRadialGradient(position.x, position.y, 0, position.x, position.y, 34);
        aura.addColorStop(0, toneView.aura);
        aura.addColorStop(0.38, toneView.fill);
        aura.addColorStop(1, "rgba(0, 0, 0, 0)");
        ctx.fillStyle = aura;
        ctx.beginPath();
        ctx.arc(position.x, position.y, 34, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowColor = toneView.hoverGlow;
        ctx.shadowBlur = 30;
        ctx.strokeStyle = toneView.line;
        ctx.lineWidth = 2.2;
        ctx.beginPath();
        ctx.arc(position.x, position.y, 7, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();
    }

    function getChartTooltipElement() {
        let tooltipEl = document.getElementById("chart-ping-tooltip");
        if (tooltipEl) {
            return tooltipEl;
        }
        tooltipEl = document.createElement("div");
        tooltipEl.id = "chart-ping-tooltip";
        tooltipEl.className = "pointer-events-none absolute z-[9999] hidden";
        document.body.appendChild(tooltipEl);
        return tooltipEl;
    }

    function externalChartTooltip(context) {
        const { chart, tooltip } = context;
        const tooltipEl = getChartTooltipElement();
        if (!tooltip || tooltip.opacity === 0 || !tooltip.dataPoints || tooltip.dataPoints.length === 0) {
            tooltipEl.classList.add("hidden");
            return;
        }

        const point = tooltip.dataPoints[0];
        const meta = pingMetaOrdered[point.dataIndex];
        const hourLabel = meta ? formatStatusHourLabel("", meta.hour) : formatStatusHourLabel("", null);
        const valueLabel = meta && meta.hasPing ? `${Math.round(meta.pingValue)} ms` : insightT("monitor.responseUnavailable");
        const toneView = getResponseChartToneView(meta);
        const iconClass = meta && meta.hasPing ? `fa-gauge-high ${toneView.icon}` : "fa-circle-question text-gray-400";
        const titleClass = meta && meta.hasPing ? toneView.icon : "text-gray-300";
        const detailsLine = meta && meta.hasPing
            ? insightT("monitor.averageResponseAt", { hour: hourLabel, value: valueLabel })
            : insightT("monitor.noResponseAt", { hour: hourLabel });

        tooltipEl.innerHTML = `
            <div class="tooltip-content rounded-[0.5em]" role="tooltip" style="display:block; position:relative; bottom:auto; left:auto; transform:none; width:220px;">
                <div class="tooltip-header">
                    <i class="fa-solid ${iconClass} tooltip-icon"></i>
                    <span class="tooltip-title ${titleClass}">&nbsp;${escapeHtml(insightT("monitor.responseTime"))}</span>
                </div>
                <div class="tooltip-details">
                    <p>${detailsLine}</p>
                </div>
            </div>
        `;

        const rect = chart.canvas.getBoundingClientRect();
        const anchorX = rect.left + window.scrollX + tooltip.caretX;
        const anchorY = rect.top + window.scrollY + tooltip.caretY;

        tooltipEl.classList.remove("hidden");
        tooltipEl.style.visibility = "hidden";
        tooltipEl.style.left = "0px";
        tooltipEl.style.top = "0px";
        const tooltipHeight = tooltipEl.offsetHeight || 0;
        const tooltipWidth = tooltipEl.offsetWidth || 220;

        const left = Math.max(window.scrollX + 8, anchorX - (tooltipWidth / 2));
        const top = anchorY - tooltipHeight - 12;
        tooltipEl.style.left = `${left}px`;
        tooltipEl.style.top = `${top}px`;
        tooltipEl.style.visibility = "visible";
    }

    const responseChartGlow = {
        id: "responseChartGlow",
        beforeDatasetDraw(chart, args) {
            if (args.index !== 0) {
                return;
            }
            const { ctx } = chart;
            const activeIndex = getResponseChartActiveIndex(chart);
            const activeTone = Number.isInteger(activeIndex) ? getResponseChartToneView(pingMetaOrdered[activeIndex]) : null;
            ctx.save();
            ctx.shadowColor = activeTone?.hoverGlow || getResponseChartDominantGlow();
            ctx.shadowBlur = activeTone ? 36 : 18;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 0;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
        },
        afterDatasetDraw(chart, args) {
            if (args.index !== 0) {
                return;
            }
            chart.ctx.restore();
            drawResponseChartHoverGlow(chart, getResponseChartActiveIndex(chart));
        }
    };

    contentDiv.insightChart = new Chart(ctx, {
      type: "line",
      data: {
        labels: pingMetaOrdered.map((entry) => formatStatusHourLabel("", entry.hour)),
        datasets: [{
          label: insightT("monitor.responseDataset"),
          data: pingMetaOrdered.map((entry) => entry.pingValue),
          borderColor: "rgba(16, 163, 127, 1)",
          backgroundColor: createResponseChartFill,
          borderWidth: 2.4,
          tension: 0.44,
          fill: true,
          pointRadius: 0,
          pointHoverRadius: 6.5,
          pointHitRadius: 18,
          pointHoverBackgroundColor(context) {
            return getResponseChartToneView(pingMetaOrdered[context.dataIndex]).line;
          },
          pointHoverBorderColor: responseChartTheme.pointBorder,
          pointHoverBorderWidth: 2,
          clip: false,
          segment: {
            borderColor: createResponseChartSegment
          },
          spanGaps: true
        }]
      },
      plugins: [responseChartGlow],
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
          padding: {
            top: 18,
            right: 14,
            bottom: 4,
            left: 4
          }
        },
        interaction: {
          mode: "index",
          intersect: false,
          axis: "x"
        },
        scales: {
          y: {
            beginAtZero: true,
            grace: "18%",
            border: { display: false },
            grid: { color: responseChartTheme.grid, drawTicks: false },
            ticks: { color: responseChartTheme.tick, font: { size: 10 }, padding: 8 }
          },
          x: {
            border: { display: false },
            grid: { display: false },
            ticks: { color: responseChartTheme.tickMuted, font: { size: 10 }, padding: 6 }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            enabled: false,
            external: externalChartTooltip,
            backgroundColor: responseChartTheme.tooltipBackground,
            titleColor: "#FFFFFF",
            bodyColor: "#FFFFFF",
            borderColor: responseChartTheme.tooltipBorder,
            borderWidth: 1,
            cornerRadius: 4,
            displayColors: false,
            titleFont: { size: 12, weight: "bold" },
            bodyFont: { size: 10 },
            padding: 8
          }
        }
      }
    });
    return [headerDiv, contentDiv];
}
