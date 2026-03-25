<?php
require_once '../config/database.php';
require_once '../config/auth.php';

$trip_id = $_GET['id'] ?? die('ID not found');

// Handle messages
$message = '';
$message_type = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'approved') {
        $message = '✅ Inspection approved successfully!';
        $message_type = 'success';
    } elseif ($_GET['msg'] == 'inspection_approved') {
        $message = '✅ Inspection approved successfully!';
        $message_type = 'success';
    } elseif ($_GET['msg'] == 'trip_approved') {
        $message = '✅ Trip approved and marked as COMPLETED!';
        $message_type = 'success';
    }
} elseif (isset($_GET['error'])) {
    if ($_GET['error'] == 'approval_failed') {
        $message = '❌ Failed to approve inspection';
        $message_type = 'danger';
    }
}

// Also check for session messages from approve_trip.php
if (isset($_SESSION['success_msg'])) {
    $message = $_SESSION['success_msg'];
    $message_type = 'success';
    unset($_SESSION['success_msg']);
} elseif (isset($_SESSION['error_msg'])) {
    $message = $_SESSION['error_msg'];
    $message_type = 'danger';
    unset($_SESSION['error_msg']);
}

// Get trip, log, routes, and inspection data
$trip_res = $conn->query("SELECT * FROM trips WHERE id = $trip_id");
$trip_data = $trip_res->fetch_assoc();

if (!$trip_data) die("Trip data not found.");

$log_res = $conn->query("SELECT * FROM car_rental_logs WHERE trip_id = $trip_id LIMIT 1");
$log = $log_res->fetch_assoc();

if (!$log) {
    $log = [
        'car_id' => $trip_data['car_id'],
        'driver_name' => $trip_data['driver_name'],
        'log_date' => $trip_data['start_date'],
        'exit_meter' => 0,
        'return_meter' => 0
    ];
}

$store_log_id = $log ? ($log['id'] ?? null) : null;
$routes = $store_log_id ? $conn->query("SELECT * FROM log_routes WHERE log_id = $store_log_id ORDER BY id ASC") : null;

$route_list = []; 
$dep_times = []; 
$arr_times = []; 
$total_wait = 0; 
$total_break = 0;

if ($routes) {
    while($rt = $routes->fetch_assoc()){
        $route_list[] = $rt;
        if($rt['departure_time']) $dep_times[] = $rt['departure_time'];
        if($rt['arrival_time'])   $arr_times[] = $rt['arrival_time'];
        $total_wait += $rt['wait_minutes'];
        $total_break += $rt['break_minutes'];
    }
}


$pre_inspection = $conn->query("SELECT * FROM vehicle_inspections WHERE trip_id = $trip_id AND inspection_type = 'PRE_TRIP'")->fetch_assoc();
$pre_details = [];

if ($pre_inspection) {
    $pre_id = $pre_inspection['id'];
    $details_sql = "SELECT d.result, m.item_name FROM inspection_details d 
                    JOIN inspection_items m ON d.item_id = m.id WHERE d.inspection_id = $pre_id";
    $details_res = $conn->query($details_sql);
    
    if ($details_res && $details_res->num_rows > 0) {
        while($d = $details_res->fetch_assoc()) {
            $pre_details[] = $d;
        }
    }
}

$post_inspection = $conn->query("SELECT * FROM vehicle_inspections WHERE trip_id = $trip_id AND inspection_type = 'POST_TRIP'")->fetch_assoc();
$post_details = [];

if ($post_inspection) {
    $post_id = $post_inspection['id'];
    $details_sql = "SELECT d.result, m.item_name FROM inspection_details d 
                    JOIN inspection_items m ON d.item_id = m.id WHERE d.inspection_id = $post_id";
    $details_res = $conn->query($details_sql);
    
    if ($details_res && $details_res->num_rows > 0) {
        while($d = $details_res->fetch_assoc()) {
            $post_details[] = $d;
        }
    }
}

// Use whichever exists for overall details (for backward compatibility)
$inspect_header = $pre_inspection ?? $post_inspection;
$ins_details = !empty($pre_details) ? $pre_details : $post_details;

