/**
 * Opens Quick Progress / Status update popup modal.
 */
function openQuickUpdateModal(id, title, status, progress) {
    document.getElementById('quickUpdateTaskId').value = id;
    document.getElementById('quickUpdateTaskTitle').textContent = title;
    document.getElementById('quickUpdateStatus').value = status;
    document.getElementById('quickUpdateProgressRange').value = progress;
    document.getElementById('quickUpdateProgressVal').textContent = progress + '%';

    const modal = new bootstrap.Modal(document.getElementById('quickUpdateModal'));
    modal.show();
}

/**
 * Opens Mobile Task Inspection & Action Modal.
 */
function openTaskModal(id, title, desc, assignee, assigneeRole, creator, prio, prioClass, status, statusClass, progress, dateFmt, timeFmt, dateRaw, timeRaw, isOverdue, assignedToId) {
    document.getElementById('mTaskAvatar').textContent = title.charAt(0).toUpperCase();
    document.getElementById('mTaskTitle').textContent = title;
    document.getElementById('mTaskDesc').textContent = desc && desc.trim() !== '' ? desc : 'No detailed description provided.';
    document.getElementById('mTaskAssignee').textContent = assignee + ' (' + assigneeRole + ')';
    document.getElementById('mTaskCreator').textContent = creator;
    document.getElementById('mTaskProgressNum').textContent = progress + '%';

    const prioBadge = document.getElementById('mTaskPrioBadge');
    prioBadge.textContent = prio;
    prioBadge.className = 'badge px-2.5 py-0.5 mt-1 small ' + prioClass;

    const statusBadge = document.getElementById('mTaskStatusBadge');
    statusBadge.textContent = status;
    statusBadge.className = 'badge px-3 py-1.5 rounded-pill fs-6 ' + statusClass;

    const deadlineEl = document.getElementById('mTaskDeadline');
    deadlineEl.textContent = dateFmt + ' • ' + timeFmt;
    deadlineEl.className = 'fw-bold font-monospace small ' + (isOverdue ? 'text-danger' : 'text-dark');

    const overdueAlert = document.getElementById('mTaskOverdueAlert');
    if (isOverdue) {
        overdueAlert.classList.remove('d-none');
    } else {
        overdueAlert.classList.add('d-none');
    }

    // Bind Quick Update Trigger
    document.getElementById('mTaskQuickUpdateBtn').onclick = function() {
        const dModal = bootstrap.Modal.getInstance(document.getElementById('taskDetailsModal'));
        if (dModal) dModal.hide();
        openQuickUpdateModal(id, title, status, progress);
    };

    const modal = new bootstrap.Modal(document.getElementById('taskDetailsModal'));
    modal.show();
}

/**
 * Super Admin Full Editor Modal
 */
function openSuperAdminEditModal(id, title, desc, assigneeId, prio, status, progress, date, time) {
    document.getElementById('editTaskId').value = id;
    document.getElementById('editTaskTitle').value = title;
    document.getElementById('editTaskDesc').value = desc;
    document.getElementById('editTaskAssignee').value = assigneeId;
    document.getElementById('editTaskPriority').value = prio;
    document.getElementById('editTaskStatus').value = status;
    document.getElementById('editTaskProgress').value = progress;
    document.getElementById('editTaskDeadlineDate').value = date;
    document.getElementById('editTaskDeadlineTime').value = time;

    const modal = new bootstrap.Modal(document.getElementById('superAdminEditModal'));
    modal.show();
}

// Global Security Handlers
document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
document.addEventListener('keydown', function (e) {
    if (e.key === "F12") { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
    if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
});