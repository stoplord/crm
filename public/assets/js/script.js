    document.addEventListener('contextmenu', function (e) {
    e.preventDefault();
    });

    document.addEventListener('keydown', function (e) {

    if (e.key === "F12") {
        e.preventDefault();
    }


    if (e.ctrlKey && e.key.toLowerCase() === "u") {
        e.preventDefault();
    }

    if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) {
        e.preventDefault();
    }


    if (e.ctrlKey && e.key.toLowerCase() === "s") {
        e.preventDefault();
    }
    });
    