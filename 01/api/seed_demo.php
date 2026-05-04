<?php
/**
 * 시연용 더미 데이터 생성 스크립트
 *
 * 사용법:
 *   브라우저에서 http://localhost/d_contents/01/api/seed_demo.php
 *   또는 https://www.eotextbook.com/d_contents/01/api/seed_demo.php
 *
 * 옵션 (URL 파라미터):
 *   ?code=DEMO2026   세션 코드 (기본값 DEMO2026)
 *   ?total=100        활동별 전체(국가) 표본 수 (기본값 100)
 *   ?group=25         활동별 학교당 데모 세션(우리 반) 인원 수 (기본값 25, 최대 50)
 *   ?reset=1          기존 데모 데이터 삭제 후 재생성
 *
 * 학교는 '이오중학교'와 '이오고등학교' 두 곳을 자동으로 시드합니다.
 * 시연 시 둘 중 어느 학교를 선택해도 같은 코드(DEMO2026)로 들어갑니다.
 *
 * ⚠️ 사용 후 서버에서 삭제 권장 (또는 .htaccess 등으로 접근 차단)
 */

require __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

$CODE    = isset($_GET['code'])  ? trim($_GET['code'])  : 'DEMO2026';
$TOTAL   = isset($_GET['total']) ? max(1, intval($_GET['total'])) : 100;
$GROUP   = isset($_GET['group']) ? max(1, intval($_GET['group'])) : 25;
$RESET   = isset($_GET['reset']) && $_GET['reset'] == '1';

if ($GROUP > 50) $GROUP = 50;

// 시연용 학교 두 곳 — 두 학교 각각에 데모 세션 생성
$SCHOOLS = ['이오중학교', '이오고등학교'];

// 솔로 추가 표본 = TOTAL - (학교 두 곳 × GROUP)
$soloExtra = max(0, $TOTAL - count($SCHOOLS) * $GROUP);

echo "<h2>🎬 시연용 더미 데이터 생성</h2>";
echo "<p>코드: <b>{$CODE}</b> · 학교: <b>" . implode(', ', $SCHOOLS) . "</b></p>";
echo "<p>활동별 전체 표본: <b>{$TOTAL}명</b> = 학교 2곳 × 우리 반 {$GROUP}명 + 솔로 추가 {$soloExtra}명</p>";
echo "<hr>";

/* ================================================================
 * RESET: 기존 데모 데이터 삭제
 * ================================================================ */
if ($RESET) {
    echo "<h3>🗑️ 기존 데모 데이터 삭제</h3>";
    foreach ($SCHOOLS as $sch) cleanDemo($conn, $CODE, $sch);
    echo "<hr>";
}

/* ================================================================
 * 활동 정의 + 결과 데이터 생성기
 * ================================================================ */

// 1. job_card_adventure
$JCA_VALUES = ['stability','pay','balance','joy','belonging','growth','challenge','influence','contribution','achievement','recognition','autonomy'];
$JCA_VALUE_NAMES = [
    'stability'=>['name'=>'안정성','emoji'=>'🛡️','desc'=>'오래 안정적으로 일하기'],
    'pay'=>['name'=>'보수','emoji'=>'💰','desc'=>'경제적 보상이 큰 직업'],
    'balance'=>['name'=>'일과 삶의 균형','emoji'=>'⚖️','desc'=>'여가 시간이 확보되는 것'],
    'joy'=>['name'=>'즐거움','emoji'=>'😊','desc'=>'즐겁게 할 수 있는 일'],
    'belonging'=>['name'=>'소속감','emoji'=>'🤝','desc'=>'조직에 소속되는 느낌'],
    'growth'=>['name'=>'자기 계발','emoji'=>'📈','desc'=>'계속 성장해 나가는 것'],
    'challenge'=>['name'=>'도전성','emoji'=>'🔥','desc'=>'새로운 것에 도전하기'],
    'influence'=>['name'=>'영향력','emoji'=>'👑','desc'=>'사람들을 이끄는 것'],
    'contribution'=>['name'=>'사회적 기여','emoji'=>'🌍','desc'=>'사회에 봉사하고 기여'],
    'achievement'=>['name'=>'성취','emoji'=>'🏅','desc'=>'목표를 달성하는 기쁨'],
    'recognition'=>['name'=>'사회적 인정','emoji'=>'⭐','desc'=>'인정받고 존경받기'],
    'autonomy'=>['name'=>'자율성','emoji'=>'🦅','desc'=>'스스로 결정하고 선택'],
];
$JCA_JOBS = [
    ['name'=>'방송 작가','emoji'=>'✍️','field'=>'미디어'],
    ['name'=>'데이터 분석가','emoji'=>'📊','field'=>'IT'],
    ['name'=>'음향 엔지니어','emoji'=>'🎧','field'=>'음악'],
    ['name'=>'게임 디자이너','emoji'=>'🎮','field'=>'IT'],
    ['name'=>'사회복지사','emoji'=>'🫂','field'=>'복지'],
    ['name'=>'광고 기획자','emoji'=>'💡','field'=>'마케팅'],
    ['name'=>'환경 공학자','emoji'=>'🌱','field'=>'환경'],
    ['name'=>'애니메이터','emoji'=>'🎬','field'=>'예술'],
];

