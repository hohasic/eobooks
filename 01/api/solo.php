<?php
/**
 * v3.0 혼자하기 API
 *
 * POST ?action=start
 *   body: { activity_id, school, nickname }
 *   응답: { participant_id }
 *
 * 함께하기 코드 없이 혼자 활동하는 경우.
 * 세션을 하나 만들고(group_code = NULL), 참여자 1명 등록.
 */

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $activityId = trim($input['activity_id'] ?? '');
    $school     = trim($input['school'] ?? '');
    $nickname   = trim($input['nickname'] ?? '');

    // 입력 검증
    if ($activityId === '' || $school === '' || $nickname === '') {
        jsonResponse(['error' => '학교와 별명을 입력해 주세요.'], 400);
    }
    if (mb_strlen($school) > 20) {
        jsonResponse(['error' => '학교는 20자 이하로 입력해 주세요.'], 400);
    }
    if (mb_strlen($nickname) > 12) {
        jsonResponse(['error' => '별명은 12자 이하로 입력해 주세요.'], 400);
    }

    // 혼자하기 세션 생성 (group_code = NULL)
    $stmt = $conn->prepare("
        INSERT INTO sessions (group_code, school, activity_id)
        VALUES (NULL, ?, ?)
    ");
    $stmt->bind_param('ss', $school, $activityId);
    $stmt->execute();
    $sessionId = $stmt->insert_id;
    $stmt->close();

    // 참여자 등록
    $stmt = $conn->prepare("
        INSERT INTO participants (session_id, nickname, context)
        VALUES (?, ?, 'solo')
    ");
    $stmt->bind_param('is', $sessionId, $nickname);
    $stmt->execute();
    $participantId = $stmt->insert_id;
    $stmt->close();

    jsonResponse([
        'participant_id' => $participantId
    ]);
}

jsonResponse(['error' => '잘못된 요청입니다.'], 400);
?>
