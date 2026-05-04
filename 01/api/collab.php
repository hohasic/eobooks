<?php
/**
 * 모둠 활동 API (v1.0)
 *
 * POST ?action=create        → 방 생성 (선생님)
 * POST ?action=join          → 방 참여 (학생)
 * GET  ?action=status        → 방 상태 + 참여자 목록 + 진행률
 * POST ?action=start         → 활동 시작 (방장만)
 * POST ?action=next_phase    → 다음 단계 (방장만)
 * POST ?action=submit_phase  → 단계 데이터 제출
 * GET  ?action=phase_data    → 단계 데이터 조회
 * POST ?action=assign_targets→ 평가 대상 배정 (방장, Phase2 진입 시)
 * POST ?action=complete      → 활동 종료 (방장만)
 */

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

// ================================================================
//  방 생성 (선생님)
// ================================================================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $roomCode      = trim($input['room_code'] ?? '');
    $school        = trim($input['school'] ?? '');
    $activityId    = trim($input['activity_id'] ?? '');
    $expectedCount = (int)($input['expected_count'] ?? 4);
    $minCount      = (int)($input['min_count'] ?? 3);
    $context       = $input['context'] ?? null;

    if ($roomCode === '' || $school === '' || $activityId === '') {
        jsonResponse(['error' => '방 코드, 학교명, 활동 ID는 필수입니다.'], 400);
    }
    if (mb_strlen($roomCode) > 12) {
        jsonResponse(['error' => '방 코드는 12자 이하로 입력해 주세요.'], 400);
    }
    if ($expectedCount < 2 || $expectedCount > 50) {
        jsonResponse(['error' => '인원 수는 2~50명 사이로 입력해 주세요.'], 400);
    }

    // 같은 조합으로 활성 방이 있는지 확인
    $stmt = $conn->prepare("
        SELECT id FROM collab_rooms
        WHERE room_code = ? AND school = ? AND activity_id = ? AND status IN ('waiting','active')
    ");
    $stmt->bind_param('sss', $roomCode, $school, $activityId);
    $stmt->execute();
    $existing = null;
    $stmt->bind_result($existing);
    $stmt->fetch();
    $stmt->close();

    if ($existing) {
        jsonResponse(['error' => '이미 같은 코드의 활성 방이 있습니다. 다른 코드를 사용해 주세요.'], 409);
    }

    // 방 생성
    $stmt = $conn->prepare("
        INSERT INTO collab_rooms (room_code, school, activity_id, expected_count, min_count, context)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('sssiis', $roomCode, $school, $activityId, $expectedCount, $minCount, $context);
    if (!$stmt->execute()) {
        jsonResponse(['error' => '방 생성에 실패했습니다.'], 500);
    }
    $roomId = $stmt->insert_id;
    $stmt->close();

    // 방장 참여자 등록 (닉네임 "선생님", is_host=1)
    $hostNick = '선생님';
    $isHost = 1;
    $stmt = $conn->prepare("
        INSERT INTO collab_participants (room_id, nickname, is_host) VALUES (?, ?, ?)
    ");
    $stmt->bind_param('isi', $roomId, $hostNick, $isHost);
    $stmt->execute();
    $hostId = $stmt->insert_id;
    $stmt->close();

    jsonResponse(['room_id' => $roomId, 'participant_id' => $hostId], 201);
}

// ================================================================
//  방 참여 (학생)
// ================================================================
if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $directRoomId = (int)($input['room_id'] ?? 0);  // QR 경유: room_id 직접 지정
    $roomCode   = trim($input['room_code'] ?? '');
    $school     = trim($input['school'] ?? '');
    $activityId = trim($input['activity_id'] ?? '');
    $nickname   = trim($input['nickname'] ?? '');

    if ($directRoomId <= 0 && $roomCode === '') {
        jsonResponse(['error' => '방 코드를 입력해 주세요.'], 400);
    }
    if ($nickname === '') {
        jsonResponse(['error' => '별명을 입력해 주세요.'], 400);
    }
    if (mb_strlen($nickname) > 12) {
        jsonResponse(['error' => '별명은 12자 이하로 입력해 주세요.'], 400);
    }

    $roomId = null; $expCount = 0; $roomSchool = ''; $roomCode2 = '';

    if ($directRoomId > 0) {
        // QR 경유: room_id로 직접 방 찾기 (겹침 문제 없음)
        $stmt = $conn->prepare("
            SELECT id, expected_count, school, room_code FROM collab_rooms
            WHERE id = ? AND status = 'waiting'
        ");
        $stmt->bind_param('i', $directRoomId);
        $stmt->execute();
        $stmt->bind_result($roomId, $expCount, $roomSchool, $roomCode2);
        $stmt->fetch();
        $stmt->close();
    } else {
        // 수동 입력: room_code + school로 검색 (같은 코드라도 학교가 다르면 다른 방)
        if ($school === '') {
            jsonResponse(['error' => '학교명을 입력해 주세요.'], 400);
        }
        $stmt = $conn->prepare("
            SELECT id, expected_count, school, room_code FROM collab_rooms
            WHERE room_code = ? AND school = ? AND activity_id = ? AND status = 'waiting'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->bind_param('sss', $roomCode, $school, $activityId);
        $stmt->execute();
        $stmt->bind_result($roomId, $expCount, $roomSchool, $roomCode2);
        $stmt->fetch();
        $stmt->close();
    }

    if (!$roomId) {
        jsonResponse(['error' => '방을 찾을 수 없거나 이미 시작된 활동입니다.'], 404);
    }

    // 현재 학생 참여자 수 확인 (방장 제외)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM collab_participants WHERE room_id = ? AND is_host = 0");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $currentStudents = 0;
    $stmt->bind_result($currentStudents);
    $stmt->fetch();
    $stmt->close();

    if ($currentStudents >= $expCount) {
        jsonResponse(['error' => '인원이 가득 찼습니다.'], 400);
    }

    // 닉네임 중복 확인 + 등록
    $isHost = 0;
    $stmt = $conn->prepare("
        INSERT INTO collab_participants (room_id, nickname, is_host) VALUES (?, ?, ?)
    ");
    $stmt->bind_param('isi', $roomId, $nickname, $isHost);

    if (!$stmt->execute()) {
        if ($conn->errno === 1062) { // Duplicate entry
            jsonResponse(['error' => '이미 사용 중인 별명입니다. 다른 별명을 입력해 주세요.'], 409);
        }
        jsonResponse(['error' => '참여 등록에 실패했습니다.'], 500);
    }
    $participantId = $stmt->insert_id;
    $stmt->close();

    jsonResponse([
        'room_id' => $roomId,
        'participant_id' => $participantId,
        'school' => $roomSchool,
        'room_code' => $roomCode2
    ], 201);
}

// ================================================================
//  방 상태 조회 (폴링)
// ================================================================
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    if ($roomId <= 0) jsonResponse(['error' => 'room_id 필수'], 400);

    // 방 정보
    $stmt = $conn->prepare("
        SELECT status, current_phase, expected_count, min_count, room_code, school
        FROM collab_rooms WHERE id = ?
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $status = ''; $phase = 0; $expCount = 0; $minCnt = 0; $rCode = ''; $rSchool = '';
    $stmt->bind_result($status, $phase, $expCount, $minCnt, $rCode, $rSchool);
    if (!$stmt->fetch()) {
        $stmt->close();
        jsonResponse(['error' => '방을 찾을 수 없습니다.'], 404);
    }
    $stmt->close();

    // 참여자 목록
    $stmt = $conn->prepare("
        SELECT id, nickname, is_host FROM collab_participants WHERE room_id = ? ORDER BY joined_at
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $pid = 0; $pnick = ''; $phost = 0;
    $stmt->bind_result($pid, $pnick, $phost);
    $participants = [];
    while ($stmt->fetch()) {
        $participants[] = ['id' => $pid, 'nickname' => $pnick, 'is_host' => $phost];
    }
    $stmt->close();

    // 진행률 (활동 중일 때만 의미 있음)
    $phaseProgress = null;
    if ($status === 'active' && $phase > 0) {
        // 방장 제외 참여자 수
        $studentCount = 0;
        foreach ($participants as $p) {
            if (!$p['is_host']) $studentCount++;
        }
        // 현재 단계 완료 수
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM collab_phase_data
            WHERE room_id = ? AND phase = ?
              AND participant_id IN (
                  SELECT id FROM collab_participants WHERE room_id = ? AND is_host = 0
              )
        ");
        $stmt->bind_param('iii', $roomId, $phase, $roomId);
        $stmt->execute();
        $completedCount = 0;
        $stmt->bind_result($completedCount);
        $stmt->fetch();
        $stmt->close();

        $phaseProgress = [
            'completed' => $completedCount,
            'total' => $studentCount
        ];
    }

    jsonResponse([
        'status' => $status,
        'current_phase' => $phase,
        'expected_count' => $expCount,
        'min_count' => $minCnt,
        'room_code' => $rCode,
        'school' => $rSchool,
        'participants' => $participants,
        'phase_progress' => $phaseProgress
    ]);
}

// ================================================================
//  활동 시작 (방장만)
// ================================================================
if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = (int)($input['room_id'] ?? 0);
    $participantId = (int)($input['participant_id'] ?? 0);

    if (!isHost($conn, $roomId, $participantId)) {
        jsonResponse(['error' => '방장만 활동을 시작할 수 있습니다.'], 403);
    }

    $stmt = $conn->prepare("
        UPDATE collab_rooms SET status = 'active', current_phase = 1, started_at = NOW()
        WHERE id = ? AND status = 'waiting'
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        jsonResponse(['error' => '이미 시작되었거나 존재하지 않는 방입니다.'], 400);
    }
    $stmt->close();

    jsonResponse(['success' => true, 'current_phase' => 1]);
}

// ================================================================
//  다음 단계 (방장만)
// ================================================================
if ($action === 'next_phase' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = (int)($input['room_id'] ?? 0);
    $participantId = (int)($input['participant_id'] ?? 0);

    if (!isHost($conn, $roomId, $participantId)) {
        jsonResponse(['error' => '방장만 단계를 전환할 수 있습니다.'], 403);
    }

    $stmt = $conn->prepare("
        UPDATE collab_rooms SET current_phase = current_phase + 1
        WHERE id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $stmt->close();

    // 새 단계 번호 조회
    $stmt = $conn->prepare("SELECT current_phase FROM collab_rooms WHERE id = ?");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $newPhase = 0;
    $stmt->bind_result($newPhase);
    $stmt->fetch();
    $stmt->close();

    jsonResponse(['success' => true, 'new_phase' => $newPhase]);
}

// ================================================================
//  단계 데이터 제출
// ================================================================
if ($action === 'submit_phase' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId        = (int)($input['room_id'] ?? 0);
    $participantId = (int)($input['participant_id'] ?? 0);
    $phase         = (int)($input['phase'] ?? 0);
    $phaseData     = $input['phase_data'] ?? null;

    if ($roomId <= 0 || $participantId <= 0 || $phase <= 0 || $phaseData === null) {
        jsonResponse(['error' => '필수 데이터가 누락되었습니다.'], 400);
    }

    $dataJson = json_encode($phaseData, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        INSERT INTO collab_phase_data (room_id, participant_id, phase, phase_data)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE phase_data = VALUES(phase_data), completed_at = NOW()
    ");
    $stmt->bind_param('iiis', $roomId, $participantId, $phase, $dataJson);

    if ($stmt->execute()) {
        jsonResponse(['success' => true]);
    } else {
        jsonResponse(['error' => '데이터 저장에 실패했습니다.'], 500);
    }
}

// ================================================================
//  단계 데이터 조회
// ================================================================
if ($action === 'phase_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $phase  = (int)($_GET['phase'] ?? 0);

    if ($roomId <= 0 || $phase <= 0) {
        jsonResponse(['error' => 'room_id와 phase는 필수입니다.'], 400);
    }

    $stmt = $conn->prepare("
        SELECT cpd.participant_id, cp.nickname, cp.is_host, cpd.phase_data
        FROM collab_phase_data cpd
        JOIN collab_participants cp ON cp.id = cpd.participant_id
        WHERE cpd.room_id = ? AND cpd.phase = ?
        ORDER BY cp.joined_at
    ");
    $stmt->bind_param('ii', $roomId, $phase);
    $stmt->execute();
    $pId = 0; $pNick = ''; $pHost = 0; $pData = '';
    $stmt->bind_result($pId, $pNick, $pHost, $pData);

    $data = [];
    while ($stmt->fetch()) {
        $data[] = [
            'participant_id' => $pId,
            'nickname' => $pNick,
            'is_host' => $pHost,
            'phase_data' => json_decode($pData, true)
        ];
    }
    $stmt->close();

    jsonResponse(['data' => $data]);
}

// ================================================================
//  평가 대상 배정 (방장이 Phase 2 진입 시 호출)
//  원형 배정: 참여자 목록 셔플 후 각자 다음 K명 배정
// ================================================================
if ($action === 'assign_targets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = (int)($input['room_id'] ?? 0);
    $participantId = (int)($input['participant_id'] ?? 0);
    $maxTargets = (int)($input['max_targets'] ?? 5);

    // 배정은 결정적 셔플이므로 누가 호출해도 동일 결과.
    // 방장이 Phase 2 진입 시 최초 호출하고, 학생도 자신의 배정 확인용으로 호출함.
    // → isHost 체크 제거

    // 학생 참여자만 조회 (방장 제외)
    $stmt = $conn->prepare("
        SELECT id, nickname FROM collab_participants
        WHERE room_id = ? AND is_host = 0 ORDER BY id
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $sid = 0; $snick = '';
    $stmt->bind_result($sid, $snick);
    $students = [];
    while ($stmt->fetch()) {
        $students[] = ['id' => $sid, 'nickname' => $snick];
    }
    $stmt->close();

    $n = count($students);
    if ($n < 2) {
        jsonResponse(['error' => '평가하려면 최소 2명의 학생이 필요합니다.'], 400);
    }

    // K = min(maxTargets, n-1)
    $k = min($maxTargets, $n - 1);

    // 셔플 (시드 고정: room_id 기반, 동일 요청 시 동일 결과)
    $ids = array_map(function($s) { return $s['id']; }, $students);
    mt_srand($roomId * 1000 + $n); // 결정적 셔플
    $shuffled = $ids;
    for ($i = $n - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $tmp = $shuffled[$i];
        $shuffled[$i] = $shuffled[$j];
        $shuffled[$j] = $tmp;
    }

    // 원형 배정: 각 참여자에게 다음 K명 배정
    $assignments = [];
    $nickMap = [];
    foreach ($students as $s) {
        $nickMap[$s['id']] = $s['nickname'];
    }

    for ($i = 0; $i < $n; $i++) {
        $fromId = $shuffled[$i];
        $targets = [];
        for ($j = 1; $j <= $k; $j++) {
            $targetIdx = ($i + $j) % $n;
            $targetId = $shuffled[$targetIdx];
            $targets[] = ['id' => $targetId, 'nickname' => $nickMap[$targetId]];
        }
        $assignments[] = [
            'participant_id' => $fromId,
            'targets' => $targets
        ];
    }

    jsonResponse(['assignments' => $assignments, 'targets_per_person' => $k]);
}

// ================================================================
//  활동 종료 (방장만)
// ================================================================
if ($action === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = (int)($input['room_id'] ?? 0);
    $participantId = (int)($input['participant_id'] ?? 0);

    if (!isHost($conn, $roomId, $participantId)) {
        jsonResponse(['error' => '방장만 활동을 종료할 수 있습니다.'], 403);
    }

    $stmt = $conn->prepare("
        UPDATE collab_rooms SET status = 'completed', completed_at = NOW()
        WHERE id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => true]);
}

// ================================================================
//  전체 통계 (활동별 누적)
//  GET ?action=global_stats&activity_id=strength_intro
// ================================================================
if ($action === 'global_stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $activityId = trim($_GET['activity_id'] ?? '');
    if ($activityId === '') {
        jsonResponse(['error' => 'activity_id 필수'], 400);
    }

    // 1) 완료된 방 목록 (active 또는 completed, phase >= 3)
    $stmt = $conn->prepare("
        SELECT id FROM collab_rooms
        WHERE activity_id = ? AND status IN ('active','completed') AND current_phase >= 3
    ");
    $stmt->bind_param('s', $activityId);
    $stmt->execute();
    $rid = 0;
    $stmt->bind_result($rid);
    $roomIds = [];
    while ($stmt->fetch()) { $roomIds[] = $rid; }
    $stmt->close();

    if (count($roomIds) === 0) {
        jsonResponse([
            'total_participants' => 0,
            'total_rooms' => 0,
            'avg_match_rate' => 0,
            'top_strengths' => [],
            'top_blind' => []
        ]);
    }

    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    $types = str_repeat('i', count($roomIds));

    // 2) 총 학생 참여자 수
    $sql = "SELECT COUNT(*) FROM collab_participants WHERE room_id IN ($placeholders) AND is_host = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$roomIds);
    $stmt->execute();
    $totalParticipants = 0;
    $stmt->bind_result($totalParticipants);
    $stmt->fetch();
    $stmt->close();

    // 3) Phase 1 데이터 (자기 선택) + Phase 2 데이터 (친구 평가) 전부 가져오기
    $doubleIds = array_merge($roomIds, $roomIds);
    $doubleTypes = str_repeat('i', count($doubleIds));

    $sql = "
        SELECT cpd.room_id, cpd.participant_id, cpd.phase, cpd.phase_data, cp.is_host
        FROM collab_phase_data cpd
        JOIN collab_participants cp ON cp.id = cpd.participant_id
        WHERE cpd.room_id IN ($placeholders)
          AND cpd.phase IN (1, 2)
          AND cp.is_host = 0
        ORDER BY cpd.room_id, cpd.participant_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$roomIds);
    $stmt->execute();
    $dRoomId = 0; $dPid = 0; $dPhase = 0; $dData = ''; $dHost = 0;
    $stmt->bind_result($dRoomId, $dPid, $dPhase, $dData, $dHost);

    // 방별 → 참여자별 데이터 수집
    $rooms = []; // roomId => { p1: {pid => [strengths]}, p2: {pid => [evals]} }
    while ($stmt->fetch()) {
        if (!isset($rooms[$dRoomId])) {
            $rooms[$dRoomId] = ['p1' => [], 'p2' => []];
        }
        $parsed = json_decode($dData, true);
        if ($dPhase === 1) {
            $rooms[$dRoomId]['p1'][$dPid] = $parsed['selected_strengths'] ?? [];
        } else {
            $rooms[$dRoomId]['p2'][$dPid] = $parsed['evaluations'] ?? [];
        }
    }
    $stmt->close();

    // 4) 집계
    $allStrengthCount = [];  // 자기 선택 빈도
    $allBlindCount = [];     // 숨은 강점 빈도
    $totalMatch = 0;
    $matchCount = 0;

    foreach ($rooms as $rId => $rData) {
        $p1 = $rData['p1'];
        $p2 = $rData['p2'];

        // 각 학생이 받은 평가 집계
        $received = []; // pid => { strengthKey => count }
        foreach ($p2 as $evaluatorId => $evals) {
            foreach ($evals as $ev) {
                $tid = (int)($ev['target_id'] ?? 0);
                $sk = $ev['strength'] ?? '';
                if ($tid && $sk) {
                    if (!isset($received[$tid])) $received[$tid] = [];
                    $received[$tid][$sk] = ($received[$tid][$sk] ?? 0) + 1;
                }
            }
        }

        // 학생별 일치도 + 강점 빈도 계산
        foreach ($p1 as $pid => $myStrengths) {
            // 자기 선택 빈도
            foreach ($myStrengths as $k) {
                $allStrengthCount[$k] = ($allStrengthCount[$k] ?? 0) + 1;
            }

            // 일치도
            $friendKeys = isset($received[$pid]) ? array_keys($received[$pid]) : [];
            $openCount = 0;
            foreach ($myStrengths as $k) {
                if (isset($received[$pid][$k])) $openCount++;
            }
            if (count($myStrengths) > 0) {
                $totalMatch += round($openCount / count($myStrengths) * 100);
                $matchCount++;
            }

            // 숨은 강점 (친구만 선택)
            foreach ($friendKeys as $k) {
                if (!in_array($k, $myStrengths)) {
                    $allBlindCount[$k] = ($allBlindCount[$k] ?? 0) + 1;
                }
            }
        }
    }

    // TOP5 강점
    arsort($allStrengthCount);
    $topStrengths = [];
    $i = 0;
    foreach ($allStrengthCount as $k => $c) {
        $topStrengths[] = ['key' => $k, 'count' => $c];
        if (++$i >= 5) break;
    }

    // TOP5 숨은 강점
    arsort($allBlindCount);
    $topBlind = [];
    $i = 0;
    foreach ($allBlindCount as $k => $c) {
        $topBlind[] = ['key' => $k, 'count' => $c];
        if (++$i >= 5) break;
    }

    $avgMatch = $matchCount > 0 ? round($totalMatch / $matchCount) : 0;

    jsonResponse([
        'total_participants' => $totalParticipants,
        'total_rooms' => count($roomIds),
        'avg_match_rate' => $avgMatch,
        'top_strengths' => $topStrengths,
        'top_blind' => $topBlind
    ]);
}

// ================================================================
//  헬퍼: 방장 확인
// ================================================================
function isHost($conn, $roomId, $participantId) {
    $stmt = $conn->prepare("
        SELECT is_host FROM collab_participants
        WHERE room_id = ? AND id = ?
    ");
    $stmt->bind_param('ii', $roomId, $participantId);
    $stmt->execute();
    $host = 0;
    $stmt->bind_result($host);
    $found = $stmt->fetch();
    $stmt->close();
    return $found && $host === 1;
}
?>
