function calculateAvailability(hours) {
    const totalMinutes = 1440;
    let knownMinutes = 0;
    let offlineKnownMinutes = 0;
    let unknownHours = 0;

    (Array.isArray(hours) ? hours : []).forEach((hour) => {
        if (!hour || hour.hasBeenOnline === "UNKNOWN") {
            unknownHours++;
            return;
        }
        if (hour.hasBeenOnline === "MAINTENANCE") {
            knownMinutes += 60;
            return;
        }
        knownMinutes += 60;
        const rawOffline = Number(hour.minutes_offline);
        const boundedOffline = Number.isFinite(rawOffline) ? Math.max(0, Math.min(60, rawOffline)) : 60;
        offlineKnownMinutes += boundedOffline;
    });

    const availableKnownMinutes = Math.max(0, knownMinutes - offlineKnownMinutes);
    // Strict QoS: observed availability divided by total time over 24 hours.
    const qualityPercent = (availableKnownMinutes / totalMinutes) * 100;
    const coveragePercent = (knownMinutes / totalMinutes) * 100;

    return {
        qualityPercent,
        coveragePercent,
        knownMinutes,
        unknownHours,
        isEstimated: coveragePercent < 100
    };
}

function formatStatusCalcMethodLabel(value) {
    const raw = String(value || "").trim().toLowerCase();
    const labels = {
        time_weighted: insightT("status.calculation.timeWeighted"),
        legacy: insightT("status.calculation.legacy"),
        inherit: insightT("status.calculation.inherited"),
        mixed: insightT("status.calculation.mixed")
    };
    return labels[raw] || raw;
}

function formatStatusCheckedByLabel(value) {
    const raw = String(value || "").trim().toLowerCase();
    const labels = {
        pyt: "Python",
        python: "Python",
        agents: insightT("status.checkedBy.agents"),
        consensus: insightT("status.checkedBy.agents"),
        system: insightT("status.checkedBy.system")
    };
    return labels[raw] || raw;
}

function formatStatusErrorTypeLabel(value) {
    const raw = String(value || "").trim().toLowerCase();
    const labels = {
        http_5xx: "HTTP 5xx",
        http_4xx: "HTTP 4xx",
        redirect: insightT("status.error.redirect"),
        redirect_loop: insightT("status.error.redirectLoop"),
        timeout: insightT("status.error.timeout"),
        dns_error: "DNS",
        tls_error: "TLS",
        network_error: insightT("status.error.network"),
        slow_response: insightT("status.error.slow"),
        unknown: insightT("status.error.unknown")
    };
    return labels[raw] || raw;
}

function createStatusProbeMetaDetails(hour) {
    const meta = hour && typeof hour.probe_meta === "object" && hour.probe_meta !== null ? hour.probe_meta : {};
    const lines = [];
    const latency = Number(hour && hour.avg_response_time);
    if (Number.isFinite(latency) && latency > 0) {
        lines.push(insightT("status.meta.latency", { value: Math.round(latency) }));
    }
    const checkedBy = formatStatusCheckedByLabel(meta.checked_by || hour?.checked_by);
    const sourceNode = String(meta.source_node || hour?.source_node || "").trim();
    const sourceParts = [];
    if (checkedBy) {
        sourceParts.push(checkedBy);
    }
    if (sourceNode) {
        sourceParts.push(sourceNode);
    }
    if (sourceParts.length > 0) {
        lines.push(insightT("status.meta.source", { value: sourceParts.join(" · ") }));
    }
    const interval = Number(meta.probe_interval_sec || hour?.probe_interval_sec);
    if (Number.isFinite(interval) && interval > 0) {
        lines.push(insightT("status.meta.interval", { value: interval }));
    }
    const calcMethod = formatStatusCalcMethodLabel(meta.calc_method || hour?.calc_method);
    if (calcMethod) {
        lines.push(insightT("status.meta.calculation", { value: calcMethod }));
    }
    const lastError = formatStatusErrorTypeLabel(meta.last_error_type || hour?.last_error_type);
    if (lastError) {
        lines.push(insightT("status.meta.lastError", { value: lastError }));
    }
    return lines.map((line) => `<p>${escapeHtml(line)}</p>`).join("");
}

