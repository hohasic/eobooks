<?php
/**
 * 모둠 활동 테이블 생성 (1회 실행 후 삭제)
 * 브라우저에서 접속: /d_contents/01/api/collab_setup.php
 */
require_once __DIR__ . '/db.php';

$results = [];

// 1. collab_rooms — 모둠 활동 방
$sql1 = "CREATE TABLE IF NOT EXISTS `collab_rooms` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `room_code`       VARCHAR(12) NOT NULL        COMMENT '선생님이 입력한 방 코드',
    `school`          VARCHAR(20) NOT NULL        COMMENT '학교명',
    `activity_id`     VARCHAR(50) NOT NULL        COMMENT '활동 ID',
    `expected_count`  INT NOT NULL DEFAULT 4      COMMENT '예상 인원 (표시용)',
    `min_count`       INT NOT NULL DEFAULT 3      COMMENT '최소 인원 (활동별)',
    `status`          ENUM('waiting','active','completed') DEFAULT 'waiting',
    `current_phase`   INT DEFAULT 0               COMMENT '0=대기실, 1,2,3...=활동 단계',
    `context`         ENUM('middle','high') DEFAULT NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `started_at`      TIMESTAMP NULL              COMMENT '활동 시작 시각',
    `completed_at`    TIMESTAMP NULL              COMMENT '활동 종료 시각',
    FOREIGN KEY (`activity_id`) REFERENCES `activities`(`activity_id`),
    INDEX `idx_room_lookup` (`room_code`, `school`, `activity_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='모둠 활동 방'";

$results[] = $conn->query($sql1)
    ? '✅ collab_rooms 테이블 생성 완료'
    : '❌ collab_rooms 실패: ' . $conn->error;

// 2. collab_participants — 모둠 참여자
$sql2 = "CREATE TABLE IF NOT EXISTS `collab_participants` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `room_id`     INT NOT NULL,
    `nickname`    VARCHAR(12) NOT NULL,
    `is_host`     TINYINT(1) DEFAULT 0           COMMENT '1=방장(선생님)',
    `joined_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`room_id`) REFERENCES `collab_rooms`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_room_nickname` (`room_id`, `nickname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='모둠 참여자'";

$results[] = $conn->query($sql2)
    ? '✅ collab_participants 테이블 생성 완료'
    : '❌ collab_participants 실패: ' . $conn->error;

// 3. collab_phase_data — 단계별 제출 데이터
$sql3 = "CREATE TABLE IF NOT EXISTS `collab_phase_data` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `room_id`         INT NOT NULL,
    `participant_id`  INT NOT NULL,
    `phase`           INT NOT NULL                COMMENT '단계 번호',
    `phase_data`      LONGTEXT NOT NULL           COMMENT 'JSON 데이터',
    `completed_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`room_id`) REFERENCES `collab_rooms`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`participant_id`) REFERENCES `collab_participants`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_phase_entry` (`room_id`, `participant_id`, `phase`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='모둠 활동 단계별 데이터'";

$results[] = $conn->query($sql3)
    ? '✅ collab_phase_data 테이블 생성 완료'
    : '❌ collab_phase_data 실패: ' . $conn->error;

// 4. activities 테이블에 strength_intro 추가
$stmt = $conn->prepare("INSERT IGNORE INTO `activities` (`activity_id`, `title`, `description`) VALUES (?, ?, ?)");
$aid = 'strength_intro';
$atitle = '나를 소개합니다';
$adesc = '강점 탐색 + 조해리의 창 기반 자기/타인 인식 비교';
$stmt->bind_param('sss', $aid, $atitle, $adesc);
$results[] = $stmt->execute()
    ? '✅ strength_intro 활동 등록 완료 (또는 이미 존재)'
    : '❌ 활동 등록 실패: ' . $stmt->error;
$stmt->close();

// 결과 출력
header('Content-Type: text/html; charset=utf-8');
echo '<h2>모둠 활동 테이블 설정</h2>';
echo '<ul>';
foreach ($results as $r) {
    echo '<li style="margin:8px 0;font-size:16px">' . $r . '</li>';
}
echo '</ul>';
echo '<p style="color:#999;margin-top:20px">설정이 완료되면 이 파일을 서버에서 삭제하세요.</p>';
?>
