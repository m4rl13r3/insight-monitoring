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

    function bindProbeCreation() {
        const dialog = document.querySelector("[data-probe-dialog]");
        const form = document.querySelector("[data-probe-form]");
        const targetInput = form?.querySelector("[data-probe-target]");
        const targetLabel = document.querySelector("[data-probe-target-label]");
        const targetHint = document.querySelector("[data-probe-target-hint]");
        const targetIcon = document.querySelector("[data-probe-target-icon]");
        const title = document.querySelector("[data-probe-dialog-title]");
        const dialogIcon = document.querySelector("[data-probe-dialog-icon]");
        const typeField = document.querySelector("[data-probe-type-field]");
        const feedback = document.querySelector("[data-probe-feedback]");
        const submitButton = document.querySelector("[data-probe-submit]");
        const submitLabel = document.querySelector("[data-probe-submit-label]");
        const submitIcon = document.querySelector("[data-probe-submit-icon]");
        const intervalSelect = form?.querySelector("select[name='interval_sec']");
        const deleteDialog = document.querySelector("[data-probe-delete-dialog]");
        const deleteTarget = document.querySelector("[data-probe-delete-target]");
        const deleteFeedback = document.querySelector("[data-probe-delete-feedback]");
        const deleteConfirm = document.querySelector("[data-probe-delete-confirm]");
        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || !(targetInput instanceof HTMLInputElement)) {
            return;
        }

        const translate = (key) => window.InsightI18n?.t(key) || key;

        function setTranslatedContent(node, key) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            node.dataset.i18n = key;
            node.textContent = translate(key);
        }

        function selectedProbeType() {
            if (form.dataset.probeMode === "http") {
                return "http";
            }
            const selected = form.querySelector("input[name='probe_type']:checked");
            return selected instanceof HTMLInputElement ? selected.value : "icmp";
        }

        function updateTargetField() {
            const probeType = selectedProbeType();
            const settings = probeType === "http"
                ? {
                    label: "admin.probes.url",
                    hint: "admin.probes.httpHint",
                    placeholder: "admin.probes.httpPlaceholder",
                    icon: "fa-solid fa-link"
                }
                : probeType === "tcp"
                    ? {
                        label: "admin.probes.tcpTarget",
                        hint: "admin.probes.tcpHint",
                        placeholder: "admin.probes.tcpPlaceholder",
                        icon: "fa-solid fa-network-wired"
                    }
                    : {
                        label: "admin.probes.icmpTarget",
                        hint: "admin.probes.icmpHint",
                        placeholder: "admin.probes.icmpPlaceholder",
                        icon: "fa-solid fa-satellite-dish"
                    };
            setTranslatedContent(targetLabel, settings.label);
            setTranslatedContent(targetHint, settings.hint);
            targetInput.dataset.i18nPlaceholder = settings.placeholder;
            targetInput.placeholder = translate(settings.placeholder);
            if (targetIcon instanceof HTMLElement) {
                targetIcon.className = settings.icon;
            }
        }

        function openDialog(mode) {
            form.reset();
            form.dataset.probeMode = mode;
            form.dataset.probeAction = "create";
            delete form.dataset.probeId;
            if (feedback instanceof HTMLElement) {
                feedback.hidden = true;
                feedback.textContent = "";
            }
            if (typeField instanceof HTMLElement) {
                typeField.hidden = mode !== "server";
            }
            const titleKey = mode === "server" ? "admin.probes.serverTitle" : "admin.probes.monitorTitle";
            setTranslatedContent(title, titleKey);
            setTranslatedContent(submitLabel, "admin.probes.submit");
            if (submitIcon instanceof HTMLElement) {
                submitIcon.className = "fa-solid fa-plus";
            }
            if (dialogIcon instanceof HTMLElement) {
                dialogIcon.className = mode === "server" ? "fa-solid fa-server" : "fa-solid fa-heart-pulse";
            }
            updateTargetField();
            dialog.showModal();
            targetInput.focus();
        }

        function openEditDialog(button) {
            const probeType = String(button.dataset.probeType || "http").toLowerCase();
            const mode = probeType === "http" ? "http" : "server";
            form.reset();
            form.dataset.probeMode = mode;
            form.dataset.probeAction = "update";
            form.dataset.probeId = String(button.dataset.probeId || "");
            if (typeField instanceof HTMLElement) {
                typeField.hidden = mode !== "server";
            }
            if (mode === "server") {
                const radio = form.querySelector(`input[name='probe_type'][value='${probeType === "tcp" ? "tcp" : "icmp"}']`);
                if (radio instanceof HTMLInputElement) {
                    radio.checked = true;
                }
            }
            targetInput.value = String(button.dataset.probeTarget || "");
            if (intervalSelect instanceof HTMLSelectElement) {
                const interval = String(button.dataset.probeInterval || "60");
                intervalSelect.value = Array.from(intervalSelect.options).some((option) => option.value === interval) ? interval : "60";
            }
            setTranslatedContent(title, mode === "server" ? "admin.probes.editServerTitle" : "admin.probes.editMonitorTitle");
            setTranslatedContent(submitLabel, "admin.probes.save");
            if (dialogIcon instanceof HTMLElement) {
                dialogIcon.className = mode === "server" ? "fa-solid fa-server" : "fa-solid fa-heart-pulse";
            }
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
        document.querySelectorAll("[data-probe-close]").forEach((button) => {
            button.addEventListener("click", () => dialog.close());
        });
        form.querySelectorAll("input[name='probe_type']").forEach((input) => {
            input.addEventListener("change", updateTargetField);
        });
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
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
                        interval_sec: Number(new FormData(form).get("interval_sec") || 60)
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(String(result.error || "admin.probes.errorGeneric"));
                }
                dialog.close();
                window.location.reload();
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
                meta.textContent = `${String(channel.provider_label || channel.provider || "Apprise")} · ${Array.isArray(channel.events) ? channel.events.length : 0} ${translate("admin.notifications.eventsShort")}`;
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
            const allowed = ["test", "monitor_down", "monitor_up", "incident_open", "incident_resolved"];
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
    bindProbeCreation();
    bindNotificationManagement();
    bindAccessManagement();
    bindPasswordToggles();
    if (window.InsightI18n?.ready) {
        window.InsightI18n.ready.then(applyTranslations);
    } else {
        applyTranslations();
    }
    window.addEventListener("insight:locale-changed", applyTranslations);
})();
