<?php
/**
 * Approve Trip (Final Approval)
 * Updates trips table with manager approval
 * 
 * This is separate from inspection approval:
 * - approve_inspection.php = maintenance manager approves PRE/POST inspections
 * - approve_trip.php = manager approves trip completion
 */

session_start();
require_once 'config.php';
require_once 'auth.php';

// Check authorization
if (!hasAnyRole(['ADMIN', 'MANAGER', 'OPERATOR'])) {
    die('Unauthorized access');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method');
}

$trip_id = isset($_POST['trip_id']) ? intval($_POST['trip_id']) : 0;
$manager_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Unknown';

if (!$trip_id) {
    die('Trip ID is required');
}

try {
    // Verify trip exists
    $verify = $conn->prepare("SELECT id, trip_name FROM trips WHERE id = ?");
    $verify->bind_param("i", $trip_id);
    $verify->execute();
    $trip_result = $verify->get_result();
    
    if ($trip_result->num_rows === 0) {
        die('Trip not found');
    }
    
    $trip = $trip_result->fetch_assoc();
    $verify->close();
    
    // Update trip with manager approval
    $update = $conn->prepare("
        UPDATE trips 
        SET 
            approved_by = ?,
            approved_at = NOW(),
            status = 'COMPLETED'
        WHERE id = ?
    ");
    
    $update->bind_param("si", $manager_name, $trip_id);
    
    if (!$update->execute()) {
        throw new Exception("Database error: " . $update->error);
    }
    
    $update->close();
    
    // Redirect back to trip detail with success message
    $_SESSION['success_msg'] = "✓ Trip '{$trip['trip_name']}' approved successfully!";
    header("Location: ../public/view_detail.php?id=" . $trip_id);
    exit();
    
} catch (Exception $e) {
    $_SESSION['error_msg'] = "Error approving trip: " . htmlspecialchars($e->getMessage());
    header("Location: ../public/view_detail.php?id=" . $trip_id);
    exit();
}
?>