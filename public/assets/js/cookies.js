window.addEventListener("load", async () => {

    const KEEP_LOCALSTORAGE = [
        "token",
        "user",
        "auth",
        "login"
    ];

    const KEEP_COOKIES = [
        "PHPSESSID",
        "remember_token",
        "auth_token"
    ];

    // Backup localStorage
    const backup = {};
    KEEP_LOCALSTORAGE.forEach(key => {
        backup[key] = localStorage.getItem(key);
    });

    // Clear storage
    localStorage.clear();
    sessionStorage.clear();

    // Restore login keys
    Object.keys(backup).forEach(key => {
        if (backup[key] !== null) {
            localStorage.setItem(key, backup[key]);
        }
    });

    // Delete unwanted cookies
    document.cookie.split(";").forEach(cookie => {
        const name = cookie.split("=")[0].trim();

        if (!KEEP_COOKIES.includes(name)) {

            const domains = [
                location.hostname,
                "." + location.hostname
            ];

            const paths = ["/"];

            domains.forEach(domain => {
                paths.forEach(path => {
                    document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=${path};domain=${domain};`;
                });
            });

            document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;`;
        }
    });

    // Clear Cache Storage
    if ("caches" in window) {
        const keys = await caches.keys();
        await Promise.all(keys.map(key => caches.delete(key)));
    }

    // Clear IndexedDB
    if (window.indexedDB && indexedDB.databases) {
        const dbs = await indexedDB.databases();
        dbs.forEach(db => {
            if (db.name) indexedDB.deleteDatabase(db.name);
        });
    }

    console.log("Storage cleaned. Login details preserved.");
});