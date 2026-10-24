<?php

?>
        <!-- Footer (Moved INSIDE #page-content-wrapper so it stacks correctly) -->
        <footer class="bg-white text-center text-muted py-3 mt-auto border-top">
            <div class="container-fluid">
                <small>stoplord.com &copy; <?= date('Y') ?> - all rights reserved.</small>
            </div>
        </footer>

    </div> <!-- End of #page-content-wrapper -->
</div> <!-- End of #wrapper -->

<!-- Bootstrap 5 JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Global Vanilla JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Mobile Sidebar Toggle Logic
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', function (e) {
                e.preventDefault();
                sidebar.classList.toggle('show-sidebar');
            });
        }

        // 2. Initialize Bootstrap Tooltips globally
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // 3. Initialize Bootstrap Popovers globally
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
        
        // 4. AJAX CSRF Setup wrapper (Helper function for making secure fetch requests)
        window.secureFetch = async function(url, options = {}) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            if (!options.headers) options.headers = {};
            options.headers['X-CSRF-Token'] = csrfToken;
            options.headers['X-Requested-With'] = 'XMLHttpRequest';
            return fetch(url, options);
        };
    });
</script>


 <script>
// /**
//  * Browser Close Protection Script
//  * Prompts the user to log out before closing the browser/tab, 
//  * while ignoring normal internal navigation within the CRM.
//  */
// document.addEventListener('DOMContentLoaded', function() {
//     let isInternalNavigation = false;

//     // Track clicks on internal links
//     document.querySelectorAll('a').forEach(link => {
//         link.addEventListener('click', function(e) {
//             const href = this.getAttribute('href');
//             const target = this.getAttribute('target');
            
//             // Allow internal relative links or absolute links on the same domain
//             if (href && !href.startsWith('#') && target !== '_blank' && (href.startsWith('/') || href.includes(window.location.hostname))) {
//                 isInternalNavigation = true;
//             }
//         });
//     });

//     // Track form submissions so they don't trigger the warning
//     document.querySelectorAll('form').forEach(form => {
//         form.addEventListener('submit', function() {
//             isInternalNavigation = true;
//         });
//     });

//     // Trigger warning ONLY when closing the tab, window, or browser
//     window.addEventListener('beforeunload', function(e) {
//         if (isInternalNavigation) {
//             return; // Skip if navigating internally
//         }

//         // Standard browser confirmation prompt
//         e.preventDefault();
//         e.returnValue = 'Please log out first before closing the browser!';
//         return 'Please log out first before closing the browser!';
//     });
// });
// </script>



<?php 

if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): 
?>
    <script src="/public/assets/js/tracker.js"></script>
<?php endif; ?>


<!-- Sitewide Hardcore Anti-Zoom & Interaction Guards -->
<script>
(function() {
    // 1. Prevent Pinch & Gesture Zoom (Safari / iOS / Chrome Mobile)
    document.addEventListener('gesturestart', function(e) { e.preventDefault(); }, { passive: false });
    document.addEventListener('gesturechange', function(e) { e.preventDefault(); }, { passive: false });
    document.addEventListener('gestureend', function(e) { e.preventDefault(); }, { passive: false });

    // 2. Prevent Double-Tap Zoom
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function(event) {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
            event.preventDefault();
        }
        lastTouchEnd = now;
    }, false);

    // 3. Prevent Multi-finger Touch (Pinch Zoom)
    document.addEventListener('touchstart', function(event) {
        if (event.touches.length > 1) {
            event.preventDefault();
        }
    }, { passive: false });

    // 4. Prevent Desktop Mouse Wheel Zoom (Ctrl + Wheel)
    document.addEventListener('wheel', function(event) {
        if (event.ctrlKey) {
            event.preventDefault();
        }
    }, { passive: false });

    // 5. Prevent Keyboard Zoom Shortcuts (Ctrl +, Ctrl -, Ctrl 0, etc.) and DevTools
    document.addEventListener('keydown', function(e) {
        // Desktop Browser Zoom Combinations
        if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '=' || e.key === '0' || e.key === '_')) {
            e.preventDefault();
        }

        // Security / Inspection Shortcuts
        if (e.key === "F12") { e.preventDefault(); }
        if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
        if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
        if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
    });

    // 6. Disable Right-Click Context Menu Sitewide
    document.addEventListener('contextmenu', function(e) { 
        e.preventDefault(); 
    });
})();
</script>




</body>
</html>