function createStatusBarMarkup({ colorClass, ariaLabel, tooltipContent, status = "UNKNOWN", checkedAt = "" }) {
    return `
        <div class="flex-1 h-6 ${colorClass} rounded-[0.5em] status-bar tooltip"
             role="button"
             tabindex="0"
             aria-label="${escapeHtml(ariaLabel)}"
             aria-expanded="false"
             data-status="${escapeHtml(status)}"
             data-date="${escapeHtml(checkedAt)}">
            ${tooltipContent}
        </div>
    `;
}

function createStatusBars(hours) {
    const totalHours = 24;
    const bars = [];
    for (let i = 0; i < totalHours; i++) {
        const hour = hours[i];
        if (!hour || typeof hour !== "object") {
            const unknownHourLabel = formatStatusHourLabel("", i);
            const unknownTooltip = `
                <div class="tooltip-content rounded-[0.5em]" role="tooltip">
                    <div class="tooltip-header">
                        <i class="fa-solid fa-circle-question tooltip-icon text-gray-300"></i>
                        <span class="tooltip-title">&nbsp;${escapeHtml(insightT("state.unknown"))}</span>
                    </div>
                    <div class="tooltip-details">
                        <p>${escapeHtml(insightT("statusBar.noDataAt", { hour: unknownHourLabel }))}</p>
                    </div>
                </div>`;
            bars.push(createStatusBarMarkup({
                colorClass: "bg-gray-400",
                ariaLabel: insightT("statusBar.dataUnavailable"),
                tooltipContent: unknownTooltip
            }));
        } else {
            const safeHour = Number.isFinite(Number(hour.hour)) ? Number(hour.hour) : i;
            const safeMinutesOffline = Number.isFinite(Number(hour.minutes_offline)) ? Number(hour.minutes_offline) : 0;
            const rawCheckedAt = hour.checked_at || "";
            const rawHourLabel = formatStatusHourLabel(hour.checked_at, safeHour);
            const probeMetaDetails = createStatusProbeMetaDetails(hour);
            const state = ["YES", "PARTIALLY", "NO", "UNKNOWN", "MAINTENANCE"].includes(hour.hasBeenOnline)
                ? hour.hasBeenOnline
                : "UNKNOWN";

            let colorClass = "bg-gray-400";
            let tooltipContent = "";
            if (state === "YES") {
                colorClass = "bg-green-400";
                tooltipContent = `
                    <div class="tooltip-content" role="tooltip">
                        <div class="tooltip-header">
                            <i class="fa-solid fa-circle-check tooltip-icon text-green-400"></i>
                            <span class="tooltip-title">&nbsp;${escapeHtml(insightT("state.operational"))}</span>
                        </div>
                        <div class="tooltip-details">
                            <p>${escapeHtml(insightT("statusBar.fullyOperationalAt", { hour: rawHourLabel }))}</p>
                            ${probeMetaDetails}
                        </div>
                    </div>`;
                bars.push(createStatusBarMarkup({
                    colorClass,
                    ariaLabel: insightT("statusBar.operationalAt", { hour: rawHourLabel }),
                    tooltipContent,
                    status: state,
                    checkedAt: rawCheckedAt
                }));
            } else if (state === "PARTIALLY") {
                colorClass = "bg-yellow-400";
                tooltipContent = `
                    <div class="tooltip-content rounded-[0.5em]" role="tooltip">
                        <div class="tooltip-header">
                            <i class="fa-solid fa-triangle-exclamation tooltip-icon text-yellow-400"></i>
                            <span class="tooltip-title">&nbsp;${escapeHtml(insightT("state.degraded"))}</span>
                        </div>
                        <div class="tooltip-details">
                            <p>${escapeHtml(insightT("statusBar.inactiveAt", { minutes: safeMinutesOffline, hour: rawHourLabel }))}</p>
                            ${probeMetaDetails}
                        </div>
                    </div>`;
                bars.push(createStatusBarMarkup({
                    colorClass,
                    ariaLabel: insightT("statusBar.degradedAt", { minutes: safeMinutesOffline, hour: rawHourLabel }),
                    tooltipContent,
                    status: state,
                    checkedAt: rawCheckedAt
                }));
            } else if (state === "NO") {
                colorClass = "bg-red-400";
                tooltipContent = `
                    <div class="tooltip-content" role="tooltip">
                        <div class="tooltip-header">
                            <i class="fa-solid fa-circle-xmark tooltip-icon text-red-400"></i>
                            <span class="tooltip-title">&nbsp;${escapeHtml(insightT("state.offline"))}</span>
                        </div>
                        <div class="tooltip-details">
                            <p>${escapeHtml(insightT("statusBar.fullyDownAt", { hour: rawHourLabel }))}</p>
                            ${probeMetaDetails}
                        </div>
                    </div>`;
                bars.push(createStatusBarMarkup({
                    colorClass,
                    ariaLabel: insightT("statusBar.offlineAt", { hour: rawHourLabel }),
                    tooltipContent,
                    status: state,
                    checkedAt: rawCheckedAt
                }));
            } else if (state === "MAINTENANCE") {
                colorClass = "bg-sky-400";
                tooltipContent = `
                    <div class="tooltip-content" role="tooltip">
                        <div class="tooltip-header">
                            <i class="fa-solid fa-screwdriver-wrench tooltip-icon text-sky-300"></i>
                            <span class="tooltip-title">&nbsp;${escapeHtml(insightT("state.scheduledMaintenance"))}</span>
                        </div>
                        <div class="tooltip-details">
                            <p>${escapeHtml(insightT("statusBar.maintenanceAt", { hour: rawHourLabel }))}</p>
                            ${probeMetaDetails}
                        </div>
                    </div>`;
                bars.push(createStatusBarMarkup({
                    colorClass,
                    ariaLabel: insightT("statusBar.scheduledMaintenanceAt", { hour: rawHourLabel }),
                    tooltipContent,
                    status: state,
                    checkedAt: rawCheckedAt
                }));
            } else {
                const unknownTooltip = `
                    <div class="tooltip-content rounded-[0.5em]" role="tooltip">
                        <div class="tooltip-header">
                            <i class="fa-solid fa-circle-question tooltip-icon text-gray-300"></i>
                            <span class="tooltip-title">&nbsp;${escapeHtml(insightT("state.unknown"))}</span>
                        </div>
                        <div class="tooltip-details">
                            <p>${escapeHtml(insightT("statusBar.unknownAt", { hour: rawHourLabel }))}</p>
                            ${probeMetaDetails}
                        </div>
                    </div>`;
                bars.push(createStatusBarMarkup({
                    colorClass: "bg-gray-400",
                    ariaLabel: insightT("statusBar.unknownAt", { hour: rawHourLabel }),
                    tooltipContent: unknownTooltip,
                    status: "UNKNOWN",
                    checkedAt: rawCheckedAt
                }));
            }
        }
    }
    return bars.reverse().join("");
}

