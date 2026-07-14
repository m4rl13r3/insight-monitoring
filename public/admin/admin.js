(function () {
    function applyTranslations() {
        if (!window.InsightI18n) {
            return;
        }
        window.InsightI18n.apply(document);
        document.querySelectorAll("[data-auth-error]").forEach((node) => {
            node.textContent = window.InsightI18n.t(node.dataset.authError || "");
        });
        formatTimes();
    }

    function formatTimes() {
        if (!window.InsightI18n) {
            return;
        }
        const locale = window.InsightI18n.getIntlLocale();
        const timezone = String(window.INSIGHT_CONFIG?.timezone || "Europe/Paris");
        document.querySelectorAll("time[datetime]").forEach((node) => {
            const date = new Date(node.getAttribute("datetime") || "");
            if (Number.isNaN(date.getTime())) {
                return;
            }
            const mode = node.dataset.timeFormat || "dateTime";
            const options = mode === "relative"
                ? { dateStyle: "medium", timeStyle: "short", timeZone: timezone }
                : mode === "date"
                    ? { dateStyle: "medium", timeZone: timezone }
                    : { dateStyle: "medium", timeStyle: "short", timeZone: timezone };
            node.textContent = new Intl.DateTimeFormat(locale, options).format(date);
            node.title = new Intl.DateTimeFormat(locale, {
                dateStyle: "full",
                timeStyle: "long",
                timeZone: timezone
            }).format(date);
        });
    }

    function bindPasswordToggles() {
        document.querySelectorAll(".admin-password-toggle").forEach((button) => {
            button.addEventListener("click", () => {
                const wrapper = button.closest(".admin-input-wrap");
                const input = wrapper?.querySelector("input");
                if (!(input instanceof HTMLInputElement)) {
                    return;
                }
                const visible = input.type === "text";
                input.type = visible ? "password" : "text";
                const key = visible ? "admin.auth.showPassword" : "admin.auth.hidePassword";
                const label = window.InsightI18n?.t(key) || (visible ? "Afficher" : "Masquer");
                button.setAttribute("aria-label", label);
                button.setAttribute("title", label);
                button.querySelector("i")?.classList.toggle("fa-eye", visible);
                button.querySelector("i")?.classList.toggle("fa-eye-slash", !visible);
            });
        });
    }

    function bindDashboardRoutes() {
        const views = Array.from(document.querySelectorAll("[data-admin-view]"));
        if (views.length === 0) {
            return;
        }
        const routes = new Set(views.map((view) => String(view.dataset.adminView || "")));
        const links = Array.from(document.querySelectorAll("[data-admin-route]"));

        function requestedRoute() {
            const route = window.location.hash.replace(/^#/, "").trim().toLowerCase();
            return routes.has(route) ? route : "overview";
        }

        function applyRoute(replaceInvalid) {
            const route = requestedRoute();
            const requested = window.location.hash.replace(/^#/, "").trim().toLowerCase();
            if (replaceInvalid && requested !== route) {
                window.history.replaceState(null, "", `#${route}`);
            }
            views.forEach((view) => {
                view.hidden = String(view.dataset.adminView || "") !== route;
            });
            links.forEach((link) => {
                const active = String(link.dataset.adminRoute || "") === route;
                link.classList.toggle("is-active", active);
                if (active) {
                    link.setAttribute("aria-current", "page");
                } else {
                    link.removeAttribute("aria-current");
                }
            });
            document.body.dataset.adminActiveView = route;
            window.dispatchEvent(new CustomEvent("insight:admin-route-changed", {detail: {route}}));
            const activeLink = links.find((link) => String(link.dataset.adminRoute || "") === route);
            if (activeLink instanceof HTMLElement && window.matchMedia("(max-width: 860px)").matches) {
                activeLink.scrollIntoView({block: "nearest", inline: "center", behavior: "auto"});
            }
            window.scrollTo(0, 0);
            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(() => window.scrollTo(0, 0));
            });
        }

        links.forEach((link) => {
            link.addEventListener("click", (event) => {
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                const route = String(link.dataset.adminRoute || "overview");
                if (requestedRoute() !== route) {
                    window.history.pushState(null, "", `#${route}`);
                }
                applyRoute(false);
            });
        });
        window.addEventListener("popstate", () => applyRoute(true));
        window.addEventListener("hashchange", () => applyRoute(true));
        applyRoute(true);
    }

    let pendingWorkflowDelete = null;

    function requestWorkflowDelete(label, handler) {
        const dialog = document.querySelector("[data-workflow-delete-dialog]");
        const target = document.querySelector("[data-workflow-delete-target]");
        const feedback = document.querySelector("[data-workflow-delete-feedback]");
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }
        pendingWorkflowDelete = handler;
        if (target instanceof HTMLElement) {
            target.textContent = label;
        }
        if (feedback instanceof HTMLElement) {
            feedback.hidden = true;
            feedback.textContent = "";
        }
        dialog.showModal();
    }

    function bindWorkflowDelete() {
        const dialog = document.querySelector("[data-workflow-delete-dialog]");
        const confirm = document.querySelector("[data-workflow-delete-confirm]");
        const feedback = document.querySelector("[data-workflow-delete-feedback]");
        if (!(dialog instanceof HTMLDialogElement) || !(confirm instanceof HTMLButtonElement)) {
            return;
        }
        document.querySelectorAll("[data-workflow-delete-close]").forEach((button) => button.addEventListener("click", () => dialog.close()));
        dialog.addEventListener("close", () => {
            pendingWorkflowDelete = null;
        });
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        confirm.addEventListener("click", async () => {
            if (typeof pendingWorkflowDelete !== "function") {
                return;
            }
            confirm.disabled = true;
            try {
                await pendingWorkflowDelete();
                dialog.close();
                window.location.reload();
            } catch (error) {
                if (feedback instanceof HTMLElement) {
                    const key = error instanceof Error ? error.message : "admin.common.errorGeneric";
                    feedback.textContent = window.InsightI18n?.t(key) || key;
                    feedback.hidden = false;
                }
            } finally {
                confirm.disabled = false;
            }
        });
    }

    function bindProbeCreation() {
        const dialog = document.querySelector("[data-probe-dialog]");
        const form = document.querySelector("[data-probe-form]");
        const targetInput = form?.querySelector("[data-probe-target]");
        const targetLabel = document.querySelector("[data-probe-target-label]");
        const targetHint = document.querySelector("[data-probe-target-hint]");
        const targetIcon = document.querySelector("[data-probe-target-icon]");
        const typeIcon = form?.querySelector("[data-probe-type-icon]");
        const title = document.querySelector("[data-probe-dialog-title]");
        const dialogIcon = document.querySelector("[data-probe-dialog-icon]");
        const feedback = document.querySelector("[data-probe-feedback]");
        const submitButton = document.querySelector("[data-probe-submit]");
        const submitLabel = document.querySelector("[data-probe-submit-label]");
        const submitIcon = document.querySelector("[data-probe-submit-icon]");
        const intervalSelect = form?.querySelector("select[name='interval_sec']");
        const calculationMethod = form?.querySelector("input[name='calc_method']");
        const strictAvailability = form?.querySelector("[data-strict-availability]");
        const deleteDialog = document.querySelector("[data-probe-delete-dialog]");
        const deleteTarget = document.querySelector("[data-probe-delete-target]");
        const deleteFeedback = document.querySelector("[data-probe-delete-feedback]");
        const deleteConfirm = document.querySelector("[data-probe-delete-confirm]");
        const heartbeatDialog = document.querySelector("[data-heartbeat-secret-dialog]");
        const heartbeatValue = document.querySelector("[data-heartbeat-secret-value]");
        const diagnosticDialog = document.querySelector("[data-probe-diagnostic-dialog]");
        const diagnosticTarget = document.querySelector("[data-probe-diagnostic-target]");
        const diagnosticFeedback = document.querySelector("[data-probe-diagnostic-feedback]");
        const diagnosticSummary = document.querySelector("[data-probe-diagnostic-summary]");
        const diagnosticOutput = document.querySelector("[data-probe-diagnostic-output]");
        const diagnosticArtifact = document.querySelector("[data-probe-diagnostic-artifact]");
        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || !(targetInput instanceof HTMLInputElement)) {
            return;
        }

        const translate = (key, variables = {}) => window.InsightI18n?.t(key, variables) || key;

        function setTranslatedContent(node, key, variables = null) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            if (variables === null) {
                node.dataset.i18n = key;
            } else {
                delete node.dataset.i18n;
            }
            node.textContent = translate(key, variables || {});
        }

        function selectedProbeType() {
            const selected = form.elements.namedItem("probe_type");
            return selected instanceof HTMLSelectElement ? selected.value : "http";
        }

        function updateDialogTitle() {
            const probeType = selectedProbeType();
            const editing = form.dataset.probeAction === "update";
            if (["icmp", "tcp", "snmp", "service"].includes(probeType)) {
                setTranslatedContent(title, editing ? "admin.probes.editServerTitle" : "admin.probes.serverTitle");
                return;
            }
            setTranslatedContent(title, editing ? "admin.probes.editMonitorTypeTitle" : "admin.probes.createMonitorTypeTitle");
        }

        function updateCalculationMethod() {
            if (!(calculationMethod instanceof HTMLInputElement)) {
                return;
            }
            calculationMethod.value = strictAvailability instanceof HTMLInputElement && strictAvailability.checked ? "strict_sla" : "inherit";
        }

        function updateTargetField() {
            const probeType = selectedProbeType();
            const settings = {
                http: {
                    label: "admin.probes.url",
                    hint: "admin.probes.httpHint",
                    placeholder: "admin.probes.httpPlaceholder",
                    icon: "fa-solid fa-link"
                },
                browser: {
                    label: "admin.probes.url",
                    hint: "admin.probes.browserHint",
                    placeholder: "admin.probes.httpPlaceholder",
                    icon: "fa-solid fa-window-maximize"
                },
                websocket: {
                    label: "admin.probes.websocketTarget",
                    hint: "admin.probes.websocketHint",
                    placeholder: "admin.probes.websocketPlaceholder",
                    icon: "fa-solid fa-arrows-left-right"
                },
                tcp: {
                    label: "admin.probes.tcpTarget",
                    hint: "admin.probes.tcpHint",
                    placeholder: "admin.probes.tcpPlaceholder",
                    icon: "fa-solid fa-network-wired"
                },
                icmp: {
                    label: "admin.probes.icmpTarget",
                    hint: "admin.probes.icmpHint",
                    placeholder: "admin.probes.icmpPlaceholder",
                    icon: "fa-solid fa-satellite-dish"
                },
                dns: {
                    label: "admin.probes.dnsTarget",
                    hint: "admin.probes.dnsHint",
                    placeholder: "admin.probes.dnsPlaceholder",
                    icon: "fa-solid fa-address-book"
                },
                heartbeat: {
                    label: "admin.probes.heartbeatTarget",
                    hint: "admin.probes.heartbeatHint",
                    placeholder: "admin.probes.heartbeatPlaceholder",
                    icon: "fa-solid fa-heart-pulse"
                },
                mqtt: {
                    label: "admin.probes.mqttTarget",
                    hint: "admin.probes.mqttHint",
                    placeholder: "admin.probes.mqttPlaceholder",
                    icon: "fa-solid fa-tower-broadcast"
                },
                sql: {
                    label: "admin.probes.sqlTarget",
                    hint: "admin.probes.sqlHint",
                    placeholder: "admin.probes.sqlPlaceholder",
                    icon: "fa-solid fa-database"
                },
                docker: {
                    label: "admin.probes.dockerTarget",
                    hint: "admin.probes.dockerHint",
                    placeholder: "admin.probes.dockerPlaceholder",
                    icon: "fa-solid fa-cube"
                },
                grpc: {
                    label: "admin.probes.grpcTarget",
                    hint: "admin.probes.grpcHint",
                    placeholder: "admin.probes.grpcPlaceholder",
                    icon: "fa-solid fa-tower-cell"
                },
                redis: {
                    label: "admin.probes.redisTarget",
                    hint: "admin.probes.redisHint",
                    placeholder: "admin.probes.redisPlaceholder",
                    icon: "fa-solid fa-database"
                },
                smtp: {
                    label: "admin.probes.smtpTarget",
                    hint: "admin.probes.smtpHint",
                    placeholder: "admin.probes.smtpPlaceholder",
                    icon: "fa-solid fa-envelope"
                },
                rabbitmq: {
                    label: "admin.probes.rabbitMqTarget",
                    hint: "admin.probes.rabbitMqHint",
                    placeholder: "admin.probes.rabbitMqPlaceholder",
                    icon: "fa-solid fa-arrow-right-arrow-left"
                },
                snmp: {
                    label: "admin.probes.snmpTarget",
                    hint: "admin.probes.snmpHint",
                    placeholder: "admin.probes.snmpPlaceholder",
                    icon: "fa-solid fa-network-wired"
                },
                service: {
                    label: "admin.probes.serviceTarget",
                    hint: "admin.probes.serviceHint",
                    placeholder: "admin.probes.servicePlaceholder",
                    icon: "fa-solid fa-server"
                }
            }[probeType] || {};
            setTranslatedContent(targetLabel, settings.label);
            setTranslatedContent(targetHint, settings.hint);
            targetInput.dataset.i18nPlaceholder = settings.placeholder;
            targetInput.placeholder = translate(settings.placeholder);
            if (targetIcon instanceof HTMLElement) {
                targetIcon.className = settings.icon;
            }
            if (typeIcon instanceof HTMLElement) {
                typeIcon.className = settings.icon;
            }
            form.querySelectorAll("[data-probe-fields]").forEach((group) => {
                const selected = group.dataset.probeFields === probeType;
                group.hidden = !selected;
                if (!selected && group instanceof HTMLDetailsElement) {
                    group.open = false;
                }
            });
            const bodyCapture = form.querySelector("[data-http-body-capture]");
            if (bodyCapture instanceof HTMLElement) {
                bodyCapture.hidden = probeType !== "http";
            }
            if (dialogIcon instanceof HTMLElement) {
                dialogIcon.className = settings.icon;
            }
            updateDialogTitle();
        }

        function setField(name, value) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
                field.value = String(value ?? "");
                field.dispatchEvent(new Event("change", {bubbles: true}));
            }
        }

        function setChecked(name, value) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement) {
                field.checked = Boolean(value);
            }
        }

        function setProbeType(type) {
            const select = form.elements.namedItem("probe_type");
            if (select instanceof HTMLSelectElement) {
                select.value = type;
                select.dispatchEvent(new Event("change", {bubbles: true}));
            }
        }

        function resetDisclosures() {
            form.querySelectorAll("details.admin-probe-disclosure").forEach((disclosure) => {
                disclosure.open = false;
            });
        }

        function applyDefaults() {
            setField("interval_sec", 60);
            setField("timeout_sec", 10);
            setField("retry_count", 2);
            setField("failure_threshold", 2);
            setField("recovery_threshold", 2);
            setField("slo_target_percent", 99.9);
            setField("calc_method", "inherit");
            setChecked("strict_availability", false);
            updateCalculationMethod();
            setField("http_method", "GET");
            setField("http_redirect", "follow");
            setField("accepted_status_codes", "200-399");
            setField("keyword_mode", "none");
            setField("tls_expiry_threshold_days", 14);
            setField("dns_record_type", "A");
            setField("heartbeat_grace_sec", 300);
            setField("mqtt_qos", 0);
            setField("sql_query", "SELECT 1");
            setChecked("active", true);
            setChecked("public_visible", true);
            setChecked("tls_verify", true);
            setChecked("diagnostics_enabled", true);
            setChecked("diagnostic_capture_body", false);
            setChecked("capture_success_screenshot", false);
        }

        function openDialog(mode) {
            form.reset();
            resetDisclosures();
            form.dataset.probeAction = "create";
            delete form.dataset.probeId;
            applyDefaults();
            setProbeType(mode === "server" ? "icmp" : "http");
            if (feedback instanceof HTMLElement) {
                feedback.hidden = true;
                feedback.textContent = "";
            }
            setTranslatedContent(submitLabel, "admin.probes.submit");
            if (submitIcon instanceof HTMLElement) {
                submitIcon.className = "fa-solid fa-plus";
            }
            updateTargetField();
            dialog.showModal();
            targetInput.focus();
        }

        function openEditDialog(button) {
            let probe = {};
            try {
                probe = JSON.parse(String(button.dataset.probeJson || "{}"));
            } catch (_error) {
                probe = {};
            }
            const probeType = String(probe.probe_type || button.dataset.probeType || "http").toLowerCase();
            const mode = ["icmp", "tcp", "snmp", "service"].includes(probeType) ? "server" : "monitor";
            form.reset();
            resetDisclosures();
            form.dataset.probeAction = "update";
            form.dataset.probeId = String(probe.id || button.dataset.probeId || "");
            applyDefaults();
            setProbeType(probeType);
            targetInput.value = String(probe.url || button.dataset.probeTarget || "");
            const storedCalculationMethod = String(probe.calc_method || "inherit").toLowerCase();
            ["name", "timeout_sec", "retry_count", "failure_threshold", "recovery_threshold", "accepted_status_codes", "http_method", "http_redirect", "keyword_text", "keyword_mode", "json_path", "json_expected_value", "request_headers_json", "request_body", "basic_auth_username", "tls_expiry_threshold_days", "dns_record_type", "dns_expected_value", "heartbeat_grace_sec", "slo_target_percent", "browser_script", "websocket_send", "websocket_expect", "mqtt_username", "mqtt_expect", "mqtt_qos", "sql_username", "sql_query", "sql_expect"].forEach((name) => {
                if (Object.prototype.hasOwnProperty.call(probe, name)) {
                    setField(name, probe[name]);
                }
            });
            setChecked("strict_availability", storedCalculationMethod === "strict_sla");
            updateCalculationMethod();
            setChecked("active", probe.active ?? true);
            setChecked("public_visible", probe.public_visible ?? true);
            setChecked("tls_verify", probe.tls_verify ?? true);
            setChecked("diagnostics_enabled", probe.diagnostics_enabled ?? true);
            setChecked("diagnostic_capture_body", probe.diagnostic_capture_body ?? false);
            setChecked("capture_success_screenshot", probe.capture_success_screenshot ?? false);
            const password = form.elements.namedItem("basic_auth_password");
            if (password instanceof HTMLInputElement) {
                password.placeholder = probe.has_basic_auth_password ? translate("admin.probes.secretConfigured") : "";
            }
            [["browser_variables_json", "has_browser_variables"], ["websocket_headers_json", "has_websocket_headers"], ["mqtt_password", "has_mqtt_password"], ["sql_password", "has_sql_password"]].forEach(([name, flag]) => {
                const field = form.elements.namedItem(name);
                if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                    field.placeholder = probe[flag] ? translate("admin.probes.secretConfigured") : field.getAttribute("data-default-placeholder") || "";
                }
            });
            if (intervalSelect instanceof HTMLSelectElement) {
                const interval = String(probe.probe_interval_sec || button.dataset.probeInterval || "60");
                intervalSelect.value = Array.from(intervalSelect.options).some((option) => option.value === interval) ? interval : "60";
                intervalSelect.dispatchEvent(new Event("change", {bubbles: true}));
            }
            setTranslatedContent(submitLabel, "admin.probes.save");
            if (submitIcon instanceof HTMLElement) {
                submitIcon.className = "fa-solid fa-check";
            }
            if (feedback instanceof HTMLElement) {
                feedback.hidden = true;
                feedback.textContent = "";
            }
            updateTargetField();
            dialog.showModal();
            targetInput.focus();
        }

        document.querySelectorAll("[data-probe-create]").forEach((button) => {
            button.addEventListener("click", () => openDialog(String(button.dataset.probeCreate || "http")));
        });
        document.querySelectorAll("[data-probe-edit]").forEach((button) => {
            button.addEventListener("click", () => openEditDialog(button));
        });
        if (diagnosticDialog instanceof HTMLDialogElement) {
            document.querySelectorAll("[data-probe-diagnostic]").forEach((button) => {
                button.addEventListener("click", async () => {
                    const diagnosticId = Number(button.dataset.probeDiagnostic || 0);
                    if (diagnosticTarget instanceof HTMLElement) {
                        diagnosticTarget.textContent = String(button.dataset.probeDiagnosticTarget || "");
                    }
                    if (diagnosticFeedback instanceof HTMLElement) {
                        diagnosticFeedback.hidden = true;
                    }
                    if (diagnosticSummary instanceof HTMLElement) {
                        diagnosticSummary.replaceChildren();
                    }
                    if (diagnosticOutput instanceof HTMLElement) {
                        diagnosticOutput.textContent = translate("common.loading");
                    }
                    if (diagnosticArtifact instanceof HTMLAnchorElement) {
                        diagnosticArtifact.hidden = true;
                    }
                    diagnosticDialog.showModal();
                    try {
                        const response = await fetch(`/admin/probe-diagnostic.php?id=${diagnosticId}`, {credentials: "same-origin", cache: "no-store"});
                        const result = await response.json();
                        if (!response.ok || result.ok !== true) {
                            throw new Error("admin.probes.errorDiagnostic");
                        }
                        const diagnostic = result.diagnostic || {};
                        if (diagnosticSummary instanceof HTMLElement) {
                            [["admin.monitors.state", diagnostic.status], ["admin.probes.errorCode", diagnostic.error_code || translate("admin.common.notAvailable")], ["admin.monitors.lastCheck", diagnostic.created_at]].forEach(([label, value]) => {
                                const wrapper = document.createElement("div");
                                const term = document.createElement("dt");
                                const detail = document.createElement("dd");
                                term.textContent = translate(label);
                                detail.textContent = String(value || "");
                                wrapper.append(term, detail);
                                diagnosticSummary.append(wrapper);
                            });
                        }
                        if (diagnosticOutput instanceof HTMLElement) {
                            diagnosticOutput.textContent = JSON.stringify({timing: diagnostic.timing || {}, response_headers: diagnostic.response_headers || {}, network: diagnostic.network || {}, body_excerpt: diagnostic.body_excerpt || ""}, null, 2);
                        }
                        if (diagnosticArtifact instanceof HTMLAnchorElement && diagnostic.has_artifact) {
                            diagnosticArtifact.href = `/admin/probe-diagnostic.php?id=${diagnosticId}&artifact=1`;
                            diagnosticArtifact.hidden = false;
                        }
                    } catch (_error) {
                        if (diagnosticFeedback instanceof HTMLElement) {
                            diagnosticFeedback.textContent = translate("admin.probes.errorDiagnostic");
                            diagnosticFeedback.hidden = false;
                        }
                        if (diagnosticOutput instanceof HTMLElement) {
                            diagnosticOutput.textContent = "";
                        }
                    }
                });
            });
            document.querySelectorAll("[data-probe-diagnostic-close]").forEach((button) => button.addEventListener("click", () => diagnosticDialog.close()));
            diagnosticDialog.addEventListener("click", (event) => {
                if (event.target === diagnosticDialog) {
                    diagnosticDialog.close();
                }
            });
        }
        document.querySelectorAll("[data-probe-close]").forEach((button) => {
            button.addEventListener("click", () => dialog.close());
        });
        const probeTypeSelect = form.elements.namedItem("probe_type");
        if (probeTypeSelect instanceof HTMLSelectElement) {
            probeTypeSelect.addEventListener("change", updateTargetField);
        }
        if (strictAvailability instanceof HTMLInputElement) {
            strictAvailability.addEventListener("change", updateCalculationMethod);
        }
        window.addEventListener("insight:locale-changed", () => {
            updateDialogTitle();
        });
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        form.addEventListener("invalid", (event) => {
            const field = event.target;
            if (!(field instanceof HTMLElement)) {
                return;
            }
            const disclosure = field.closest("details.admin-probe-disclosure");
            if (disclosure instanceof HTMLDetailsElement) {
                disclosure.open = true;
            }
        }, true);
        if (deleteDialog instanceof HTMLDialogElement) {
            document.querySelectorAll("[data-probe-delete]").forEach((button) => {
                button.addEventListener("click", () => {
                    deleteDialog.dataset.probeId = String(button.dataset.probeId || "");
                    if (deleteTarget instanceof HTMLElement) {
                        deleteTarget.textContent = String(button.dataset.probeTarget || "");
                    }
                    if (deleteFeedback instanceof HTMLElement) {
                        deleteFeedback.hidden = true;
                        deleteFeedback.textContent = "";
                    }
                    deleteDialog.showModal();
                });
            });
            document.querySelectorAll("[data-probe-delete-close]").forEach((button) => {
                button.addEventListener("click", () => deleteDialog.close());
            });
            deleteDialog.addEventListener("click", (event) => {
                if (event.target === deleteDialog) {
                    deleteDialog.close();
                }
            });
        }
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }
            if (feedback instanceof HTMLElement) {
                feedback.hidden = true;
                feedback.textContent = "";
            }
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-busy", "true");
            }
            try {
                const action = String(form.dataset.probeAction || "create");
                const data = new FormData(form);
                const response = await fetch("/admin/api/probes.php", {
                    method: action === "update" ? "PATCH" : "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")
                    },
                    body: JSON.stringify({
                        id: Number(form.dataset.probeId || 0),
                        target: targetInput.value,
                        probe_type: selectedProbeType(),
                        interval_sec: Number(data.get("interval_sec") || 60),
                        calc_method: String(data.get("calc_method") || "inherit"),
                        name: String(data.get("name") || ""),
                        active: data.has("active"),
                        timeout_sec: Number(data.get("timeout_sec") || 10),
                        retry_count: Number(data.get("retry_count") || 0),
                        failure_threshold: Number(data.get("failure_threshold") || 2),
                        recovery_threshold: Number(data.get("recovery_threshold") || 2),
                        slo_target_percent: Number(data.get("slo_target_percent") || 99.9),
                        http_method: String(data.get("http_method") || "GET"),
                        http_redirect: String(data.get("http_redirect") || "follow"),
                        accepted_status_codes: String(data.get("accepted_status_codes") || "200-399"),
                        keyword_mode: String(data.get("keyword_mode") || "none"),
                        keyword_text: String(data.get("keyword_text") || ""),
                        json_path: String(data.get("json_path") || ""),
                        json_expected_value: String(data.get("json_expected_value") || ""),
                        request_headers_json: String(data.get("request_headers_json") || ""),
                        request_body: String(data.get("request_body") || ""),
                        basic_auth_username: String(data.get("basic_auth_username") || ""),
                        basic_auth_password: String(data.get("basic_auth_password") || ""),
                        browser_script: String(data.get("browser_script") || ""),
                        browser_variables_json: String(data.get("browser_variables_json") || ""),
                        capture_success_screenshot: data.has("capture_success_screenshot"),
                        websocket_headers_json: String(data.get("websocket_headers_json") || ""),
                        websocket_send: String(data.get("websocket_send") || ""),
                        websocket_expect: String(data.get("websocket_expect") || ""),
                        mqtt_username: String(data.get("mqtt_username") || ""),
                        mqtt_password: String(data.get("mqtt_password") || ""),
                        mqtt_expect: String(data.get("mqtt_expect") || ""),
                        mqtt_qos: Number(data.get("mqtt_qos") || 0),
                        sql_username: String(data.get("sql_username") || ""),
                        sql_password: String(data.get("sql_password") || ""),
                        sql_query: String(data.get("sql_query") || "SELECT 1"),
                        sql_expect: String(data.get("sql_expect") || ""),
                        diagnostics_enabled: data.has("diagnostics_enabled"),
                        diagnostic_capture_body: data.has("diagnostic_capture_body"),
                        tls_verify: data.has("tls_verify"),
                        tls_expiry_threshold_days: Number(data.get("tls_expiry_threshold_days") || 14),
                        dns_record_type: String(data.get("dns_record_type") || "A"),
                        dns_expected_value: String(data.get("dns_expected_value") || ""),
                        heartbeat_grace_sec: Number(data.get("heartbeat_grace_sec") || 300),
                        public_visible: data.has("public_visible")
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(String(result.error || "admin.probes.errorGeneric"));
                }
                dialog.close();
                if (result.probe?.heartbeat_url && heartbeatDialog instanceof HTMLDialogElement && heartbeatValue instanceof HTMLElement) {
                    heartbeatValue.textContent = String(result.probe.heartbeat_url);
                    heartbeatDialog.dataset.reloadOnClose = "1";
                    heartbeatDialog.showModal();
                } else {
                    window.location.reload();
                }
            } catch (error) {
                if (feedback instanceof HTMLElement) {
                    const key = error instanceof Error ? error.message : "admin.probes.errorGeneric";
                    feedback.textContent = translate(key);
                    feedback.hidden = false;
                }
            } finally {
                if (submitButton instanceof HTMLButtonElement) {
                    submitButton.disabled = false;
                    submitButton.removeAttribute("aria-busy");
                }
            }
        });
        if (deleteConfirm instanceof HTMLButtonElement && deleteDialog instanceof HTMLDialogElement) {
            deleteConfirm.addEventListener("click", async () => {
                deleteConfirm.disabled = true;
                deleteConfirm.setAttribute("aria-busy", "true");
                if (deleteFeedback instanceof HTMLElement) {
                    deleteFeedback.hidden = true;
                    deleteFeedback.textContent = "";
                }
                try {
                    const response = await fetch("/admin/api/probes.php", {
                        method: "DELETE",
                        credentials: "same-origin",
                        headers: {
                            "Content-Type": "application/json",
                            "X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")
                        },
                        body: JSON.stringify({id: Number(deleteDialog.dataset.probeId || 0)})
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) {
                        throw new Error(String(result.error || "admin.probes.errorGeneric"));
                    }
                    deleteDialog.close();
                    window.location.reload();
                } catch (error) {
                    if (deleteFeedback instanceof HTMLElement) {
                        const key = error instanceof Error ? error.message : "admin.probes.errorGeneric";
                        deleteFeedback.textContent = translate(key);
                        deleteFeedback.hidden = false;
                    }
                } finally {
                    deleteConfirm.disabled = false;
                    deleteConfirm.removeAttribute("aria-busy");
                }
            });
        }
        document.querySelectorAll("[data-heartbeat-secret-close]").forEach((button) => button.addEventListener("click", () => heartbeatDialog?.close()));
        document.querySelector("[data-heartbeat-secret-copy]")?.addEventListener("click", async (event) => {
            try {
                await navigator.clipboard.writeText(heartbeatValue?.textContent || "");
                const icon = event.currentTarget?.querySelector("i");
                if (icon instanceof HTMLElement) {
                    icon.className = "fa-solid fa-check";
                }
            } catch (_error) {
                if (feedback instanceof HTMLElement) {
                    feedback.textContent = translate("admin.access.copyFailed");
                    feedback.hidden = false;
                }
            }
        });
        heartbeatDialog?.addEventListener("click", (event) => {
            if (event.target === heartbeatDialog) {
                heartbeatDialog.close();
            }
        });
        heartbeatDialog?.addEventListener("close", () => {
            if (heartbeatDialog.dataset.reloadOnClose === "1") {
                window.location.reload();
            }
        });
    }

    function bindIncidentManagement() {
        const detailsDialog = document.querySelector("[data-incident-details-dialog]");
        const detailsForm = document.querySelector("[data-incident-details-form]");
        const detailsTitle = document.querySelector("[data-incident-details-title]");
        const detailsSubmit = document.querySelector("[data-incident-details-submit]");
        const detailsFeedback = document.querySelector("[data-incident-details-feedback]");
        const updateDialog = document.querySelector("[data-incident-update-dialog]");
        const updateForm = document.querySelector("[data-incident-update-form]");
        const updateTitle = document.querySelector("[data-incident-update-title]");
        const updateTarget = document.querySelector("[data-incident-update-target]");
        const updateFeedback = document.querySelector("[data-incident-update-feedback]");
        const postmortemDialog = document.querySelector("[data-incident-dialog]");
        const postmortemForm = document.querySelector("[data-incident-form]");
        const postmortemInput = document.querySelector("[data-incident-postmortem-input]");
        const postmortemTarget = document.querySelector("[data-incident-target]");
        const postmortemFeedback = document.querySelector("[data-incident-feedback]");
        const commentDialog = document.querySelector("[data-incident-comment-dialog]");
        const commentForm = document.querySelector("[data-incident-comment-form]");
        const commentTarget = document.querySelector("[data-incident-comment-target]");
        const commentFeedback = document.querySelector("[data-incident-comment-feedback]");
        const runbookDialog = document.querySelector("[data-runbook-dialog]");
        const runbookForm = document.querySelector("[data-runbook-form]");
        const runbookTitle = document.querySelector("[data-runbook-title]");
        const runbookSubmit = document.querySelector("[data-runbook-submit]");
        const runbookFeedback = document.querySelector("[data-runbook-feedback]");
        if (!(detailsDialog instanceof HTMLDialogElement) || !(detailsForm instanceof HTMLFormElement) || !(updateDialog instanceof HTMLDialogElement) || !(updateForm instanceof HTMLFormElement)) {
            return;
        }
        const translate = (key) => window.InsightI18n?.t(key) || key;

        function feedback(node, key = "") {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.hidden = key === "";
            node.textContent = key === "" ? "" : translate(key);
        }

        function readIncident(row) {
            if (!(row instanceof HTMLElement)) {
                return null;
            }
            try {
                const value = JSON.parse(String(row.dataset.incidentJson || ""));
                return value && typeof value === "object" ? value : null;
            } catch (_error) {
                return null;
            }
        }

        async function request(method, payload) {
            const response = await fetch("/admin/api/incidents.php", {
                method,
                credentials: "same-origin",
                headers: {"Content-Type": "application/json", "X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")},
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => ({ok: false, error: "admin.incidents.errorGeneric"}));
            if (!response.ok || result.ok !== true) {
                throw new Error(String(result.error || "admin.incidents.errorGeneric"));
            }
            return result;
        }

        function setChecks(form, values) {
            const selected = new Set((Array.isArray(values) ? values : []).map(Number));
            form.querySelectorAll("input[name='site_ids']").forEach((input) => {
                if (input instanceof HTMLInputElement) {
                    input.checked = selected.has(Number(input.value));
                }
            });
        }

        function openDetails(incident = null) {
            detailsForm.reset();
            detailsForm.dataset.mode = incident ? "edit" : "create";
            detailsForm.dataset.incidentId = String(Number(incident?.id || 0));
            if (incident) {
                detailsForm.elements.namedItem("title").value = String(incident.title || "");
                detailsForm.elements.namedItem("summary").value = String(incident.summary || "");
                detailsForm.elements.namedItem("severity").value = String(incident.severity || "major");
                detailsForm.elements.namedItem("runbook_id").value = String(Number(incident.runbook_id || 0));
                detailsForm.elements.namedItem("metadata").value = Object.keys(incident.metadata || {}).length > 0 ? JSON.stringify(incident.metadata, null, 2) : "";
                detailsForm.elements.namedItem("published").checked = Number(incident.published ?? 1) === 1;
                setChecks(detailsForm, incident.site_ids);
            }
            const key = incident ? "admin.incidents.edit" : "admin.incidents.create";
            [detailsTitle, detailsSubmit].forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.dataset.i18n = key;
                    node.textContent = translate(key);
                }
            });
            feedback(detailsFeedback);
            detailsDialog.showModal();
            detailsForm.elements.namedItem("title")?.focus();
        }

        document.querySelector("[data-incident-create]")?.addEventListener("click", () => openDetails());
        document.querySelectorAll("[data-incident-details-close]").forEach((button) => button.addEventListener("click", () => detailsDialog.close()));
        document.querySelectorAll("[data-incident-update-close]").forEach((button) => button.addEventListener("click", () => updateDialog.close()));

        document.querySelectorAll("[data-incident-action]").forEach((button) => {
            button.addEventListener("click", () => {
                const row = button.closest("[data-incident-row]");
                const incident = readIncident(row);
                const action = String(button.dataset.incidentAction || "");
                if (!incident) {
                    return;
                }
                if (action === "edit") {
                    openDetails(incident);
                    return;
                }
                if (action === "delete") {
                    requestWorkflowDelete(String(incident.title || incident.url || "Incident"), () => request("DELETE", {id: Number(incident.id || 0)}));
                    return;
                }
                if (action === "comment" && commentDialog instanceof HTMLDialogElement && commentForm instanceof HTMLFormElement) {
                    commentForm.reset();
                    commentForm.dataset.incidentId = String(Number(incident.id || 0));
                    if (commentTarget instanceof HTMLElement) {
                        commentTarget.textContent = String(incident.title || incident.url || "");
                    }
                    feedback(commentFeedback);
                    commentDialog.showModal();
                    commentForm.elements.namedItem("body")?.focus();
                    return;
                }
                updateForm.reset();
                updateForm.dataset.incidentId = String(Number(incident.id || 0));
                updateForm.dataset.action = action;
                const key = `admin.incidents.action.${action}`;
                if (updateTitle instanceof HTMLElement) {
                    updateTitle.dataset.i18n = key;
                    updateTitle.textContent = translate(key);
                }
                if (updateTarget instanceof HTMLElement) {
                    updateTarget.textContent = String(incident.title || incident.url || "");
                }
                feedback(updateFeedback);
                updateDialog.showModal();
                updateForm.elements.namedItem("message")?.focus();
            });
        });

        detailsForm.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!detailsForm.reportValidity()) {
                return;
            }
            const submit = detailsForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            feedback(detailsFeedback);
            const data = new FormData(detailsForm);
            const mode = String(detailsForm.dataset.mode || "create");
            try {
                await request(mode === "edit" ? "PATCH" : "POST", {
                    id: Number(detailsForm.dataset.incidentId || 0),
                    action: mode === "edit" ? "edit" : undefined,
                    title: String(data.get("title") || ""),
                    summary: String(data.get("summary") || ""),
                    severity: String(data.get("severity") || "major"),
                    runbook_id: Number(data.get("runbook_id") || 0),
                    metadata: String(data.get("metadata") || ""),
                    published: data.has("published"),
                    site_ids: Array.from(detailsForm.querySelectorAll("input[name='site_ids']:checked")).map((input) => Number(input.value))
                });
                detailsDialog.close();
                window.location.reload();
            } catch (error) {
                feedback(detailsFeedback, error instanceof Error ? error.message : "admin.incidents.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        updateForm.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!updateForm.reportValidity()) {
                return;
            }
            const submit = updateForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            feedback(updateFeedback);
            const data = new FormData(updateForm);
            try {
                await request("PATCH", {
                    id: Number(updateForm.dataset.incidentId || 0),
                    action: String(updateForm.dataset.action || "update"),
                    message: String(data.get("message") || ""),
                    published: data.has("published")
                });
                updateDialog.close();
                window.location.reload();
            } catch (error) {
                feedback(updateFeedback, error instanceof Error ? error.message : "admin.incidents.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        if (commentDialog instanceof HTMLDialogElement && commentForm instanceof HTMLFormElement) {
            document.querySelectorAll("[data-incident-comment-close]").forEach((button) => button.addEventListener("click", () => commentDialog.close()));
            commentForm.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (!commentForm.reportValidity()) {
                    return;
                }
                const submit = commentForm.querySelector("button[type='submit']");
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = true;
                }
                feedback(commentFeedback);
                const data = new FormData(commentForm);
                const incidentId = Number(commentForm.dataset.incidentId || 0);
                try {
                    const result = await request("POST", {id: incidentId, action: "comment", body: String(data.get("body") || "")});
                    const attachment = data.get("attachment");
                    if (attachment instanceof File && attachment.size > 0) {
                        const upload = new FormData();
                        upload.set("incident_id", String(incidentId));
                        upload.set("comment_id", String(Number(result.comment?.id || 0)));
                        upload.set("attachment", attachment);
                        const response = await fetch("/admin/api/incident-attachments.php", {method: "POST", credentials: "same-origin", headers: {"X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")}, body: upload});
                        const payload = await response.json().catch(() => ({ok: false, error: "admin.incidents.errorAttachment"}));
                        if (!response.ok || payload.ok !== true) {
                            throw new Error(String(payload.error || "admin.incidents.errorAttachment"));
                        }
                    }
                    commentDialog.close();
                    window.location.reload();
                } catch (error) {
                    feedback(commentFeedback, error instanceof Error ? error.message : "admin.incidents.errorComment");
                } finally {
                    if (submit instanceof HTMLButtonElement) {
                        submit.disabled = false;
                    }
                }
            });
        }

        if (runbookDialog instanceof HTMLDialogElement && runbookForm instanceof HTMLFormElement) {
            function readRunbook(row) {
                try {
                    return JSON.parse(String(row?.dataset.runbookJson || ""));
                } catch (_error) {
                    return null;
                }
            }
            function openRunbook(runbook = null) {
                runbookForm.reset();
                runbookForm.dataset.mode = runbook ? "edit" : "create";
                runbookForm.dataset.runbookId = String(Number(runbook?.id || 0));
                if (runbook) {
                    ["name", "slug", "content"].forEach((name) => {
                        runbookForm.elements.namedItem(name).value = String(runbook[name] || "");
                    });
                    runbookForm.elements.namedItem("enabled").checked = Number(runbook.enabled ?? 1) === 1;
                }
                const key = runbook ? "admin.incidents.editRunbook" : "admin.incidents.newRunbook";
                [runbookTitle, runbookSubmit].forEach((node) => {
                    if (node instanceof HTMLElement) {
                        node.dataset.i18n = key;
                        node.textContent = translate(key);
                    }
                });
                feedback(runbookFeedback);
                runbookDialog.showModal();
                runbookForm.elements.namedItem("name")?.focus();
            }
            document.querySelector("[data-runbook-create]")?.addEventListener("click", () => openRunbook());
            document.querySelectorAll("[data-runbook-edit]").forEach((button) => button.addEventListener("click", () => openRunbook(readRunbook(button.closest("[data-runbook-row]")))));
            document.querySelectorAll("[data-runbook-delete]").forEach((button) => button.addEventListener("click", () => {
                const runbook = readRunbook(button.closest("[data-runbook-row]"));
                if (runbook) {
                    requestWorkflowDelete(String(runbook.name || "Runbook"), () => request("DELETE", {resource: "runbook", id: Number(runbook.id || 0)}));
                }
            }));
            document.querySelectorAll("[data-runbook-close]").forEach((button) => button.addEventListener("click", () => runbookDialog.close()));
            runbookForm.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (!runbookForm.reportValidity()) {
                    return;
                }
                const data = new FormData(runbookForm);
                const mode = String(runbookForm.dataset.mode || "create");
                try {
                    await request(mode === "edit" ? "PATCH" : "POST", {resource: "runbook", id: Number(runbookForm.dataset.runbookId || 0), name: String(data.get("name") || ""), slug: String(data.get("slug") || ""), content: String(data.get("content") || ""), enabled: data.has("enabled")});
                    runbookDialog.close();
                    window.location.reload();
                } catch (error) {
                    feedback(runbookFeedback, error instanceof Error ? error.message : "admin.incidents.errorRunbook");
                }
            });
        }

        if (postmortemDialog instanceof HTMLDialogElement && postmortemForm instanceof HTMLFormElement && postmortemInput instanceof HTMLTextAreaElement) {
            document.querySelectorAll("[data-incident-postmortem-edit]").forEach((button) => {
                button.addEventListener("click", () => {
                    const incident = readIncident(button.closest("[data-incident-row]"));
                    if (!incident) {
                        return;
                    }
                    postmortemForm.dataset.incidentId = String(Number(incident.id || 0));
                    postmortemInput.value = String(incident.postmortem || "");
                    if (postmortemTarget instanceof HTMLElement) {
                        postmortemTarget.textContent = String(incident.title || incident.url || "");
                    }
                    feedback(postmortemFeedback);
                    postmortemDialog.showModal();
                });
            });
            document.querySelectorAll("[data-incident-dialog-close]").forEach((button) => button.addEventListener("click", () => postmortemDialog.close()));
            document.querySelector("[data-incident-clear]")?.addEventListener("click", () => {
                postmortemInput.value = "";
            });
            postmortemForm.addEventListener("submit", async (event) => {
                event.preventDefault();
                const submit = postmortemForm.querySelector("button[type='submit']");
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = true;
                }
                try {
                    await request("PATCH", {id: Number(postmortemForm.dataset.incidentId || 0), action: "postmortem", postmortem: postmortemInput.value});
                    postmortemDialog.close();
                    window.location.reload();
                } catch (error) {
                    feedback(postmortemFeedback, error instanceof Error ? error.message : "admin.incidents.errorGeneric");
                } finally {
                    if (submit instanceof HTMLButtonElement) {
                        submit.disabled = false;
                    }
                }
            });
        }

        [detailsDialog, updateDialog, postmortemDialog, commentDialog, runbookDialog].forEach((dialog) => dialog?.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        }));
    }

    function bindMaintenanceManagement() {
        const dialog = document.querySelector("[data-maintenance-dialog]");
        const form = document.querySelector("[data-maintenance-form]");
        const title = document.querySelector("[data-maintenance-dialog-title]");
        const submitLabel = document.querySelector("[data-maintenance-submit]");
        const feedback = document.querySelector("[data-maintenance-feedback]");
        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) {
            return;
        }
        const translate = (key) => window.InsightI18n?.t(key) || key;

        function showFeedback(key = "") {
            if (feedback instanceof HTMLElement) {
                feedback.hidden = key === "";
                feedback.textContent = key === "" ? "" : translate(key);
            }
        }

        function read(row) {
            try {
                return JSON.parse(String(row?.dataset.maintenanceJson || ""));
            } catch (_error) {
                return null;
            }
        }

        function localInput(value) {
            return String(value || "").replace(" ", "T").slice(0, 16);
        }

        function setField(name, value) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
                field.value = String(value ?? "");
                field.dispatchEvent(new Event("change", {bubbles: true}));
            }
        }

        function open(maintenance = null) {
            form.reset();
            form.dataset.mode = maintenance ? "edit" : "create";
            form.dataset.maintenanceId = String(Number(maintenance?.id || 0));
            if (maintenance) {
                ["title", "description", "timezone", "status", "recurrence", "recurrence_interval"].forEach((name) => setField(name, maintenance[name]));
                setField("starts_at", localInput(maintenance.starts_at));
                setField("ends_at", localInput(maintenance.ends_at));
                setField("recurrence_until", localInput(maintenance.recurrence_until));
                const selected = new Set((maintenance.site_ids || []).map(Number));
                form.querySelectorAll("input[name='site_ids']").forEach((input) => {
                    input.checked = selected.has(Number(input.value));
                });
                form.elements.namedItem("notify_public").checked = Number(maintenance.notify_public ?? 1) === 1;
            } else {
                const starts = new Date(Date.now() + 3600000);
                starts.setMinutes(0, 0, 0);
                const ends = new Date(starts.getTime() + 3600000);
                const format = (value) => new Date(value.getTime() - value.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                setField("starts_at", format(starts));
                setField("ends_at", format(ends));
            }
            const key = maintenance ? "admin.maintenance.edit" : "admin.maintenance.create";
            [title, submitLabel].forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.dataset.i18n = key;
                    node.textContent = translate(key);
                }
            });
            showFeedback();
            dialog.showModal();
            form.elements.namedItem("title")?.focus();
        }

        async function request(method, payload) {
            const response = await fetch("/admin/api/maintenances.php", {
                method,
                credentials: "same-origin",
                headers: {"Content-Type": "application/json", "X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")},
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => ({ok: false, error: "admin.maintenance.errorGeneric"}));
            if (!response.ok || result.ok !== true) {
                throw new Error(String(result.error || "admin.maintenance.errorGeneric"));
            }
            return result;
        }

        document.querySelector("[data-maintenance-create]")?.addEventListener("click", () => open());
        document.querySelectorAll("[data-maintenance-edit]").forEach((button) => button.addEventListener("click", () => open(read(button.closest("[data-maintenance-row]")))));
        document.querySelectorAll("[data-maintenance-delete]").forEach((button) => button.addEventListener("click", () => {
            const maintenance = read(button.closest("[data-maintenance-row]"));
            if (maintenance) {
                requestWorkflowDelete(String(maintenance.title || "Maintenance"), () => request("DELETE", {id: Number(maintenance.id || 0)}));
            }
        }));
        document.querySelectorAll("[data-maintenance-close]").forEach((button) => button.addEventListener("click", () => dialog.close()));
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }
            const submit = form.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            showFeedback();
            const data = new FormData(form);
            const mode = String(form.dataset.mode || "create");
            try {
                await request(mode === "edit" ? "PATCH" : "POST", {
                    id: Number(form.dataset.maintenanceId || 0),
                    title: String(data.get("title") || ""),
                    description: String(data.get("description") || ""),
                    starts_at: String(data.get("starts_at") || ""),
                    ends_at: String(data.get("ends_at") || ""),
                    timezone: String(data.get("timezone") || "UTC"),
                    recurrence: String(data.get("recurrence") || "none"),
                    recurrence_interval: Number(data.get("recurrence_interval") || 1),
                    recurrence_until: String(data.get("recurrence_until") || ""),
                    status: String(data.get("status") || "planned"),
                    notify_public: data.has("notify_public"),
                    site_ids: Array.from(form.querySelectorAll("input[name='site_ids']:checked")).map((input) => Number(input.value))
                });
                dialog.close();
                window.location.reload();
            } catch (error) {
                showFeedback(error instanceof Error ? error.message : "admin.maintenance.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });
    }

    function bindStatusPageManagement() {
        const dialog = document.querySelector("[data-status-page-dialog]");
        const form = document.querySelector("[data-status-page-form]");
        const title = document.querySelector("[data-status-page-dialog-title]");
        const submitLabel = document.querySelector("[data-status-page-submit]");
        const feedback = document.querySelector("[data-status-page-feedback]");
        const groupsRoot = document.querySelector("[data-status-page-groups]");
        const passwordField = document.querySelector("[data-status-page-password-field]");
        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || !(groupsRoot instanceof HTMLElement)) {
            return;
        }
        const translate = (key) => window.InsightI18n?.t(key) || key;
        const accessSelect = form.elements.namedItem("visibility");
        if (accessSelect instanceof HTMLSelectElement) {
            accessSelect.name = "access_policy";
            accessSelect.replaceChildren();
            [["public", "admin.statusPages.access.public"], ["password", "admin.statusPages.access.password"], ["sso", "admin.statusPages.access.sso"], ["ip_allowlist", "admin.statusPages.access.ipAllowlist"]].forEach(([value, key]) => {
                const option = document.createElement("option");
                option.value = value;
                option.dataset.i18n = key;
                option.textContent = translate(key);
                accessSelect.append(option);
            });
            const accessLabel = accessSelect.closest("label")?.querySelector(":scope > span:first-child");
            if (accessLabel instanceof HTMLElement) {
                accessLabel.dataset.i18n = "admin.statusPages.accessPolicy";
                accessLabel.textContent = translate("admin.statusPages.accessPolicy");
            }
        }
        const experienceFields = document.createElement("section");
        experienceFields.className = "admin-status-page-experience";
        experienceFields.innerHTML = [
            "<div class='admin-probe-fields-heading'><i class='fa-solid fa-wand-magic-sparkles' aria-hidden='true'></i><strong data-i18n='admin.statusPages.experience'>Branding and access</strong></div>",
            "<label class='admin-field' data-status-page-ip-field hidden><span data-i18n='admin.statusPages.ipAllowlist'>Allowed IP addresses</span><textarea class='admin-textarea admin-code-textarea' name='ip_allowlist' rows='3' maxlength='10000' placeholder='192.0.2.0/24&#10;2001:db8::/32'></textarea><span class='admin-field-hint' data-i18n='admin.statusPages.ipAllowlistHint'>One IP address or CIDR range per line.</span></label>",
            "<div class='admin-notification-primary-fields'><label class='admin-field'><span data-i18n='admin.statusPages.logoUrl'>Logo URL</span><span class='admin-input-wrap'><i class='fa-regular fa-image' aria-hidden='true'></i><input type='text' name='logo_url' maxlength='1000' placeholder='/brand/logo.svg'></span></label><label class='admin-field'><span data-i18n='admin.statusPages.faviconUrl'>Favicon URL</span><span class='admin-input-wrap'><i class='fa-solid fa-icons' aria-hidden='true'></i><input type='text' name='favicon_url' maxlength='1000' placeholder='/brand/favicon.svg'></span></label></div>",
            "<div class='admin-notification-primary-fields'><label class='admin-field'><span data-i18n='admin.statusPages.announcement'>Announcement</span><span class='admin-input-wrap'><i class='fa-solid fa-bullhorn' aria-hidden='true'></i><input type='text' name='announcement' maxlength='1000'></span></label><label class='admin-field'><span data-i18n='admin.statusPages.announcementUrl'>Announcement link</span><span class='admin-input-wrap'><i class='fa-solid fa-link' aria-hidden='true'></i><input type='text' name='announcement_url' maxlength='1000'></span></label></div>",
            "<label class='admin-field'><span data-i18n='admin.statusPages.navigationLinks'>Navigation links</span><textarea class='admin-textarea' name='navigation_links' rows='3' maxlength='10000' placeholder='Help | https://docs.example.com'></textarea><span class='admin-field-hint' data-i18n='admin.statusPages.navigationLinksHint'>One link per line in Label | URL format, up to eight links.</span></label>",
            "<div class='admin-notification-primary-fields'><label class='admin-field'><span data-i18n='admin.statusPages.historyDays'>Public history</span><span class='admin-input-wrap'><i class='fa-solid fa-clock-rotate-left' aria-hidden='true'></i><input type='number' name='history_days' min='1' max='365' value='90' required></span><span class='admin-field-hint' data-i18n='admin.statusPages.historyDaysHint'>Number of days visitors may browse.</span></label><label class='admin-field'><span data-i18n='admin.statusPages.customCss'>Custom CSS</span><textarea class='admin-textarea admin-code-textarea' name='custom_css' rows='4' maxlength='20000'></textarea></label></div>",
            "<label class='admin-switch'><input type='checkbox' name='hide_from_search_engines'><span aria-hidden='true'></span><span><strong data-i18n='admin.statusPages.hideSearch'>Hide from search engines</strong><small data-i18n='admin.statusPages.hideSearchHint'>Adds noindex and nofollow directives.</small></span></label>"
        ].join("");
        passwordField?.after(experienceFields);
        window.InsightI18n?.apply(experienceFields);
        const ipField = experienceFields.querySelector("[data-status-page-ip-field]");
        const monitorOptions = Array.from(form.querySelectorAll("input[name='site_ids']")).map((input) => ({value: input.value, label: input.closest("label")?.textContent?.trim() || input.value}));

        function showFeedback(key = "") {
            if (feedback instanceof HTMLElement) {
                feedback.hidden = key === "";
                feedback.textContent = key === "" ? "" : translate(key);
            }
        }

        function read(row) {
            try {
                return JSON.parse(String(row?.dataset.statusPageJson || ""));
            } catch (_error) {
                return null;
            }
        }

        function createGroup(group = {}) {
            const section = document.createElement("section");
            section.className = "admin-status-page-group";
            const heading = document.createElement("div");
            heading.className = "admin-status-page-group-heading";
            const name = document.createElement("input");
            name.type = "text";
            name.maxLength = 160;
            name.required = true;
            name.placeholder = translate("admin.statusPages.groupName");
            name.value = String(group.name || "");
            name.dataset.groupName = "";
            const collapsedLabel = document.createElement("label");
            collapsedLabel.className = "admin-checkbox";
            const collapsed = document.createElement("input");
            collapsed.type = "checkbox";
            collapsed.checked = Number(group.collapsed || 0) === 1;
            collapsed.dataset.groupCollapsed = "";
            const collapsedText = document.createElement("span");
            collapsedText.textContent = translate("admin.statusPages.collapsed");
            collapsedLabel.append(collapsed, collapsedText);
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "admin-icon-button is-destructive";
            remove.setAttribute("aria-label", translate("admin.statusPages.removeGroup"));
            remove.title = translate("admin.statusPages.removeGroup");
            const removeIcon = document.createElement("i");
            removeIcon.className = "fa-solid fa-xmark";
            remove.append(removeIcon);
            remove.addEventListener("click", () => section.remove());
            heading.append(name, collapsedLabel, remove);
            const grid = document.createElement("div");
            grid.className = "admin-workflow-target-grid";
            const selected = new Set((group.site_ids || []).map(Number));
            monitorOptions.forEach((option) => {
                const label = document.createElement("label");
                const checkbox = document.createElement("input");
                checkbox.type = "checkbox";
                checkbox.value = option.value;
                checkbox.checked = selected.has(Number(option.value));
                checkbox.dataset.groupSite = "";
                const text = document.createElement("span");
                text.textContent = option.label;
                label.append(checkbox, text);
                grid.append(label);
            });
            section.append(heading, grid);
            groupsRoot.append(section);
        }

        function updateAccessVisibility() {
            const policy = form.elements.namedItem("access_policy");
            if (passwordField instanceof HTMLElement && policy instanceof HTMLSelectElement) {
                passwordField.hidden = policy.value !== "password";
                if (ipField instanceof HTMLElement) {
                    ipField.hidden = policy.value !== "ip_allowlist";
                }
            }
        }

        function setField(name, value) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
                field.value = String(value ?? "");
                field.dispatchEvent(new Event("change", {bubbles: true}));
            }
        }

        function open(page = null) {
            form.reset();
            groupsRoot.replaceChildren();
            form.dataset.mode = page ? "edit" : "create";
            form.dataset.pageId = String(Number(page?.id || 0));
            form.dataset.hasPassword = page?.has_password ? "1" : "0";
            if (page) {
                ["name", "slug", "description", "custom_domain", "access_policy", "theme", "locale", "accent_color", "ip_allowlist", "logo_url", "favicon_url", "announcement", "announcement_url", "custom_css", "history_days"].forEach((name) => setField(name, page[name]));
                setField("accent_text", page.accent_color || "#16a34a");
                setField("navigation_links", (page.navigation_links || []).map((link) => String(link.label || "") + " | " + String(link.url || "")).join("\n"));
                form.elements.namedItem("enabled").checked = Number(page.enabled ?? 1) === 1;
                form.elements.namedItem("hide_from_search_engines").checked = Number(page.hide_from_search_engines || 0) === 1;
                const selected = new Set((page.site_ids || []).map(Number));
                form.querySelectorAll("input[name='site_ids']").forEach((input) => {
                    input.checked = selected.has(Number(input.value));
                });
                (page.groups || []).forEach(createGroup);
            }
            const key = page ? "admin.statusPages.edit" : "admin.statusPages.create";
            [title, submitLabel].forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.dataset.i18n = key;
                    node.textContent = translate(key);
                }
            });
            updateAccessVisibility();
            showFeedback();
            dialog.showModal();
            form.elements.namedItem("name")?.focus();
        }

        async function request(method, payload) {
            const response = await fetch("/admin/api/status-pages.php", {
                method,
                credentials: "same-origin",
                headers: {"Content-Type": "application/json", "X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")},
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => ({ok: false, error: "admin.statusPages.errorGeneric"}));
            if (!response.ok || result.ok !== true) {
                throw new Error(String(result.error || "admin.statusPages.errorGeneric"));
            }
            return result;
        }

        document.querySelector("[data-status-page-create]")?.addEventListener("click", () => open());
        document.querySelectorAll("[data-status-page-edit]").forEach((button) => button.addEventListener("click", () => open(read(button.closest("[data-status-page-row]")))));
        document.querySelectorAll("[data-status-page-delete]").forEach((button) => button.addEventListener("click", () => {
            const page = read(button.closest("[data-status-page-row]"));
            if (page) {
                requestWorkflowDelete(String(page.name || "Status page"), () => request("DELETE", {id: Number(page.id || 0)}));
            }
        }));
        document.querySelector("[data-status-page-add-group]")?.addEventListener("click", () => createGroup());
        document.querySelectorAll("[data-status-page-close]").forEach((button) => button.addEventListener("click", () => dialog.close()));
        form.elements.namedItem("access_policy")?.addEventListener("change", updateAccessVisibility);
        const color = form.elements.namedItem("accent_color");
        const colorText = form.elements.namedItem("accent_text");
        color?.addEventListener("input", () => {
            colorText.value = color.value;
        });
        colorText?.addEventListener("input", () => {
            if (/^#[a-f0-9]{6}$/i.test(colorText.value)) {
                color.value = colorText.value;
            }
        });
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }
            const submit = form.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            showFeedback();
            const data = new FormData(form);
            const groups = Array.from(groupsRoot.querySelectorAll(".admin-status-page-group")).map((group) => ({
                name: String(group.querySelector("[data-group-name]")?.value || ""),
                collapsed: Boolean(group.querySelector("[data-group-collapsed]")?.checked),
                site_ids: Array.from(group.querySelectorAll("[data-group-site]:checked")).map((input) => Number(input.value))
            }));
            const mode = String(form.dataset.mode || "create");
            try {
                await request(mode === "edit" ? "PATCH" : "POST", {
                    id: Number(form.dataset.pageId || 0),
                    name: String(data.get("name") || ""),
                    slug: String(data.get("slug") || ""),
                    description: String(data.get("description") || ""),
                    custom_domain: String(data.get("custom_domain") || ""),
                    access_policy: String(data.get("access_policy") || "public"),
                    password: String(data.get("password") || ""),
                    ip_allowlist: String(data.get("ip_allowlist") || ""),
                    theme: String(data.get("theme") || "system"),
                    locale: String(data.get("locale") || "auto"),
                    accent_color: String(data.get("accent_text") || "#16a34a"),
                    logo_url: String(data.get("logo_url") || ""),
                    favicon_url: String(data.get("favicon_url") || ""),
                    announcement: String(data.get("announcement") || ""),
                    announcement_url: String(data.get("announcement_url") || ""),
                    navigation_links: String(data.get("navigation_links") || ""),
                    custom_css: String(data.get("custom_css") || ""),
                    history_days: Number(data.get("history_days") || 90),
                    hide_from_search_engines: data.has("hide_from_search_engines"),
                    enabled: data.has("enabled"),
                    site_ids: Array.from(form.querySelectorAll("input[name='site_ids']:checked")).map((input) => Number(input.value)),
                    groups
                });
                dialog.close();
                window.location.reload();
            } catch (error) {
                showFeedback(error instanceof Error ? error.message : "admin.statusPages.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });
    }

    function bindSecurityManagement() {
        const section = document.querySelector("[data-admin-view='account']");
        const pageFeedback = document.querySelector("[data-security-feedback]");
        const totpDialog = document.querySelector("[data-security-totp-dialog]");
        const totpForm = document.querySelector("[data-security-totp-form]");
        const totpFeedback = document.querySelector("[data-security-totp-feedback]");
        const verifyDialog = document.querySelector("[data-security-verify-dialog]");
        const verifyForm = document.querySelector("[data-security-verify-form]");
        const verifyTitle = document.querySelector("[data-security-verify-title]");
        const verifyFeedback = document.querySelector("[data-security-verify-feedback]");
        const recoveryDialog = document.querySelector("[data-security-recovery-dialog]");
        const recoveryCodes = document.querySelector("[data-security-recovery-codes]");
        const passwordDialog = document.querySelector("[data-security-password-dialog]");
        const passwordForm = document.querySelector("[data-security-password-form]");
        const passwordFeedback = document.querySelector("[data-security-password-feedback]");
        const userDialog = document.querySelector("[data-security-user-dialog]");
        const userForm = document.querySelector("[data-security-user-form]");
        const userFeedback = document.querySelector("[data-security-user-feedback]");
        if (!(section instanceof HTMLElement)) {
            return;
        }
        const translate = (key) => window.InsightI18n?.t(key) || key;
        let feedbackTimer = 0;

        function feedback(node, key = "", status = "error") {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.hidden = key === "";
            node.textContent = key === "" ? "" : translate(key);
            node.dataset.status = status;
        }

        function showPageFeedback(key, status = "success") {
            window.clearTimeout(feedbackTimer);
            feedback(pageFeedback, key, status);
            feedbackTimer = window.setTimeout(() => feedback(pageFeedback), 5000);
        }

        async function request(action, payload = {}) {
            const response = await fetch("/admin/api/security.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {"Content-Type": "application/json", "X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")},
                body: JSON.stringify({action, ...payload})
            });
            const result = await response.json().catch(() => ({ok: false, error: "admin.security.errorGeneric"}));
            if (!response.ok || result.ok !== true) {
                throw new Error(String(result.error || "admin.security.errorGeneric"));
            }
            return result;
        }

        async function copy(value, button) {
            try {
                await navigator.clipboard.writeText(String(value || ""));
                const icon = button?.querySelector("i");
                if (icon instanceof HTMLElement) {
                    const previous = icon.className;
                    icon.className = "fa-solid fa-check";
                    window.setTimeout(() => {
                        icon.className = previous;
                    }, 1600);
                }
            } catch (_error) {
                showPageFeedback("admin.access.copyFailed", "error");
            }
        }

        function showRecovery(values) {
            if (!(recoveryDialog instanceof HTMLDialogElement) || !(recoveryCodes instanceof HTMLElement)) {
                return;
            }
            recoveryCodes.replaceChildren();
            (Array.isArray(values) ? values : []).forEach((value) => {
                const code = document.createElement("code");
                code.textContent = String(value);
                recoveryCodes.append(code);
            });
            recoveryDialog.dataset.reloadOnClose = "1";
            recoveryDialog.showModal();
        }

        document.querySelector("[data-security-totp-begin]")?.addEventListener("click", async (event) => {
            const button = event.currentTarget;
            if (button instanceof HTMLButtonElement) {
                button.disabled = true;
            }
            try {
                const result = await request("totp_begin");
                const secret = document.querySelector("[data-security-totp-secret]");
                const link = document.querySelector("[data-security-otpauth]");
                if (secret instanceof HTMLElement) {
                    secret.textContent = String(result.secret || "");
                }
                if (link instanceof HTMLAnchorElement) {
                    link.href = String(result.otpauth_uri || "#");
                }
                if (totpForm instanceof HTMLFormElement && totpDialog instanceof HTMLDialogElement) {
                    totpForm.reset();
                    feedback(totpFeedback);
                    totpDialog.showModal();
                    totpForm.elements.namedItem("code")?.focus();
                }
            } catch (error) {
                showPageFeedback(error instanceof Error ? error.message : "admin.security.errorGeneric", "error");
            } finally {
                if (button instanceof HTMLButtonElement) {
                    button.disabled = false;
                }
            }
        });

        document.querySelector("[data-security-copy-secret]")?.addEventListener("click", (event) => {
            copy(document.querySelector("[data-security-totp-secret]")?.textContent || "", event.currentTarget);
        });

        totpForm?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!(totpForm instanceof HTMLFormElement) || !totpForm.reportValidity()) {
                return;
            }
            const submit = totpForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            feedback(totpFeedback);
            try {
                const result = await request("totp_confirm", {code: String(new FormData(totpForm).get("code") || "")});
                totpDialog?.close();
                showRecovery(result.recovery_codes);
            } catch (error) {
                feedback(totpFeedback, error instanceof Error ? error.message : "admin.security.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        function openVerification(action, titleKey) {
            if (!(verifyDialog instanceof HTMLDialogElement) || !(verifyForm instanceof HTMLFormElement)) {
                return;
            }
            verifyForm.reset();
            verifyForm.dataset.action = action;
            feedback(verifyFeedback);
            if (verifyTitle instanceof HTMLElement) {
                verifyTitle.dataset.i18n = titleKey;
                verifyTitle.textContent = translate(titleKey);
            }
            verifyDialog.showModal();
            verifyForm.elements.namedItem("code")?.focus();
        }

        document.querySelector("[data-security-totp-disable]")?.addEventListener("click", () => openVerification("totp_disable", "admin.security.disableTotp"));
        document.querySelector("[data-security-recovery]")?.addEventListener("click", () => openVerification("recovery_regenerate", "admin.security.regenerateRecovery"));
        verifyForm?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!(verifyForm instanceof HTMLFormElement) || !verifyForm.reportValidity()) {
                return;
            }
            const submit = verifyForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            feedback(verifyFeedback);
            try {
                const result = await request(String(verifyForm.dataset.action || ""), {code: String(new FormData(verifyForm).get("code") || "")});
                verifyDialog?.close();
                if (Array.isArray(result.recovery_codes)) {
                    showRecovery(result.recovery_codes);
                } else {
                    window.location.reload();
                }
            } catch (error) {
                feedback(verifyFeedback, error instanceof Error ? error.message : "admin.security.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        document.querySelector("[data-security-password]")?.addEventListener("click", () => {
            if (passwordDialog instanceof HTMLDialogElement && passwordForm instanceof HTMLFormElement) {
                passwordForm.reset();
                feedback(passwordFeedback);
                passwordDialog.showModal();
                passwordForm.elements.namedItem("current_password")?.focus();
            }
        });
        passwordForm?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!(passwordForm instanceof HTMLFormElement) || !passwordForm.reportValidity()) {
                return;
            }
            const submit = passwordForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            const data = new FormData(passwordForm);
            feedback(passwordFeedback);
            try {
                await request("password_change", {
                    current_password: String(data.get("current_password") || ""),
                    password: String(data.get("password") || ""),
                    password_confirmation: String(data.get("password_confirmation") || "")
                });
                passwordDialog?.close();
                showPageFeedback("admin.security.passwordChanged");
            } catch (error) {
                feedback(passwordFeedback, error instanceof Error ? error.message : "admin.security.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        document.querySelector("[data-security-user-create]")?.addEventListener("click", () => {
            if (userDialog instanceof HTMLDialogElement && userForm instanceof HTMLFormElement) {
                userForm.reset();
                feedback(userFeedback);
                userDialog.showModal();
                userForm.elements.namedItem("username")?.focus();
            }
        });
        userForm?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!(userForm instanceof HTMLFormElement) || !userForm.reportValidity()) {
                return;
            }
            const submit = userForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            const data = new FormData(userForm);
            feedback(userFeedback);
            try {
                await request("user_create", {
                    username: String(data.get("username") || ""),
                    role: String(data.get("role") || "viewer"),
                    password: String(data.get("password") || ""),
                    password_confirmation: String(data.get("password_confirmation") || "")
                });
                userDialog?.close();
                window.location.reload();
            } catch (error) {
                feedback(userFeedback, error instanceof Error ? error.message : "admin.security.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        document.querySelectorAll("[data-security-user-save]").forEach((button) => button.addEventListener("click", async () => {
            const row = button.closest("[data-security-user]");
            const role = row?.querySelector("[data-security-user-role]");
            const active = row?.querySelector("[data-security-user-active]");
            if (!(row instanceof HTMLElement) || !(role instanceof HTMLSelectElement) || !(active instanceof HTMLInputElement)) {
                return;
            }
            button.disabled = true;
            try {
                await request("user_update", {id: Number(row.dataset.userId || 0), role: role.value, active: active.checked});
                showPageFeedback("admin.security.userSaved");
            } catch (error) {
                showPageFeedback(error instanceof Error ? error.message : "admin.security.errorGeneric", "error");
            } finally {
                button.disabled = false;
            }
        }));
        document.querySelectorAll("[data-security-user-delete]").forEach((button) => button.addEventListener("click", () => {
            const row = button.closest("[data-security-user]");
            const label = row?.querySelector("strong")?.textContent || translate("admin.security.user");
            requestWorkflowDelete(label, () => request("user_delete", {id: Number(row?.dataset.userId || 0)}));
        }));

        document.querySelector("[data-security-copy-recovery]")?.addEventListener("click", (event) => {
            const values = Array.from(recoveryCodes?.querySelectorAll("code") || []).map((code) => code.textContent || "").join("\n");
            copy(values, event.currentTarget);
        });
        document.querySelectorAll("[data-security-dialog-close]").forEach((button) => button.addEventListener("click", () => button.closest("dialog")?.close()));
        [totpDialog, verifyDialog, recoveryDialog, passwordDialog, userDialog].forEach((dialog) => {
            dialog?.addEventListener("click", (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        });
        recoveryDialog?.addEventListener("close", () => {
            if (recoveryDialog.dataset.reloadOnClose === "1") {
                window.location.reload();
            }
        });
    }

    function bindNodeManagement() {
        const dialog = document.querySelector("[data-node-dialog]");
        const form = document.querySelector("[data-node-form]");
        const feedback = document.querySelector("[data-node-feedback]");
        const pageFeedback = document.querySelector("[data-node-page-feedback]");
        const secretDialog = document.querySelector("[data-node-secret-dialog]");
        const environment = document.querySelector("[data-node-secret-env]");
        const command = document.querySelector("[data-node-secret-command]");
        const translate = (key) => window.InsightI18n?.t(key) || key;

        function showFeedback(node, key = "", status = "error") {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.hidden = key === "";
            node.textContent = key === "" ? "" : translate(key);
            node.dataset.status = status;
        }

        async function request(method, payload) {
            const response = await fetch("/admin/api/nodes.php", {
                method,
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": String(window.INSIGHT_CONFIG?.csrfToken || "")
                },
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || result.ok !== true) {
                throw new Error(String(result.error || "admin.network.errorGeneric"));
            }
            return result;
        }

        document.querySelector("[data-node-create]")?.addEventListener("click", () => {
            if (dialog instanceof HTMLDialogElement && form instanceof HTMLFormElement) {
                form.reset();
                showFeedback(feedback);
                dialog.showModal();
                form.elements.namedItem("node_key")?.focus();
            }
        });
        document.querySelectorAll("[data-node-close]").forEach((button) => button.addEventListener("click", () => dialog?.close()));
        dialog?.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        form?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!(form instanceof HTMLFormElement) || !form.reportValidity()) {
                return;
            }
            const submit = form.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            showFeedback(feedback);
            const data = new FormData(form);
            try {
                const result = await request("POST", {
                    node_key: String(data.get("node_key") || ""),
                    display_name: String(data.get("display_name") || ""),
                    region: String(data.get("region") || ""),
                    zone: String(data.get("zone") || "")
                });
                dialog?.close();
                if (environment instanceof HTMLElement) {
                    environment.textContent = String(result.agent_env || "");
                }
                if (command instanceof HTMLElement) {
                    command.textContent = String(result.start_command || "");
                }
                if (secretDialog instanceof HTMLDialogElement) {
                    secretDialog.dataset.reloadOnClose = "1";
                    secretDialog.showModal();
                }
            } catch (error) {
                showFeedback(feedback, error instanceof Error ? error.message : "admin.network.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });
        document.querySelectorAll("[data-node-status-action]").forEach((button) => {
            button.addEventListener("click", async () => {
                const row = button.closest("[data-node-row]");
                const nodeKey = String(row?.dataset.nodeKey || "");
                const status = String(button.dataset.nodeStatusAction || "");
                if (!(button instanceof HTMLButtonElement) || !nodeKey || !status) {
                    return;
                }
                button.disabled = true;
                showFeedback(pageFeedback);
                try {
                    await request("PATCH", {node_key: nodeKey, status});
                    window.location.reload();
                } catch (error) {
                    showFeedback(pageFeedback, error instanceof Error ? error.message : "admin.network.errorGeneric");
                    button.disabled = false;
                }
            });
        });
        document.querySelectorAll("[data-node-secret-close]").forEach((button) => button.addEventListener("click", () => secretDialog?.close()));
        secretDialog?.addEventListener("close", () => {
            if (secretDialog.dataset.reloadOnClose === "1") {
                window.location.reload();
            }
        });
        document.querySelectorAll("[data-node-copy]").forEach((button) => {
            button.addEventListener("click", async () => {
                const value = button.dataset.nodeCopy === "command" ? command?.textContent : environment?.textContent;
                try {
                    await navigator.clipboard.writeText(String(value || ""));
                    button.querySelector("i")?.classList.replace("fa-copy", "fa-check");
                } catch (_error) {
                    showFeedback(pageFeedback, "admin.access.copyFailed");
                }
            });
        });
    }

    function bindNotificationManagement() {
        const section = document.querySelector("[data-admin-view='notifications']");
        const list = document.querySelector("[data-notification-list]");
        const deliveries = document.querySelector("[data-notification-deliveries]");
        const count = document.querySelector("[data-notification-count]");
        const pageFeedback = document.querySelector("[data-notification-page-feedback]");
        const dialog = document.querySelector("[data-notification-dialog]");
        const form = document.querySelector("[data-notification-form]");
        const providerSelect = form?.querySelector("[data-notification-provider]");
        const dialogTitle = document.querySelector("[data-notification-dialog-title]");
        const dialogIcon = document.querySelector("[data-notification-dialog-icon]");
        const submitButton = document.querySelector("[data-notification-submit]");
        const submitLabel = document.querySelector("[data-notification-submit-label]");
        const feedback = document.querySelector("[data-notification-feedback]");
        const templateForm = document.querySelector("[data-notification-template-form]");
        const templateFeedback = document.querySelector("[data-notification-template-feedback]");
        const deleteDialog = document.querySelector("[data-notification-delete-dialog]");
        const deleteTarget = document.querySelector("[data-notification-delete-target]");
        const deleteFeedback = document.querySelector("[data-notification-delete-feedback]");
        const deleteConfirm = document.querySelector("[data-notification-delete-confirm]");
        if (!(section instanceof HTMLElement) || !(list instanceof HTMLElement) || !(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || !(providerSelect instanceof HTMLSelectElement)) {
            return;
        }

        const translate = (key) => window.InsightI18n?.t(key) || key;
        let state = {channels: [], templates: {}, deliveries: [], catalog: []};
        let feedbackTimer = 0;
        let lastTemplateField = null;

        function setTranslatedContent(node, key) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.dataset.i18n = key;
            node.textContent = translate(key);
        }

        function showPageFeedback(key, status) {
            if (!(pageFeedback instanceof HTMLElement)) {
                return;
            }
            window.clearTimeout(feedbackTimer);
            pageFeedback.dataset.status = status;
            pageFeedback.textContent = translate(key);
            pageFeedback.hidden = false;
            feedbackTimer = window.setTimeout(() => {
                pageFeedback.hidden = true;
            }, 5000);
        }

        function showFormFeedback(node, key) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.textContent = translate(key);
            node.hidden = false;
        }

        function clearFormFeedback(node) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.hidden = true;
            node.textContent = "";
        }

        async function request(method, payload) {
            const options = {
                method,
                credentials: "same-origin",
                headers: {"Accept": "application/json"}
            };
            if (method !== "GET") {
                options.headers["Content-Type"] = "application/json";
                options.headers["X-CSRF-Token"] = String(window.INSIGHT_CONFIG?.csrfToken || "");
                options.body = JSON.stringify(payload || {});
            }
            const response = await fetch("/admin/api/notifications.php", options);
            const result = await response.json().catch(() => ({ok: false, error: "admin.notifications.errorGeneric"}));
            if (!response.ok || !result.ok) {
                throw new Error(String(result.error || "admin.notifications.errorGeneric"));
            }
            return result;
        }

        function actionButton(icon, key, action, destructive) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = `admin-icon-button${destructive ? " is-destructive" : ""}`;
            button.dataset.notificationAction = action;
            button.dataset.i18nAriaLabel = key;
            button.dataset.i18nTitle = key;
            button.setAttribute("aria-label", translate(key));
            button.setAttribute("title", translate(key));
            const symbol = document.createElement("i");
            symbol.className = icon;
            symbol.setAttribute("aria-hidden", "true");
            button.append(symbol);
            return button;
        }

        function renderChannels() {
            list.replaceChildren();
            if (count instanceof HTMLElement) {
                count.textContent = String(state.channels.length);
            }
            if (state.channels.length === 0) {
                const empty = document.createElement("div");
                empty.className = "admin-empty admin-notification-empty";
                const icon = document.createElement("i");
                icon.className = "fa-regular fa-bell-slash";
                icon.setAttribute("aria-hidden", "true");
                const text = document.createElement("span");
                text.dataset.i18n = "admin.notifications.empty";
                text.textContent = translate("admin.notifications.empty");
                empty.append(icon, text);
                list.append(empty);
                return;
            }
            state.channels.forEach((channel) => {
                const row = document.createElement("article");
                row.className = "admin-notification-row";
                row.dataset.channelId = String(channel.id || 0);
                row.dataset.channelJson = JSON.stringify(channel);
                const iconWrap = document.createElement("span");
                iconWrap.className = "admin-notification-icon";
                const icon = document.createElement("i");
                icon.className = String(channel.provider_icon || "fa-solid fa-bell");
                icon.setAttribute("aria-hidden", "true");
                iconWrap.append(icon);
                const copy = document.createElement("div");
                copy.className = "admin-notification-copy";
                const name = document.createElement("strong");
                name.textContent = String(channel.name || "");
                const meta = document.createElement("span");
                const routed = Array.isArray(channel.site_ids) && channel.site_ids.length > 0
                    ? `${channel.site_ids.length} ${translate("admin.notifications.monitorsShort")}`
                    : translate("admin.notifications.allMonitors");
                const severity = translate(`admin.incidents.severity.${String(channel.minimum_severity || "info")}`);
                meta.textContent = `${String(channel.provider_label || channel.provider || "Apprise")} · ${Array.isArray(channel.events) ? channel.events.length : 0} ${translate("admin.notifications.eventsShort")} · ${severity} · ${routed}`;
                copy.append(name, meta);
                const status = document.createElement("span");
                const failed = channel.last_status === "error";
                status.className = "admin-status-badge";
                status.dataset.status = channel.enabled ? (failed ? "offline" : "operational") : "unknown";
                const dot = document.createElement("span");
                dot.setAttribute("aria-hidden", "true");
                const statusText = document.createElement("span");
                const statusKey = channel.enabled ? (failed ? "admin.notifications.error" : "admin.notifications.active") : "admin.notifications.inactive";
                statusText.dataset.i18n = statusKey;
                statusText.textContent = translate(statusKey);
                status.append(dot, statusText);
                const actions = document.createElement("div");
                actions.className = "admin-row-actions";
                actions.append(
                    actionButton("fa-solid fa-paper-plane", "admin.notifications.test", "test", false),
                    actionButton("fa-solid fa-pen", "admin.notifications.edit", "edit", false),
                    actionButton("fa-regular fa-trash-can", "admin.notifications.delete", "delete", true)
                );
                row.append(iconWrap, copy, status, actions);
                list.append(row);
            });
        }

        function eventKey(event) {
            const allowed = ["test", "monitor_down", "monitor_up", "incident_open", "incident_update", "incident_acknowledged", "incident_resolved", "tls_expiring", "tls_invalid", "maintenance_started", "maintenance_ended"];
            return allowed.includes(String(event || "")) ? `admin.notifications.event.${String(event)}` : "admin.notifications.event.test";
        }

        function parseDate(value) {
            const raw = String(value || "").trim();
            if (raw === "") {
                return "";
            }
            return raw.includes("T") ? raw : `${raw.replace(" ", "T")}Z`;
        }

        function renderDeliveries() {
            if (!(deliveries instanceof HTMLElement)) {
                return;
            }
            deliveries.replaceChildren();
            if (state.deliveries.length === 0) {
                const empty = document.createElement("div");
                empty.className = "admin-empty";
                const icon = document.createElement("i");
                icon.className = "fa-regular fa-clock";
                icon.setAttribute("aria-hidden", "true");
                const text = document.createElement("span");
                text.dataset.i18n = "admin.notifications.noHistory";
                text.textContent = translate("admin.notifications.noHistory");
                empty.append(icon, text);
                deliveries.append(empty);
                return;
            }
            state.deliveries.forEach((delivery) => {
                const row = document.createElement("div");
                row.className = "admin-delivery-row";
                const status = document.createElement("span");
                status.className = "admin-delivery-state";
                status.dataset.status = delivery.status === "sent" ? "success" : "error";
                const statusIcon = document.createElement("i");
                statusIcon.className = delivery.status === "sent" ? "fa-solid fa-check" : "fa-solid fa-xmark";
                statusIcon.setAttribute("aria-hidden", "true");
                status.append(statusIcon);
                const copy = document.createElement("div");
                const name = document.createElement("strong");
                name.textContent = String(delivery.channel_name || translate("admin.notifications.deletedChannel"));
                const title = document.createElement("span");
                title.textContent = String(delivery.title_rendered || delivery.error_message || "");
                copy.append(name, title);
                const event = document.createElement("span");
                event.className = "admin-delivery-event";
                event.dataset.i18n = eventKey(delivery.event_key);
                event.textContent = translate(eventKey(delivery.event_key));
                const time = document.createElement("time");
                const datetime = parseDate(delivery.attempted_at);
                if (datetime !== "") {
                    time.setAttribute("datetime", datetime);
                }
                row.append(status, copy, event, time);
                deliveries.append(row);
            });
            formatTimes();
        }

        function renderProviderOptions() {
            if (!Array.isArray(state.catalog) || state.catalog.length === 0) {
                return;
            }
            const selected = providerSelect.value;
            providerSelect.replaceChildren();
            state.catalog.forEach((provider) => {
                const option = document.createElement("option");
                option.value = String(provider.id || "apprise");
                option.dataset.mode = String(provider.mode || "apprise");
                option.dataset.icon = String(provider.icon || "fa-solid fa-bell");
                option.textContent = String(provider.label || provider.id || "Apprise");
                providerSelect.append(option);
            });
            providerSelect.value = Array.from(providerSelect.options).some((option) => option.value === selected) ? selected : "smtp";
        }

        function selectedProviderMode() {
            return providerSelect.selectedOptions[0]?.dataset.mode || "apprise";
        }

        function updateProviderFields() {
            const mode = selectedProviderMode();
            form.querySelectorAll("[data-notification-config]").forEach((group) => {
                group.hidden = group.dataset.notificationConfig !== mode;
            });
            if (dialogIcon instanceof HTMLElement) {
                dialogIcon.className = providerSelect.selectedOptions[0]?.dataset.icon || "fa-regular fa-bell";
            }
        }

        function setField(name, value) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
                field.value = String(value ?? "");
            }
        }

        function setSecretPlaceholder(name, configured) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                field.value = "";
                field.placeholder = configured ? translate("admin.notifications.secretConfigured") : String(field.dataset.defaultPlaceholder || field.placeholder || "");
            }
        }

        function rememberDefaultPlaceholders() {
            form.querySelectorAll("[data-secret-field]").forEach((field) => {
                if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                    field.dataset.defaultPlaceholder = field.placeholder;
                }
            });
        }

        function openCreateDialog() {
            form.reset();
            form.dataset.notificationAction = "create";
            delete form.dataset.notificationId;
            setTranslatedContent(dialogTitle, "admin.notifications.createTitle");
            setTranslatedContent(submitLabel, "admin.notifications.create");
            form.querySelectorAll("input[name='events']").forEach((input) => {
                if (input instanceof HTMLInputElement) {
                    input.checked = true;
                }
            });
            setField("smtp_port", 465);
            setField("smtp_encryption", "ssl");
            setField("smtp_from_name", String(window.INSIGHT_CONFIG?.appName || "Insight"));
            setField("provider", "smtp");
            setField("minimum_severity", "info");
            form.querySelectorAll("[data-secret-field]").forEach((field) => {
                if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                    field.placeholder = String(field.dataset.defaultPlaceholder || "");
                }
            });
            clearFormFeedback(feedback);
            updateProviderFields();
            dialog.showModal();
            const name = form.elements.namedItem("name");
            if (name instanceof HTMLInputElement) {
                name.focus();
            }
        }

        function openEditDialog(channel) {
            form.reset();
            form.dataset.notificationAction = "update";
            form.dataset.notificationId = String(channel.id || 0);
            setTranslatedContent(dialogTitle, "admin.notifications.editTitle");
            setTranslatedContent(submitLabel, "admin.notifications.saveChannel");
            setField("name", channel.name || "");
            setField("provider", channel.provider || "apprise");
            setField("minimum_severity", channel.minimum_severity || "info");
            const enabled = form.elements.namedItem("enabled");
            if (enabled instanceof HTMLInputElement) {
                enabled.checked = Boolean(channel.enabled);
            }
            const events = Array.isArray(channel.events) ? channel.events : [];
            form.querySelectorAll("input[name='events']").forEach((input) => {
                if (input instanceof HTMLInputElement) {
                    input.checked = events.includes(input.value);
                }
            });
            const siteIds = new Set((Array.isArray(channel.site_ids) ? channel.site_ids : []).map(Number));
            form.querySelectorAll("input[name='site_ids']").forEach((input) => {
                if (input instanceof HTMLInputElement) {
                    input.checked = siteIds.has(Number(input.value));
                }
            });
            const config = channel.config && typeof channel.config === "object" ? channel.config : {};
            if (channel.provider_mode === "smtp") {
                setField("smtp_host", config.host || "");
                setField("smtp_port", config.port || 465);
                setField("smtp_encryption", config.encryption || "ssl");
                setField("smtp_username", config.username || "");
                setField("smtp_from_email", config.from_email || "");
                setField("smtp_from_name", config.from_name || String(window.INSIGHT_CONFIG?.appName || "Insight"));
                setField("smtp_to", config.to || "");
                setSecretPlaceholder("smtp_password", Boolean(config.has_password));
            } else if (channel.provider_mode === "webhook") {
                setField("webhook_method", config.method || "POST");
                setField("webhook_payload", config.payload_template || "");
                setSecretPlaceholder("webhook_url", Boolean(config.has_url));
                setSecretPlaceholder("webhook_headers", Boolean(config.has_headers));
            } else if (channel.provider_mode === "free_mobile") {
                setField("free_user", config.user || "");
                setSecretPlaceholder("free_password", Boolean(config.has_password));
            } else {
                setSecretPlaceholder("apprise_urls", Boolean(config.has_urls));
            }
            clearFormFeedback(feedback);
            updateProviderFields();
            dialog.showModal();
            const name = form.elements.namedItem("name");
            if (name instanceof HTMLInputElement) {
                name.focus();
            }
        }

        function channelPayload() {
            const mode = selectedProviderMode();
            const config = mode === "smtp" ? {
                host: String(new FormData(form).get("smtp_host") || ""),
                port: Number(new FormData(form).get("smtp_port") || 465),
                encryption: String(new FormData(form).get("smtp_encryption") || "ssl"),
                username: String(new FormData(form).get("smtp_username") || ""),
                password: String(new FormData(form).get("smtp_password") || ""),
                from_email: String(new FormData(form).get("smtp_from_email") || ""),
                from_name: String(new FormData(form).get("smtp_from_name") || ""),
                to: String(new FormData(form).get("smtp_to") || "")
            } : mode === "webhook" ? {
                url: String(new FormData(form).get("webhook_url") || ""),
                method: String(new FormData(form).get("webhook_method") || "POST"),
                headers: String(new FormData(form).get("webhook_headers") || ""),
                payload_template: String(new FormData(form).get("webhook_payload") || "")
            } : mode === "free_mobile" ? {
                user: String(new FormData(form).get("free_user") || ""),
                password: String(new FormData(form).get("free_password") || "")
            } : {
                urls: String(new FormData(form).get("apprise_urls") || "")
            };
            return {
                id: Number(form.dataset.notificationId || 0),
                name: String(new FormData(form).get("name") || ""),
                provider: providerSelect.value,
                enabled: Boolean(form.elements.namedItem("enabled")?.checked),
                events: Array.from(form.querySelectorAll("input[name='events']:checked")).map((input) => input.value),
                minimum_severity: String(new FormData(form).get("minimum_severity") || "info"),
                site_ids: Array.from(form.querySelectorAll("input[name='site_ids']:checked")).map((input) => Number(input.value)),
                config
            };
        }

        function updateTemplateFields() {
            if (!(templateForm instanceof HTMLFormElement)) {
                return;
            }
            const event = String(new FormData(templateForm).get("event") || "monitor_down");
            const template = state.templates[event] || {};
            const title = templateForm.elements.namedItem("title");
            const body = templateForm.elements.namedItem("body");
            if (title instanceof HTMLInputElement) {
                title.value = String(template.title || "");
            }
            if (body instanceof HTMLTextAreaElement) {
                body.value = String(template.body || "");
            }
            clearFormFeedback(templateFeedback);
        }

        async function loadState() {
            const result = await request("GET");
            state = {
                channels: Array.isArray(result.channels) ? result.channels : [],
                templates: result.templates && typeof result.templates === "object" ? result.templates : {},
                deliveries: Array.isArray(result.deliveries) ? result.deliveries : [],
                catalog: Array.isArray(result.catalog) ? result.catalog : []
            };
            renderProviderOptions();
            renderChannels();
            renderDeliveries();
            updateTemplateFields();
            updateProviderFields();
            window.InsightI18n?.apply(section);
        }

        rememberDefaultPlaceholders();
        providerSelect.addEventListener("change", updateProviderFields);
        document.querySelector("[data-notification-create]")?.addEventListener("click", openCreateDialog);
        document.querySelectorAll("[data-notification-close]").forEach((button) => button.addEventListener("click", () => dialog.close()));
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        list.addEventListener("click", async (event) => {
            const button = event.target instanceof Element ? event.target.closest("[data-notification-action], [data-notification-test], [data-notification-edit], [data-notification-delete]") : null;
            const row = button?.closest("[data-channel-id], [data-notification-channel]");
            if (!(button instanceof HTMLButtonElement) || !(row instanceof HTMLElement)) {
                return;
            }
            let channel = null;
            try {
                channel = JSON.parse(String(row.dataset.channelJson || "{}"));
            } catch (error) {
                channel = state.channels.find((item) => Number(item.id) === Number(row.dataset.channelId || 0));
            }
            if (!channel) {
                return;
            }
            const action = button.dataset.notificationAction || (button.hasAttribute("data-notification-test") ? "test" : button.hasAttribute("data-notification-edit") ? "edit" : "delete");
            if (action === "edit") {
                openEditDialog(channel);
                return;
            }
            if (action === "delete" && deleteDialog instanceof HTMLDialogElement) {
                deleteDialog.dataset.notificationId = String(channel.id || 0);
                if (deleteTarget instanceof HTMLElement) {
                    deleteTarget.textContent = String(channel.name || "");
                }
                clearFormFeedback(deleteFeedback);
                deleteDialog.showModal();
                return;
            }
            if (action === "test") {
                button.disabled = true;
                button.setAttribute("aria-busy", "true");
                try {
                    await request("POST", {action: "test", id: Number(channel.id || 0)});
                    showPageFeedback("admin.notifications.testSuccess", "success");
                    await loadState();
                } catch (error) {
                    showPageFeedback(error instanceof Error ? error.message : "admin.notifications.errorTest", "error");
                    await loadState().catch(() => {});
                } finally {
                    button.disabled = false;
                    button.removeAttribute("aria-busy");
                }
            }
        });

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }
            clearFormFeedback(feedback);
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-busy", "true");
            }
            try {
                const action = String(form.dataset.notificationAction || "create");
                await request(action === "update" ? "PATCH" : "POST", channelPayload());
                dialog.close();
                showPageFeedback(action === "update" ? "admin.notifications.updateSuccess" : "admin.notifications.createSuccess", "success");
                await loadState();
            } catch (error) {
                showFormFeedback(feedback, error instanceof Error ? error.message : "admin.notifications.errorGeneric");
            } finally {
                if (submitButton instanceof HTMLButtonElement) {
                    submitButton.disabled = false;
                    submitButton.removeAttribute("aria-busy");
                }
            }
        });

        if (deleteDialog instanceof HTMLDialogElement) {
            document.querySelectorAll("[data-notification-delete-close]").forEach((button) => button.addEventListener("click", () => deleteDialog.close()));
            deleteDialog.addEventListener("click", (event) => {
                if (event.target === deleteDialog) {
                    deleteDialog.close();
                }
            });
        }
        if (deleteConfirm instanceof HTMLButtonElement && deleteDialog instanceof HTMLDialogElement) {
            deleteConfirm.addEventListener("click", async () => {
                deleteConfirm.disabled = true;
                deleteConfirm.setAttribute("aria-busy", "true");
                clearFormFeedback(deleteFeedback);
                try {
                    await request("DELETE", {id: Number(deleteDialog.dataset.notificationId || 0)});
                    deleteDialog.close();
                    showPageFeedback("admin.notifications.deleteSuccess", "success");
                    await loadState();
                } catch (error) {
                    showFormFeedback(deleteFeedback, error instanceof Error ? error.message : "admin.notifications.errorGeneric");
                } finally {
                    deleteConfirm.disabled = false;
                    deleteConfirm.removeAttribute("aria-busy");
                }
            });
        }

        if (templateForm instanceof HTMLFormElement) {
            templateForm.elements.namedItem("event")?.addEventListener("change", updateTemplateFields);
            [templateForm.elements.namedItem("title"), templateForm.elements.namedItem("body")].forEach((field) => {
                field?.addEventListener("focus", () => {
                    lastTemplateField = field;
                });
            });
            templateForm.querySelectorAll("[data-template-token]").forEach((button) => {
                button.addEventListener("click", () => {
                    const fallback = templateForm.elements.namedItem("body");
                    const field = lastTemplateField instanceof HTMLInputElement || lastTemplateField instanceof HTMLTextAreaElement ? lastTemplateField : fallback;
                    if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLTextAreaElement)) {
                        return;
                    }
                    const start = field.selectionStart ?? field.value.length;
                    const end = field.selectionEnd ?? start;
                    field.setRangeText(String(button.dataset.templateToken || ""), start, end, "end");
                    field.focus();
                });
            });
            templateForm.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (!templateForm.reportValidity()) {
                    return;
                }
                const button = templateForm.querySelector("button[type='submit']");
                if (button instanceof HTMLButtonElement) {
                    button.disabled = true;
                    button.setAttribute("aria-busy", "true");
                }
                clearFormFeedback(templateFeedback);
                try {
                    const data = new FormData(templateForm);
                    await request("PATCH", {
                        action: "template",
                        event: String(data.get("event") || ""),
                        title: String(data.get("title") || ""),
                        body: String(data.get("body") || "")
                    });
                    showPageFeedback("admin.notifications.templateSuccess", "success");
                    await loadState();
                } catch (error) {
                    showFormFeedback(templateFeedback, error instanceof Error ? error.message : "admin.notifications.errorTemplate");
                } finally {
                    if (button instanceof HTMLButtonElement) {
                        button.disabled = false;
                        button.removeAttribute("aria-busy");
                    }
                }
            });
        }

        loadState().catch((error) => {
            showPageFeedback(error instanceof Error ? error.message : "admin.notifications.errorDatabase", "error");
        });
    }

    function bindOncallManagement() {
        const list = document.querySelector("[data-oncall-list]");
        const count = document.querySelector("[data-oncall-count]");
        const dialog = document.querySelector("[data-oncall-dialog]");
        const form = document.querySelector("[data-oncall-form]");
        const title = document.querySelector("[data-oncall-dialog-title]");
        const submitLabel = document.querySelector("[data-oncall-submit-label]");
        const feedback = document.querySelector("[data-oncall-feedback]");
        const members = document.querySelector("[data-oncall-members]");
        const template = document.querySelector("[data-oncall-member-template]");
        const deleteDialog = document.querySelector("[data-oncall-delete-dialog]");
        const deleteTarget = document.querySelector("[data-oncall-delete-target]");
        const deleteFeedback = document.querySelector("[data-oncall-delete-feedback]");
        const deleteConfirm = document.querySelector("[data-oncall-delete-confirm]");
        if (!(list instanceof HTMLElement) || !(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || !(members instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return;
        }
        const translate = (key) => window.InsightI18n?.t(key) || key;
        let schedules = [];

        function setContent(node, key) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.dataset.i18n = key;
            node.textContent = translate(key);
        }

        function showFeedback(node, key, status = "error") {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.dataset.status = status;
            node.textContent = key ? translate(key) : "";
            node.hidden = !key;
        }

        async function request(method = "GET", payload = null) {
            const options = {method, credentials: "same-origin", headers: {"Accept": "application/json"}};
            if (method !== "GET") {
                options.headers["Content-Type"] = "application/json";
                options.headers["X-CSRF-Token"] = String(window.INSIGHT_CONFIG?.csrfToken || "");
                options.body = JSON.stringify(payload || {});
            }
            const response = await fetch("/admin/api/oncall.php", options);
            const result = await response.json().catch(() => ({ok: false, error: "admin.oncall.errorGeneric"}));
            if (!response.ok || !result.ok) {
                throw new Error(String(result.error || "admin.oncall.errorGeneric"));
            }
            return result;
        }

        function actionButton(icon, key, action, destructive = false) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = `admin-icon-button${destructive ? " is-destructive" : ""}`;
            button.dataset.oncallAction = action;
            button.dataset.i18nAriaLabel = key;
            button.dataset.i18nTitle = key;
            button.setAttribute("aria-label", translate(key));
            button.title = translate(key);
            const glyph = document.createElement("i");
            glyph.className = icon;
            glyph.setAttribute("aria-hidden", "true");
            button.append(glyph);
            return button;
        }

        function render() {
            list.replaceChildren();
            if (count instanceof HTMLElement) {
                count.textContent = String(schedules.length);
            }
            if (schedules.length === 0) {
                const empty = document.createElement("div");
                empty.className = "admin-empty";
                const icon = document.createElement("i");
                icon.className = "fa-solid fa-user-clock";
                icon.setAttribute("aria-hidden", "true");
                const text = document.createElement("span");
                setContent(text, "admin.oncall.empty");
                empty.append(icon, text);
                list.append(empty);
                return;
            }
            schedules.forEach((schedule) => {
                const row = document.createElement("article");
                row.className = "admin-oncall-row";
                row.dataset.oncallId = String(schedule.id || 0);
                const icon = document.createElement("span");
                icon.className = "admin-notification-icon";
                icon.innerHTML = '<i class="fa-solid fa-user-clock" aria-hidden="true"></i>';
                const copy = document.createElement("div");
                copy.className = "admin-notification-copy";
                const name = document.createElement("strong");
                name.textContent = String(schedule.name || "");
                const meta = document.createElement("span");
                meta.textContent = `${Array.isArray(schedule.members) ? schedule.members.length : 0} ${translate("admin.oncall.membersShort")} · ${Number(schedule.escalation_delay_minutes || 0)} min · ${String(schedule.timezone || "UTC")}`;
                copy.append(name, meta);
                const badge = document.createElement("span");
                badge.className = "admin-status-badge";
                badge.dataset.status = schedule.enabled ? "operational" : "unknown";
                const dot = document.createElement("span");
                dot.setAttribute("aria-hidden", "true");
                const badgeText = document.createElement("span");
                setContent(badgeText, schedule.enabled ? "admin.oncall.active" : "admin.oncall.inactive");
                badge.append(dot, badgeText);
                const actions = document.createElement("div");
                actions.className = "admin-row-actions";
                actions.append(
                    actionButton("fa-solid fa-pen", "admin.oncall.edit", "edit"),
                    actionButton("fa-regular fa-trash-can", "admin.oncall.delete", "delete", true)
                );
                row.append(icon, copy, badge, actions);
                list.append(row);
            });
            window.InsightI18n?.apply(list);
        }

        function dateInput(value) {
            return String(value || "").replace(" ", "T").slice(0, 16);
        }

        function defaultWindow() {
            const start = new Date();
            start.setSeconds(0, 0);
            const end = new Date(start.getTime() + 7 * 86400000);
            const local = (value) => new Date(value.getTime() - value.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            return [local(start), local(end)];
        }

        function addMember(data = {}) {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector("[data-oncall-member]");
            if (!(row instanceof HTMLElement)) {
                return;
            }
            row.dataset.oncallMemberId = String(Number(data.id || 0));
            const [defaultStart, defaultEnd] = defaultWindow();
            const name = row.querySelector("[data-oncall-member-name]");
            const channel = row.querySelector("[data-oncall-member-channel]");
            const starts = row.querySelector("[data-oncall-member-start]");
            const ends = row.querySelector("[data-oncall-member-end]");
            const recurrence = row.querySelector("[data-oncall-member-recurrence]");
            if (name instanceof HTMLInputElement) name.value = String(data.name || "");
            if (channel instanceof HTMLSelectElement && data.channel_id) channel.value = String(data.channel_id);
            if (starts instanceof HTMLInputElement) starts.value = dateInput(data.starts_at) || defaultStart;
            if (ends instanceof HTMLInputElement) ends.value = dateInput(data.ends_at) || defaultEnd;
            if (recurrence instanceof HTMLSelectElement) recurrence.value = String(data.recurrence || "weekly");
            members.append(row);
            window.InsightI18n?.apply(row);
        }

        function setField(name, value) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
                if (field.type === "checkbox" && field instanceof HTMLInputElement) {
                    field.checked = Boolean(value);
                } else {
                    field.value = String(value ?? "");
                    field.dispatchEvent(new Event("input", {bubbles: true}));
                }
            }
        }

        function openCreate() {
            form.reset();
            delete form.dataset.oncallId;
            members.replaceChildren();
            setField("timezone", String(window.INSIGHT_CONFIG?.timezone || "Europe/Paris"));
            setField("escalation_delay_minutes", 5);
            setField("repeat_interval_minutes", 15);
            setField("maximum_repeats", 3);
            setField("minimum_severity", "major");
            setField("enabled", true);
            addMember();
            setContent(title, "admin.oncall.createTitle");
            setContent(submitLabel, "admin.oncall.create");
            showFeedback(feedback, "");
            dialog.showModal();
        }

        function openEdit(schedule) {
            form.reset();
            form.dataset.oncallId = String(schedule.id || 0);
            members.replaceChildren();
            ["name", "timezone", "escalation_delay_minutes", "repeat_interval_minutes", "maximum_repeats", "minimum_severity", "enabled"].forEach((name) => setField(name, schedule[name]));
            const selectedSites = new Set((Array.isArray(schedule.site_ids) ? schedule.site_ids : []).map(Number));
            form.querySelectorAll("input[name='site_ids']").forEach((input) => {
                if (input instanceof HTMLInputElement) input.checked = selectedSites.has(Number(input.value));
            });
            (Array.isArray(schedule.members) ? schedule.members : []).forEach(addMember);
            if (members.children.length === 0) addMember();
            setContent(title, "admin.oncall.editTitle");
            setContent(submitLabel, "admin.oncall.save");
            showFeedback(feedback, "");
            dialog.showModal();
        }

        function payload() {
            const data = new FormData(form);
            return {
                id: Number(form.dataset.oncallId || 0),
                name: String(data.get("name") || ""),
                timezone: String(data.get("timezone") || "UTC"),
                escalation_delay_minutes: Number(data.get("escalation_delay_minutes") || 0),
                repeat_interval_minutes: Number(data.get("repeat_interval_minutes") || 15),
                maximum_repeats: Number(data.get("maximum_repeats") || 3),
                minimum_severity: String(data.get("minimum_severity") || "major"),
                enabled: Boolean(form.elements.namedItem("enabled")?.checked),
                site_ids: Array.from(form.querySelectorAll("input[name='site_ids']:checked")).map((input) => Number(input.value)),
                members: Array.from(members.querySelectorAll("[data-oncall-member]")).map((row) => ({
                    id: Number(row.dataset.oncallMemberId || 0),
                    name: String(row.querySelector("[data-oncall-member-name]")?.value || ""),
                    channel_id: Number(row.querySelector("[data-oncall-member-channel]")?.value || 0),
                    starts_at: String(row.querySelector("[data-oncall-member-start]")?.value || ""),
                    ends_at: String(row.querySelector("[data-oncall-member-end]")?.value || ""),
                    recurrence: String(row.querySelector("[data-oncall-member-recurrence]")?.value || "weekly")
                }))
            };
        }

        async function load() {
            const result = await request();
            schedules = Array.isArray(result.schedules) ? result.schedules : [];
            render();
        }

        document.querySelector("[data-oncall-create]")?.addEventListener("click", openCreate);
        document.querySelector("[data-oncall-add-member]")?.addEventListener("click", () => addMember());
        document.querySelectorAll("[data-oncall-close]").forEach((button) => button.addEventListener("click", () => dialog.close()));
        members.addEventListener("click", (event) => {
            const button = event.target instanceof Element ? event.target.closest("[data-oncall-remove-member]") : null;
            button?.closest("[data-oncall-member]")?.remove();
        });
        list.addEventListener("click", (event) => {
            const button = event.target instanceof Element ? event.target.closest("[data-oncall-action], [data-oncall-edit], [data-oncall-delete]") : null;
            const row = button?.closest("[data-oncall-id]");
            if (!(button instanceof HTMLButtonElement) || !(row instanceof HTMLElement)) return;
            const schedule = schedules.find((item) => Number(item.id) === Number(row.dataset.oncallId || 0));
            if (!schedule) return;
            const action = button.dataset.oncallAction || (button.hasAttribute("data-oncall-edit") ? "edit" : "delete");
            if (action === "edit") {
                openEdit(schedule);
            } else if (deleteDialog instanceof HTMLDialogElement) {
                deleteDialog.dataset.oncallId = String(schedule.id || 0);
                if (deleteTarget instanceof HTMLElement) deleteTarget.textContent = String(schedule.name || "");
                showFeedback(deleteFeedback, "");
                deleteDialog.showModal();
            }
        });
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const submit = form.querySelector("[data-oncall-submit]");
            if (submit instanceof HTMLButtonElement) submit.disabled = true;
            showFeedback(feedback, "");
            try {
                const body = payload();
                await request(body.id > 0 ? "PATCH" : "POST", body);
                dialog.close();
                await load();
            } catch (error) {
                showFeedback(feedback, error instanceof Error ? error.message : "admin.oncall.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) submit.disabled = false;
            }
        });
        if (deleteDialog instanceof HTMLDialogElement) {
            document.querySelectorAll("[data-oncall-delete-close]").forEach((button) => button.addEventListener("click", () => deleteDialog.close()));
        }
        if (deleteConfirm instanceof HTMLButtonElement && deleteDialog instanceof HTMLDialogElement) {
            deleteConfirm.addEventListener("click", async () => {
                deleteConfirm.disabled = true;
                showFeedback(deleteFeedback, "");
                try {
                    await request("DELETE", {id: Number(deleteDialog.dataset.oncallId || 0)});
                    deleteDialog.close();
                    await load();
                } catch (error) {
                    showFeedback(deleteFeedback, error instanceof Error ? error.message : "admin.oncall.errorGeneric");
                } finally {
                    deleteConfirm.disabled = false;
                }
            });
        }
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) dialog.close();
        });
        load().catch(() => {});
    }

    function bindAccessManagement() {
        const section = document.querySelector("[data-admin-view='account']");
        const feedback = document.querySelector("[data-access-feedback]");
        const tokenList = document.querySelector("[data-access-token-list]");
        const clientList = document.querySelector("[data-access-client-list]");
        const tokenDialog = document.querySelector("[data-access-token-dialog]");
        const clientDialog = document.querySelector("[data-access-client-dialog]");
        const secretDialog = document.querySelector("[data-access-secret-dialog]");
        const tokenForm = document.querySelector("[data-access-token-form]");
        const clientForm = document.querySelector("[data-access-client-form]");
        const tokenFeedback = document.querySelector("[data-access-token-feedback]");
        const clientFeedback = document.querySelector("[data-access-client-feedback]");
        const secretValues = document.querySelector("[data-access-secret-values]");
        if (!(section instanceof HTMLElement) || !(tokenList instanceof HTMLElement) || !(clientList instanceof HTMLElement)) {
            return;
        }

        const translate = (key) => window.InsightI18n?.t(key) || key;
        let state = {tokens: [], oauth_clients: []};
        let feedbackTimer = 0;

        function setFeedback(key, status = "success") {
            if (!(feedback instanceof HTMLElement)) {
                return;
            }
            window.clearTimeout(feedbackTimer);
            feedback.textContent = translate(key);
            feedback.dataset.status = status;
            feedback.hidden = false;
            feedbackTimer = window.setTimeout(() => {
                feedback.hidden = true;
            }, 5000);
        }

        function setFormFeedback(node, key) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.textContent = translate(key);
            node.hidden = false;
        }

        function clearFormFeedback(node) {
            if (node instanceof HTMLElement) {
                node.hidden = true;
                node.textContent = "";
            }
        }

        async function request(method = "GET", payload = null) {
            const options = {method, credentials: "same-origin", headers: {Accept: "application/json"}};
            if (payload !== null) {
                options.headers["Content-Type"] = "application/json";
                options.headers["X-CSRF-Token"] = String(window.INSIGHT_CONFIG?.csrfToken || "");
                options.body = JSON.stringify(payload);
            }
            const response = await fetch("/admin/api/access.php", options);
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) {
                throw new Error(String(result.error || "admin.access.errorGeneric"));
            }
            return result;
        }

        function unixDate(value) {
            const timestamp = Number(value || 0);
            if (!timestamp) {
                return "";
            }
            return new Intl.DateTimeFormat(window.InsightI18n?.getIntlLocale() || "fr-FR", {
                dateStyle: "medium",
                timeStyle: "short",
                timeZone: String(window.INSIGHT_CONFIG?.timezone || "Europe/Paris")
            }).format(new Date(timestamp * 1000));
        }

        async function copyValue(value, button) {
            try {
                await navigator.clipboard.writeText(String(value));
                const icon = button?.querySelector("i");
                if (icon instanceof HTMLElement) {
                    const previous = icon.className;
                    icon.className = "fa-solid fa-check";
                    window.setTimeout(() => {
                        icon.className = previous;
                    }, 1200);
                }
            } catch (error) {
                setFeedback("admin.access.copyFailed", "error");
            }
        }

        function iconButton(iconName, label, action, id) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "admin-icon-button is-destructive";
            button.dataset.accessAction = action;
            button.dataset.accessId = String(id);
            button.setAttribute("aria-label", label);
            button.setAttribute("title", label);
            const icon = document.createElement("i");
            icon.className = iconName;
            icon.setAttribute("aria-hidden", "true");
            button.append(icon);
            return button;
        }

        function appendScopes(container, scopes) {
            const meta = document.createElement("div");
            meta.className = "admin-access-item-meta";
            (Array.isArray(scopes) ? scopes : []).forEach((scope) => {
                const code = document.createElement("code");
                code.textContent = String(scope);
                meta.append(code);
            });
            container.append(meta);
        }

        function renderTokens() {
            tokenList.replaceChildren();
            const tokens = Array.isArray(state.tokens) ? state.tokens : [];
            if (tokens.length === 0) {
                const empty = document.createElement("div");
                empty.className = "admin-access-empty";
                empty.innerHTML = '<i class="fa-solid fa-key" aria-hidden="true"></i>';
                const text = document.createElement("span");
                text.textContent = translate("admin.access.noTokens");
                empty.append(text);
                tokenList.append(empty);
                return;
            }
            tokens.forEach((token) => {
                const row = document.createElement("div");
                row.className = "admin-access-item";
                const copy = document.createElement("div");
                copy.className = "admin-access-item-copy";
                const name = document.createElement("strong");
                name.textContent = String(token.name || "");
                const prefix = document.createElement("span");
                const expired = Number(token.expires_at || 0) > 0 && Number(token.expires_at) <= Math.floor(Date.now() / 1000);
                const unavailable = Boolean(token.revoked_at) || expired;
                const expiry = token.expires_at ? `${translate("admin.access.expires")} ${unixDate(token.expires_at)}` : translate("admin.access.noExpiry");
                prefix.textContent = `${String(token.prefix || "")} · ${unavailable ? translate("admin.access.revoked") : expiry}`;
                copy.append(name, prefix);
                appendScopes(copy, token.scopes);
                row.append(copy);
                if (!token.revoked_at) {
                    row.append(iconButton("fa-regular fa-trash-can", translate("admin.access.revokeToken"), "revoke-token", token.id));
                }
                tokenList.append(row);
            });
        }

        function renderClients() {
            clientList.replaceChildren();
            const clients = Array.isArray(state.oauth_clients) ? state.oauth_clients : [];
            if (clients.length === 0) {
                const empty = document.createElement("div");
                empty.className = "admin-access-empty";
                empty.innerHTML = '<i class="fa-solid fa-link" aria-hidden="true"></i>';
                const text = document.createElement("span");
                text.textContent = translate("admin.access.noClients");
                empty.append(text);
                clientList.append(empty);
                return;
            }
            clients.forEach((client) => {
                const row = document.createElement("div");
                row.className = "admin-access-item";
                const copy = document.createElement("div");
                copy.className = "admin-access-item-copy";
                const name = document.createElement("strong");
                name.textContent = String(client.name || "");
                const clientId = document.createElement("span");
                clientId.textContent = String(client.client_id || "");
                copy.append(name, clientId);
                appendScopes(copy, client.scopes);
                row.append(copy, iconButton("fa-regular fa-trash-can", translate("admin.access.deleteClient"), "delete-client", client.id));
                clientList.append(row);
            });
        }

        function applyState(nextState) {
            state = nextState && typeof nextState === "object" ? nextState : state;
            const apiToggle = document.querySelector("[data-access-toggle='api']");
            const oauthToggle = document.querySelector("[data-access-toggle='oauth']");
            if (apiToggle instanceof HTMLInputElement) {
                apiToggle.checked = Boolean(state.api_enabled);
            }
            if (oauthToggle instanceof HTMLInputElement) {
                oauthToggle.checked = Boolean(state.oauth_provider_enabled);
                oauthToggle.disabled = !Boolean(state.issuer_ready);
            }
            [["api", Boolean(state.api_enabled)], ["oauth", Boolean(state.oauth_provider_enabled)]].forEach(([feature, enabled]) => {
                const badge = document.querySelector(`[data-access-feature-status='${feature}']`);
                const label = badge?.querySelector("[data-access-feature-label]");
                if (!(badge instanceof HTMLElement) || !(label instanceof HTMLElement)) {
                    return;
                }
                const key = enabled ? "admin.access.active" : "admin.access.inactive";
                badge.dataset.status = enabled ? "operational" : "unknown";
                label.dataset.i18n = key;
                label.textContent = translate(key);
            });
            renderTokens();
            renderClients();
        }

        function showSecrets(values) {
            if (!(secretDialog instanceof HTMLDialogElement) || !(secretValues instanceof HTMLElement)) {
                return;
            }
            secretValues.replaceChildren();
            values.forEach(([label, value]) => {
                const row = document.createElement("div");
                row.className = "admin-access-secret-value";
                const title = document.createElement("span");
                title.textContent = label;
                const code = document.createElement("code");
                code.textContent = String(value);
                const button = document.createElement("button");
                button.type = "button";
                button.className = "admin-icon-button";
                button.setAttribute("aria-label", translate("admin.access.copy"));
                button.setAttribute("title", translate("admin.access.copy"));
                button.innerHTML = '<i class="fa-regular fa-copy" aria-hidden="true"></i>';
                button.addEventListener("click", () => copyValue(value, button));
                row.append(title, code, button);
                secretValues.append(row);
            });
            secretDialog.showModal();
        }

        section.addEventListener("click", async (event) => {
            const copyButton = event.target instanceof Element ? event.target.closest("[data-copy-value]") : null;
            if (copyButton instanceof HTMLButtonElement) {
                await copyValue(copyButton.dataset.copyValue || "", copyButton);
                return;
            }
            const actionButton = event.target instanceof Element ? event.target.closest("[data-access-action]") : null;
            if (!(actionButton instanceof HTMLButtonElement)) {
                return;
            }
            const action = String(actionButton.dataset.accessAction || "");
            const confirmation = action === "revoke-token" ? "admin.access.confirmRevoke" : "admin.access.confirmDeleteClient";
            if (!window.confirm(translate(confirmation))) {
                return;
            }
            actionButton.disabled = true;
            try {
                const result = await request("POST", {
                    action: action === "revoke-token" ? "revoke_token" : "delete_oauth_client",
                    id: Number(actionButton.dataset.accessId || 0)
                });
                applyState(result.state);
                setFeedback(action === "revoke-token" ? "admin.access.tokenRevoked" : "admin.access.clientDeleted");
            } catch (error) {
                setFeedback(error instanceof Error ? error.message : "admin.access.errorGeneric", "error");
            } finally {
                actionButton.disabled = false;
            }
        });

        document.querySelectorAll("[data-copy-value]").forEach((button) => {
            if (!section.contains(button)) {
                button.addEventListener("click", () => copyValue(button.dataset.copyValue || "", button));
            }
        });

        document.querySelectorAll("[data-access-toggle]").forEach((toggle) => {
            toggle.addEventListener("change", async () => {
                if (!(toggle instanceof HTMLInputElement)) {
                    return;
                }
                toggle.disabled = true;
                try {
                    const result = await request("POST", {
                        action: toggle.dataset.accessToggle === "api" ? "set_api_enabled" : "set_oauth_provider_enabled",
                        enabled: toggle.checked
                    });
                    applyState(result.state);
                    setFeedback(toggle.checked ? "admin.access.featureEnabled" : "admin.access.featureDisabled");
                } catch (error) {
                    toggle.checked = !toggle.checked;
                    setFeedback(error instanceof Error ? error.message : "admin.access.errorGeneric", "error");
                } finally {
                    toggle.disabled = toggle.dataset.accessToggle === "oauth" && !Boolean(state.issuer_ready);
                }
            });
        });

        document.querySelector("[data-access-create-token]")?.addEventListener("click", () => {
            if (tokenDialog instanceof HTMLDialogElement && tokenForm instanceof HTMLFormElement) {
                tokenForm.reset();
                tokenForm.querySelectorAll("input[name='scopes']").forEach((input) => {
                    input.checked = ["status:read", "monitors:read", "incidents:read"].includes(input.value);
                });
                clearFormFeedback(tokenFeedback);
                tokenDialog.showModal();
                tokenForm.elements.namedItem("name")?.focus();
            }
        });

        document.querySelector("[data-access-create-client]")?.addEventListener("click", () => {
            if (clientDialog instanceof HTMLDialogElement && clientForm instanceof HTMLFormElement) {
                clientForm.reset();
                clientForm.querySelectorAll("input[name='scopes']").forEach((input) => {
                    input.checked = ["openid", "profile"].includes(input.value);
                });
                clearFormFeedback(clientFeedback);
                clientDialog.showModal();
                clientForm.elements.namedItem("name")?.focus();
            }
        });

        document.querySelectorAll("[data-access-dialog-close]").forEach((button) => {
            button.addEventListener("click", () => button.closest("dialog")?.close());
        });
        document.querySelectorAll("[data-access-secret-close]").forEach((button) => {
            button.addEventListener("click", () => secretDialog?.close());
        });
        secretDialog?.addEventListener("close", () => {
            secretValues?.replaceChildren();
        });
        [tokenDialog, clientDialog, secretDialog].forEach((dialog) => {
            dialog?.addEventListener("click", (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        });

        tokenForm?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!(tokenForm instanceof HTMLFormElement) || !tokenForm.reportValidity()) {
                return;
            }
            const submit = tokenForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            clearFormFeedback(tokenFeedback);
            try {
                const data = new FormData(tokenForm);
                const result = await request("POST", {
                    action: "create_token",
                    name: String(data.get("name") || ""),
                    expires_in_days: Number(data.get("expires_in_days") || 90),
                    scopes: Array.from(tokenForm.querySelectorAll("input[name='scopes']:checked")).map((input) => input.value)
                });
                tokenDialog?.close();
                applyState(result.state);
                showSecrets([[translate("admin.access.token"), result.token]]);
            } catch (error) {
                setFormFeedback(tokenFeedback, error instanceof Error ? error.message : "admin.access.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        clientForm?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!(clientForm instanceof HTMLFormElement) || !clientForm.reportValidity()) {
                return;
            }
            const submit = clientForm.querySelector("button[type='submit']");
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            clearFormFeedback(clientFeedback);
            try {
                const data = new FormData(clientForm);
                const scopes = Array.from(clientForm.querySelectorAll("input[name='scopes']:checked")).map((input) => input.value);
                if (!scopes.includes("openid")) {
                    scopes.unshift("openid");
                }
                const result = await request("POST", {
                    action: "create_oauth_client",
                    name: String(data.get("name") || ""),
                    redirect_uris: String(data.get("redirect_uris") || ""),
                    scopes
                });
                clientDialog?.close();
                applyState(result.state);
                showSecrets([
                    [translate("admin.access.clientId"), result.client_id],
                    [translate("admin.access.clientSecret"), result.client_secret]
                ]);
            } catch (error) {
                setFormFeedback(clientFeedback, error instanceof Error ? error.message : "admin.access.errorGeneric");
            } finally {
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });

        request().then((result) => applyState(result)).catch((error) => {
            setFeedback(error instanceof Error ? error.message : "admin.access.errorGeneric", "error");
        });
        window.addEventListener("insight:locale-changed", () => {
            renderTokens();
            renderClients();
        });
    }

    bindDashboardRoutes();
    bindWorkflowDelete();
    bindProbeCreation();
    bindIncidentManagement();
    bindMaintenanceManagement();
    bindStatusPageManagement();
    bindNodeManagement();
    bindSecurityManagement();
    bindNotificationManagement();
    bindOncallManagement();
    bindAccessManagement();
    bindPasswordToggles();
    if (window.InsightI18n?.ready) {
        window.InsightI18n.ready.then(applyTranslations);
    } else {
        applyTranslations();
    }
    window.addEventListener("insight:locale-changed", applyTranslations);
})();
