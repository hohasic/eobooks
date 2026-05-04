<?php
/**
 * dream_team_mars 활동 등록 (1회용)
 * 브라우저에서 실행: https://www.eotextbook.com/d_contents/01/api/add_dream_team_mars.php
 * 실행 후 삭제하세요.
 */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/db.php';

$stmt = $conn->prepare("INSERT IGNORE INTO `activities` (`activity_id`, `title`, `description`) VALUES (?, ?, ?)");

$id = 'dream_team_mars';
$title = '드림팀 마스';
$desc = '화성 정착 선발대 구성 + 위기 대응 시뮬레이션 (20명→4명 선발, 5개 시나리오)';

$stmt->bind_param('sss', $id, $title, $desc);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo "<p>✅ 드림팀 마스 (dream_team_mars) 등록 완료!</p>";
} else {
    echo "<p>ℹ️ 드림팀 마스 (dream_team_mars) — 이미 존재합니다.</p>";
}

$stmt->close();
$conn->close();

echo "<hr><p>🎉 이제 <a href='/d_contents/01/activities/dream_team_mars/?context=middle'>드림팀 마스</a>를 테스트할 수 있습니다.</p>";
echo "<p style='color:#999; font-size:12px'>⚠️ 이 파일은 실행 후 반드시 삭제하세요.</p>";
?>