// Calculate driving duration
$driving_duration_text = "--:--";
if (!empty($dep_times) && !empty($arr_times)) {
    $start = strtotime(min($dep_times));
    $end = strtotime(max($arr_times));
    $net_seconds = ($end - $start) - (($total_wait + $total_break) * 60);
    if ($net_seconds > 0) {
        $hours = floor($net_seconds / 3600);
        $minutes = floor(($net_seconds % 3600) / 60);
        $driving_duration_text = "{$hours}h {$minutes}m";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Report #<?= $trip_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: #f5f7fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .header-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .header-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 5px 0;
        }

        .header-meta {
            font-size: 13px;
            opacity: 0.9;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .compact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 15px;
        }

        .info-box {
            background: white;
            padding: 12px;
            border-radius: 8px;
            border-left: 3px solid #667eea;
            font-size: 12px;
        }

        .info-label {
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 4px;
            font-size: 11px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 700;
            color: #1a1a1a;
        }

        .section-card {
            background: white;
            border-radius: 10px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .section-header {
            background: linear-gradient(90deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 14px 16px;
            border-bottom: 2px solid #dee2e6;
            border-left: 4px solid #667eea;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-body {
            padding: 14px 16px;
        }

        .table-compact {
            font-size: 12px;
            margin-bottom: 0;
        }

        .table-compact thead {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }

        .table-compact thead th {
            padding: 8px;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #555;
            border: none;
        }

        .table-compact tbody td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .table-compact tbody tr:hover {
            background: #f9f9f9;
        }

        .route-item {
            display: grid;
            grid-template-columns: 1.5fr 0.7fr 1.5fr 0.7fr 0.6fr 0.6fr;
            gap: 8px;
            padding: 10px;
            border-bottom: 1px solid #eee;
            font-size: 12px;
            align-items: center;
        }

        .route-item:last-child {
            border-bottom: none;
        }

        .route-item .time {
            font-weight: 700;
            color: #667eea;
            text-align: center;
        }

        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px solid #eee;
        }

        .summary-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .summary-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .summary-value {
            font-size: 16px;
            font-weight: 700;
            color: #667eea;
        }

        .inspection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .inspection-item {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }

        .inspection-item.pass {
            border-left-color: #28a745;
            background: #f0fdf4;
        }

        .inspection-item.fail {
            border-left-color: #dc3545;
            background: #fef2f2;
        }

        .approval-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .approval-badge.approved {
            background: #d1fae5;
            color: #065f46;
        }

        .approval-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .inspection-detail {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #eee;
        }

        .inspection-type-section {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .inspection-type-title {
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 8px;
            color: #333;
        }

        .inspection-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 8px;
        }

        .inspection-field {
            font-size: 11px;
        }

        .inspection-field-label {
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
            font-size: 10px;
            margin-bottom: 2px;
        }

        .inspection-field-value {
            font-weight: 700;
            color: #1a1a1a;
            font-size: 12px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-pass {
            background: #d1fae5;
            color: #065f46;
        }

        .status-fail {
            background: #fee2e2;
            color: #991b1b;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn {
            font-size: 13px;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
        }

        .signature-area {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #ddd;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #999;
            height: 40px;
            margin-bottom: 8px;
        }

        .signature-name {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #555;
        }

        .alert {
            border: none;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        @media (max-width: 768px) {
            .header-meta {
                flex-direction: column;
                gap: 8px;
            }

            .compact-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .summary-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .inspection-detail {
                grid-template-columns: 1fr;
            }

            .signature-area {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        @media print {
            .no-print { display: none; }
            body { background: white; }
            .header-card { box-shadow: none; border: 1px solid #ddd; }
            .section-card { box-shadow: none; border: 1px solid #ddd; }
            .table-compact { font-size: 10px; }
        }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid" style="max-width: 1200px; padding: 16px;">
    
    <!-- Action Buttons -->
    <div class="action-buttons no-print">
        <a href="report.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Print</button>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show no-print">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Header Card -->
    <div class="header-card">
        <div class="header-title">Trip Report #<?= str_pad($trip_id, 5, '0', STR_PAD_LEFT) ?></div>
        <div class="header-meta">
            <span><strong><?= htmlspecialchars($trip_data['trip_name']) ?></strong> | Driver: <?= htmlspecialchars($log['driver_name']) ?></span>
            <span>Car: <strong><?= htmlspecialchars($log['car_id']) ?></strong></span>
            <span><?= date('d M Y', strtotime($log['log_date'])) ?></span>
            <span class="badge bg-white text-dark"><?= htmlspecialchars($trip_data['status']) ?></span>
        </div>

        <div class="compact-grid">
            <div class="info-box">
                <div class="info-label">Exit Meter</div>
                <div class="info-value"><?= number_format($log['exit_meter']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Return Meter</div>
                <div class="info-value"><?= number_format($log['return_meter']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Total Distance</div>
                <div class="info-value"><?= number_format($log['return_meter'] - $log['exit_meter']) ?> km</div>
            </div>
            <div class="info-box">
                <div class="info-label">Driving Time</div>
                <div class="info-value"><?= $driving_duration_text ?></div>
            </div>
        </div>
    </div>

    <!-- DRIVING LOG SECTION -->
    <div class="section-card">
        <div class="section-header">
            <i class="bi bi-map"></i> Driving Log
        </div>
        <div class="section-body">
            <!-- Routes -->
            <?php if (!empty($route_list)): ?>
            <div style="margin-bottom: 12px; border: 1px solid #eee; border-radius: 6px; overflow: hidden;">
                <div style="display: grid; grid-template-columns: 1.5fr 0.7fr 1.5fr 0.7fr 0.6fr 0.6fr; gap: 8px; padding: 10px; background: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 700; font-size: 11px; text-transform: uppercase; color: #555;">
                    <div>From</div>
                    <div style="text-align: center;">Time</div>
                    <div>To</div>
                    <div style="text-align: center;">Time</div>
                    <div style="text-align: center;">Wait</div>
                    <div style="text-align: center;">Break</div>
                </div>
                <?php foreach($route_list as $rt): ?>
                <div class="route-item">
                    <span><?= htmlspecialchars($rt['departure_location']) ?></span>
                    <span class="time"><?= date('H:i', strtotime($rt['departure_time'])) ?></span>
                    <span><?= htmlspecialchars($rt['arrival_location']) ?></span>
                    <span class="time"><?= date('H:i', strtotime($rt['arrival_time'])) ?></span>
                    <span style="text-align: center;"><?= $rt['wait_minutes'] ?> m</span>
                    <span style="text-align: center;"><?= $rt['break_minutes'] ?> m</span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Summary -->
            <div class="summary-row">
                <div class="summary-item">
                    <div class="summary-label">Net Driving</div>
                    <div class="summary-value"><?= $driving_duration_text ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Wait Time</div>
                    <div class="summary-value"><?= $total_wait ?> m</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Break Time</div>
                    <div class="summary-value"><?= $total_break ?> m</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Distance</div>
                    <div class="summary-value"><?= number_format($log['return_meter'] - $log['exit_meter']) ?> km</div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-info mb-0">No route details recorded yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- INSPECTION SECTION -->
    <div class="section-card">
        <div class="section-header">
            <i class="bi bi-clipboard-check"></i> Inspection Report
        </div>
        <div class="section-body">
            <?php if ($inspect_header): ?>
                <!-- Inspection Items Grid -->
                <?php if (!empty($pre_details) || !empty($post_details)): ?>
                <div style="margin-bottom: 16px;">
                    <!-- PRE-TRIP Items -->
                    <?php if (!empty($pre_details)): ?>
                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 12px; font-weight: 700; color: #667eea; margin-bottom: 8px; text-transform: uppercase;">
                            <i class="bi bi-play-circle"></i> Pre-Trip Inspection Items
                        </div>
                        <div class="inspection-grid">
                            <?php foreach($pre_details as $det): 
                                $is_pass = (strtolower($det['result']) == 'pass');
                            ?>
                            <div class="inspection-item <?= $is_pass ? 'pass' : 'fail' ?>">
                                <span><?= htmlspecialchars($det['item_name']) ?></span>
                                <span style="font-weight: 700; font-size: 10px;">
                                    <?= $is_pass ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-x-lg"></i>' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                                    
                    <!-- POST-TRIP Items -->
                    <?php if (!empty($post_details)): ?>
                    <div>
                        <div style="font-size: 12px; font-weight: 700; color: #667eea; margin-bottom: 8px; text-transform: uppercase;">
                            <i class="bi bi-check-circle"></i> Post-Trip Inspection Items
                        </div>
                        <div class="inspection-grid">
                            <?php foreach($post_details as $det): 
                                $is_pass = (strtolower($det['result']) == 'pass');
                            ?>
                            <div class="inspection-item <?= $is_pass ? 'pass' : 'fail' ?>">
                                <span><?= htmlspecialchars($det['item_name']) ?></span>
                                <span style="font-weight: 700; font-size: 10px;">
                                    <?= $is_pass ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-x-lg"></i>' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Inspection Details -->
                <div class="inspection-detail">
                    <!-- PRE-INSPECTION -->
                    <div class="inspection-type-section">
                        <div class="inspection-type-title"><i class="bi bi-play-circle"></i> Pre-Inspection</div>
                        <?php if ($pre_inspection): ?>
                        <div class="inspection-row">
                            <div class="inspection-field">
                                <div class="inspection-field-label">Inspector</div>
                                <div class="inspection-field-value"><?= htmlspecialchars($pre_inspection['inspector_name']) ?></div>
                            </div>
                            <div class="inspection-field">
                                <div class="inspection-field-label">Date Verified</div>
                                <div class="inspection-field-value"><?= date('d/m/Y', strtotime($pre_inspection['verified_date'])) ?></div>
                            </div>
                        </div>
                         <div class="inspection-row">
                            <div class="inspection-field">
                                <div class="inspection-field-label">Approved By</div>
                                <div class="inspection-field-value" style="color: #667eea;"><?= htmlspecialchars($pre_inspection['maintenance_manager'] ?? 'Pending') ?></div>
                                <?php if (hasAnyRole(['ADMIN', 'MAINTENANCE_MANAGER']) && empty($pre_inspection['maintenance_manager'])): ?>
                                <div style="margin-top: 8px;">
                                    <button type="button" 
                                            class="btn btn-sm btn-success" 
                                            onclick="approveInspection(<?= $pre_inspection['id'] ?>, '<?= addslashes($trip_data['trip_name']) ?>', 'PRE')">
                                        <i class="bi bi-check-circle"></i> Approve
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="inspection-field">
                                <div class="inspection-field-label">Approval Date</div>
                                <div class="inspection-field-value"><?= !empty($pre_inspection['maintenance_manager']) ? date('d/m/Y', strtotime($pre_inspection['verified_date'])) : '---' ?></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div style="padding: 10px; background: #f0f0f0; border-radius: 6px; font-size: 12px; color: #666;">
                            <i class="bi bi-info-circle"></i> No pre-inspection record
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- POST-INSPECTION -->
                    <div class="inspection-type-section">
                        <div class="inspection-type-title"><i class="bi bi-check-circle"></i> Post-Inspection</div>
                        <?php if ($post_inspection): ?>
                        <div class="inspection-row">
                            <div class="inspection-field">
                                <div class="inspection-field-label">Inspector</div>
                                <div class="inspection-field-value"><?= htmlspecialchars($post_inspection['inspector_name']) ?></div>
                            </div>
                            <div class="inspection-field">
                                <div class="inspection-field-label">Date Verified</div>
                                <div class="inspection-field-value"><?= date('d/m/Y', strtotime($post_inspection['verified_date'])) ?></div>
                            </div>
                        </div>
                        <div class="inspection-row">
                            <div class="inspection-field">
                                <div class="inspection-field-label">Approved By</div>
                                <div class="inspection-field-value" style="color: #667eea;"><?= htmlspecialchars($post_inspection['maintenance_manager'] ?? 'Pending') ?></div>
                                <?php if (hasAnyRole(['ADMIN', 'MAINTENANCE_MANAGER']) && empty($post_inspection['maintenance_manager'])): ?>
                                <div style="margin-top: 8px;">
                                    <button type="button" 
                                            class="btn btn-sm btn-success" 
                                            onclick="approveInspection(<?= $post_inspection['id'] ?>, '<?= addslashes($trip_data['trip_name']) ?>', 'POST')">
                                        <i class="bi bi-check-circle"></i> Approve
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="inspection-field">
                                <div class="inspection-field-label">Approval Date</div>
                                <div class="inspection-field-value"><?= !empty($post_inspection['maintenance_manager']) ? date('d/m/Y', strtotime($post_inspection['verified_date'])) : '---' ?></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div style="padding: 10px; background: #f0f0f0; border-radius: 6px; font-size: 12px; color: #666;">
                            <i class="bi bi-info-circle"></i> No post-inspection record yet
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notes -->
                <?php if (!empty($inspect_header['notes'])): ?>
                <div style="margin-top: 12px; padding: 10px; background: #f9f9f9; border-radius: 6px; font-size: 12px; border-left: 3px solid #667eea;">
                    <strong style="color: #333;">Notes:</strong>
                    <div style="margin-top: 6px; color: #555;"><?= nl2br(htmlspecialchars($inspect_header['notes'])) ?></div>
                </div>
                <?php endif; ?>

            <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle"></i> No inspection data available yet.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Trip Approval Section (at bottom) -->
    <div class="section-card no-print">
        <div class="section-header">
            <i class="bi bi-check-circle"></i> Trip Approval Status
        </div>
        <div class="section-body">
            <?php if (empty($trip_data['approved_by'])): ?>
            <div class="approval-badge pending" style="margin-bottom: 12px;">⏳ Pending Trip Approval</div>
            <?php if (hasAnyRole(['ADMIN', 'MANAGER', 'OPERATOR'])): ?>
                <?php if ($post_inspection && !empty($post_inspection['maintenance_manager'])): ?>
                <div style="margin-top: 12px;">
                    <button type="button" 
                            class="btn btn-success btn-sm" 
                            onclick="approveTripFinal('<?= addslashes($trip_data['trip_name']) ?>')">
                        <i class="bi bi-check-circle"></i> Approve Trip
                    </button>
                </div>
                <?php else: ?>
                <div style="padding: 10px; background: #fef3c7; border-radius: 6px; border-left: 3px solid #f59e0b; font-size: 12px; color: #92400e;">
                    <i class="bi bi-info-circle"></i> Post-inspection must be approved first before trip approval
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php else: ?>
            <div class="approval-badge approved" style="margin-bottom: 8px;">
                ✓ Approved by <?= htmlspecialchars($trip_data['approved_by']) ?>
            </div>
            <div style="font-size: 12px; color: #666;">
                Approval Date: <?= date('d/m/Y H:i', strtotime($trip_data['approved_at'])) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Signature Area (Print Only) -->
    <div class="d-none d-print-block" style="margin-top: 30px;">
        <div class="signature-area">
            <div>
                <div class="signature-line"></div>
                <div class="signature-name">Driver</div>
            </div>
            <div>
                <div class="signature-line"></div>
                <div class="signature-name">Inspector</div>
            </div>
            <div>
                <div class="signature-line"></div>
                <div class="signature-name">Manager</div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Approve inspection inline (both PRE and POST)
function approveInspection(inspectionId, tripName, inspectionType) {
    const confirmMsg = `Are you sure you want to approve the ${inspectionType}-inspection for "${tripName}"?`;
    if (confirm(confirmMsg)) {
        window.location.href = `../includes/approve_inspection.php?inspection_id=${inspectionId}&from=view_detail`;
    }
}

// Approve trip (final step - saves to trips table)
function approveTripFinal(tripName) {
    const confirmMsg = `Are you sure you want to APPROVE the trip "${tripName}" as COMPLETE?`;
    if (confirm(confirmMsg)) {
        // Submit form to approve trip
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '../includes/approve_trip.php';
        
        const tripIdInput = document.createElement('input');
        tripIdInput.type = 'hidden';
        tripIdInput.name = 'trip_id';
        tripIdInput.value = new URLSearchParams(window.location.search).get('id');
        
        form.appendChild(tripIdInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>