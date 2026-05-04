<?php
/**
 * v3.0 그룹(함께하기) API
 * mysqlnd 없이 동작 (get_result() 대신 bind_result() 사용)
 *
 * POST ?action=join
 *   body: { group_code, school, activity_id, nickname }
 *   응답: { participant_id, group_id }
 */

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $groupCode  = trim($input['group_code'] ?? '');
    $school     = trim($input['school'] ?? '');
    $activityId = trim($input['activity_id'] ?? '');
    $nickname   = trim($input['nickname'] ?? '');

    // 입력 검증
    if ($groupCode === '' || $school === '' || $activityId === '' || $nickname === '') {
        jsonResponse(['error' => '모든 항목을 입력해 주세요.'], 400);
    }
    if (mb_strlen($groupCode) > 12) {
        jsonResponse(['error' => '함께하기 코드는 12자 이하로 입력해 주세요.'], 400);
    }
    if (mb_strlen($school) > 20) {
        jsonResponse(['error' => '학교는 20자 이하로 입력해 주세요.'], 400);
    }
    if (mb_strlen($nickname) > 12) {
        jsonResponse(['error' => '별명은 12자 이하로 입력해 주세요.'], 400);
    }

    // 만료된 세션 정리
    expireOldSessions($conn);

    // 세션 찾기 (group_code + school + activity_id)
    $stmt = $conn->prepare("
        SELECT id FROM sessions
        WHERE group_code = ? AND school = ? AND activity_id = ? AND is_expired = 0
        LIMIT 1
    ");
    $stmt->bind_param('sss', $groupCode, $school, $activityId);
    $stmt->execute();
    $foundSessionId = null;
    $stmt->bind_result($foundSessionId);
    $sessionFound = $stmt->fetch();
    $stmt->close();

    if (!$sessionFound) {
        // 세션 없으면 새로 생성
        $stmt = $conn->prepare("INSERT INTO sessions (group_code, school, activity_id) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $groupCode, $school, $activityId);
        $stmt->execute();
        $sessionId = $stmt->insert_id;
        $stmt->close();
    } else {
        $sessionId = $foundSessionId;

        // last_joined_at 갱신
        $conn->query("UPDATE sessions SET last_joined_at = NOW() WHERE id = " . intval($sessionId));

        // 인원 수 체크 (최대 50명) — 단, DEMO 세션은 시연용이므로 제한 없음
        $isDemo = (stripos($groupCode, 'DEMO') === 0);
        if (!$isDemo) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM participants WHERE session_id = ?");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $count = 0;
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            if ($count >= 50) {
                jsonResponse(['error' => '이 세션은 최대 인원(50명)에 도달했습니다.'], 400);
            }
        }
    }

    // 같은 세션에서 같은 닉네임 확인 (재입장 vs 중복) — 서브쿼리 없이 2단계로
    $stmt = $conn->prepare("SELECT id FROM participants WHERE session_id = ? AND nickname = ? LIMIT 1");
    $stmt->bind_param('is', $sessionId, $nickname);
    $stmt->execute();
    $existingId = null;
    $stmt->bind_result($existingId);
    $existingFound = $stmt->fetch();
    $stmt->close();

    if ($existingFound) {
        // 결과 제출 여부 별도 확인
        $stmt2 = $conn->prepare("SELECT COUNT(*) FROM activity_results WHERE participant_id = ?");
        $stmt2->bind_param('i', $existingId);
        $stmt2->execute();
        $existingHasResult = 0;
        $stmt2->bind_result($existingHasResult);
        $stmt2->fetch();
        $stmt2->close();

        // 같은 코드+닉네임 재입장 → 기존 participant_id 반환
        jsonResponse([
            'participant_id' => (int)$existingId,
            'group_id'       => (int)$sessionId,
            'rejoined'       => true,
            'has_result'     => (int)$existingHasResult > 0
        ]);
    }

    // 새 참여자 등록
    $stmt = $conn->prepare("INSERT INTO participants (session_id, nickname, context) VALUES (?, ?, 'middle')");
    $stmt->bind_param('is', $sessionId, $nickname);

    if (!$stmt->execute()) {
        if ($conn->errno === 1062) {
            jsonResponse(['error' => '이미 사용 중인 별명입니다. 다른 별명을 입력해 주세요.'], 409);
        }
        jsonResponse(['error' => '참여자 등록에 실패했습니다.'], 500);
    }

    $participantId = $stmt->insert_id;
    $stmt->close();

    jsonResponse([
        'participant_id' => (int)$participantId,
        'group_id'       => (int)$sessionId
    ]);
}

jsonResponse(['error' => '잘못된 요청입니다.'], 400);
?>
