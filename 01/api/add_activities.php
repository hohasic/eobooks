<?php
/**
 * 활동 4개 추가 등록 (1회용)
 * 실행 후 삭제하세요.
 */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/db.php';

$activities = [
    ['holland_interest', '직업 흥미 유형 검사', '홀랜드 RIASEC 6유형 흥미 검사'],
    ['aptitude_check', '직업 적성 검사', '다중지능 기반 적성 영역 자기평가'],
    ['mbti_personality', '성격 유형 검사 (MBTI)', 'MBTI 4축 이분법 성격 유형 검사'],
    ['work_values', '직업 가치관 검사', '12가지 직업 가치 드래그 랭킹'],
    ['job_change_quiz', '직업 변화 스피드 퀴즈', '사회 변화→직업 변화 인과 사슬 추적'],
    ['decision_style', '나의 의사 결정 유형은?', 'Harren 의사결정 스타일 검사 (합리·직관·의존)'],
];

echo "<h2>활동 추가 등록</h2>";

$stmt = $conn->prepare("INSERT IGNORE INTO `activities` (`activity_id`, `title`, `description`) VALUES (?, ?, ?)");

foreach ($activities as $a) {
    $stmt->bind_param('sss', $a[0], $a[1], $a[2]);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        echo "<p>✅ {$a[1]} ({$a[0]}) 등록 완료</p>";
    } else {
        echo "<p>ℹ️ {$a[1]} ({$a[0]}) — 이미 존재</p>";
    }
}

$stmt->close();
$conn->close();

echo "<hr><p>🎉 완료! 이제 활동을 테스트할 수 있습니다.</p>";
echo "<p style='color:#999; font-size:12px'>⚠️ 이 파일은 실행 후 삭제하세요.</p>";
?>
