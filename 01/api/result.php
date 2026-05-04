<?php
/**
 * v3.0 결과 API
 * mysqlnd 없이 동작 (get_result()/fetch_all() 대신 bind_result() 사용)
 *
 * POST ?action=submit       → 결과 저장
 * GET  ?action=group        → 우리 반(세션) 통계
 * GET  ?action=national     → 전체 통계
 */

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

// ==================== 결과 제출 ====================
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $participantId = (int)($input['participant_id'] ?? 0);
    $activityId    = trim($input['activity_id'] ?? '');
    $groupId       = isset($input['group_id']) ? (int)$input['group_id'] : null;
    $resultData    = $input['result_data'] ?? null;

    if ($participantId <= 0 || $activityId === '' || $resultData === null) {
        jsonResponse(['error' => '필수 데이터가 누락되었습니다.'], 400);
    }

    $resultJson = json_encode($resultData, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        INSERT INTO activity_results (participant_id, activity_id, session_id, result_data)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('isis', $participantId, $activityId, $groupId, $resultJson);

    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'result_id' => $stmt->insert_id]);
    } else {
        jsonResponse(['error' => '결과 저장에 실패했습니다.'], 500);
    }
}

// ==================== 우리 반(세션) 통계 ====================
if ($action === 'group' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $groupId    = (int)($_GET['group_id'] ?? 0);
    $activityId = trim($_GET['activity_id'] ?? '');

    if ($groupId <= 0 || $activityId === '') {
        jsonResponse(['error' => '필수 파라미터가 누락되었습니다.'], 400);
    }

    // 참여 인원
    $stmt = $conn->prepare("SELECT COUNT(*) FROM participants WHERE session_id = ?");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $totalParticipants = 0;
    $stmt->bind_result($totalParticipants);
    $stmt->fetch();
    $stmt->close();

    // 제출 완료 인원
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT ar.participant_id)
        FROM activity_results ar
        WHERE ar.session_id = ? AND ar.activity_id = ?
    ");
    $stmt->bind_param('is', $groupId, $activityId);
    $stmt->execute();
    $submittedCount = 0;
    $stmt->bind_result($submittedCount);
    $stmt->fetch();
    $stmt->close();

    // ── MBTI 전용 처리 ──────────────────────────────────────
    if ($activityId === 'mbti_personality') {
        $allTypes = ['ISTJ','ISFJ','INFJ','INTJ','ISTP','ISFP','INFP','INTP',
                     'ESTP','ESFP','ENFP','ENTP','ESTJ','ESFJ','ENFJ','ENTJ'];
        $mbtiTypes = [];
        foreach ($allTypes as $t) {
            $mbtiTypes[$t] = ['count' => 0, 'members' => []];
        }

        // 닉네임과 결과를 함께 조회
        $stmt = $conn->prepare("
            SELECT p.nickname, ar.result_data
            FROM activity_results ar
            JOIN participants p ON p.id = ar.participant_id
            WHERE ar.session_id = ? AND ar.activity_id = ?
            ORDER BY ar.submitted_at ASC
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowNick = null; $rowRd = null;
        $stmt->bind_result($rowNick, $rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['mbtiCode']) && isset($mbtiTypes[$d['mbtiCode']])) {
                $mbtiTypes[$d['mbtiCode']]['count']++;
                $mbtiTypes[$d['mbtiCode']]['members'][] = $rowNick;
            }
        }
        $stmt->close();

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'mbti_types'         => $mbtiTypes
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 의사결정 유형 전용 처리 (그룹) ──────────────────────
    if ($activityId === 'decision_style') {
        $typeKeys = ['rational', 'intuitive', 'dependent'];
        $decisionTypes = [];
        foreach ($typeKeys as $t) {
            $decisionTypes[$t] = ['count' => 0, 'members' => []];
        }

        $stmt = $conn->prepare("
            SELECT p.nickname, ar.result_data
            FROM activity_results ar
            JOIN participants p ON p.id = ar.participant_id
            WHERE ar.session_id = ? AND ar.activity_id = ?
            ORDER BY ar.submitted_at ASC
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowNick = null; $rowRd = null;
        $stmt->bind_result($rowNick, $rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['topType']) && isset($decisionTypes[$d['topType']])) {
                $decisionTypes[$d['topType']]['count']++;
                $decisionTypes[$d['topType']]['members'][] = $rowNick;
            }
        }
        $stmt->close();

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'decision_types'     => $decisionTypes
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 적성 검사 전용 처리 ─────────────────────────────────
    if ($activityId === 'aptitude_check') {
        $areaKeys = ['body','hand','space','music','creative','lang','math','self','social','nature','art'];
        $areaSums = array_fill_keys($areaKeys, 0);
        $areaCount = 0;

        $stmt = $conn->prepare("
            SELECT result_data FROM activity_results
            WHERE session_id = ? AND activity_id = ?
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['scores'])) {
                foreach ($areaKeys as $key) {
                    $areaSums[$key] += isset($d['scores'][$key]) ? (float)$d['scores'][$key] : 0;
                }
                $areaCount++;
            }
        }
        $stmt->close();

        $areaAvg = [];
        foreach ($areaKeys as $key) {
            $areaAvg[$key] = $areaCount > 0 ? round($areaSums[$key] / $areaCount, 1) : 0;
        }

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'area_avg_scores'    => $areaAvg
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 직업 가치관 검사 전용 처리 ───────────────────────────
    if ($activityId === 'work_values') {
        $valueKeys = ['stability','salary','balance','joy','belong','growth',
                      'achieve','autonomy','challenge','influence','contribution','recognition'];
        $valueSums = array_fill_keys($valueKeys, 0);
        $valueCount = 0;

        $stmt = $conn->prepare("
            SELECT result_data FROM activity_results
            WHERE session_id = ? AND activity_id = ?
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['scores'])) {
                foreach ($valueKeys as $key) {
                    $valueSums[$key] += isset($d['scores'][$key]) ? (float)$d['scores'][$key] : 0;
                }
                $valueCount++;
            }
        }
        $stmt->close();

        $valueAvg = [];
        foreach ($valueKeys as $key) {
            $valueAvg[$key] = $valueCount > 0 ? round($valueSums[$key] / $valueCount, 1) : 0;
        }

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'value_avg_scores'   => $valueAvg
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 직업 흥미 유형 검사 전용 처리 ───────────────────────
    if ($activityId === 'holland_interest') {
        $typeKeys = ['R','I','A','S','E','C'];
        $typeDist  = array_fill_keys($typeKeys, 0);  // 1위 유형별 인원
        $typeSums  = array_fill_keys($typeKeys, 0);  // 점수 합계
        $typeCount = 0;

        $stmt = $conn->prepare("
            SELECT result_data FROM activity_results
            WHERE session_id = ? AND activity_id = ?
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if (!$d || !isset($d['scores'])) continue;
            // 점수 합산 (레이더용)
            foreach ($typeKeys as $k) {
                $typeSums[$k] += isset($d['scores'][$k]) ? (float)$d['scores'][$k] : 0;
            }
            $typeCount++;
            // 1위 유형 카운트 (분포용)
            if (isset($d['top2'][0]) && isset($typeDist[$d['top2'][0]])) {
                $typeDist[$d['top2'][0]]++;
            }
        }
        $stmt->close();

        $typeAvg = [];
        foreach ($typeKeys as $k) {
            $typeAvg[$k] = $typeCount > 0 ? round($typeSums[$k] / $typeCount, 1) : 0;
        }

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'type_distribution'  => $typeDist,
            'type_avg_scores'    => $typeAvg
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 직업 변화 스피드 퀴즈 전용 처리 ──────────────────────────────
    if ($activityId === 'job_change_quiz') {
        $areaKeys = ['ai','aging','env','global'];
        $areaStats = [];
        foreach ($areaKeys as $k) {
            $areaStats[$k] = ['correct' => 0, 'total' => 0];
        }
        $pctSum = 0;
        $count = 0;

        $stmt = $conn->prepare("
            SELECT result_data FROM activity_results
            WHERE session_id = ? AND activity_id = ?
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if (!$d) continue;
            $pctSum += isset($d['percentage']) ? (float)$d['percentage'] : 0;
            $count++;
            if (isset($d['areas'])) {
                foreach ($areaKeys as $k) {
                    if (isset($d['areas'][$k])) {
                        $areaStats[$k]['correct'] += (int)($d['areas'][$k]['correct'] ?? 0);
                        $areaStats[$k]['total']   += (int)($d['areas'][$k]['total'] ?? 0);
                    }
                }
            }
        }
        $stmt->close();

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'avg_percentage'     => $count > 0 ? round($pctSum / $count) : 0,
            'area_stats'         => $areaStats
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 드림팀 마스 전용 처리 ──────────────────────────────
    if ($activityId === 'dream_team_mars') {
        $traitKeys = ['cooperation','reliability','creativity','challenge','communication'];
        $traitSums = array_fill_keys($traitKeys, 0);
        $candidateCounts = [];  // candidate_id => count
        $count = 0;

        $stmt = $conn->prepare("
            SELECT result_data FROM activity_results
            WHERE session_id = ? AND activity_id = ?
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if (!$d) continue;
            $count++;
            // 특성 분포 집계
            if (isset($d['trait_scores'])) {
                foreach ($traitKeys as $k) {
                    $traitSums[$k] += isset($d['trait_scores'][$k]) ? (int)$d['trait_scores'][$k] : 0;
                }
            }
            // 인기 후보 집계 (최종 4명 기준)
            if (isset($d['phase2_selected']) && is_array($d['phase2_selected'])) {
                foreach ($d['phase2_selected'] as $cid) {
                    $cid = (int)$cid;
                    $candidateCounts[$cid] = ($candidateCounts[$cid] ?? 0) + 1;
                }
            }
        }
        $stmt->close();

        // 인기 후보 정렬
        arsort($candidateCounts);
        $popularCandidates = [];
        $i = 0;
        foreach ($candidateCounts as $cid => $cnt) {
            if ($i >= 5) break;
            $popularCandidates[] = ['candidate_id' => $cid, 'count' => $cnt];
            $i++;
        }

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'total'              => (int)$count,
            'popular_candidates' => $popularCandidates,
            'trait_distribution'  => $traitSums
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 나의 균형 찾기 전용 처리 (그룹) ──────────────────────
    if ($activityId === 'life_balance_check') {
        $balanceTypes = [];
        $scoreSums = ['work' => 0, 'study' => 0, 'leisure' => 0];
        $count = 0;

        $stmt = $conn->prepare("
            SELECT p.nickname, ar.result_data
            FROM activity_results ar
            JOIN participants p ON p.id = ar.participant_id
            WHERE ar.session_id = ? AND ar.activity_id = ?
            ORDER BY ar.submitted_at ASC
        ");
        $stmt->bind_param('is', $groupId, $activityId);
        $stmt->execute();
        $rowNick = null; $rowRd = null;
        $stmt->bind_result($rowNick, $rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if (!$d) continue;
            $count++;
            // 유형 분포 집계
            if (isset($d['type'])) {
                $typeName = $d['type'];
                if (!isset($balanceTypes[$typeName])) {
                    $balanceTypes[$typeName] = ['count' => 0, 'members' => []];
                }
                $balanceTypes[$typeName]['count']++;
                $balanceTypes[$typeName]['members'][] = $rowNick;
            }
            // 점수 합산
            if (isset($d['scores'])) {
                $scoreSums['work']    += (float)($d['scores']['work'] ?? 0);
                $scoreSums['study']   += (float)($d['scores']['study'] ?? 0);
                $scoreSums['leisure'] += (float)($d['scores']['leisure'] ?? 0);
            }
        }
        $stmt->close();

        $avgScores = [
            'work'    => $count > 0 ? round($scoreSums['work'] / $count, 1) : 0,
            'study'   => $count > 0 ? round($scoreSums['study'] / $count, 1) : 0,
            'leisure' => $count > 0 ? round($scoreSums['leisure'] / $count, 1) : 0
        ];

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'submitted_count'    => (int)$submittedCount,
            'balance_types'      => $balanceTypes,
            'avg_scores'         => $avgScores
        ]);
    }
    // ────────────────────────────────────────────────────────

    // 결과 데이터 가져오기 (기타 활동 공통)
    $stmt = $conn->prepare("
        SELECT ar.result_data
        FROM activity_results ar
        WHERE ar.session_id = ? AND ar.activity_id = ?
    ");
    $stmt->bind_param('is', $groupId, $activityId);
    $stmt->execute();
    $rowResultData = null;
    $stmt->bind_result($rowResultData);
    $results = [];
    while ($stmt->fetch()) {
        $results[] = $rowResultData;
    }
    $stmt->close();

    // 통계 계산
    $valueCounts = [];
    $jobCounts = [];
    $matchScoreSum = 0;
    $matchScoreCount = 0;

    foreach ($results as $rawJson) {
        $data = json_decode($rawJson, true);
        if (!$data) continue;

        if (isset($data['valueTop3']) && is_array($data['valueTop3'])) {
            foreach ($data['valueTop3'] as $valueId) {
                $valueCounts[$valueId] = ($valueCounts[$valueId] ?? 0) + 1;
            }
        }
        if (isset($data['matchScore'])) {
            $matchScoreSum += (float)$data['matchScore'];
            $matchScoreCount++;
        }
        if (isset($data['collectedJobs']) && is_array($data['collectedJobs'])) {
            foreach ($data['collectedJobs'] as $job) {
                $jobName = is_array($job) ? ($job['name'] ?? '') : (string)$job;
                if ($jobName !== '') {
                    $jobCounts[$jobName] = ($jobCounts[$jobName] ?? 0) + 1;
                }
            }
        }
    }

    arsort($valueCounts);
    $topValues = [];
    $i = 0;
    foreach ($valueCounts as $valueId => $count) {
        if ($i >= 3) break;
        $topValues[] = ['value_id' => $valueId, 'count' => $count];
        $i++;
    }

    arsort($jobCounts);
    $topJobs = [];
    $i = 0;
    foreach ($jobCounts as $jobName => $count) {
        if ($i >= 5) break;
        $topJobs[] = ['job_name' => $jobName, 'count' => $count];
        $i++;
    }

    jsonResponse([
        'total_participants' => (int)$totalParticipants,
        'submitted_count'    => (int)$submittedCount,
        'top_values'         => $topValues,
        'avg_match_score'    => $matchScoreCount > 0 ? $matchScoreSum / $matchScoreCount : 0,
        'top_jobs'           => $topJobs
    ]);
}

// ==================== 전체(전국) 통계 ====================
if ($action === 'national' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $activityId    = trim($_GET['activity_id'] ?? '');
    $participantId = (int)($_GET['participant_id'] ?? 0);

    if ($activityId === '') {
        jsonResponse(['error' => 'activity_id가 필요합니다.'], 400);
    }

    // 전체 참여자 수
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_results WHERE activity_id = ?");
    $stmt->bind_param('s', $activityId);
    $stmt->execute();
    $totalParticipants = 0;
    $stmt->bind_result($totalParticipants);
    $stmt->fetch();
    $stmt->close();

    // ── MBTI 전용 처리 ──────────────────────────────────────
    if ($activityId === 'mbti_personality') {
        $allTypes = ['ISTJ','ISFJ','INFJ','INTJ','ISTP','ISFP','INFP','INTP',
                     'ESTP','ESFP','ENFP','ENTP','ESTJ','ESFJ','ENFJ','ENTJ'];
        $mbtiTypes = array_fill_keys($allTypes, 0);

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['mbtiCode']) && isset($mbtiTypes[$d['mbtiCode']])) {
                $mbtiTypes[$d['mbtiCode']]++;
            }
        }
        $stmt->close();

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'mbti_types'         => $mbtiTypes
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 의사결정 유형 전용 처리 (전체) ──────────────────────
    if ($activityId === 'decision_style') {
        $typeKeys = ['rational', 'intuitive', 'dependent'];
        $decisionTypes = array_fill_keys($typeKeys, 0);

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['topType']) && isset($decisionTypes[$d['topType']])) {
                $decisionTypes[$d['topType']]++;
            }
        }
        $stmt->close();

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'decision_types'     => $decisionTypes
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 적성 검사 전용 처리 ─────────────────────────────────
    if ($activityId === 'aptitude_check') {
        $areaKeys = ['body','hand','space','music','creative','lang','math','self','social','nature','art'];
        $top1Counts = array_fill_keys($areaKeys, 0);

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['top3'][0]) && isset($top1Counts[$d['top3'][0]])) {
                $top1Counts[$d['top3'][0]]++;
            }
        }
        $stmt->close();

        arsort($top1Counts);

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'area_top1_counts'   => $top1Counts
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 직업 가치관 검사 전용 처리 ───────────────────────────
    if ($activityId === 'work_values') {
        $valueKeys = ['stability','salary','balance','joy','belong','growth',
                      'achieve','autonomy','challenge','influence','contribution','recognition'];
        $top1Counts = array_fill_keys($valueKeys, 0);

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['top3'][0]) && isset($top1Counts[$d['top3'][0]])) {
                $top1Counts[$d['top3'][0]]++;
            }
        }
        $stmt->close();

        arsort($top1Counts);

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'value_top1_counts'  => $top1Counts
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 직업 흥미 유형 검사 전용 처리 ───────────────────────
    if ($activityId === 'holland_interest') {
        $typeKeys = ['R','I','A','S','E','C'];
        $typeDist = array_fill_keys($typeKeys, 0);

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if ($d && isset($d['top2'][0]) && isset($typeDist[$d['top2'][0]])) {
                $typeDist[$d['top2'][0]]++;
            }
        }
        $stmt->close();

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'type_distribution'  => $typeDist
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 직업 변화 스피드 퀴즈 전용 처리 ──────────────────────────────
    if ($activityId === 'job_change_quiz') {
        $areaKeys = ['ai','aging','env','global'];
        $areaStats = [];
        foreach ($areaKeys as $k) {
            $areaStats[$k] = ['correct' => 0, 'total' => 0];
        }
        $pctSum = 0;
        $count = 0;

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if (!$d) continue;
            $pctSum += isset($d['percentage']) ? (float)$d['percentage'] : 0;
            $count++;
            if (isset($d['areas'])) {
                foreach ($areaKeys as $k) {
                    if (isset($d['areas'][$k])) {
                        $areaStats[$k]['correct'] += (int)($d['areas'][$k]['correct'] ?? 0);
                        $areaStats[$k]['total']   += (int)($d['areas'][$k]['total'] ?? 0);
                    }
                }
            }
        }
        $stmt->close();

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'avg_percentage'     => $count > 0 ? round($pctSum / $count) : 0,
            'area_stats'         => $areaStats
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 드림팀 마스 전용 처리 (전체) ──────────────────────
    if ($activityId === 'dream_team_mars') {
        $traitKeys = ['cooperation','reliability','creativity','challenge','communication'];
        $traitSums = array_fill_keys($traitKeys, 0);
        $candidateCounts = [];
        $count = 0;

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if (!$d) continue;
            $count++;
            if (isset($d['trait_scores'])) {
                foreach ($traitKeys as $k) {
                    $traitSums[$k] += isset($d['trait_scores'][$k]) ? (int)$d['trait_scores'][$k] : 0;
                }
            }
            if (isset($d['phase2_selected']) && is_array($d['phase2_selected'])) {
                foreach ($d['phase2_selected'] as $cid) {
                    $cid = (int)$cid;
                    $candidateCounts[$cid] = ($candidateCounts[$cid] ?? 0) + 1;
                }
            }
        }
        $stmt->close();

        arsort($candidateCounts);
        $popularCandidates = [];
        $i = 0;
        foreach ($candidateCounts as $cid => $cnt) {
            if ($i >= 5) break;
            $popularCandidates[] = ['candidate_id' => $cid, 'count' => $cnt];
            $i++;
        }

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'total'              => (int)$count,
            'popular_candidates' => $popularCandidates,
            'trait_distribution'  => $traitSums
        ]);
    }
    // ────────────────────────────────────────────────────────

    // ── 나의 균형 찾기 전용 처리 (전체) ──────────────────────
    if ($activityId === 'life_balance_check') {
        $balanceTypes = [];
        $scoreSums = ['work' => 0, 'study' => 0, 'leisure' => 0];
        $count = 0;

        $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
        $stmt->bind_param('s', $activityId);
        $stmt->execute();
        $rowRd = null;
        $stmt->bind_result($rowRd);
        while ($stmt->fetch()) {
            $d = json_decode($rowRd, true);
            if (!$d) continue;
            $count++;
            if (isset($d['type'])) {
                $typeName = $d['type'];
                $balanceTypes[$typeName] = ($balanceTypes[$typeName] ?? 0) + 1;
            }
            if (isset($d['scores'])) {
                $scoreSums['work']    += (float)($d['scores']['work'] ?? 0);
                $scoreSums['study']   += (float)($d['scores']['study'] ?? 0);
                $scoreSums['leisure'] += (float)($d['scores']['leisure'] ?? 0);
            }
        }
        $stmt->close();

        $avgScores = [
            'work'    => $count > 0 ? round($scoreSums['work'] / $count, 1) : 0,
            'study'   => $count > 0 ? round($scoreSums['study'] / $count, 1) : 0,
            'leisure' => $count > 0 ? round($scoreSums['leisure'] / $count, 1) : 0
        ];

        jsonResponse([
            'total_participants' => (int)$totalParticipants,
            'balance_types'      => $balanceTypes,
            'avg_scores'         => $avgScores
        ]);
    }
    // ────────────────────────────────────────────────────────

    // 전체 결과 데이터 (기타 활동 공통)
    $stmt = $conn->prepare("SELECT result_data FROM activity_results WHERE activity_id = ?");
    $stmt->bind_param('s', $activityId);
    $stmt->execute();
    $rowResultData = null;
    $stmt->bind_result($rowResultData);
    $results = [];
    while ($stmt->fetch()) {
        $results[] = $rowResultData;
    }
    $stmt->close();

    $valueCounts = [];
    $jobCounts = [];
    $matchScoreSum = 0;
    $matchScoreCount = 0;

    foreach ($results as $rawJson) {
        $data = json_decode($rawJson, true);
        if (!$data) continue;

        if (isset($data['valueTop3']) && is_array($data['valueTop3'])) {
            foreach ($data['valueTop3'] as $valueId) {
                $valueCounts[$valueId] = ($valueCounts[$valueId] ?? 0) + 1;
            }
        }
        if (isset($data['matchScore'])) {
            $matchScoreSum += (float)$data['matchScore'];
            $matchScoreCount++;
        }
        if (isset($data['collectedJobs']) && is_array($data['collectedJobs'])) {
            foreach ($data['collectedJobs'] as $job) {
                $jobName = is_array($job) ? ($job['name'] ?? '') : (string)$job;
                if ($jobName !== '') {
                    $jobCounts[$jobName] = ($jobCounts[$jobName] ?? 0) + 1;
                }
            }
        }
    }

    arsort($valueCounts);
    $nationalValues = [];
    $i = 0;
    foreach ($valueCounts as $valueId => $count) {
        if ($i >= 6) break;
        $nationalValues[] = ['value_id' => $valueId, 'count' => $count];
        $i++;
    }

    arsort($jobCounts);
    $topJobs = [];
    $i = 0;
    foreach ($jobCounts as $jobName => $count) {
        if ($i >= 5) break;
        $topJobs[] = ['job_name' => $jobName, 'count' => $count];
        $i++;
    }

    // 내 가치관 전국 순위
    $myValueRank = null;
    if ($participantId > 0) {
        $stmt = $conn->prepare("
            SELECT result_data FROM activity_results
            WHERE participant_id = ? AND activity_id = ?
            ORDER BY submitted_at DESC LIMIT 1
        ");
        $stmt->bind_param('is', $participantId, $activityId);
        $stmt->execute();
        $myResultJson = null;
        $stmt->bind_result($myResultJson);
        $stmt->fetch();
        $stmt->close();

        if ($myResultJson) {
            $myData = json_decode($myResultJson, true);
            if (isset($myData['valueTop3'][0])) {
                $myTopValue = $myData['valueTop3'][0];
                $rank = 1;
                foreach ($valueCounts as $valueId => $count) {
                    if ($valueId === $myTopValue) {
                        $myValueRank = $rank;
                        break;
                    }
                    $rank++;
                }
            }
        }
    }

    jsonResponse([
        'total_participants' => (int)$totalParticipants,
        'national_values'    => $nationalValues,
        'avg_match_score'    => $matchScoreCount > 0 ? $matchScoreSum / $matchScoreCount : 0,
        'top_jobs'           => $topJobs,
        'my_value_rank'      => $myValueRank
    ]);
}

jsonResponse(['error' => '잘못된 요청입니다.'], 400);
?>
