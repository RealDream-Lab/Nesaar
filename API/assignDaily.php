<?php
// Daily presence assignment algorithm (preview + optional apply)
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';

try {
    csrf_enforce();
    license_guard_enforce_api();
    $adminSession = admin_session_require($pdo);

    // Params
    $dryRun = true;
    if (isset($_POST['dry_run'])) {
        $dryRun = filter_var($_POST['dry_run'], FILTER_VALIDATE_BOOLEAN);
    }
    if (!isset($_POST['dry_run'])) $dryRun = true;

    $apply = false;
    if (isset($_POST['apply'])) {
        $apply = filter_var($_POST['apply'], FILTER_VALIDATE_BOOLEAN);
    }

    $afternoonThreshold = 12; // default 12:00 as afternoon
    if (isset($_POST['afternoon_threshold'])) {
        $afternoonThreshold = intval($_POST['afternoon_threshold']);
    }

    // optional randomness seed
    if (isset($_POST['seed']) && $_POST['seed'] !== '') {
        mt_srand(intval($_POST['seed']));
    } else {
        mt_srand();
    }

    // Fetch exams detail
    $stmt = $pdo->query("SELECT exam_date, exam_time, required_proctors FROM `ExamsDetil` ORDER BY exam_date ASC, exam_time ASC");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Group by day -> sessions (time)
    $days = []; // date => ['date'=>date, 'sessions'=> [ ['time'=>HH:MM,'required'=>int,'remaining'=>int] ] ]
    foreach ($rows as $r) {
        $date = $r['exam_date'];
        $time = $r['exam_time'];
        $req = intval($r['required_proctors']);
        if (!isset($days[$date])) $days[$date] = ['date' => $date, 'sessions' => []];
        // aggregate if duplicate time exists
        if (!isset($days[$date]['sessions'][$time])) $days[$date]['sessions'][$time] = ['time' => $time, 'required' => 0, 'remaining' => 0];
        $days[$date]['sessions'][$time]['required'] += $req;
        $days[$date]['sessions'][$time]['remaining'] += $req;
    }

    // Sort days and sessions
    $orderedDates = array_keys($days);
    sort($orderedDates, SORT_STRING);
    $dayIndexForDate = [];
    $orderedDays = [];
    $idx = 0;
    foreach ($orderedDates as $d) {
        // sort sessions in this day by time
        $sessionsAssoc = $days[$d]['sessions'];
        ksort($sessionsAssoc, SORT_STRING);
        $sessions = array_values($sessionsAssoc);
        $days[$d]['sessions'] = $sessions;
        $orderedDays[] = $days[$d];
        $dayIndexForDate[$d] = $idx++;
    }

    // Counters
    $totalSlots = 0;
    $afternoonTotalSlots = 0;
    foreach ($orderedDays as $day) {
        foreach ($day['sessions'] as $s) {
            $totalSlots += intval($s['required']);
            $parts = explode(':', $s['time']);
            $h = intval($parts[0] ?? 0);
            if ($h >= $afternoonThreshold) $afternoonTotalSlots += intval($s['required']);
        }
    }

    // Fetch proctors
    $pstmt = $pdo->query('SELECT id, first_name, last_name FROM `Proctors` ORDER BY id');
    $proctors = $pstmt ? $pstmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $numProctors = count($proctors);
    if ($numProctors <= 0) {
        echo json_encode(['success' => false, 'error' => 'no_proctors']);
        exit;
    }

    // Fetch restrictions
    $rstmt = $pdo->query('SELECT proctor_id, exam_date, exam_time FROM `ProctorRestrictions`');
    $restrs = $rstmt ? $rstmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $restrictions = [];
    foreach ($restrs as $r) {
        $pid = intval($r['proctor_id']);
        $k = $r['exam_date'] . '|' . $r['exam_time'];
        if (!isset($restrictions[$pid])) $restrictions[$pid] = [];
        $restrictions[$pid][$k] = true;
    }

    // Per-proctor stats
    $assignedCount = [];
    $afternoonCount = [];
    $lastAssignedDayIndex = [];
    $assignedInDay = [];
    $proctorMap = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        $assignedCount[$pid] = 0;
        $afternoonCount[$pid] = 0;
        $lastAssignedDayIndex[$pid] = null;
        $assignedInDay[$pid] = [];
        $proctorMap[$pid] = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    }

    // Means
    $mean = ($numProctors > 0) ? ($totalSlots / $numProctors) : 0.0;
    $floorMean = (int)floor($mean);
    $ceilMean = (int)ceil($mean);
    $targetMin = $floorMean;
    $targetMax = $ceilMean;

    // Helpers
    $isAfternoon = function($time) use ($afternoonThreshold) {
        $parts = explode(':', $time);
        $hour = intval($parts[0] ?? 0);
        return $hour >= $afternoonThreshold;
    };

    $shuffleWithRand = function(&$arr) {
        $n = count($arr);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $t = $arr[$i]; $arr[$i] = $arr[$j]; $arr[$j] = $t;
        }
    };

    // Build fast lookup: remaining per day/session already in $orderedDays

    // Candidate builder for a day
    $buildCandidatesForDay = function($dayIndex, $allowConsecDays = false, $ignoreMax = false) use (&$orderedDays, &$restrictions, &$assignedCount, &$afternoonCount, &$lastAssignedDayIndex, &$sessionAssignedProctors, $isAfternoon, $targetMax, $targetMin, $ceilMean, $proctors) {
        $day = $orderedDays[$dayIndex];
        $cands = [];
        foreach ($proctors as $p) {
            $pid = intval($p['id']);
            // already assigned package in this day? skip
            // Note: we allow یک بسته در هر روز برای هر مراقب (اختصاص یک‌باره)
            // اگر نیاز به مرحله استثنایی باشد، بعداً به‌صورت تک‌اسلاتی رسیدگی می‌کنیم.
            // در این فاز، بسته‌ی روزانه فقط یک‌بار.
            if (isset($lastAssignedDayIndex[$pid]) && $lastAssignedDayIndex[$pid] === $dayIndex) continue;

            // count restricted and allowed sessions for this day
            $restrictedCount = 0;
            $allowedSessions = [];
            foreach ($day['sessions'] as $si => $s) {
                if ($s['remaining'] <= 0) continue;
                $key = $day['date'] . '|' . $s['time'];
                if (isset($restrictions[$pid]) && isset($restrictions[$pid][$key])) {
                    $restrictedCount++;
                    continue;
                }
                // Check if proctor already assigned to this session
                if (isset($sessionAssignedProctors[$key][$pid])) {
                    // Skip this session as it's already assigned to this proctor
                    continue;
                }
                $allowedSessions[] = $si;
            }
            // rule: if 2 or more restrictions in this day, skip for package phase
            if ($restrictedCount >= 2) continue;
            if (empty($allowedSessions)) continue;

            // avoid consecutive days if not allowed
            if (!$allowConsecDays) {
                if ($lastAssignedDayIndex[$pid] !== null && $lastAssignedDayIndex[$pid] === $dayIndex - 1) {
                    continue;
                }
            }

            // don't exceed targetMax with whole package unless ignoring max
            $packageSize = count($allowedSessions);
            if (!$ignoreMax && ($assignedCount[$pid] + $packageSize) > $targetMax) {
                continue;
            }

            // score: fewer afternoon increments preferred, then lower assignedCount
            $deltaAf = 0;
            foreach ($allowedSessions as $si) {
                if ($isAfternoon($day['sessions'][$si]['time'])) $deltaAf++;
            }

            $cands[] = [
                'pid' => $pid,
                'allowedSessions' => $allowedSessions,
                'deltaAfternoon' => $deltaAf,
                'packageSize' => $packageSize,
            ];
        }

        // sort by (deltaAfternoon ASC, assignedCount ASC) with random tie-break
        usort($cands, function($a, $b) use (&$assignedCount) {
            if ($a['deltaAfternoon'] === $b['deltaAfternoon']) {
                if ($assignedCount[$a['pid']] === $assignedCount[$b['pid']]) return mt_rand(-1,1);
                return $assignedCount[$a['pid']] <=> $assignedCount[$b['pid']];
            }
            return $a['deltaAfternoon'] <=> $b['deltaAfternoon'];
        });

        return $cands;
    };

    // Assignments container
    $assignmentsForOutput = [];
    
    // Track which proctors are already assigned to each session (to prevent duplicates)
    // Key: "exam_date|exam_time" => [proctor_id => true]
    $sessionAssignedProctors = [];

    // Phase A: package assignments per day
    foreach ($orderedDays as $dIndex => $_day) {
        // loop until no remaining slots or no candidate even with relax
        $safety = 0;
        while ($safety < 10000) {
            $safety++;
            // check if day finished
            $remaining = 0;
            foreach ($orderedDays[$dIndex]['sessions'] as $s) { $remaining += max(0, intval($s['remaining'])); }
            if ($remaining <= 0) break;

            // try strict
            $cands = $buildCandidatesForDay($dIndex, false, false);
            if (empty($cands)) {
                // allow consecutive days
                $cands = $buildCandidatesForDay($dIndex, true, false);
            }
            if (empty($cands)) {
                // ignore max to force-fill day
                $cands = $buildCandidatesForDay($dIndex, true, true);
            }
            if (empty($cands)) {
                break; // cannot place more in this day
            }

            $pick = $cands[0];
            $pid = $pick['pid'];
            $allowedSessions = $pick['allowedSessions'];
            if (empty($allowedSessions)) {
                // safety
                break;
            }

            // Assign one slot in each allowed session (chronological order already ensured)
            $assignedAnyInThisPackage = false;
            foreach ($allowedSessions as $si) {
                $sess =& $orderedDays[$dIndex]['sessions'][$si];
                if ($sess['remaining'] <= 0) continue;
                
                // Check if proctor already assigned to this session
                $sessionKey = $orderedDays[$dIndex]['date'] . '|' . $sess['time'];
                if (isset($sessionAssignedProctors[$sessionKey][$pid])) {
                    // Skip: proctor already assigned to this session
                    continue;
                }
                
                // assign
                $sess['remaining'] -= 1;
                $assignedCount[$pid]++;
                if ($isAfternoon($sess['time'])) $afternoonCount[$pid]++;
                
                // Mark proctor as assigned to this session BEFORE adding to output
                if (!isset($sessionAssignedProctors[$sessionKey])) {
                    $sessionAssignedProctors[$sessionKey] = [];
                }
                $sessionAssignedProctors[$sessionKey][$pid] = true;
                
                $assignmentsForOutput[] = [
                    'exam_date' => $orderedDays[$dIndex]['date'],
                    'exam_time' => $sess['time'],
                    'proctor_id' => $pid,
                    'proctor_name' => $proctorMap[$pid] ?? ''
                ];
                
                $assignedAnyInThisPackage = true;
            }
            // Only mark day as assigned if we actually assigned something
            if ($assignedAnyInThisPackage) {
                $lastAssignedDayIndex[$pid] = $dIndex;
            }
        }
    }

    // Phase B: exceptional fill for heavily restricted proctors — up to ceilMean
    // Build list of remaining slots across all days (morning preferred)
    $remainingSlots = [];
    foreach ($orderedDays as $dIndex => $day) {
        foreach ($day['sessions'] as $si => $s) {
            for ($k = 0; $k < max(0, intval($s['remaining'])); $k++) {
                $remainingSlots[] = [
                    'dayIndex' => $dIndex,
                    'date' => $day['date'],
                    'time' => $s['time'],
                    'isAfternoon' => $isAfternoon($s['time'])
                ];
            }
        }
    }

    // order: mornings first, then afternoons; within that, by dayIndex ascending
    usort($remainingSlots, function($a,$b){
        if ($a['isAfternoon'] === $b['isAfternoon']) {
            if ($a['dayIndex'] === $b['dayIndex']) return strcmp($a['time'], $b['time']);
            return $a['dayIndex'] <=> $b['dayIndex'];
        }
        // mornings first
        return $a['isAfternoon'] ? 1 : -1;
    });

    if (!empty($remainingSlots)) {
        // two passes: avoid consecutive days, then allow if still under ceilMean
        $passes = [false, true]; // false => avoid consecutive days
        foreach ($passes as $allowConsecDays) {
            foreach ($proctors as $p) {
                $pid = intval($p['id']);
                while ($assignedCount[$pid] < $ceilMean) {
                    $found = false;
                    foreach ($remainingSlots as $i => $slot) {
                        if ($slot === null) continue;
                        // restriction check
                        $key = $slot['date'] . '|' . $slot['time'];
                        if (isset($restrictions[$pid]) && isset($restrictions[$pid][$key])) continue;
                        
                        // Check if proctor already assigned to this session
                        if (isset($sessionAssignedProctors[$key][$pid])) continue;
                        
                        // day adjacency check
                        if (!$allowConsecDays) {
                            if ($lastAssignedDayIndex[$pid] !== null && $lastAssignedDayIndex[$pid] === ($slot['dayIndex'] - 1)) continue;
                        }
                        // assign
                        $assignedCount[$pid]++;
                        if ($slot['isAfternoon']) $afternoonCount[$pid]++;
                        
                        // Mark proctor as assigned to this session BEFORE adding to output
                        if (!isset($sessionAssignedProctors[$key])) {
                            $sessionAssignedProctors[$key] = [];
                        }
                        $sessionAssignedProctors[$key][$pid] = true;
                        
                        $assignmentsForOutput[] = [
                            'exam_date' => $slot['date'],
                            'exam_time' => $slot['time'],
                            'proctor_id' => $pid,
                            'proctor_name' => $proctorMap[$pid] ?? ''
                        ];
                        $lastAssignedDayIndex[$pid] = $slot['dayIndex'];
                        
                        // consume this remaining slot
                        $remainingSlots[$i] = null;
                        $found = true;
                        break;
                    }
                    if (!$found) break;
                }
            }
        }
        // compact remaining slots back to day/session remaining counters (for reporting unfilled)
        // reset remaining to 0 then recount
        foreach ($orderedDays as $dIndex => &$day) {
            foreach ($day['sessions'] as $si => &$s) { $s['remaining'] = 0; }
        }
        unset($day, $s);
        foreach ($remainingSlots as $slot) {
            if ($slot === null) continue;
            $d = $slot['dayIndex'];
            // find session by time
            foreach ($orderedDays[$d]['sessions'] as &$sess) {
                if ($sess['time'] === $slot['time']) { $sess['remaining']++; break; }
            }
            unset($sess);
        }
    }

    // ------------------------------------------------------------------
    // Balancing Pass: Afternoon distribution tightening (swap based)
    // Goal: bring all proctors within [afternoonFloor, afternoonCeil] if feasible
    // without changing total assignment counts (use morning<->afternoon swaps).
    // ------------------------------------------------------------------
    $afternoonMean = $afternoonTotalSlots / max(1,$numProctors);
    $afternoonFloor = (int)floor($afternoonMean);
    $afternoonCeil = (int)ceil($afternoonMean);

    // Build index of assignments per proctor for swap operations
    $proctorAssignments = [];
    foreach ($proctors as $p) { $proctorAssignments[intval($p['id'])] = []; }
    foreach ($assignmentsForOutput as $ai => $a) {
        $pid = $a['proctor_id'];
        if ($pid === null) continue;
        if (!isset($proctorAssignments[$pid])) $proctorAssignments[$pid] = [];
        $proctorAssignments[$pid][] = $ai;
    }

    // Reuse $sessionAssignedProctors as $sessionHasProctor for consistency
    $sessionHasProctor =& $sessionAssignedProctors;

    $loopGuard = 0;
    while ($loopGuard < 500) {
        $loopGuard++;
        // Collect deficits and surpluses
        $deficits = [];
        $surpluses = [];
        foreach ($proctors as $p) {
            $pid = intval($p['id']);
            $afc = $afternoonCount[$pid];
            if ($afc < $afternoonFloor) $deficits[] = $pid; elseif ($afc > $afternoonCeil) $surpluses[] = $pid;
        }
        if (empty($deficits) || empty($surpluses)) break;

        // sort: largest surplus first; largest deficit (lowest afternoon) first
        usort($surpluses, function($a,$b) use (&$afternoonCount){ return $afternoonCount[$b] <=> $afternoonCount[$a]; });
        usort($deficits, function($a,$b) use (&$afternoonCount){ return $afternoonCount[$a] <=> $afternoonCount[$b]; });

        $progress = false;
        foreach ($deficits as $rcvPid) {
            if ($afternoonCount[$rcvPid] >= $afternoonFloor) continue;
            foreach ($surpluses as $donPid) {
                if ($afternoonCount[$donPid] <= $afternoonCeil) continue;
                // Find donor afternoon assignment to swap
                $donAfternoonIdx = null;
                foreach ($proctorAssignments[$donPid] as $ai) {
                    $a = $assignmentsForOutput[$ai];
                    if ($isAfternoon($a['exam_time'])) {
                        // ensure recipient not already in this session
                        $k = $a['exam_date'] . '|' . $a['exam_time'];
                        if (isset($sessionHasProctor[$k][$rcvPid])) continue; // recipient already assigned here
                        // ensure recipient not restricted in this session
                        if (isset($restrictions[$rcvPid]) && isset($restrictions[$rcvPid][$k])) continue;
                        $donAfternoonIdx = $ai; break;
                    }
                }
                if ($donAfternoonIdx === null) continue;
                // Find recipient morning assignment to swap out (to keep totals stable)
                $rcvMorningIdx = null;
                foreach ($proctorAssignments[$rcvPid] as $ai) {
                    $a = $assignmentsForOutput[$ai];
                    if (!$isAfternoon($a['exam_time'])) {
                        // donor must not already be in that morning session & donor not restricted
                        $k = $a['exam_date'] . '|' . $a['exam_time'];
                        if (isset($sessionHasProctor[$k][$donPid])) continue;
                        if (isset($restrictions[$donPid]) && isset($restrictions[$donPid][$k])) continue;
                        $rcvMorningIdx = $ai; break;
                    }
                }
                if ($rcvMorningIdx === null) continue; // need both sides

                // Perform swap
                $aDon =& $assignmentsForOutput[$donAfternoonIdx];
                $aRcv =& $assignmentsForOutput[$rcvMorningIdx];

                $donAfK = $aDon['exam_date'] . '|' . $aDon['exam_time'];
                $rcvMoK = $aRcv['exam_date'] . '|' . $aRcv['exam_time'];

                // update sessionHasProctor maps
                unset($sessionHasProctor[$donAfK][$donPid]);
                $sessionHasProctor[$donAfK][$rcvPid] = true;
                unset($sessionHasProctor[$rcvMoK][$rcvPid]);
                $sessionHasProctor[$rcvMoK][$donPid] = true;

                // swap proctor ids/names
                $aDon['proctor_id'] = $rcvPid;
                $aDon['proctor_name'] = $proctorMap[$rcvPid] ?? '';
                $aRcv['proctor_id'] = $donPid;
                $aRcv['proctor_name'] = $proctorMap[$donPid] ?? '';

                // adjust afternoon counts (totals unchanged)
                $afternoonCount[$donPid]--;
                $afternoonCount[$rcvPid]++;

                // update assignment index lists
                foreach ($proctorAssignments[$donPid] as $k => $v) { if ($v === $donAfternoonIdx) { unset($proctorAssignments[$donPid][$k]); break; } }
                foreach ($proctorAssignments[$rcvPid] as $k => $v) { if ($v === $rcvMorningIdx) { unset($proctorAssignments[$rcvPid][$k]); break; } }
                $proctorAssignments[$donPid] = array_values($proctorAssignments[$donPid]);
                $proctorAssignments[$rcvPid] = array_values($proctorAssignments[$rcvPid]);
                $proctorAssignments[$donPid][] = $rcvMorningIdx; // donor got recipient morning
                $proctorAssignments[$rcvPid][] = $donAfternoonIdx; // recipient got donor afternoon

                $progress = true;
                break; // move to next recipient
            }
        }
        if (!$progress) break; // cannot improve further
    }

    // ------------------------------------------------------------------
    // Consolidation Pass: For a proctor with 2 sessions in a day (d2) and
    // 1 session in another day (d1), try to swap the single session into
    // the missing session of d2 so the proctor gets a full day. Swap keeps
    // totals stable by giving the donor of (d2, missing) the (d1, single).
    // Rules respected: restrictions, unique per-session assignment; prefer
    // donors that already have presence on d1 (not increasing their day count).
    // ------------------------------------------------------------------
    // Prepare helper maps
    $dayTimesByDate = [];
    foreach ($orderedDays as $d) {
        $times = [];
        foreach ($d['sessions'] as $s) $times[] = $s['time'];
        $dayTimesByDate[$d['date']] = $times;
    }

    $assignmentsBySession = [];
    foreach ($assignmentsForOutput as $ai => $a) {
        $k = $a['exam_date'] . '|' . $a['exam_time'];
        if (!isset($assignmentsBySession[$k])) $assignmentsBySession[$k] = [];
        $assignmentsBySession[$k][] = $ai;
    }

    // Build per-proctor day->times map
    $proctorDayTimes = [];
    foreach ($proctors as $p) { $proctorDayTimes[intval($p['id'])] = []; }
    foreach ($assignmentsForOutput as $ai => $a) {
        $pid = $a['proctor_id']; if ($pid === null) continue;
        $date = $a['exam_date']; $time = $a['exam_time'];
        if (!isset($proctorDayTimes[$pid][$date])) $proctorDayTimes[$pid][$date] = [];
        $proctorDayTimes[$pid][$date][$time] = true;
    }

    $swapMade = true; $iter = 0;
    while ($swapMade && $iter < 200) {
        $iter++; $swapMade = false;
        foreach ($proctors as $p) {
            $pid = intval($p['id']);
            $daysForPid = $proctorDayTimes[$pid] ?? [];
            if (empty($daysForPid)) continue;
            // gather d1 with exactly 1 session and d2 with partial (< full & >=1)
            $d1Dates = [];
            $d2Dates = [];
            foreach ($daysForPid as $date => $timesSet) {
                $count = count($timesSet);
                $full = count($dayTimesByDate[$date] ?? []);
                if ($count === 1) $d1Dates[] = $date;
                if ($count >= 1 && $count < $full) $d2Dates[] = $date;
            }
            if (empty($d1Dates) || empty($d2Dates)) continue;

            // iterate candidate pairs
            foreach ($d2Dates as $date2) {
                $fullTimes = $dayTimesByDate[$date2];
                $pidTimes2 = array_keys($daysForPid[$date2] ?? []);
                // find missing time(s) in date2
                $missingTimes = array_values(array_diff($fullTimes, $pidTimes2));
                if (empty($missingTimes)) continue;

                foreach ($d1Dates as $date1) {
                    // find pid's single time in date1
                    $pidTimes1 = array_keys($daysForPid[$date1] ?? []);
                    if (count($pidTimes1) !== 1) continue;
                    $time1 = $pidTimes1[0];

                    // ensure target not restricted on missing t2
                    foreach ($missingTimes as $time2) {
                        $k2 = $date2 . '|' . $time2;
                        if (isset($restrictions[$pid]) && isset($restrictions[$pid][$k2])) continue;

                        // donor candidates: proctors currently assigned to (date2,time2)
                        $donorIdxList = $assignmentsBySession[$k2] ?? [];
                        if (empty($donorIdxList)) continue;

                        // prefer donors who already have presence on date1
                        $orderedDonorIdx = $donorIdxList;
                        usort($orderedDonorIdx, function($aiA, $aiB) use (&$assignmentsForOutput, $date1, &$proctorDayTimes){
                            $dA = $assignmentsForOutput[$aiA]['proctor_id'];
                            $dB = $assignmentsForOutput[$aiB]['proctor_id'];
                            $hasA = isset($proctorDayTimes[$dA][$date1]);
                            $hasB = isset($proctorDayTimes[$dB][$date1]);
                            if ($hasA === $hasB) return 0; return $hasA ? -1 : 1;
                        });

                        // find pid's assignment index for (date1,time1)
                        $k1 = $date1 . '|' . $time1;
                        $pidIdx1 = null;
                        foreach ($assignmentsBySession[$k1] ?? [] as $ai) {
                            if ($assignmentsForOutput[$ai]['proctor_id'] === $pid) { $pidIdx1 = $ai; break; }
                        }
                        if ($pidIdx1 === null) continue;

                        foreach ($orderedDonorIdx as $donAi) {
                            $donPid = $assignmentsForOutput[$donAi]['proctor_id'];
                            if ($donPid === null || $donPid === $pid) continue;
                            // donor not restricted at (date1,time1)
                            if (isset($restrictions[$donPid]) && isset($restrictions[$donPid][$k1])) continue;
                            // donor not already in (date1,time1)
                            $already = false;
                            foreach ($assignmentsBySession[$k1] ?? [] as $ai2) {
                                if ($assignmentsForOutput[$ai2]['proctor_id'] === $donPid) { $already = true; break; }
                            }
                            if ($already) continue;

                            // perform swap: (donPid at date2,time2) <-> (pid at date1,time1)
                            $aDon =& $assignmentsForOutput[$donAi]; // currently (date2,time2)
                            $aPid =& $assignmentsForOutput[$pidIdx1]; // currently (date1,time1)

                            $isAf2 = $isAfternoon($aDon['exam_time']);
                            $isAf1 = $isAfternoon($aPid['exam_time']);

                            // update session->proctor set maps
                            // k2: remove donPid add pid
                            unset($sessionHasProctor[$k2][$donPid]);
                            $sessionHasProctor[$k2][$pid] = true;
                            // k1: remove pid add donPid
                            unset($sessionHasProctor[$k1][$pid]);
                            $sessionHasProctor[$k1][$donPid] = true;

                            // swap in assignmentsForOutput
                            $aDon['proctor_id'] = $pid; $aDon['proctor_name'] = $proctorMap[$pid] ?? '';
                            $aPid['proctor_id'] = $donPid; $aPid['proctor_name'] = $proctorMap[$donPid] ?? '';

                            // update proctorAssignments index lists
                            foreach ($proctorAssignments[$donPid] as $k => $v) { if ($v === $donAi) { unset($proctorAssignments[$donPid][$k]); break; } }
                            foreach ($proctorAssignments[$pid] as $k => $v) { if ($v === $pidIdx1) { unset($proctorAssignments[$pid][$k]); break; } }
                            $proctorAssignments[$donPid] = array_values($proctorAssignments[$donPid]);
                            $proctorAssignments[$pid] = array_values($proctorAssignments[$pid]);
                            $proctorAssignments[$donPid][] = $pidIdx1;
                            $proctorAssignments[$pid][] = $donAi;

                            // update afternoon counts
                            if ($isAf2) { $afternoonCount[$donPid]--; $afternoonCount[$pid]++; }
                            if ($isAf1) { $afternoonCount[$pid]--; $afternoonCount[$donPid]++; }

                            // update per-proctor day-times
                            // remove pid from (date1,time1), add (date2,time2)
                            unset($proctorDayTimes[$pid][$date1][$time1]);
                            if (empty($proctorDayTimes[$pid][$date1])) unset($proctorDayTimes[$pid][$date1]);
                            if (!isset($proctorDayTimes[$pid][$date2])) $proctorDayTimes[$pid][$date2] = [];
                            $proctorDayTimes[$pid][$date2][$time2] = true;
                            // donor: remove (date2,time2), add (date1,time1)
                            unset($proctorDayTimes[$donPid][$date2][$time2]);
                            if (empty($proctorDayTimes[$donPid][$date2])) unset($proctorDayTimes[$donPid][$date2]);
                            if (!isset($proctorDayTimes[$donPid][$date1])) $proctorDayTimes[$donPid][$date1] = [];
                            $proctorDayTimes[$donPid][$date1][$time1] = true;

                            // assignmentsBySession lists stay valid (same sessions), no index move needed

                            $swapMade = true;
                            break 3; // move to next proctor after one successful consolidation
                        }
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Fairness Normalization Pass (post-consolidation)
    // Ensures each proctor's total assignments lie within [floorMean, ceilMean].
    // Strategy:
    // 1. Recompute counts from current assignments (after previous swaps).
    // 2. Attempt whole-day transfers from overfull -> underfull where unrestricted.
    // 3. If whole-day transfer not possible, allow single-session reassignment.
    // 4. Keep totals within bounds and respect restrictions & uniqueness.
    // ------------------------------------------------------------------
    // Recompute counts fresh
    $assignedCount = []; $afternoonCount = []; $proctorDayTimes = [];
    foreach ($proctors as $p) { $pid = intval($p['id']); $assignedCount[$pid] = 0; $afternoonCount[$pid] = 0; $proctorDayTimes[$pid] = []; }
    foreach ($assignmentsForOutput as $ai => $a) {
        $pid = $a['proctor_id']; if ($pid === null) continue; $assignedCount[$pid]++;
        if ($isAfternoon($a['exam_time'])) $afternoonCount[$pid]++;
        $date = $a['exam_date']; $time = $a['exam_time'];
        if (!isset($proctorDayTimes[$pid][$date])) $proctorDayTimes[$pid][$date] = []; $proctorDayTimes[$pid][$date][$time] = true;
    }

    // Build helper: all sessions per date
    $allDaySessions = [];
    foreach ($orderedDays as $d) { $tlist = []; foreach ($d['sessions'] as $s) { $tlist[] = $s['time']; } $allDaySessions[$d['date']] = $tlist; }

    $fairLoop = 0; $fairProgress = true;
    while ($fairLoop < 300 && $fairProgress) {
        $fairLoop++; $fairProgress = false;
        $below = []; $above = [];
        foreach ($proctors as $p) {
            $pid = intval($p['id']); $tot = $assignedCount[$pid];
            if ($tot < $floorMean) $below[] = $pid; elseif ($tot > $ceilMean) $above[] = $pid;
        }
        if (empty($below) || empty($above)) break;
        // Order: largest surplus first, largest deficit first
        usort($above, function($a,$b) use (&$assignedCount){ return $assignedCount[$b] <=> $assignedCount[$a]; });
        usort($below, function($a,$b) use (&$assignedCount){ return $assignedCount[$a] <=> $assignedCount[$b]; });

        foreach ($below as $pidBelow) {
            if ($assignedCount[$pidBelow] >= $floorMean) continue;
            foreach ($above as $pidAbove) {
                if ($assignedCount[$pidAbove] <= $ceilMean) continue; // donor no longer above

                // Attempt whole-day transfer
                $donorDays = array_keys($proctorDayTimes[$pidAbove] ?? []);
                $shuffleWithRand($donorDays);
                $transferred = false;
                foreach ($donorDays as $dDate) {
                    $sessionsInDay = $allDaySessions[$dDate] ?? [];
                    $sessionCount = count($sessionsInDay);
                    if ($assignedCount[$pidBelow] + $sessionCount > $ceilMean) continue; // would exceed ceil
                    if ($assignedCount[$pidAbove] - $sessionCount < $floorMean) continue; // would drop below floor
                    // ensure below proctor has no presence that day (requires package transfer semantics)
                    if (isset($proctorDayTimes[$pidBelow][$dDate])) continue;
                    // Check restrictions across full day
                    $blocked = false;
                    foreach ($sessionsInDay as $t) {
                        $k = $dDate . '|' . $t;
                        if (isset($restrictions[$pidBelow]) && isset($restrictions[$pidBelow][$k])) { $blocked = true; break; }
                    }
                    if ($blocked) continue;
                    // Perform transfer: reassign all assignments in that day from donor to recipient
                    foreach ($assignmentsForOutput as $ai => &$rec) {
                        if ($rec['exam_date'] === $dDate && $rec['proctor_id'] === $pidAbove) {
                            $rec['proctor_id'] = $pidBelow; $rec['proctor_name'] = $proctorMap[$pidBelow] ?? '';
                            $assignedCount[$pidAbove]--; $assignedCount[$pidBelow]++;
                            if ($isAfternoon($rec['exam_time'])) { $afternoonCount[$pidAbove]--; $afternoonCount[$pidBelow]++; }
                        }
                    }
                    unset($rec);
                    // Update day maps
                    unset($proctorDayTimes[$pidAbove][$dDate]);
                    $proctorDayTimes[$pidBelow][$dDate] = [];
                    foreach ($sessionsInDay as $t) { $proctorDayTimes[$pidBelow][$dDate][$t] = true; }
                    $fairProgress = true; $transferred = true; break;
                }
                if ($transferred) break; // move to next below

                // Partial (single-session) transfer fallback if whole-day failed
                // Find donor session not restricted for recipient
                foreach ($assignmentsForOutput as $ai => &$rec2) {
                    if ($rec2['proctor_id'] !== $pidAbove) continue;
                    if ($assignedCount[$pidBelow] >= $floorMean) break; // reached floor
                    $k = $rec2['exam_date'] . '|' . $rec2['exam_time'];
                    if (isset($restrictions[$pidBelow]) && isset($restrictions[$pidBelow][$k])) continue;
                    // Transfer single session
                    $rec2['proctor_id'] = $pidBelow; $rec2['proctor_name'] = $proctorMap[$pidBelow] ?? '';
                    $assignedCount[$pidAbove]--; $assignedCount[$pidBelow]++;
                    if ($isAfternoon($rec2['exam_time'])) { $afternoonCount[$pidAbove]--; $afternoonCount[$pidBelow]++; }
                    // Update day-time maps
                    $dateX = $rec2['exam_date']; $timeX = $rec2['exam_time'];
                    if (!isset($proctorDayTimes[$pidBelow][$dateX])) $proctorDayTimes[$pidBelow][$dateX] = [];
                    $proctorDayTimes[$pidBelow][$dateX][$timeX] = true;
                    unset($proctorDayTimes[$pidAbove][$dateX][$timeX]); if (empty($proctorDayTimes[$pidAbove][$dateX])) unset($proctorDayTimes[$pidAbove][$dateX]);
                    $fairProgress = true;
                    // stop if donor now within bounds or recipient reached floor
                    if ($assignedCount[$pidBelow] >= $floorMean || $assignedCount[$pidAbove] <= $ceilMean) { unset($rec2); break; }
                }
                unset($rec2);
                if ($assignedCount[$pidBelow] >= $floorMean) break;
            }
        }
    }

    // After normalization, ensure no one outside bounds; (soft guarantee)
    // Second Afternoon Tightening Pass (narrow band: difference <= 2)
    // Rebuild proctorAssignments structure
    $proctorAssignments = []; foreach ($proctors as $p) { $proctorAssignments[intval($p['id'])] = []; }
    foreach ($assignmentsForOutput as $ai => $a) { $pid = $a['proctor_id']; if ($pid !== null) $proctorAssignments[$pid][] = $ai; }

    $sessionHasProctor = []; foreach ($assignmentsForOutput as $a) { $k = $a['exam_date'] . '|' . $a['exam_time']; if (!isset($sessionHasProctor[$k])) $sessionHasProctor[$k] = []; if ($a['proctor_id'] !== null) $sessionHasProctor[$k][$a['proctor_id']] = true; }

    $tightIter = 0;
    while ($tightIter < 400) {
        $tightIter++;
        // compute min/max afternoon counts
        $minAf = PHP_INT_MAX; $maxAf = -1; $pidMin = null; $pidMax = null;
        foreach ($proctors as $p) { $pid = intval($p['id']); $afc = $afternoonCount[$pid]; if ($afc < $minAf) { $minAf = $afc; $pidMin = $pid; } if ($afc > $maxAf) { $maxAf = $afc; $pidMax = $pid; } }
        if ($maxAf - $minAf <= 2) break; // band acceptable

        // find afternoon assignment of pidMax and morning assignment of pidMin
        $afternoonAi = null; foreach ($proctorAssignments[$pidMax] as $ai) { $a = $assignmentsForOutput[$ai]; if ($isAfternoon($a['exam_time'])) { $k = $a['exam_date'].'|'.$a['exam_time']; if (isset($sessionHasProctor[$k][$pidMin])) continue; if (isset($restrictions[$pidMin]) && isset($restrictions[$pidMin][$k])) continue; $afternoonAi = $ai; break; } }
        $morningAi = null; foreach ($proctorAssignments[$pidMin] as $ai) { $a = $assignmentsForOutput[$ai]; if (!$isAfternoon($a['exam_time'])) { $k = $a['exam_date'].'|'.$a['exam_time']; if (isset($sessionHasProctor[$k][$pidMax])) continue; if (isset($restrictions[$pidMax]) && isset($restrictions[$pidMax][$k])) continue; $morningAi = $ai; break; } }
        if ($afternoonAi === null || $morningAi === null) break; // cannot improve

        // perform swap
        $aAf =& $assignmentsForOutput[$afternoonAi];
        $aMo =& $assignmentsForOutput[$morningAi];
        $kAf = $aAf['exam_date'].'|'.$aAf['exam_time']; $kMo = $aMo['exam_date'].'|'.$aMo['exam_time'];
        unset($sessionHasProctor[$kAf][$pidMax]); $sessionHasProctor[$kAf][$pidMin] = true;
        unset($sessionHasProctor[$kMo][$pidMin]); $sessionHasProctor[$kMo][$pidMax] = true;
        $aAf['proctor_id'] = $pidMin; $aAf['proctor_name'] = $proctorMap[$pidMin] ?? '';
        $aMo['proctor_id'] = $pidMax; $aMo['proctor_name'] = $proctorMap[$pidMax] ?? '';
        // update afternoon counts
        $afternoonCount[$pidMax]--; $afternoonCount[$pidMin]++;
        // update assignment lists
        foreach ($proctorAssignments[$pidMax] as $k => $v) { if ($v === $afternoonAi) { unset($proctorAssignments[$pidMax][$k]); break; } }
        foreach ($proctorAssignments[$pidMin] as $k => $v) { if ($v === $morningAi) { unset($proctorAssignments[$pidMin][$k]); break; } }
        $proctorAssignments[$pidMax] = array_values($proctorAssignments[$pidMax]);
        $proctorAssignments[$pidMin] = array_values($proctorAssignments[$pidMin]);
        $proctorAssignments[$pidMax][] = $morningAi; $proctorAssignments[$pidMin][] = $afternoonAi;
        unset($aAf, $aMo);
    }

    // Deduplicate assignments (final safety check)
    $seen = [];
    $dedupedAssignments = [];
    foreach ($assignmentsForOutput as $a) {
        $key = $a['exam_date'] . '|' . $a['exam_time'] . '|' . $a['proctor_id'];
        if (isset($seen[$key])) {
            // Skip duplicate
            continue;
        }
        $seen[$key] = true;
        $dedupedAssignments[] = $a;
    }
    $assignmentsForOutput = $dedupedAssignments;
    
    // Recalculate final counts one last time for reporting integrity
    foreach ($proctors as $p) { $pid = intval($p['id']); $assignedCount[$pid] = 0; $afternoonCount[$pid] = 0; }
    foreach ($assignmentsForOutput as $a) { $pid = $a['proctor_id']; if ($pid === null) continue; $assignedCount[$pid]++; if ($isAfternoon($a['exam_time'])) $afternoonCount[$pid]++; }

    // Build per_proctor summary after all normalization phases
    $perProctor = [];
    foreach ($proctors as $p) {
        $pid = intval($p['id']);
        $perProctor[] = [
            'id' => $pid,
            'name' => $proctorMap[$pid] ?? (string)$pid,
            'total_assigned' => $assignedCount[$pid] ?? 0,
            'afternoon_assigned' => $afternoonCount[$pid] ?? 0
        ];
    }

    // Unfilled slots report
    $unfilledSlots = [];
    foreach ($orderedDays as $day) {
        foreach ($day['sessions'] as $s) {
            $remain = intval($s['remaining']);
            for ($k=0; $k<$remain; $k++) {
                // compute light candidate diagnostics similar to scattered
                $candCount = 0;
                foreach ($proctors as $p) {
                    $pid = intval($p['id']);
                    $key = $day['date'] . '|' . $s['time'];
                    if (isset($restrictions[$pid]) && isset($restrictions[$pid][$key])) continue;
                    if (($assignedCount[$pid] ?? 0) >= $targetMax) continue;
                    $candCount++;
                }
                $unfilledSlots[] = [
                    'exam_date' => $day['date'],
                    'exam_time' => $s['time'],
                    'candidate_count' => $candCount
                ];
            }
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
        'per_proctor' => $perProctor,
        'unfilled_slots' => $unfilledSlots,
        'assignments_preview' => $assignmentsForOutput
    ];

    // Apply to DB if requested
    if ($apply && !$dryRun) {
        try {
            // DDL operations must be outside transaction (implicit commit in MySQL)
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

            // unique session/proctor index (outside transaction)
            try {
                $idxStmt = $pdo->query("SHOW INDEX FROM `ExamAssignments` WHERE Key_name='uniq_session_proctor'");
                $hasIdx = $idxStmt && $idxStmt->fetch(PDO::FETCH_ASSOC);
                if (!$hasIdx) {
                    $pdo->exec("ALTER TABLE `ExamAssignments` ADD UNIQUE KEY `uniq_session_proctor` (`exam_date`,`exam_time`,`proctor_id`)");
                }
            } catch (Throwable $e) { /* ignore if index already exists */ }

            // Now perform data operations in transaction
            $pdo->beginTransaction();
            try {
                $pdo->exec("DELETE FROM `ExamAssignments`");
                $ins = $pdo->prepare('INSERT INTO `ExamAssignments` (exam_date, exam_time, proctor_id, proctor_name) VALUES (?, ?, ?, ?)');
                foreach ($assignmentsForOutput as $a) {
                    $ins->execute([$a['exam_date'], $a['exam_time'], $a['proctor_id'], $a['proctor_name']]);
                }
                $pdo->commit();
                $report['applied'] = true;
            } catch (Throwable $insertErr) {
                if ($pdo->inTransaction()) {
                    try { $pdo->rollBack(); } catch (Throwable $rbEx) {}
                }
                throw $insertErr; // re-throw to outer catch
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'db_write_failed', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $report['applied'] = false;
    }

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
