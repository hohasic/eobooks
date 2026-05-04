<?php
/**
 * v3.0 DB 초기 설정 스크립트
 * 브라우저에서 한 번만 실행하면 됩니다: http://localhost/d_contents/01/api/setup.php
 * 실행 후 삭제하거나 서버에 올리지 마세요.
 */

header('Content-Type: text/html; charset=utf-8');

$host = 'localhost';
$user = 'root';
$isLocal = isset($_SERVER['HTTP_HOST']) && (
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false
);
$pass = $isLocal ? '' : 'Career2026!';  // 로컬 XAMPP: 빈 비밀번호 / Cafe24 서버: Career2026!

echo "<h2>v3.0 DB 설정</h2>";

// 1. MySQL 연결
$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die("<p style='color:red'>❌ MySQL 연결 실패: " . $conn->connect_error . "</p>");
}
echo "<p>✅ MySQL 연결 성공</p>";

// 2. DB 생성
$dbName = 'eobooks_v3';
$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "<p>✅ 데이터베이스 '{$dbName}' 생성 (또는 이미 존재)</p>";

$conn->select_db($dbName);

// 3. 테이블 생성

// 활동 라이브러리
$conn->query("
CREATE TABLE IF NOT EXISTS `activities` (
    `activity_id` VARCHAR(50) PRIMARY KEY COMMENT '내용 기반 ID (예: job_card_adventure)',
    `title` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "<p>✅ activities 테이블 생성</p>";

// 교육과정 성취기준
$conn->query("
CREATE TABLE IF NOT EXISTS `curriculum_standards` (
    `standard_id` VARCHAR(30) PRIMARY KEY,
    `school_level` ENUM('middle','high') NOT NULL,
    `unit` VARCHAR(50) NOT NULL,
    `description` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "<p>✅ curriculum_standards 테이블 생성</p>";

// 활동-성취기준 매핑
$conn->query("
CREATE TABLE IF NOT EXISTS `activity_standard_map` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `activity_id` VARCHAR(50) NOT NULL,
    `standard_id` VARCHAR(30) NOT NULL,
    `mapping_type` ENUM('primary','secondary') DEFAULT 'primary',
    FOREIGN KEY (`activity_id`) REFERENCES `activities`(`activity_id`),
    FOREIGN KEY (`standard_id`) REFERENCES `curriculum_standards`(`standard_id`),
    UNIQUE KEY `uniq_map` (`activity_id`, `standard_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "<p>✅ activity_standard_map 테이블 생성</p>";

// 세션 (임시)
$conn->query("
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `group_code` VARCHAR(12) DEFAULT NULL COMMENT '함께하기 코드 (NULL이면 혼자하기)',
    `school` VARCHAR(20) NOT NULL,
    `activity_id` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `is_expired` TINYINT(1) DEFAULT 0,
    FOREIGN KEY (`activity_id`) REFERENCES `activities`(`activity_id`),
    INDEX `idx_group_lookup` (`group_code`, `school`, `activity_id`, `is_expired`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "<p>✅ sessions 테이블 생성</p>";

// 참여자
$conn->query("
CREATE TABLE IF NOT EXISTS `participants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` INT NOT NULL,
    `nickname` VARCHAR(12) NOT NULL,
    `context` ENUM('middle','high','solo') DEFAULT 'solo' COMMENT '접근 맥락',
    `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`session_id`) REFERENCES `sessions`(`id`),
    UNIQUE KEY `uniq_session_nickname` (`session_id`, `nickname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "<p>✅ participants 테이블 생성</p>";

// 활동 결과
$conn->query("
CREATE TABLE IF NOT EXISTS `activity_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `participant_id` INT NOT NULL,
    `activity_id` VARCHAR(50) NOT NULL,
    `session_id` INT DEFAULT NULL,
    `result_data` LONGTEXT NOT NULL COMMENT '활동별 결과 데이터 (JSON 문자열)',
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`participant_id`) REFERENCES `participants`(`id`),
    FOREIGN KEY (`activity_id`) REFERENCES `activities`(`activity_id`),
    FOREIGN KEY (`session_id`) REFERENCES `sessions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "<p>✅ activity_results 테이블 생성</p>";

// 4. 활동 데이터 삽입
$activities = [
    ['job_card_adventure',  '직업 카드 어드벤처',   '직업 가치 월드컵 + 적성-직업 카드 매칭'],
    ['holland_interest',    '흥미 유형 검사',        '홀랜드 직업흥미 유형 자기검사'],
    ['aptitude_check',      '적성 검사',             '나의 강점 적성 영역 탐색'],
    ['mbti_personality',    '성격 유형 검사',        'MBTI 기반 직업 성격 유형 탐색'],
    ['work_values',         '직업 가치관 검사',      '나에게 중요한 직업 가치 탐색'],
];

foreach ($activities as $a) {
    $conn->query("
        INSERT IGNORE INTO `activities` (`activity_id`, `title`, `description`)
        VALUES ('{$a[0]}', '{$a[1]}', '{$a[2]}')
    ");
    echo "<p>✅ 활동 등록: {$a[1]} ({$a[0]})</p>";
}

echo "<hr>";
echo "<h3>🎉 설정 완료!</h3>";
echo "<p>이제 <a href='/d_contents/01/middle/'>중학교 플랫폼</a>에서 직업 카드 어드벤처를 테스트할 수 있습니다.</p>";
echo "<p style='color:#999; font-size:12px'>⚠️ 이 파일은 설정 후 삭제하거나, 서버에 올리지 마세요.</p>";

$conn->close();
?>