const statusMessageKeys = {
    ok: "global.ok",
    someDown: "global.someDown",
    maintenance: "global.maintenance",
    allDown: "global.allDown"
};

function getStatusMessage(type) {
    return insightT(statusMessageKeys[type] || "global.unknown");
}

function updateGlobalStatus(data) {
    const statusMessage = document.querySelector(".status-message");
    if (!statusMessage) {
        return;
    }

    const statuses = (Array.isArray(data) ? data : []).map((site) => {
        const latest = Array.isArray(site.hours) ? site.hours.find((hour) => hour !== null) : null;
        return latest ? latest.hasBeenOnline : "UNKNOWN";
    });

    const hasKnown = statuses.some((status) => status === "YES" || status === "PARTIALLY" || status === "NO" || status === "MAINTENANCE");
    const allDown = statuses.length > 0 && statuses.every((status) => status === "NO");
    const allUp = statuses.length > 0 && statuses.every((status) => status === "YES");
    const hasIssue = statuses.some((status) => status === "NO" || status === "PARTIALLY");
    const hasMaintenance = statuses.some((status) => status === "MAINTENANCE");
    const hasUnknown = statuses.some((status) => status === "UNKNOWN");
    const overviewPanel = document.querySelector(".status-overview-panel");
    const overviewState = document.querySelector(".status-overview-state");

    let color;
    let overviewStatus;
    let overviewLabel;
    if (allDown) {
        statusMessage.textContent = getStatusMessage("allDown");
        statusMessage.classList.remove("text-green-400", "text-yellow-400", "text-gray-400", "text-blue-300");
        statusMessage.classList.add("text-red-400");
        color = "#F87171";
        overviewStatus = "critical";
        overviewLabel = insightT("state.unavailable");
    } else if (allUp) {
        statusMessage.textContent = getStatusMessage("ok");
        statusMessage.classList.remove("text-yellow-400", "text-red-400", "text-gray-400", "text-blue-300");
        statusMessage.classList.add("text-green-400");
        color = "#4ADE80";
        overviewStatus = "ok";
        overviewLabel = insightT("state.operational");
    } else if (hasMaintenance && !hasIssue) {
        statusMessage.textContent = getStatusMessage("maintenance");
        statusMessage.classList.remove("text-green-400", "text-yellow-400", "text-red-400", "text-gray-400");
        statusMessage.classList.add("text-blue-300");
        color = "#7DD3FC";
        overviewStatus = "maintenance";
        overviewLabel = insightT("state.maintenance");
    } else if (hasIssue || (hasKnown && hasUnknown)) {
        statusMessage.textContent = getStatusMessage("someDown");
        statusMessage.classList.remove("text-green-400", "text-red-400", "text-gray-400", "text-blue-300");
        statusMessage.classList.add("text-yellow-400");
        color = "#FACC15";
        overviewStatus = "degraded";
        overviewLabel = insightT("state.degraded");
    } else {
        statusMessage.textContent = insightT("global.unknown");
        statusMessage.classList.remove("text-green-400", "text-yellow-400", "text-red-400", "text-blue-300");
        statusMessage.classList.add("text-gray-400");
        color = "#9CA3AF";
        overviewStatus = "unknown";
        overviewLabel = insightT("state.undetermined");
    }
    if (overviewPanel) {
        overviewPanel.dataset.status = overviewStatus;
    }
    if (overviewState) {
        overviewState.textContent = overviewLabel;
    }
    dispatchWindowEvent("updateLogoColor", { color });
    // Update blurred dots colors according to global status
    updateDotColors(color);
}