function gen_job_card_adventure() {
    global $JCA_VALUES, $JCA_VALUE_NAMES, $JCA_JOBS;
    $vs = $JCA_VALUES;
    shuffle($vs);
    $top3keys = array_slice($vs, 0, 3);
    $top3 = [];
    foreach ($top3keys as $k) {
        $top3[] = ['id'=>$k] + $JCA_VALUE_NAMES[$k];
    }
    $score = rand(4, 8);
    $jobsShuffled = $JCA_JOBS;
    shuffle($jobsShuffled);
    $collected = array_slice($jobsShuffled, 0, $score);
    return [
        'quizCorrect' => rand(6, 8),
        'quizTotal' => 8,
        'valueTop3' => $top3,
        'matchScore' => $score,
        'collectedJobs' => $collected,
        'timestamp' => date('c'),
    ];
}

// 2. mbti_personality
function gen_mbti_personality() {
    $axes = ['EI','SN','TF','JP'];
    $code = '';
    $scores = [];
    for ($i = 0; $i < 4; $i++) {
        $a = rand(2, 8);
        $b = 10 - $a;
        $scores[] = [$a, $b];
        $code .= ($a >= $b) ? $axes[$i][0] : $axes[$i][1];
    }
    return ['mbtiCode' => $code, 'scores' => $scores, 'timestamp' => date('c')];
}

// 3. aptitude_check
$APT_KEYS = ['body','hand','space','music','creative','lang','math','self','social','nature','art'];
function gen_aptitude_check() {
    global $APT_KEYS;
    $scores = [];
    foreach ($APT_KEYS as $k) {
        // 각 영역 5문항 × 1~5점 = 5~25
        $sum = 0;
        for ($q = 0; $q < 5; $q++) $sum += rand(1, 5);
        $scores[$k] = $sum;
    }
    arsort($scores);
    $top3 = array_slice(array_keys($scores), 0, 3);
    // 원래 순서 복원
    $ordered = [];
    foreach ($APT_KEYS as $k) $ordered[$k] = $scores[$k];
    return ['scores' => $ordered, 'top3' => $top3, 'timestamp' => date('c')];
}

// 4. work_values
$WV_KEYS = ['stability','salary','balance','joy','belong','growth','achieve','autonomy','challenge','influence','contribution','recognition'];
function gen_work_values() {
    global $WV_KEYS;
    $scores = [];
    foreach ($WV_KEYS as $k) $scores[$k] = 0;
    // 12라운드 토너먼트 가정 — 가중 랜덤
    for ($r = 0; $r < 30; $r++) {
        $k = $WV_KEYS[array_rand($WV_KEYS)];
        $scores[$k] += rand(1, 3);
    }
    arsort($scores);
    $top3 = array_slice(array_keys($scores), 0, 3);
    $ordered = [];
    foreach ($WV_KEYS as $k) $ordered[$k] = $scores[$k];
    return ['scores' => $ordered, 'top3' => $top3, 'timestamp' => date('c')];
}

// 5. holland_interest
$HOL_KEYS = ['R','I','A','S','E','C'];
function gen_holland_interest() {
    global $HOL_KEYS;
    $scores = [];
    foreach ($HOL_KEYS as $k) $scores[$k] = rand(5, 25);
    arsort($scores);
    $top2 = array_slice(array_keys($scores), 0, 2);
    $ordered = [];
    foreach ($HOL_KEYS as $k) $ordered[$k] = $scores[$k];
    return ['scores' => $ordered, 'top2' => $top2, 'timestamp' => date('c')];
}

