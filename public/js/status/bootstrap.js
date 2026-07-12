function syncStatusGrid() {
    const root = document.documentElement;
    const wrapper = document.getElementById("status-wrapper");
    if (!root || !wrapper) {
        return;
    }

    const gridTools = window.INSIGHT_GRID;
    const metrics = gridTools && typeof gridTools.compute === "function"
        ? gridTools.compute({
            root,
            gutterVar: "--status-shell-gutter",
            maxWidthVar: "--status-shell-max",
            gapCells: 1,
            minimumTrackCells: 6
        })
        : null;

    const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 1440;
    const gridSize = metrics && Number.isFinite(metrics.gridSize)
        ? metrics.gridSize
        : Math.max(32, Math.min(56, viewportWidth / 24));
    const anchorSelectors = [
        ".status-cards .summary-card.status-grid-card",
        ".status-detail-stack .status-grid-card",
        ".status-cards .status-grid-card",
        ".status-overview-panel.status-grid-card",
        ".content-layout",
        ".status-cards"
    ];
    let anchor = null;
    for (const selector of anchorSelectors) {
        const candidate = Array.from(document.querySelectorAll(selector)).find((item) => {
            const rect = item.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0;
        });
        if (candidate) {
            anchor = candidate;
            break;
        }
    }
    const wrapperRect = wrapper.getBoundingClientRect();
    const anchorRect = anchor ? anchor.getBoundingClientRect() : null;
    const originX = anchorRect ? anchorRect.left : (metrics && Number.isFinite(metrics.leftMargin) ? metrics.leftMargin : 0);
    const originY = anchorRect ? anchorRect.top - wrapperRect.top : 0;

    root.style.setProperty("--status-grid-size", `${gridSize.toFixed(6)}px`);
    root.style.setProperty("--status-grid-origin-x", `${originX.toFixed(6)}px`);
    root.style.setProperty("--status-grid-origin-y", `${originY.toFixed(6)}px`);
}

function releaseStatusPreloader() {
    const preloader = document.getElementById("preloader");
    if (!preloader) {
        return;
    }
    preloader.style.opacity = "0";
    preloader.style.visibility = "hidden";
    preloader.style.pointerEvents = "none";
    window.setTimeout(() => {
        preloader.style.display = "none";
    }, 120);
}

let statusGridFrame = 0;
const statusBootstrapDebugEnabled = Boolean(window.__INSIGHT_DEBUG || /(?:[?&])debug=1(?:&|$)/.test(window.location.search));
const statusBootstrapLog = statusBootstrapDebugEnabled ? window.console.log.bind(window.console) : () => {};
const statusBootstrapError = statusBootstrapDebugEnabled ? window.console.error.bind(window.console) : () => {};

function requestStatusGridSync() {
    if (statusGridFrame) {
        window.cancelAnimationFrame(statusGridFrame);
    }
    statusGridFrame = window.requestAnimationFrame(() => {
        statusGridFrame = 0;
        syncStatusGrid();
    });
}

window.syncStatusGrid = syncStatusGrid;

async function initializeStatusPage() {
    if (window.InsightI18n?.ready) {
        await window.InsightI18n.ready;
        window.InsightI18n.apply(document);
    }
    const domContentLoadedEvent = new CustomEvent("domContentLoaded");
    window.dispatchEvent(domContentLoadedEvent);
    statusBootstrapLog("domContentLoaded event dispatched");
    releaseStatusPreloader();
    if (typeof initializeStatusTimeZoneControls === "function") {
        initializeStatusTimeZoneControls();
    }
    fetchStatusData();
    setupAutoRefresh();
    requestStatusGridSync();
    window.setTimeout(requestStatusGridSync, 250);
    window.setTimeout(requestStatusGridSync, 1000);
    window.setTimeout(requestStatusGridSync, 2200);
    window.setTimeout(requestStatusGridSync, 4000);
    const wrapper = document.getElementById("status-wrapper");
    if (wrapper && window.MutationObserver) {
        const observer = new MutationObserver(requestStatusGridSync);
        observer.observe(wrapper, {
            childList: true,
            subtree: true
        });
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeStatusPage);
} else {
    initializeStatusPage();
}

window.addEventListener("popstate", () => {
    if (Array.isArray(formattedSitesData) && formattedSitesData.length > 0) {
        applyRouteView(formattedSitesData);
        requestStatusGridSync();
        return;
    }
    fetchStatusData();
    requestStatusGridSync();
});

window.addEventListener("load", requestStatusGridSync);
window.addEventListener("resize", requestStatusGridSync);
window.addEventListener("insight:theme-changed", () => {
    requestStatusGridSync();
    if (typeof fetchStatusData === "function") {
        fetchStatusData();
    }
});

window.addEventListener("insight:locale-changed", async () => {
    if (typeof initializeStatusTimeZoneControls === "function") {
        initializeStatusTimeZoneControls();
    }
    if (Array.isArray(formattedSitesData) && formattedSitesData.length > 0) {
        await applyRouteView(formattedSitesData);
        updateGlobalStatus(formattedSitesData);
        updateLastCheckedTime(formattedSitesData);
    }
    const runtimeModal = document.getElementById("runtimeDegradedModal");
    if (publicRuntimeState && runtimeModal && !runtimeModal.classList.contains("hidden")) {
        showRuntimeDegradedModal(publicRuntimeState);
    }
    requestStatusGridSync();
});