function updateLastCheckedTime(data) {
    lastCheckedTime = null;
    (Array.isArray(data) ? data : []).forEach(site => {
        site.hours.forEach(hour => {
            if (hour !== null && hour.checked_at) {
                const checkedDate = parseStatusDateTime(hour.checked_at);
                if (checkedDate && !Number.isNaN(checkedDate.getTime()) && (!lastCheckedTime || checkedDate > lastCheckedTime)) {
                    lastCheckedTime = checkedDate;
                }
            }
        });
    });
    if (lastUpdatedIntervalId) {
        clearInterval(lastUpdatedIntervalId);
        lastUpdatedIntervalId = null;
    }
    if (lastCheckedTime) {
        updateLastUpdatedDisplay();
        lastUpdatedIntervalId = setInterval(updateLastUpdatedDisplay, 1000);
    } else {
        const lastUpdatedEl = document.getElementById("last-updated");
        if (lastUpdatedEl) {
            lastUpdatedEl.textContent = insightT("updated.unavailable");
        }
    }
}

function updateLastUpdatedDisplay() {
    if (!lastCheckedTime) return;
    const now = new Date();
    const diffInSeconds = Math.floor((now - lastCheckedTime) / 1000);
    let timeText;
    if (diffInSeconds < 60) {
      timeText = insightT("updated.justNow");
    } else if (diffInSeconds < 3600) {
      const minutes = Math.floor(diffInSeconds / 60);
      timeText = insightT("updated.minutesAgo", { count: minutes });
    } else if (diffInSeconds < 86400) {
      const hours = Math.floor(diffInSeconds / 3600);
      timeText = insightT("updated.hoursAgo", { count: hours });
    } else {
      timeText = insightT("updated.longAgo");
    }
    const lastUpdatedEl = document.getElementById("last-updated");
    if (lastUpdatedEl) {
        lastUpdatedEl.textContent = timeText;
    }
  }