$ACTIVITIES = [
    ['id'=>'job_card_adventure', 'gen'=>'gen_job_card_adventure'],
    ['id'=>'mbti_personality',   'gen'=>'gen_mbti_personality'],
    ['id'=>'aptitude_check',     'gen'=>'gen_aptitude_check'],
    ['id'=>'work_values',        'gen'=>'gen_work_values'],
    ['id'=>'holland_interest',   'gen'=>'gen_holland_interest'],
];

/* ================================================================
 * 닉네임 풀
 * ================================================================ */
$NICK_PREFIX = ['데모','별빛','파도','구름','노을','달빛','햇살','바람','단풍','새벽','솔방울','이슬','꽃잎','나비','반딧불','민들레','은하','수정','무지개','초록','코코','루나','하루','봄날','여름'];
$NICK_SUFFIX = ['이','님','학생','친구','이오'];

function makeNicknames($n, $prefix='') {
    global $NICK_PREFIX, $NICK_SUFFIX;
    $set = [];
    $used = [];
    $i = 0;
    while (count($set) < $n) {
        $base = $NICK_PREFIX[array_rand($NICK_PREFIX)] . $NICK_SUFFIX[array_rand($NICK_SUFFIX)];
        $nick = $prefix . $base . ($i > 0 ? $i : '');
        if (mb_strlen($nick) > 12) $nick = mb_substr($nick, 0, 12);
        if (!isset($used[$nick])) {
            $used[$nick] = 1;
            $set[] = $nick;
        }
        $i++;
        if ($i > 5000) break;
    }
    return $set;
}

/* ================================================================
 * 메인: 활동별 시드
 * ================================================================ */
foreach ($ACTIVITIES as $act) {
    $aid = $act['id'];
    $gen = $act['gen'];
    echo "<h3>📦 활동: {$aid}</h3>";

    $totalInserted = 0;

    /* (A) 학교별 데모 그룹 세션 */
    foreach ($SCHOOLS as $school) {
        $context = (strpos($school, '중학교') !== false) ? 'middle' : 'high';

        // 기존 동일 세션 있으면 재사용
        $stmt = $conn->prepare("SELECT id FROM `sessions` WHERE `group_code`=? AND `school`=? AND `activity_id`=? AND `is_expired`=0 LIMIT 1");
        $stmt->bind_param('sss', $CODE, $school, $aid);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($r) {
            $groupSid = (int)$r['id'];
            echo "<p>· [{$school}] 기존 데모 세션 재사용 (id={$groupSid})</p>";
        } else {
            $stmt = $conn->prepare("INSERT INTO `sessions` (`group_code`,`school`,`activity_id`,`created_at`,`last_joined_at`,`is_expired`) VALUES (?,?,?,NOW(),NOW(),0)");
            $stmt->bind_param('sss', $CODE, $school, $aid);
            $stmt->execute();
            $groupSid = (int)$conn->insert_id;
            $stmt->close();
            echo "<p>· [{$school}] 새 데모 세션 생성 (id={$groupSid})</p>";
        }

        $groupNicks = makeNicknames($GROUP, ($context === 'middle' ? '중' : '고'));
        $insertedG = 0;
        foreach ($groupNicks as $nick) {
            $stmt = $conn->prepare("INSERT INTO `participants` (`session_id`,`nickname`,`context`,`joined_at`) VALUES (?,?,?,NOW())");
            $stmt->bind_param('iss', $groupSid, $nick, $context);
            if (!$stmt->execute()) { $stmt->close(); continue; }
            $pid = (int)$conn->insert_id;
            $stmt->close();

            $data = $gen();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            $stmt = $conn->prepare("INSERT INTO `activity_results` (`participant_id`,`activity_id`,`session_id`,`result_data`,`submitted_at`) VALUES (?,?,?,?,NOW())");
            $stmt->bind_param('isis', $pid, $aid, $groupSid, $json);
            $stmt->execute();
            $stmt->close();
            $insertedG++;
        }
        echo "<p>· [{$school}] 데모 세션에 {$insertedG}명 결과 저장</p>";
        $totalInserted += $insertedG;
    }

    /* (B) 솔로(전체 추가) 세션 — 두 학교에 절반씩 분배 */
    if ($soloExtra > 0) {
        $perSchool = (int)ceil($soloExtra / count($SCHOOLS));
        $remaining = $soloExtra;

        foreach ($SCHOOLS as $school) {
            if ($remaining <= 0) break;
            $n = min($perSchool, $remaining);
            $context = (strpos($school, '중학교') !== false) ? 'middle' : 'high';

            $stmt = $conn->prepare("INSERT INTO `sessions` (`group_code`,`school`,`activity_id`,`created_at`,`last_joined_at`,`is_expired`) VALUES (NULL,?,?,NOW(),NOW(),0)");
            $stmt->bind_param('ss', $school, $aid);
            $stmt->execute();
            $soloSid = (int)$conn->insert_id;
            $stmt->close();

            $soloNicks = makeNicknames($n, ($context === 'middle' ? '솔중' : '솔고'));
            $insertedS = 0;
            foreach ($soloNicks as $nick) {
                $stmt = $conn->prepare("INSERT INTO `participants` (`session_id`,`nickname`,`context`,`joined_at`) VALUES (?,?,?,NOW())");
                $stmt->bind_param('iss', $soloSid, $nick, $context);
                if (!$stmt->execute()) { $stmt->close(); continue; }
                $pid = (int)$conn->insert_id;
                $stmt->close();

                $data = $gen();
                $json = json_encode($data, JSON_UNESCAPED_UNICODE);
                $stmt = $conn->prepare("INSERT INTO `activity_results` (`participant_id`,`activity_id`,`session_id`,`result_data`,`submitted_at`) VALUES (?,?,?,?,NOW())");
                $stmt->bind_param('isis', $pid, $aid, $soloSid, $json);
                $stmt->execute();
                $stmt->close();
                $insertedS++;
            }
            echo "<p>· [{$school}] 솔로 추가 {$insertedS}명 (sid={$soloSid})</p>";
            $totalInserted += $insertedS;
            $remaining -= $n;
        }
    }

    echo "<p>✅ 활동 {$aid} — 총 <b>{$totalInserted}명</b> 결과 저장</p>";
}

