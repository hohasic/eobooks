<?php
/**
 * life_balance_check 활동 DB 등록 (1회성 스크립트)
 * 브라우저에서 실행 후 삭제하세요.
 * https://www.eotextbook.com/d_contents/01/api/add_life_balance_check.php
 */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/db.php';

$stmt = $conn->prepare("INSERT IGNORE INTO `activities` (`activity_id`, `title`, `description`) VALUES (?, ?, ?)");

$id    = 'life_balance_check';
$title = '나의 균형 찾기';
$desc  = '일·학습·여가 균형 탐색 활동 (시나리오 선택형, 중/고 컨텍스트 분기)';

$stmt->bind_param('sss', $id, $title, $desc);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo "<p>✅ 나의 균형 찾기 (life_balance_check) 등록 완료!</p>";
} else {
    echo "<p>ℹ️ 나의 균형 찾기 (life_balance_check) — 이미 존재합니다.</p>";
}

$stmt->close();
$conn->close();

echo "<hr><p>🎉 이제 테스트할 수 있습니다:</p>";
echo "<p><a href='/d_contents/01/activities/life_balance_check/?context=middle'>중학교 버전</a> | ";
echo "<a href='/d_contents/01/activities/life_balance_check/?context=high'>고등학교 버전</a></p>";
echo "<p style='color:#999; font-size:12px'>⚠️ 이 파일은 실행 후 반드시 삭제하세요.</p>";
?>