function getRuntimeDegradedModalElements() {
    return {
        overlay: document.getElementById("runtimeDegradedModal"),
        closeBtn: document.getElementById("runtimeDegradedModalClose"),
        ackBtn: document.getElementById("runtimeDegradedModalAcknowledge"),
        summary: document.getElementById("runtimeDegradedModalSummary"),
        details: document.getElementById("runtimeDegradedModalDetails")
    };
}

function closeRuntimeDegradedModal() {
    const { overlay } = getRuntimeDegradedModalElements();
    if (!overlay) {
        return;
    }
    overlay.classList.add("hidden");
}

function buildRuntimeDegradedFingerprint(state) {
    const updatedAt = String(state && state.updated_at ? state.updated_at : "");
    const engine = String(state && state.active_engine ? state.active_engine : "");
    const reason = String(state && state.monitor_python_error ? state.monitor_python_error : "");
    return `${updatedAt}|${engine}|${reason}`;
}

function showRuntimeDegradedModal(state) {
    const {
        overlay,
        closeBtn,
        ackBtn,
        summary,
        details
    } = getRuntimeDegradedModalElements();

    if (!overlay) {
        return;
    }

    if (summary) {
        summary.textContent = insightT("runtime.summary");
    }

    if (details) {
        const engine = String(state && state.active_engine ? state.active_engine : "unknown").toUpperCase();
        const timezone = String(state && state.service_timezone ? state.service_timezone : "Europe/Paris");
        const updatedAt = String(state && state.updated_at ? state.updated_at : insightT("runtime.unknown"));
        details.textContent = insightT("runtime.details", { engine, timezone, updatedAt });
    }

    if (!overlay.dataset.bound) {
        overlay.addEventListener("click", (event) => {
            if (event.target === overlay) {
                closeRuntimeDegradedModal();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener("click", closeRuntimeDegradedModal);
        }
        if (ackBtn) {
            ackBtn.addEventListener("click", closeRuntimeDegradedModal);
        }
        overlay.dataset.bound = "1";
    }

    overlay.classList.remove("hidden");
}

function syncRuntimeDegradedModal(state) {
    const storageKey = "status_runtime_degraded_seen";
    const isDegraded = Number(state && state.is_degraded) === 1;
    if (!isDegraded) {
        closeRuntimeDegradedModal();
        try {
            window.sessionStorage.removeItem(storageKey);
        } catch (_err) {
        }
        return;
    }

    const fingerprint = buildRuntimeDegradedFingerprint(state || {});
    let seen = null;
    try {
        seen = window.sessionStorage.getItem(storageKey);
    } catch (_err) {
        seen = null;
    }

    if (seen === fingerprint) {
        return;
    }

    showRuntimeDegradedModal(state || {});
    try {
        window.sessionStorage.setItem(storageKey, fingerprint);
    } catch (_err) {
    }
}

window.addEventListener("publicRuntimeStateFetched", (event) => {
    const state = event && event.detail ? event.detail.state : null;
    syncRuntimeDegradedModal(state);
});
