<?php
/**
 * 시연용 더미 데이터 일괄 삭제
 *
 * 사용법:
 *   http://localhost/d_contents/01/api/clean_demo.php?code=DEMO2026
 *
 * '이오중학교'와 '이오고등학교' 두 학교의 데모 데이터를 모두 삭제합니다.
 *
 * ⚠️ 실제 사용자가 이 학교명 + 같은 코드로 참여 중이면 그 데이터도 삭제됩니다.
 *    데모 전용 코드(DEMO2026)를 사용하세요.
 */

require __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

$CODE = isset($_GET['code']) ? trim($_GET['code']) : 'DEMO2026';
$SCHOOLS = ['이오중학교', '이오고등학교'];

echo "<h2>🗑️ 시연용 더미 데이터 삭제</h2>";
echo "<p>코드: <b>{$CODE}</b> · 학교: <b>" . implode(', ', $SCHOOLS) . "</b></p>";
echo "<hr>";

$allSids = [];
foreach ($SCHOOLS as $school) {
    $stmt = $conn->prepare("SELECT id, group_code, school, activity_id FROM `sessions` WHERE (`group_code`=? AND `school`=?) OR (`group_code` IS NULL AND `school`=?)");
    $stmt->bind_param('sss', $CODE, $school, $school);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $allSids[] = (int)$row['id'];
        $gc = $row['group_code'] === null ? '(솔로)' : $row['group_code'];
        echo "<p>· 세션 id={$row['id']} ({$row['activity_id']}) — {$gc}/{$row['school']}</p>";
    }
    $stmt->close();
}

if (empty($allSids)) {
    echo "<p style='color:#a00'>삭제할 데모 세션이 없습니다.</p>";
    $conn->close();
    exit;
}

$in = implode(',', array_unique($allSids));

$conn->query("DELETE FROM `activity_results` WHERE `session_id` IN ({$in})");
$r1 = $conn->affected_rows;
$conn->query("DELETE FROM `participants` WHERE `session_id` IN ({$in})");
$r2 = $conn->affected_rows;
$conn->query("DELETE FROM `sessions` WHERE `id` IN ({$in})");
$r3 = $conn->affected_rows;

echo "<hr>";
echo "<p>✅ 결과 {$r1}건 / 참여자 {$r2}명 / 세션 {$r3}개 삭제 완료</p>";

$conn->close();
?>
