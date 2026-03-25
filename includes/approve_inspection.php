<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../includes/audit_helper.php';

// Require ADMIN or MAINTENANCE_MANAGER role
requireAnyRole(['ADMIN', 'MAINTENANCE_MANAGER']);

if (!isset($_GET['inspection_id'])) {
    header("Location: ../public/report.php?error=invalid");
    exit;
}

$inspection_id = (int)$_GET['inspection_id'];
$maintenance_manager = getCurrentUser();
$user_id = getCurrentUserId();

// Get current inspection details
$verify_stmt = $conn->prepare("SELECT id, trip_id, inspection_type, overall_status FROM vehicle_inspections WHERE id = ?");
$verify_stmt->bind_param("i", $inspection_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    header("Location: ../public/report.php?error=notfound");
    exit;
}

$inspection = $verify_result->fetch_assoc();
$trip_id = $inspection['trip_id'];

// Update inspection with maintenance_manager (current user) for approval
$stmt = $conn->prepare("UPDATE vehicle_inspections SET maintenance_manager = ? WHERE id = ?");
$stmt->bind_param("si", $maintenance_manager, $inspection_id);

if ($stmt->execute()) {
    // Get inspection type for logging and redirect
    $inspection_type = $inspection['inspection_type'];
    
    // NOTE: Do NOT update trips table here
    // Maintenance manager only approves the inspection
    // Trip status/approval is updated separately by MANAGER role in view_detail.php
    // Workflow:
    // - PRE-approval: Just mark inspection as approved
    // - POST-approval: Just mark inspection as approved
    // - Trip approval: Separate action by MANAGER after POST-approval
    
    // Log audit entry
    logAudit($conn, $user_id, 'UPDATE', 'INSPECTION', $inspection_id, 
            ['maintenance_manager' => $maintenance_manager, 'inspection_type' => $inspection_type], null);
    
    // Redirect based on where the request came from
    $type = ($inspection_type === 'PRE_TRIP') ? 'PRE' : 'POST';
    
    // If called from view_detail.php, redirect back there
    if (!empty($_GET['from']) && $_GET['from'] === 'view_detail') {
        header("Location: ../public/view_detail.php?id=$trip_id&msg=inspection_approved");
    } else {
        // Otherwise redirect to approval_inspection.php (backup interface)
        header("Location: ../public/approval_inspection.php?type=$type&msg=approved");
    }
    exit;
} else {
    // Redirect based on where the request came from
    $type = ($inspection['inspection_type'] === 'PRE_TRIP') ? 'PRE' : 'POST';
    
    if (!empty($_GET['from']) && $_GET['from'] === 'view_detail') {
        header("Location: ../public/view_detail.php?id=$trip_id&error=approval_failed");
    } else {
        header("Location: ../public/approval_inspection.php?type=$type&error=approval_failed");
    }
    exit;
}
?>