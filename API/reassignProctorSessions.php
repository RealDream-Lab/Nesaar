<?php
/**
 * Reassign sessions from one proctor to other available proctors
 * 
 * POST body JSON:
 * {
 *   "source_proctor_id": int,
 *   "sessions": [ { "exam_date": "1404/01/01", "exam_time": "08:30" }, ... ]
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "reassignments": [
 *     { "exam_date": "...", "exam_time": "...", "new_proctor_id": N, "new_proctor_name": "..." },
 *     ...
 *   ],
 *   "affected_proctors": [
 *     { "proctor_id": N, "proctor_name": "...", "added_sessions": [...] },
 *     ...
 *   ]
 * }
 */

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/db_init.php';

try {
    csrf_enforce();
    license_guard_enforce_api();

    $session = admin_session_require($pdo);

    $rateLimitKey = 'reassign_sessions:' . ($session['username'] ?? 'unknown');
    rate_limit_enforce($pdo, $rateLimitKey, 30, 60);

    // Parse input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        echo json_encode(['success' => false, 'error' => 'invalid_input'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sourceProctorId    = isset($input['source_proctor_id']) ? intval($input['source_proctor_id']) : 0;
    $sessions           = isset($input['sessions']) && is_array($input['sessions']) ? $input['sessions'] : [];
    $excludedProctorIds = isset($input['excluded_proctor_ids']) && is_array($input['excluded_proctor_ids'])
        ? array_map('intval', $input['excluded_proctor_ids'])
        : [];
    $includedProctorIds = isset($input['included_proctor_ids']) && is_array($input['included_proctor_ids'])
        ? array_map('intval', $input['included_proctor_ids'])
        : [];
    $includeMode        = isset($input['include_mode']) && $input['include_mode'] === true;

    if ($sourceProctorId <= 0) {
        echo json_encode(['success' => false, 'error' => 'invalid_proctor_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($sessions)) {
        echo json_encode(['success' => false, 'error' => 'no_sessions_provided'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validate source proctor exists
    $checkStmt = $pdo->prepare('SELECT id, first_name, last_name FROM `Proctors` WHERE id = ?');
    $checkStmt->execute([$sourceProctorId]);
    $sourceProctor = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sourceProctor) {
        echo json_encode(['success' => false, 'error' => 'proctor_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Get all proctors for redistribution
    $proctorsStmt = $pdo->query('SELECT id, first_name, last_name FROM `Proctors` ORDER BY id');
    $allProctors  = $proctorsStmt ? $proctorsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (count($allProctors) < 2) {
        echo json_encode(['success' => false, 'error' => 'not_enough_proctors', 'message' => 'برای جابجایی حداقل ۲ مراقب لازم است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Build proctor map
    $proctorMap = [];
    foreach ($allProctors as $p) {
        $proctorMap[$p['id']] = [
            'id' => (int)$p['id'],
            'name' => trim($p['first_name'] . ' ' . $p['last_name']),
            'first_name' => $p['first_name'],
            'last_name' => $p['last_name']
        ];
    }

    // Fetch restrictions
    $restrictions = [];
    try {
        $rstmt  = $pdo->query('SELECT proctor_id, exam_date, exam_time FROM `ProctorRestrictions`');
        $restrs = $rstmt ? $rstmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($restrs as $r) {
            $pid = intval($r['proctor_id']);
            $k   = $r['exam_date'] . '|' . $r['exam_time'];
            if (!isset($restrictions[$pid]))
                $restrictions[$pid] = [];
            $restrictions[$pid][$k] = true;
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Get current assignments for each proctor (to balance load)
    $assignCountStmt = $pdo->query('SELECT proctor_id, COUNT(*) as cnt FROM `ExamAssignments` WHERE proctor_id IS NOT NULL GROUP BY proctor_id');
    $assignCounts    = [];
    if ($assignCountStmt) {
        while ($row = $assignCountStmt->fetch(PDO::FETCH_ASSOC)) {
            $assignCounts[(int)$row['proctor_id']] = (int)$row['cnt'];
        }
    }

    // Initialize count for all proctors
    foreach ($allProctors as $p) {
        if (!isset($assignCounts[$p['id']])) {
            $assignCounts[$p['id']] = 0;
        }
    }

    // Get current assignments for each session to check who is already assigned
    $sessionAssignments = [];
    $saStmt             = $pdo->query('SELECT exam_date, exam_time, proctor_id FROM `ExamAssignments` WHERE proctor_id IS NOT NULL');
    if ($saStmt) {
        while ($row = $saStmt->fetch(PDO::FETCH_ASSOC)) {
            $k = $row['exam_date'] . '|' . $row['exam_time'];
            if (!isset($sessionAssignments[$k]))
                $sessionAssignments[$k] = [];
            $sessionAssignments[$k][(int)$row['proctor_id']] = true;
        }
    }

    // Start transaction
    $pdo->beginTransaction();

    $reassignments    = [];
    $affectedProctors = [];

    foreach ($sessions as $sess) {
        $examDate = isset($sess['exam_date']) ? trim($sess['exam_date']) : '';
        $examTime = isset($sess['exam_time']) ? trim($sess['exam_time']) : '';

        if (empty($examDate) || empty($examTime)) {
            continue;
        }

        $sessionKey = $examDate . '|' . $examTime;

        // Find eligible proctors for this session (not restricted, not already assigned, not source)
        $eligible = [];
        foreach ($allProctors as $p) {
            $pid = (int)$p['id'];

            // Skip source proctor
            if ($pid === $sourceProctorId)
                continue;

            // In include mode: only allow proctors in the included list
            if ($includeMode) {
                if (!in_array($pid, $includedProctorIds, true))
                    continue;
            } else {
                // In exclude mode: skip if in excluded list
                if (in_array($pid, $excludedProctorIds, true))
                    continue;
            }

            // Skip if restricted
            if (isset($restrictions[$pid][$sessionKey]))
                continue;

            // Skip if already assigned to this session
            if (isset($sessionAssignments[$sessionKey][$pid]))
                continue;

            $eligible[] = $pid;
        }

        if (empty($eligible)) {
            // No eligible proctor for this session - skip and continue
            continue;
        }

        // Sort eligible by assignment count (least first) to balance
        usort($eligible, function ($a, $b) use ($assignCounts) {
            return ($assignCounts[$a] ?? 0) - ($assignCounts[$b] ?? 0);
        });

        // Pick the one with least assignments
        $newProctorId   = $eligible[0];
        $newProctorName = $proctorMap[$newProctorId]['name'] ?? '';

        // Update the ExamAssignments table
        $updateStmt = $pdo->prepare('UPDATE `ExamAssignments` SET proctor_id = ?, proctor_name = ? WHERE exam_date = ? AND exam_time = ? AND proctor_id = ?');
        $updateStmt->execute([$newProctorId, $newProctorName, $examDate, $examTime, $sourceProctorId]);

        // Update local tracking
        $assignCounts[$newProctorId]    = ($assignCounts[$newProctorId] ?? 0) + 1;
        $assignCounts[$sourceProctorId] = max(0, ($assignCounts[$sourceProctorId] ?? 0) - 1);

        // Update session assignments tracking
        if (!isset($sessionAssignments[$sessionKey])) {
            $sessionAssignments[$sessionKey] = [];
        }
        unset($sessionAssignments[$sessionKey][$sourceProctorId]);
        $sessionAssignments[$sessionKey][$newProctorId] = true;

        $reassignments[] = [
            'exam_date' => $examDate,
            'exam_time' => $examTime,
            'new_proctor_id' => $newProctorId,
            'new_proctor_name' => $newProctorName
        ];

        // Track affected proctors
        if (!isset($affectedProctors[$newProctorId])) {
            $affectedProctors[$newProctorId] = [
                'proctor_id' => $newProctorId,
                'proctor_name' => $newProctorName,
                'added_sessions' => []
            ];
        }
        $affectedProctors[$newProctorId]['added_sessions'][] = [
            'exam_date' => $examDate,
            'exam_time' => $examTime
        ];
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'source_proctor_id' => $sourceProctorId,
        'source_proctor_name' => trim($sourceProctor['first_name'] . ' ' . $sourceProctor['last_name']),
        'reassigned_count' => count($reassignments),
        'reassignments' => $reassignments,
        'affected_proctors' => array_values($affectedProctors)
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
