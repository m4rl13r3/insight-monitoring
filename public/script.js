(function () {
    const ts = Date.now();
    const files = [
        "js/status/core.js",
        "js/status/api.js",
        "js/status/status.js",
        "js/status/render.js",
        "js/status/bootstrap.js"
    ];

    let cursor = 0;
    function loadNext() {
        if (cursor >= files.length) {
            return;
        }
        const script = document.createElement("script");
        script.src = `${files[cursor]}?ts=${ts}`;
        script.async = false;
        script.onload = () => {
            cursor += 1;
            loadNext();
        };
        script.onerror = () => {
            console.error(`Impossible de charger ${files[cursor]}`);
        };
        document.head.appendChild(script);
    }

    loadNext();
})();
