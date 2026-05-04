<?php
/**
 * change_domino → job_change_quiz 마이그레이션
 *
 * 1) activities 테이블에 새 ID 등록 (없으면)
 * 2) activity_results의 activity_id 일괄 변경
 * 3) 완료 후 이 파일은 삭제해도 됩니다.
 *
 * 실행: 브라우저에서 http://localhost/d_contents/01/api/migrate_change_domino.php
 */
require_once __DIR__ . '/db.php';

echo "<h2>change_domino → job_change_quiz 마이그레이션</h2>";

// 1. activities 테이블에 새 ID 등록
$stmt = $conn->prepare("INSERT IGNORE INTO `activities` (`activity_id`, `title`, `description`) VALUES (?, ?, ?)");
$id = 'job_change_quiz';
$title = '직업 변화 스피드 퀴즈';
$desc = '사회 변화→직업 변화 인과 사슬 추적';
$stmt->bind_param('sss', $id, $title, $desc);
$stmt->execute();
echo "<p>✅ activities 테이블: job_change_quiz 등록 완료 (affected: {$stmt->affected_rows})</p>";
$stmt->close();

// 2. activity_results 테이블의 activity_id 변경
$stmt = $conn->prepare("UPDATE `activity_results` SET `activity_id` = 'job_change_quiz' WHERE `activity_id` = 'change_domino'");
$stmt->execute();
$affected = $stmt->affected_rows;
echo "<p>✅ activity_results 테이블: {$affected}건 변경 완료</p>";
$stmt->close();

// 3. sessions 테이블의 activity_id 변경 (FK 제약 해소)
$stmt = $conn->prepare("UPDATE `sessions` SET `activity_id` = 'job_change_quiz' WHERE `activity_id` = 'change_domino'");
$stmt->execute();
$affected3 = $stmt->affected_rows;
echo "<p>✅ sessions 테이블: {$affected3}건 변경 완료</p>";
$stmt->close();

// 4. 구 activities 레코드 삭제
$stmt = $conn->prepare("DELETE FROM `activities` WHERE `activity_id` = 'change_domino'");
$stmt->execute();
echo "<p>✅ activities 테이블: change_domino 레코드 삭제 (affected: {$stmt->affected_rows})</p>";
$stmt->close();

echo "<hr><p><strong>마이그레이션 완료!</strong> 이 파일은 삭제해도 됩니다.</p>";

$conn->close();
?>