echo "<hr>";
echo "<h3>🎉 시드 완료!</h3>";
echo "<p>이제 활동에 들어가서 함께하기 코드 <b>{$CODE}</b> · 학교 <b>이오중학교</b> 또는 <b>이오고등학교</b>로 참여하면 우리 반 통계와 전체 통계에 더미 데이터가 보입니다.</p>";
echo "<p style='color:#999;font-size:12px'>※ 다시 실행하려면 ?reset=1 추가하세요. 예: <code>seed_demo.php?reset=1</code></p>";
echo "<p style='color:#a00;font-size:12px'>⚠️ 시연 후 <code>clean_demo.php</code>로 정리하거나 이 파일을 서버에서 삭제하세요.</p>";

/* ================================================================
 * 정리 함수 (RESET 시에만 호출)
 * ================================================================ */
function cleanDemo($conn, $code, $school) {
    // 해당 학교에서 데모 코드로 만든 그룹 세션 + 같은 학교 이름으로 만든 솔로 세션 모두 삭제
    $stmt = $conn->prepare("SELECT id FROM `sessions` WHERE (`group_code`=? AND `school`=?) OR (`group_code` IS NULL AND `school`=?)");
    $stmt->bind_param('sss', $code, $school, $school);
    $stmt->execute();
    $rs = $stmt->get_result();
    $sids = [];
    while ($row = $rs->fetch_assoc()) $sids[] = (int)$row['id'];
    $stmt->close();

    if (empty($sids)) {
        echo "<p>· 삭제할 기존 데모 데이터 없음</p>";
        return;
    }
    $in = implode(',', $sids);

    $conn->query("DELETE FROM `activity_results` WHERE `session_id` IN ({$in})");
    $r1 = $conn->affected_rows;
    $conn->query("DELETE FROM `participants` WHERE `session_id` IN ({$in})");
    $r2 = $conn->affected_rows;
    $conn->query("DELETE FROM `sessions` WHERE `id` IN ({$in})");
    $r3 = $conn->affected_rows;
    echo "<p>· 결과 {$r1}건 / 참여자 {$r2}명 / 세션 {$r3}개 삭제</p>";
}

$conn->close();
?>
