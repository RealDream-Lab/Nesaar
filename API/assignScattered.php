<?php
// Scattered assignment algorithm (dry-run preview + optional apply)
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    // Admin check (same style as generateExamAssignments)
    $adminSession = $_COOKIE['adminSession'] ?? null;
    if (!$adminSession) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $session = null;
    try {
        $session = json_decode(urldecode($adminSession), true);
        if (!$session || ($session['type'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Params
    $dryRun = true;
    if (isset($_POST['dry_run'])) {
        $dryRun = filter_var($_POST['dry_run'], FILTER_VALIDATE_BOOLEAN);
    }
    // default true as requested by user
    if (!isset($_POST['dry_run'])) $dryRun = true;

    $apply = false;
    if (isset($_POST['apply'])) {
        $apply = filter_var($_POST['apply'], FILTER_VALIDATE_BOOLEAN);
    }

    // afternoon threshold (hour integer), user confirmed 12
    $afternoonThreshold = 12;
    if (isset($_POST['afternoon_threshold'])) {
        $afternoonThreshold = intval($_POST['afternoon_threshold']);
    }

    // real randomness per user request. Accept optional seed (ignored when not provided)
    $useSeed = false;
    $seed = null;
    if (isset($_POST['seed']) && $_POST['seed'] !== '') {
        // user requested real randomness; seed is optional (for deterministic testing)
        $useSeed = true;
        $seed = intval($_POST['seed']);
        mt_srand($seed);
    } else {
        // ensure randomness
        mt_srand();
    }

    // Fetch exams grouped by date+time
    $stmt = $pdo->query("SELECT id, exam_date, exam_time, required_proctors FROM `ExamsDetil` ORDER BY exam_date ASC, exam_time ASC");
    $exams = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Build session groups (unique date|time keys)
    $groups = [];
    $totalSlots = 0;
    $afternoonTotalSlots = 0;
    foreach ($exams as $ex) {
        $key = $ex['exam_date'] . '|' . $ex['exam_time'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'exam_date' => $ex['exam_date'],
                'exam_time' => $ex['exam_time'],
                'required_proctors' => intval($ex['required_proctors'])
            ];
        } else {
            // If duplicate rows exist, sum required_proctors
            $groups[$key]['required_proctors'] += intval($ex['required_proctors']);
        }
        $rp = intval($ex['required_proctors']);
        $totalSlots += $rp;
        $parts = explode(':', ($ex['exam_time'] ?? '00:00'));
        $h = intval($parts[0] ?? 0);
        if ($h >= $afternoonThreshold) $afternoonTotalSlots += $rp;
    }

    // Fetch proctors
    $pstmt = $pdo->query('SELECT id, first_name, last_name FROM `Proctors` ORDER BY id');
    $proctors = $pstmt ? $pstmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $numProctors = count($proctors);
    if ($numProctors <= 0) {
        echo json_encode(['success' => false, 'error' => 'no_proctors']);
        exit;
    }

    // Fetch ProctorRestrictions
    $rstmt = $pdo->query('SELECT proctor_id, exam_date, exam_time FROM `ProctorRestrictions`');
    $restrs = $rstmt ? $rstmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $restrictions = [];
    foreach ($restrs as $r) {
        $pid = intval($r['proctor_id']);
        $k = $r['exam_date'] . '|' . $r['exam_time'];
        if (!isset($restrictions[$pid])) $restrictions[$pid] = [];
        $restrictions[$pid][$k] = true;
    }

    // Prepare proctor stats
    $assignedCount = [];
    $afternoonCount = [];
    $lastAssignedSessionIndex = [];
    $proctorMap = [];
    // Track if a proctor already has an assignment within a session group (date|time)
    // keyed by [pid][groupIndex] => true
    $assignedInGroup = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        $assignedCount[$pid] = 0;
        $afternoonCount[$pid] = 0;
        $lastAssignedSessionIndex[$pid] = null;
        $proctorMap[$pid] = $p['first_name'] . ' ' . $p['last_name'];
        // diagnostic counters
        $eligibleSlotsCount[$pid] = 0;
        $excludedByRestriction[$pid] = 0;
        $excludedByMax[$pid] = 0;
        $excludedByConsecutive[$pid] = 0;
        $assignedInGroup[$pid] = [];
    }

    // Compute mean targets
    $mean = $totalSlots / max(1, $numProctors);
    $floorMean = (int)floor($mean);
    $ceilMean = (int)ceil($mean); // user asked to round up when fractional
    $targetMax = $ceilMean;
    $targetMin = $floorMean;

    // Build ordered list of session groups (preserve chronological order)
    $groupKeys = array_keys($groups);
    sort($groupKeys, SORT_STRING);
    $sessionIndexForKey = [];
    $orderedGroups = [];
    $si = 0;
    foreach ($groupKeys as $k) {
        $orderedGroups[] = $groups[$k];
        $sessionIndexForKey[$k] = $si;
        $si++;
    }

    // Build slot structures: for each group create N slots
    $slots = []; // each slot => ['groupIndex'=>int,'exam_date'=>,'exam_time'=>,'assigned'=>null]
    foreach ($orderedGroups as $gIndex => $g) {
        $count = max(0, intval($g['required_proctors']));
        for ($i = 0; $i < $count; $i++) {
            $slots[] = [
                'groupIndex' => $gIndex,
                'exam_date' => $g['exam_date'],
                'exam_time' => $g['exam_time'],
                'assigned' => null
            ];
        }
    }

    // Helper: is afternoon
    $isAfternoon = function($time) use ($afternoonThreshold) {
        // time format expected HH:MM; handle gracefully
        $parts = explode(':', $time);
        $hour = intval($parts[0] ?? 0);
        return $hour >= $afternoonThreshold;
    };

    // Helper: shuffle with mt_rand
    $shuffleWithRand = function(&$arr) {
        $n = count($arr);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $arr[$i]; $arr[$i] = $arr[$j]; $arr[$j] = $tmp;
        }
    };

    // We'll perform two passes: (A) afternoons, (B) remaining
    // Build list of slot indices by groupIndex for afternoons and others
    $slotIndicesByGroup = [];
    foreach ($slots as $idx => $s) {
        $gk = $s['groupIndex'];
        if (!isset($slotIndicesByGroup[$gk])) $slotIndicesByGroup[$gk] = [];
        $slotIndicesByGroup[$gk][] = $idx;
    }

    $afternoonSlotIndices = [];
    $otherSlotIndices = [];
    foreach ($orderedGroups as $gIndex => $g) {
        $k = $g['exam_date'] . '|' . $g['exam_time'];
        $isAf = $isAfternoon($g['exam_time']);
        $indices = $slotIndicesByGroup[$gIndex] ?? [];
        if ($isAf) {
            foreach ($indices as $siidx) $afternoonSlotIndices[] = $siidx;
        } else {
            foreach ($indices as $siidx) $otherSlotIndices[] = $siidx;
        }
    }

    // Helper: find eligible proctors for a slot
    $findEligible = function($slot, $allowRelaxes = []) use (&$assignedCount, &$afternoonCount, &$lastAssignedSessionIndex, &$restrictions, &$proctors, &$proctorMap, $targetMax, $targetMin, $orderedGroups, $sessionIndexForKey, $isAfternoon, &$eligibleSlotsCount, &$excludedByRestriction, &$excludedByMax, &$excludedByConsecutive, &$assignedInGroup) {
        $candidates = [];
        $gIndex = $slot['groupIndex'];
        $date = $slot['exam_date'];
        $time = $slot['exam_time'];
        $key = $date . '|' . $time;
        $prevSessionIndex = $gIndex - 1;

        $skipConsecutiveCheck = in_array('allow_consecutive', $allowRelaxes, true);

        foreach ($proctors as $p) {
            $pid = intval($p['id']);
            // restriction check
            if (isset($restrictions[$pid]) && isset($restrictions[$pid][$key])) { $excludedByRestriction[$pid]++; continue; }
            // prevent multiple assignments for the same (date|time) session
            if (isset($assignedInGroup[$pid][$gIndex]) && $assignedInGroup[$pid][$gIndex] === true) { continue; }
            // max limit
            if ($assignedCount[$pid] >= $targetMax) { $excludedByMax[$pid]++; continue; }
            // consecutive avoidance: if allowed, skip those with lastAssignedSessionIndex == prevSessionIndex
            if (!$skipConsecutiveCheck) {
                $hasEnoughAssignments = ($assignedCount[$pid] >= $targetMin);
                if ($hasEnoughAssignments && $lastAssignedSessionIndex[$pid] === $prevSessionIndex) { $excludedByConsecutive[$pid]++; continue; }
            }
            $eligibleSlotsCount[$pid]++;
            $candidates[] = $pid;
        }
        return $candidates;
    };

    // Core pick function: choose best candidate by (afternoonCount, assignedCount) ascending and random tie-break
    $pickCandidate = function($candidates) use (&$assignedCount, &$afternoonCount) {
        if (!$candidates) return null;
        // sort by afternoonCount then assignedCount
        usort($candidates, function($a, $b) use (&$assignedCount, &$afternoonCount) {
            if ($afternoonCount[$a] < $afternoonCount[$b]) return -1;
            if ($afternoonCount[$a] > $afternoonCount[$b]) return 1;
            if ($assignedCount[$a] < $assignedCount[$b]) return -1;
            if ($assignedCount[$a] > $assignedCount[$b]) return 1;
            // else random tie
            return mt_rand(-1,1);
        });
        return $candidates[0];
    };

    // Assign helper that enforces relaxations progressively
    $assignSlot = function($slotIndex) use (&$slots, &$findEligible, &$pickCandidate, &$assignedCount, &$afternoonCount, &$lastAssignedSessionIndex, $orderedGroups, $isAfternoon, &$assignedInGroup) {
        $slot = &$slots[$slotIndex];
        $gIndex = $slot['groupIndex'];
        $date = $slot['exam_date'];
        $time = $slot['exam_time'];
        $key = $date . '|' . $time;
        // Try 1: strict (no consecutive)
        $cands = $findEligible($slot, []);
        // Try 2: allow consecutive if none
        if (empty($cands)) {
            $cands = $findEligible($slot, ['allow_consecutive']);
        }
        if (empty($cands)) {
            // No eligible proctor: leave unassigned
            return null;
        }
        $pid = $pickCandidate($cands);
        if ($pid === null) return null;
        // assign
        $slots[$slotIndex]['assigned'] = $pid;
        $assignedCount[$pid]++;
        if ($isAfternoon($time)) $afternoonCount[$pid]++;
        $lastAssignedSessionIndex[$pid] = $gIndex;
        // mark that this proctor now has an assignment in this session group
        $assignedInGroup[$pid][$gIndex] = true;
        return $pid;
    };

    // PASS A: assign afternoon slots first
    // We'll process afternoon groups in chronological order (their slots are already in afternoonSlotIndices order)
    $shuffleWithRand($afternoonSlotIndices); // randomize within the afternoon set to avoid bias across identical priority
    foreach ($afternoonSlotIndices as $sidx) {
        // Respect targetMax and restrictions
        $assigned = $assignSlot($sidx);
        // if null, leave unassigned for now
    }

    // PASS B: assign remaining (including morning and unfilled afternoon)
    $allRemaining = array_merge($otherSlotIndices, []);
    // add any unfilled afternoon slots that remain
    foreach ($afternoonSlotIndices as $sidx) {
        if ($slots[$sidx]['assigned'] === null) $allRemaining[] = $sidx;
    }
    // randomize order a bit but keep chronological bias: we'll process grouped by groupIndex ascending
    usort($allRemaining, function($a, $b) use ($slots) {
        if ($slots[$a]['groupIndex'] === $slots[$b]['groupIndex']) return $a - $b;
        return $slots[$a]['groupIndex'] - $slots[$b]['groupIndex'];
    });

    foreach ($allRemaining as $sidx) {
        if ($slots[$sidx]['assigned'] !== null) continue;
        $assignSlot($sidx);
    }

    // Build assignment maps for rebalancing
    $proctorAssignments = [];
    $proctorHasGroup = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        $proctorAssignments[$pid] = [];
        $proctorHasGroup[$pid] = [];
    }
    foreach ($slots as $idx => $slot) {
        $pid = $slot['assigned'];
        if ($pid === null) continue;
        $proctorAssignments[$pid][] = $idx;
        $proctorHasGroup[$pid][$slot['groupIndex']] = true;
    }

    $removeAssignmentIndex = function (&$list, $slotIdx) {
        foreach ($list as $key => $value) {
            if ($value === $slotIdx) {
                unset($list[$key]);
                break;
            }
        }
        $list = array_values($list);
    };

    $findSlotToReassign = function ($targetPid, $allowConsecutive, $requireAfternoon = null, $ownerFilter = null) use (&$slots, &$assignedCount, $targetMin, $targetMax, &$restrictions, &$proctorHasGroup, $isAfternoon, &$afternoonCount) {
        foreach ($slots as $idx => $slot) {
            $currentPid = $slot['assigned'];
            if ($currentPid === null) continue;
            if ($currentPid === $targetPid) continue;
            if ($ownerFilter !== null && !$ownerFilter($currentPid)) continue;
            if ($assignedCount[$targetPid] >= $targetMax) continue;
            $isAf = $isAfternoon($slot['exam_time']);
            if ($requireAfternoon === true && !$isAf) continue;
            if ($requireAfternoon === false && $isAf) continue;
            if ($assignedCount[$currentPid] <= $targetMin) continue;
            $groupIndex = $slot['groupIndex'];
            if (isset($proctorHasGroup[$targetPid][$groupIndex])) continue;
            $key = $slot['exam_date'] . '|' . $slot['exam_time'];
            if (isset($restrictions[$targetPid]) && isset($restrictions[$targetPid][$key])) continue;
            if (!$allowConsecutive) {
                if (isset($proctorHasGroup[$targetPid][$groupIndex - 1]) || isset($proctorHasGroup[$targetPid][$groupIndex + 1])) {
                    continue;
                }
            }
            return $idx;
        }
        return null;
    };

    $applyReassignment = function ($slotIdx, $targetPid) use (&$slots, &$assignedCount, &$afternoonCount, &$proctorAssignments, &$proctorHasGroup, $isAfternoon, $removeAssignmentIndex) {
        $currentPid = $slots[$slotIdx]['assigned'];
        if ($currentPid === null || $currentPid === $targetPid) {
            return null;
        }

        $groupIndex = $slots[$slotIdx]['groupIndex'];
        $time = $slots[$slotIdx]['exam_time'];
        $isAf = $isAfternoon($time);

        $assignedCount[$currentPid] = max(0, $assignedCount[$currentPid] - 1);
        if ($isAf && $afternoonCount[$currentPid] > 0) {
            $afternoonCount[$currentPid]--;
        }
        if (isset($proctorAssignments[$currentPid])) {
            $removeAssignmentIndex($proctorAssignments[$currentPid], $slotIdx);
        }
        unset($proctorHasGroup[$currentPid][$groupIndex]);

        $slots[$slotIdx]['assigned'] = $targetPid;
        $assignedCount[$targetPid]++;
        if ($isAf) {
            $afternoonCount[$targetPid]++;
        }
        $proctorAssignments[$targetPid][] = $slotIdx;
        $proctorHasGroup[$targetPid][$groupIndex] = true;

        return $currentPid;
    };

    // Balancing pass 1: lift proctors that are still below the floor target
    $shortageProctors = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        if ($assignedCount[$pid] < $targetMin) {
            $shortageProctors[] = $pid;
        }
    }

    if (!empty($shortageProctors)) {
        usort($shortageProctors, function ($a, $b) use (&$assignedCount) {
            if ($assignedCount[$a] === $assignedCount[$b]) return $a <=> $b;
            return $assignedCount[$a] <=> $assignedCount[$b];
        });

        foreach ($shortageProctors as $pid) {
            $loopGuard = 0;
            while ($assignedCount[$pid] < $targetMin && $loopGuard < 500) {
                $loopGuard++;
                $slotIdx = $findSlotToReassign($pid, false, null, null);
                if ($slotIdx === null) {
                    $slotIdx = $findSlotToReassign($pid, true, null, null);
                }
                if ($slotIdx === null) break;

                $applyReassignment($slotIdx, $pid);
            }
        }
    }

    // Balancing pass 2: swap morning/afternoon slots to tighten afternoon distribution
    $afternoonMean = $afternoonTotalSlots / max(1, $numProctors);
    $afternoonFloor = (int)floor($afternoonMean);
    $afternoonCeil = (int)ceil($afternoonMean);

    $canAssignForSwap = function ($pid, $slotIdx, $allowConsecutive, $groupOverride = null) use (&$slots, &$proctorHasGroup, &$restrictions) {
        $slot = $slots[$slotIdx];
        $key = $slot['exam_date'] . '|' . $slot['exam_time'];
        if (isset($restrictions[$pid]) && isset($restrictions[$pid][$key])) return false;
        $groupIndex = $slot['groupIndex'];
        $assignedGroups = $groupOverride ?? ($proctorHasGroup[$pid] ?? []);
        if (isset($assignedGroups[$groupIndex])) return false;
        if (!$allowConsecutive) {
            if (isset($assignedGroups[$groupIndex - 1]) || isset($assignedGroups[$groupIndex + 1])) return false;
        }
        return true;
    };

    $performSwap = function ($targetPid, $donorPid, $afternoonSlotIdx, $morningSlotIdx) use (&$slots, &$proctorAssignments, &$proctorHasGroup, &$afternoonCount, $isAfternoon, $removeAssignmentIndex) {
        $afternoonGroupIndex = $slots[$afternoonSlotIdx]['groupIndex'];
        $morningGroupIndex = $slots[$morningSlotIdx]['groupIndex'];

        if (isset($proctorAssignments[$donorPid])) {
            $removeAssignmentIndex($proctorAssignments[$donorPid], $afternoonSlotIdx);
        }
        if (isset($proctorAssignments[$targetPid])) {
            $removeAssignmentIndex($proctorAssignments[$targetPid], $morningSlotIdx);
        }

        $slots[$afternoonSlotIdx]['assigned'] = $targetPid;
        $slots[$morningSlotIdx]['assigned'] = $donorPid;

        $proctorAssignments[$targetPid][] = $afternoonSlotIdx;
        $proctorAssignments[$donorPid][] = $morningSlotIdx;

        unset($proctorHasGroup[$donorPid][$afternoonGroupIndex]);
        $proctorHasGroup[$targetPid][$afternoonGroupIndex] = true;

        unset($proctorHasGroup[$targetPid][$morningGroupIndex]);
        $proctorHasGroup[$donorPid][$morningGroupIndex] = true;

        if ($isAfternoon($slots[$afternoonSlotIdx]['exam_time'])) {
            $afternoonCount[$targetPid]++;
            $afternoonCount[$donorPid]--;
        }
    };

    $afternoonDeficits = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        if ($afternoonCount[$pid] < $afternoonFloor) {
            $afternoonDeficits[] = $pid;
        }
    }

    if (!empty($afternoonDeficits) && $afternoonCeil > 0) {
        usort($afternoonDeficits, function ($a, $b) use (&$afternoonCount) {
            if ($afternoonCount[$a] === $afternoonCount[$b]) return $a <=> $b;
            return $afternoonCount[$a] <=> $afternoonCount[$b];
        });

        $attemptCombos = [
            ['target' => false, 'donor' => false],
            ['target' => false, 'donor' => true],
            ['target' => true, 'donor' => false],
            ['target' => true, 'donor' => true],
        ];

        foreach ($afternoonDeficits as $targetPid) {
            $loopGuard = 0;
            while ($afternoonCount[$targetPid] < $afternoonFloor && $loopGuard < 200) {
                $loopGuard++;

                $surplusProctors = [];
                foreach ($proctors as $pp) {
                    $dpid = intval($pp['id']);
                    if ($afternoonCount[$dpid] > $afternoonCeil) {
                        $surplusProctors[] = $dpid;
                    }
                }

                if (empty($surplusProctors)) {
                    break;
                }

                $swapDone = false;
                foreach ($attemptCombos as $combo) {
                    foreach ($surplusProctors as $donorPid) {
                        if ($afternoonCount[$donorPid] <= $afternoonCeil) continue;

                        $donorAfternoonSlots = [];
                        foreach ($proctorAssignments[$donorPid] as $slotIdx) {
                            if ($isAfternoon($slots[$slotIdx]['exam_time'])) {
                                $donorAfternoonSlots[] = $slotIdx;
                            }
                        }
                        if (empty($donorAfternoonSlots)) continue;

                        $targetMorningSlots = [];
                        foreach ($proctorAssignments[$targetPid] as $slotIdx) {
                            if (!$isAfternoon($slots[$slotIdx]['exam_time'])) {
                                $targetMorningSlots[] = $slotIdx;
                            }
                        }
                        if (empty($targetMorningSlots)) {
                            continue;
                        }

                        foreach ($donorAfternoonSlots as $afSlotIdx) {
                            $donorGroupOverride = $proctorHasGroup[$donorPid] ?? [];
                            $afGroupIndex = $slots[$afSlotIdx]['groupIndex'];
                            unset($donorGroupOverride[$afGroupIndex]);

                            if (!$canAssignForSwap($targetPid, $afSlotIdx, $combo['target'])) {
                                continue;
                            }

                            foreach ($targetMorningSlots as $morningSlotIdx) {
                                if (!$canAssignForSwap($donorPid, $morningSlotIdx, $combo['donor'], $donorGroupOverride)) {
                                    continue;
                                }

                                $performSwap($targetPid, $donorPid, $afSlotIdx, $morningSlotIdx);
                                $swapDone = true;
                                break 4;
                            }
                        }
                    }
                }

                if (!$swapDone) {
                    break;
                }
            }
        }
    }

    // Build report
    $perProctor = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        $perProctor[$pid] = [
            'id' => $pid,
            'name' => $proctorMap[$pid],
            'total_assigned' => $assignedCount[$pid],
            'afternoon_assigned' => $afternoonCount[$pid]
        ];
    }

    $unfilledSlots = [];
    $assignmentsForOutput = [];
    foreach ($slots as $slot) {
        if ($slot['assigned'] === null) {
            // compute candidate diagnostic for this unfilled slot
            $k = $slot['exam_date'] . '|' . $slot['exam_time'];
            $cands = [];
            foreach ($proctors as $p) {
                $pid = intval($p['id']);
                if (isset($restrictions[$pid]) && isset($restrictions[$pid][$k])) continue;
                if ($assignedCount[$pid] >= $targetMax) continue;
                $cands[] = $pid;
            }
            $unfilledSlots[] = ['exam_date' => $slot['exam_date'], 'exam_time' => $slot['exam_time'], 'candidate_count' => count($cands), 'candidates' => $cands];
            $assignmentsForOutput[] = ['exam_date' => $slot['exam_date'], 'exam_time' => $slot['exam_time'], 'proctor_id' => null, 'proctor_name' => '', 'candidate_count' => count($cands)];
        } else {
            $pid = $slot['assigned'];
            $assignmentsForOutput[] = ['exam_date' => $slot['exam_date'], 'exam_time' => $slot['exam_time'], 'proctor_id' => $pid, 'proctor_name' => $proctorMap[$pid]];
        }
    }

    $report = [
        'success' => true,
        'dry_run' => $dryRun,
        'total_slots' => $totalSlots,
        'afternoon_slots' => $afternoonTotalSlots,
        'num_proctors' => $numProctors,
        'mean' => $mean,
        'floor_mean' => $floorMean,
        'ceil_mean' => $ceilMean,
        'per_proctor' => array_values($perProctor),
        'unfilled_slots' => $unfilledSlots,
        'assignments_preview' => $assignmentsForOutput
    ];

    // Diagnostics: per-proctor eligible/exclusion counters to help debugging
    $diagPerProctor = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        $diagPerProctor[$pid] = [
            'id' => $pid,
            'name' => $proctorMap[$pid],
            'eligible_slots' => $eligibleSlotsCount[$pid] ?? 0,
            'excluded_by_restriction' => $excludedByRestriction[$pid] ?? 0,
            'excluded_by_max' => $excludedByMax[$pid] ?? 0,
            'excluded_by_consecutive' => $excludedByConsecutive[$pid] ?? 0
        ];
    }
    $report['diagnostics'] = ['per_proctor' => array_values($diagPerProctor)];

    // If apply requested and dryRun=false, write assignments into ExamAssignments table
    if ($apply && !$dryRun) {
        try {
            $needsSchemaReset = false;
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM `ExamAssignments` LIKE 'proctor_id'");
                if (!$colStmt || !$colStmt->fetch(PDO::FETCH_ASSOC)) {
                    $needsSchemaReset = true;
                }
            } catch (Throwable $schemaCheckError) {
                $needsSchemaReset = true;
            }

            if ($needsSchemaReset) {
                $pdo->exec("DROP TABLE IF EXISTS `ExamAssignments`");
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS `ExamAssignments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `exam_date` CHAR(10) DEFAULT '' COLLATE utf8mb4_unicode_ci,
                `exam_time` CHAR(5) DEFAULT '' COLLATE utf8mb4_unicode_ci,
                `proctor_id` INT NULL,
                `proctor_name` VARCHAR(120) DEFAULT '' COLLATE utf8mb4_unicode_ci,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Ensure unique constraint to prevent duplicate assignments of the same proctor within the same session
            try {
                $idxStmt = $pdo->query("SHOW INDEX FROM `ExamAssignments` WHERE Key_name='uniq_session_proctor'");
                $hasIdx = $idxStmt && $idxStmt->fetch(PDO::FETCH_ASSOC);
                if (!$hasIdx) {
                    $pdo->exec("ALTER TABLE `ExamAssignments` ADD UNIQUE KEY `uniq_session_proctor` (`exam_date`,`exam_time`,`proctor_id`)");
                }
            } catch (Throwable $e) { /* ignore if index already exists or cannot be created */ }

            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM `ExamAssignments`");

            $ins = $pdo->prepare('INSERT INTO `ExamAssignments` (exam_date, exam_time, proctor_id, proctor_name) VALUES (?, ?, ?, ?)');
            foreach ($assignmentsForOutput as $a) {
                $ins->execute([$a['exam_date'], $a['exam_time'], $a['proctor_id'], $a['proctor_name']]);
            }

            $pdo->commit();
            $report['applied'] = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable $rollbackError) { /* swallow rollback errors */ }
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'db_write_failed', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $report['applied'] = false;
    }

    // If there are unfilled slots, include a note
    if (!empty($unfilledSlots)) {
        $report['note'] = 'unfilled_slots_exist';
        $report['unfilled_count'] = count($unfilledSlots);
    }

    echo json_encode($report, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
