<!-- MODAL: CREATE / ASSIGN NEW TASK -->
<div class="modal fade" id="createTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="/public/tasks/index.php" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_task">

            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                            <i class="bi bi-plus-circle-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0"><?= $isSuperAdmin ? 'Assign New Task' : 'Create Personal Task' ?></h4>
                            <small class="text-muted fw-medium">Specify details, priority & deadline</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="ultra-label">Task Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control ultra-input" required>
                    </div>

                    <?php if ($isSuperAdmin): ?>
                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Assign To (Employee / Developer) <span class="text-danger">*</span></label>
                        <select name="assigned_to" class="form-select ultra-input" required>
                            <option value="">Select Assignee...</option>
                            <?php foreach ($usersList as $u): ?>
                                <option value="<?= $u['id'] ?>">
                                    <?= htmlspecialchars($u['name'], ENT_QUOTES) ?> (<?= ucfirst(str_replace('_', ' ', $u['role'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-12 <?= $isSuperAdmin ? 'col-md-6' : 'col-md-12' ?>">
                        <label class="ultra-label">Priority Level</label>
                        <select name="priority" class="form-select ultra-input">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Urgent">🔥 Urgent Priority</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Deadline Date <span class="text-danger">*</span></label>
                        <input type="date" name="deadline_date" class="form-control ultra-input" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Deadline Time</label>
                        <input type="time" name="deadline_time" class="form-control ultra-input font-monospace" value="18:00">
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Task Description & Requirements</label>
                        <textarea name="description" class="form-control ultra-input" rows="3"></textarea>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 py-3">
                        <i class="bi bi-check2-circle me-1"></i> Save Task
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: QUICK UPDATE PROGRESS & STATUS -->
<div class="modal fade" id="quickUpdateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/public/tasks/index.php" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_task_progress">
            <input type="hidden" name="task_id" id="quickUpdateTaskId">

            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.3rem;">
                            <i class="bi bi-sliders"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0">Update Progress</h4>
                            <small class="text-muted fw-medium">Update current task state & completion %</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="bg-light rounded-4 p-3 mb-4 border">
                    <span class="ultra-label text-muted mb-1">Target Task</span>
                    <h6 id="quickUpdateTaskTitle" class="fw-bold text-primary mb-0"></h6>
                </div>

                <div class="mb-4">
                    <label class="ultra-label">Status Stage</label>
                    <select name="status" id="quickUpdateStatus" class="form-select ultra-input">
                        <option value="Pending">Pending</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Under Review">Under Review</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="ultra-label mb-0">Progress (% Complete)</label>
                        <span id="quickUpdateProgressVal" class="badge bg-primary fs-6 font-monospace">0%</span>
                    </div>
                    <input type="range" class="form-range mt-2" min="0" max="100" step="5" name="progress" id="quickUpdateProgressRange" oninput="document.getElementById('quickUpdateProgressVal').textContent = this.value + '%'">
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 py-3">
                        <i class="bi bi-check-lg me-1"></i> Save Progress
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: MOBILE INSPECTION POPUP -->
<div class="modal fade" id="taskDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mTaskAvatar" class="app-avatar-fallback me-3 shadow-sm"></div>
                        <div>
                            <h5 id="mTaskTitle" class="fw-bold text-dark mb-0"></h5>
                            <span id="mTaskPrioBadge" class="badge px-2.5 py-0.5 mt-1 small"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="metric-stat-card mb-3 text-center p-3">
                    <span class="metric-stat-label">Progress & Status</span>
                    <div class="d-flex align-items-center justify-content-center gap-2 mt-1">
                        <h2 id="mTaskProgressNum" class="fw-bold text-primary mb-0 font-monospace"></h2>
                        <span id="mTaskStatusBadge" class="badge px-3 py-1.5 rounded-pill fs-6"></span>
                    </div>
                    <div id="mTaskOverdueAlert" class="badge bg-danger rounded-pill px-3 py-1 mt-2 d-none">
                        ⚠️ Past Due Deadline
                    </div>
                </div>

                <div class="d-flex flex-column gap-2 mb-3">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-person-circle text-primary me-2"></i> Assignee</span>
                        <span id="mTaskAssignee" class="fw-bold text-dark small"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-calendar-event text-primary me-2"></i> Deadline</span>
                        <span id="mTaskDeadline" class="fw-bold font-monospace small"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-person-check text-primary me-2"></i> Assigned By</span>
                        <span id="mTaskCreator" class="small fw-semibold text-muted"></span>
                    </div>
                </div>

                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Task Deliverables</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div id="mTaskDesc" class="text-dark small fw-medium" style="white-space: pre-wrap; line-height: 1.5;"></div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" id="mTaskQuickUpdateBtn" class="btn btn-ultra-primary w-100 py-3">
                        <i class="bi bi-sliders me-2"></i> Update Progress / Status
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- MODAL: SUPER ADMIN FULL TASK EDITOR -->
<?php if ($isSuperAdmin): ?>
<div class="modal fade" id="superAdminEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="/public/tasks/index.php" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_task">
            <input type="hidden" name="task_id" id="editTaskId">

            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0">Edit Task Assignment</h4>
                            <small class="text-muted fw-medium">Modify scope, reassignment, or deadline dates</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="ultra-label">Task Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="editTaskTitle" class="form-control ultra-input" required>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="ultra-label">Assigned To</label>
                        <select name="assigned_to" id="editTaskAssignee" class="form-select ultra-input" required>
                            <?php foreach ($usersList as $u): ?>
                                <option value="<?= $u['id'] ?>">
                                    <?= htmlspecialchars($u['name'], ENT_QUOTES) ?> (<?= ucfirst(str_replace('_', ' ', $u['role'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-4">
                        <label class="ultra-label">Priority</label>
                        <select name="priority" id="editTaskPriority" class="form-select ultra-input">
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-4">
                        <label class="ultra-label">Status</label>
                        <select name="status" id="editTaskStatus" class="form-select ultra-input">
                            <option value="Pending">Pending</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Under Review">Under Review</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="ultra-label">Progress (%)</label>
                        <input type="number" min="0" max="100" name="progress" id="editTaskProgress" class="form-control ultra-input font-monospace" required>
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="ultra-label">Deadline Date <span class="text-danger">*</span></label>
                        <input type="date" name="deadline_date" id="editTaskDeadlineDate" class="form-control ultra-input" required>
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="ultra-label">Deadline Time</label>
                        <input type="time" name="deadline_time" id="editTaskDeadlineTime" class="form-control ultra-input font-monospace">
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Description</label>
                        <textarea name="description" id="editTaskDesc" class="form-control ultra-input" rows="3"></textarea>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 py-3">
                        <i class="bi bi-save me-1"></i> Update Task
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>