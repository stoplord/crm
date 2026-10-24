document.addEventListener("DOMContentLoaded", function() {
    let isActive = true;
    let idleTime = 0;
    let totalSeconds = 0; // Synced from backend
    
    // Format seconds to HH:MM:SS
    function formatTime(seconds) {
        const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
        const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        return `${h}:${m}:${s}`;
    }

    // Update UI Timer every second
    setInterval(() => {
        if (isActive) {
            totalSeconds++;
            document.getElementById('working-time-display').innerText = `Working Time: ${formatTime(totalSeconds)}`;
        }
    }, 1000);

    // Reset idle timer on user interaction
    const resetIdle = () => { isActive = true; idleTime = 0; };
    window.addEventListener('mousemove', resetIdle);
    window.addEventListener('keydown', resetIdle);
    window.addEventListener('click', resetIdle);
    window.addEventListener('scroll', resetIdle);

    // Heartbeat & Inactivity Check (Every 30 seconds)
    setInterval(() => {
        idleTime += 30;
        
        if (idleTime === 240) { // 4 minutes
            // Trigger Bootstrap Modal Warning
            new bootstrap.Modal(document.getElementById('inactivityWarningModal')).show();
        }

        if (idleTime >= 300) { // 5 minutes
            isActive = false;
            window.location.href = '/public/logout.php?reason=inactivity';
            return;
        }

        if (isActive) {
            fetch('/api/heartbeat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'heartbeat' })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'logged_out') {
                    window.location.href = '/public/login.php?msg=timeout';
                } else if (data.status === 'active') {
                    totalSeconds = data.total_seconds; // Sync authoritative server time
                }
            });
        }
    }, 30000);
});