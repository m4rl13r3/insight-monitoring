document.addEventListener("DOMContentLoaded", () => {
    const section = document.getElementById("status-subscriptions");
    const form = document.querySelector("[data-subscription-form]");
    const feedback = document.querySelector("[data-subscription-feedback]");
    if (!(section instanceof HTMLElement) || !(form instanceof HTMLFormElement) || !(feedback instanceof HTMLElement)) {
        return;
    }
    if (window.INSIGHT_CONFIG?.subscriptionsEnabled !== true) {
        section.hidden = true;
        return;
    }
    const scope = form.querySelector("[data-subscription-scope]");
    const scopeTrigger = form.querySelector("[data-subscription-scope-trigger]");
    const scopePanel = form.querySelector("[data-subscription-scope-panel]");
    const scopeLabel = form.querySelector("[data-subscription-scope-label]");
    const layout = window.INSIGHT_CONFIG?.statusPageLayout || {};
    const configuredSites = [
        ...(Array.isArray(layout.ungrouped_sites) ? layout.ungrouped_sites : []),
        ...(Array.isArray(layout.groups) ? layout.groups.flatMap((group) => Array.isArray(group.sites) ? group.sites : []) : [])
    ];
    const sites = Array.from(new Map(configuredSites.filter((site) => Number(site?.id) > 0).map((site) => [Number(site.id), site])).values());
    function updateScopeLabel() {
        if (!(scopeLabel instanceof HTMLElement) || !(scopePanel instanceof HTMLElement)) {
            return;
        }
        const selected = scopePanel.querySelectorAll("input:checked").length;
        scopeLabel.textContent = selected > 0 ? insightT("subscriptions.selectedServices", {count: selected}) : insightT("subscriptions.allServices");
    }
    if (sites.length > 1 && scope instanceof HTMLElement && scopePanel instanceof HTMLElement && scopeTrigger instanceof HTMLButtonElement) {
        sites.forEach((site) => {
            const label = document.createElement("label");
            const input = document.createElement("input");
            const text = document.createElement("span");
            input.type = "checkbox";
            input.value = String(site.id);
            text.textContent = String(site.label || site.url || site.id);
            input.addEventListener("change", updateScopeLabel);
            label.append(input, text);
            scopePanel.append(label);
        });
        scope.hidden = false;
        scopeTrigger.addEventListener("click", () => {
            const open = scopeTrigger.getAttribute("aria-expanded") !== "true";
            scopeTrigger.setAttribute("aria-expanded", open ? "true" : "false");
            scopePanel.hidden = !open;
        });
        document.addEventListener("pointerdown", (event) => {
            if (!scope.contains(event.target)) {
                scopeTrigger.setAttribute("aria-expanded", "false");
                scopePanel.hidden = true;
            }
        });
    }
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = form.querySelector("button[type='submit']");
        const email = String(new FormData(form).get("email") || "").trim();
        if (!email) {
            return;
        }
        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
        }
        feedback.dataset.state = "pending";
        feedback.textContent = insightT("subscriptions.pending");
        try {
            const response = await fetch("/api/subscribers.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    email,
                    page: String(window.INSIGHT_CONFIG?.statusPageSlug || "default"),
                    locale: String(document.documentElement.lang || "en"),
                    site_ids: scopePanel instanceof HTMLElement ? Array.from(scopePanel.querySelectorAll("input:checked")).map((input) => Number(input.value)) : []
                })
            });
            const payload = await response.json();
            if (!response.ok || payload.ok !== true) {
                throw new Error(String(payload.error || "subscription_failed"));
            }
            feedback.dataset.state = "success";
            feedback.textContent = insightT(payload.status === "confirmed" ? "subscriptions.confirmed" : "subscriptions.sent");
            form.reset();
        } catch (_error) {
            feedback.dataset.state = "error";
            feedback.textContent = insightT("subscriptions.error");
        } finally {
            if (button instanceof HTMLButtonElement) {
                button.disabled = false;
            }
        }
    });
});
