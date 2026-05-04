/**
 * EoBooks Digital Contents — Collaborative Activity Module (v1.0)
 * 모둠 활동 공통 모듈: 방 만들기/참여하기, 대기실, 호스트 컨트롤, 단계 관리
 *
 * 사용법:
 *   EoCollab.init({
 *       activityId: 'strength_intro',
 *       title: '✨ 나를 소개합니다',
 *       subtitle: '강점을 발견하고 친구의 시선으로 확인해 보세요!',
 *       minCount: 3,
 *       maxTargets: 5,
 *       phases: [
 *           { name: '자기 강점 선택', hostLabel: '강점 선택 중' },
 *           { name: '친구 강점 평가', hostLabel: '친구 평가 중' },
 *           { name: '결과 확인',      hostLabel: '결과' }
 *       ],
 *       onPhaseStart: function(phase, data) { },
 *       onAllData: function(allPhaseData) { }
 *   });
 */

// QR 코드 라이브러리 동적 로드
(function() {
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
    s.async = true;
    document.head.appendChild(s);
})();

var EoCollab = (function() {
    'use strict';

    var API_BASE = '/d_contents/01/api';

    var state = {
        roomId: null,
        participantId: null,
        isHost: false,
        nickname: null,
        school: null,
        roomCode: null,
        activityId: null,
        currentPhase: 0,
        status: 'waiting',
        participants: [],
        expectedCount: 0,
        minCount: 3,
        context: null,
        phaseDone: false       // 현재 단계 제출 완료 여부
    };

    var config = {
        activityId: '',
        title: '',
        subtitle: '',
        minCount: 3,
        maxTargets: 5,
        phases: [],
        onPhaseStart: null,
        onAllData: null
    };

    var pollTimer = null;
    var POLL_LOBBY = 3000;
    var POLL_ACTIVE = 8000;

    // ============================================================
    //  init — 진입점
    // ============================================================
    function init(options) {
        for (var k in options) {
            if (options.hasOwnProperty(k)) config[k] = options[k];
        }
        state.activityId = config.activityId;
        state.minCount = config.minCount;

        // URL에서 context, room(QR용 room_id) 감지
        var params = new URLSearchParams(window.location.search);
        state.context = params.get('context') || null;
        state._urlRoomId = params.get('room') || '';  // QR에서 전달된 room_id (고유)

        renderEntryScreen();

        // QR 링크로 들어온 경우 → 바로 참여 폼으로
        if (state._urlRoomId) {
            showJoinForm();
        }
    }

    // ============================================================
    //  초기 화면 — 역할 선택
    // ============================================================
    function renderEntryScreen() {
        var container = getContainer();
        container.innerHTML =
            '<div class="collab-badge" id="collabBadge"></div>' +
            '<div class="collab-screen active collab-entry" id="screenEntry">' +
                '<div class="collab-entry-header">' +
                    '<div class="collab-entry-title">' + config.title + '</div>' +
                    '<div class="collab-entry-subtitle">' + config.subtitle + '</div>' +
                '</div>' +
                '<div class="role-cards">' +
                    '<div class="role-card" onclick="EoCollab._showCreateForm()">' +
                        '<div class="role-icon">🏫</div>' +
                        '<div class="role-name">방 만들기</div>' +
                        '<div class="role-desc">선생님이 방을 만들고<br>학생 입장을 관리합니다</div>' +
                    '</div>' +
                    '<div class="role-card" onclick="EoCollab._showJoinForm()">' +
                        '<div class="role-icon">🙋</div>' +
                        '<div class="role-name">참여하기</div>' +
                        '<div class="role-desc">선생님이 알려준 코드로<br>활동에 참여합니다</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="collab-screen" id="screenCreate"></div>' +
            '<div class="collab-screen" id="screenJoin"></div>' +
            '<div class="collab-screen" id="screenLobby"></div>' +
            '<div class="collab-screen" id="screenHostMonitor"></div>' +
            '<div class="collab-screen" id="screenStudentWaiting"></div>' +
            '<div class="collab-screen" id="screenActivity"></div>' +
            '<div class="collab-popup-overlay" id="collabPopup"></div>';
    }

    // ============================================================
    //  방 만들기 폼
    // ============================================================
    function showCreateForm() {
        switchScreen('screenCreate');
        var el = document.getElementById('screenCreate');
        el.innerHTML =
            '<div class="collab-form">' +
                '<button class="form-back" onclick="EoCollab._backToEntry()">← 돌아가기</button>' +
                '<div style="text-align:center;margin-bottom:8px">' +
                    '<div style="font-size:20px;font-weight:900">🏫 방 만들기</div>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="form-label">방 코드 <span class="required">*</span></label>' +
                    '<input type="text" id="createCode" maxlength="12" placeholder="예: 1반, 3모둠">' +
                    '<div class="error-msg" id="createCodeErr"></div>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="form-label">학교명 <span class="required">*</span></label>' +
                    '<input type="text" id="createSchool" maxlength="20" placeholder="예: 이오중학교">' +
                    '<div class="error-msg" id="createSchoolErr"></div>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="form-label">인원 수 (선생님 제외) <span class="required">*</span></label>' +
                    '<input type="number" id="createCount" min="2" max="50" value="4" placeholder="2~50">' +
                    '<div class="error-msg" id="createCountErr"></div>' +
                '</div>' +
                '<button class="collab-btn collab-btn-primary" onclick="EoCollab._doCreate()">방 만들기</button>' +
            '</div>';
    }

    function doCreate() {
        var code = document.getElementById('createCode').value.trim();
        var school = document.getElementById('createSchool').value.trim();
        var count = parseInt(document.getElementById('createCount').value) || 0;

        // 검증
        clearErrors();
        var hasErr = false;
        if (code.length < 1) { showFieldErr('createCodeErr', '방 코드를 입력해 주세요.'); hasErr = true; }
        if (school.length < 2) { showFieldErr('createSchoolErr', '학교명을 입력해 주세요.'); hasErr = true; }
        if (count < 2 || count > 50) { showFieldErr('createCountErr', '2~50 사이로 입력해 주세요.'); hasErr = true; }
        if (hasErr) return;

        // 중복 클릭 방지
        var btn = document.querySelector('#screenCreate .collab-btn-primary');
        if (btn) { btn.disabled = true; btn.textContent = '처리 중...'; }

        apiPost('/collab.php?action=create', {
            room_code: code,
            school: school,
            activity_id: state.activityId,
            expected_count: count,
            min_count: config.minCount,
            context: state.context
        }, function(data) {
            state.roomId = data.room_id;
            state.participantId = data.participant_id;
            state.isHost = true;
            state.nickname = '선생님';
            state.roomCode = code;
            state.school = school;
            state.expectedCount = count;
            showBadge();
            renderLobby();
        }, function(err) {
            showFieldErr('createCodeErr', err);
            // 실패 시 버튼 복구
            var btn = document.querySelector('#screenCreate .collab-btn-primary');
            if (btn) { btn.disabled = false; btn.textContent = '방 만들기'; }
        });
    }

    // ============================================================
    //  참여하기 폼
    // ============================================================
    function showJoinForm() {
        switchScreen('screenJoin');
        var el = document.getElementById('screenJoin');
        var viaQR = !!state._urlRoomId;

        // QR 경유: 방 코드/학교명 입력 불필요 (room_id로 직접 연결)
        var manualFields = viaQR
            ? '<div style="text-align:center;padding:10px;background:var(--accent-dim);border-radius:10px;font-size:13px;color:var(--accent);font-weight:600">' +
                  '✅ QR 코드로 연결되었습니다' +
              '</div>' +
              '<div class="error-msg" id="joinCodeErr"></div>'
            : '<div class="form-group">' +
                  '<label class="form-label">학교명 <span class="required">*</span></label>' +
                  '<input type="text" id="joinSchool" maxlength="20" placeholder="예: 이오중학교">' +
                  '<div class="error-msg" id="joinSchoolErr"></div>' +
              '</div>' +
              '<div class="form-group">' +
                  '<label class="form-label">방 코드 <span class="required">*</span></label>' +
                  '<input type="text" id="joinCode" maxlength="12" placeholder="선생님이 알려준 코드">' +
                  '<div class="error-msg" id="joinCodeErr"></div>' +
              '</div>';

        el.innerHTML =
            '<div class="collab-form">' +
                '<button class="form-back" onclick="EoCollab._backToEntry()">← 돌아가기</button>' +
                '<div style="text-align:center;margin-bottom:8px">' +
                    '<div style="font-size:20px;font-weight:900">🙋 참여하기</div>' +
                '</div>' +
                manualFields +
                '<div class="form-group">' +
                    '<label class="form-label">별명 <span class="required">*</span></label>' +
                    '<input type="text" id="joinNick" maxlength="12" placeholder="활동에서 사용할 별명">' +
                    '<div class="error-msg" id="joinNickErr"></div>' +
                '</div>' +
                '<button class="collab-btn collab-btn-primary" onclick="EoCollab._doJoin()">참여하기</button>' +
            '</div>';

        // QR로 들어왔으면 별명 입력란에 포커스
        if (viaQR) {
            var nickEl = document.getElementById('joinNick');
            if (nickEl) nickEl.focus();
        }
    }

    function doJoin() {
        var viaQR = !!state._urlRoomId;
        var codeEl = document.getElementById('joinCode');
        var schoolEl = document.getElementById('joinSchool');
        var code = codeEl ? codeEl.value.trim() : '';
        var school = schoolEl ? schoolEl.value.trim() : '';
        var nick = document.getElementById('joinNick').value.trim();

        clearErrors();
        var hasErr = false;
        if (!viaQR) {
            if (school.length < 2) { showFieldErr('joinSchoolErr', '학교명을 입력해 주세요.'); hasErr = true; }
            if (code.length < 1) { showFieldErr('joinCodeErr', '방 코드를 입력해 주세요.'); hasErr = true; }
        }
        if (nick.length < 1) { showFieldErr('joinNickErr', '별명을 입력해 주세요.'); hasErr = true; }
        if (nick === '선생님') { showFieldErr('joinNickErr', '"선생님"은 사용할 수 없습니다.'); hasErr = true; }
        if (hasErr) return;

        // 중복 클릭 방지
        var btn = document.querySelector('#screenJoin .collab-btn-primary');
        if (btn) { btn.disabled = true; btn.textContent = '접속 중...'; }

        var body = {
            room_code: code,
            school: school,
            activity_id: state.activityId,
            nickname: nick
        };

        // QR 경유: room_id 직접 지정 (겹침 없음)
        if (viaQR) {
            body.room_id = parseInt(state._urlRoomId, 10);
        }

        apiPost('/collab.php?action=join', body, function(data) {
            state.roomId = data.room_id;
            state.participantId = data.participant_id;
            state.isHost = false;
            state.nickname = nick;
            state.roomCode = data.room_code || code;
            state.school = data.school || school;
            showBadge();
            renderLobby();
        }, function(err) {
            if (err.indexOf('별명') >= 0) {
                showFieldErr('joinNickErr', err);
            } else if (err.indexOf('학교') >= 0) {
                showFieldErr('joinSchoolErr', err);
            } else {
                showFieldErr('joinCodeErr', err);
            }
            // 실패 시 버튼 복구
            var btn = document.querySelector('#screenJoin .collab-btn-primary');
            if (btn) { btn.disabled = false; btn.textContent = '참여하기'; }
        });
    }

    // ============================================================
    //  대기실 (Lobby)
    // ============================================================
    function renderLobby() {
        switchScreen('screenLobby');
        var el = document.getElementById('screenLobby');

        var hostControls = state.isHost ?
            '<div class="lobby-host-controls">' +
                '<div class="lobby-min-warning" id="lobbyMinWarn" style="display:none"></div>' +
                '<button class="collab-btn collab-btn-success" id="lobbyStartBtn" onclick="EoCollab._startActivity()">' +
                    '활동 시작' +
                '</button>' +
            '</div>' :
            '<div class="lobby-waiting-msg">' +
                '<span class="spinner-sm"></span>선생님이 활동을 시작할 때까지 기다려 주세요' +
            '</div>';

        var qrSection = state.isHost ?
            '<div class="lobby-qr">' +
                '<div class="lobby-qr-title">학생용 QR 코드</div>' +
                '<div class="lobby-qr-canvas" id="lobbyQrCanvas"></div>' +
                '<div class="lobby-qr-url" id="lobbyQrUrl"></div>' +
            '</div>' : '';

        el.innerHTML =
            '<div class="lobby-header">' +
                '<div class="lobby-school">' + esc(state.school) + '</div>' +
                '<div class="lobby-code-display">' + esc(state.roomCode) + '</div>' +
                '<div class="lobby-count"><span class="current" id="lobbyCurrentCount">0</span> / ' + state.expectedCount + '명</div>' +
            '</div>' +
            qrSection +
            '<div class="lobby-participants">' +
                '<div class="lobby-participants-title">참여자</div>' +
                '<div class="lobby-participant-list" id="lobbyList"></div>' +
            '</div>' +
            hostControls;

        // 호스트: QR 코드 생성
        if (state.isHost) {
            generateLobbyQR();
        }

        // 폴링 시작
        startPolling(POLL_LOBBY);
        fetchStatus(); // 즉시 1회
    }

    function generateLobbyQR() {
        var canvas = document.getElementById('lobbyQrCanvas');
        var urlEl = document.getElementById('lobbyQrUrl');
        if (!canvas) return;

        // 현재 페이지 URL에 room(room_id) 파라미터 추가
        // room_id는 DB 고유 ID → 다른 학교와 겹칠 일 없음
        var base = window.location.origin + window.location.pathname;
        var params = new URLSearchParams(window.location.search);
        params.delete('code');  // 혹시 남아있을 수 있는 구 파라미터 제거
        params.set('room', state.roomId);
        var joinUrl = base + '?' + params.toString();

        // URL 표시
        if (urlEl) {
            urlEl.innerHTML = '<a href="' + joinUrl + '" target="_blank">' + joinUrl + '</a>';
        }

        // QR 코드 생성 (qrcode.js 라이브러리)
        if (typeof QRCode !== 'undefined') {
            new QRCode(canvas, {
                text: joinUrl,
                width: 180,
                height: 180,
                colorDark: '#1a1a2e',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            canvas.innerHTML = '<div style="padding:20px;color:var(--text-light);font-size:12px">QR 라이브러리 로딩 실패</div>';
        }
    }

    function updateLobbyUI(data) {
        state.participants = data.participants;
        state.status = data.status;
        state.currentPhase = data.current_phase;

        // 학생: 활동이 시작되면 자동으로 활동 화면으로 전환
        if (!state.isHost && data.status === 'active' && data.current_phase > 0) {
            stopPolling();
            enterPhase(data.current_phase);
            return;
        }

        // 참여자 수 (방장 제외)
        var studentCount = 0;
        data.participants.forEach(function(p) { if (!p.is_host) studentCount++; });

        var countEl = document.getElementById('lobbyCurrentCount');
        if (countEl) countEl.textContent = studentCount;

        // 참여자 목록
        var listEl = document.getElementById('lobbyList');
        if (listEl) {
            listEl.innerHTML = data.participants.map(function(p) {
                var cls = p.is_host ? 'lobby-participant-tag host' : 'lobby-participant-tag';
                var icon = p.is_host ? '🏫 ' : '';
                return '<span class="' + cls + '">' + icon + esc(p.nickname) + '</span>';
            }).join('');
        }

        // 방장: 최소 인원 경고
        if (state.isHost) {
            var warnEl = document.getElementById('lobbyMinWarn');
            if (warnEl) {
                if (studentCount < state.minCount) {
                    warnEl.textContent = '최소 ' + state.minCount + '명이 필요합니다 (현재 ' + studentCount + '명)';
                    warnEl.style.display = 'block';
                } else {
                    warnEl.style.display = 'none';
                }
            }
        }
    }

    // ============================================================
    //  활동 시작 (방장)
    // ============================================================
    function startActivity() {
        // 학생 수 확인
        var studentCount = 0;
        state.participants.forEach(function(p) { if (!p.is_host) studentCount++; });

        if (studentCount < state.minCount) {
            showPopup(
                '인원이 부족합니다',
                '최소 ' + state.minCount + '명이 필요하지만 현재 ' + studentCount + '명입니다.<br>그래도 시작하시겠습니까?',
                function() { doStartActivity(); },
                function() { /* 취소 */ }
            );
            return;
        }

        doStartActivity();
    }

    function doStartActivity() {
        // 중복 클릭 방지
        var btn = document.getElementById('lobbyStartBtn');
        if (btn) { btn.disabled = true; btn.textContent = '시작 중...'; }

        apiPost('/collab.php?action=start', {
            room_id: state.roomId,
            participant_id: state.participantId
        }, function() {
            stopPolling();
            state.status = 'active';
            state.currentPhase = 1;
            enterPhase(1);
        });
    }

    // ============================================================
    //  단계 진입
    // ============================================================
    function enterPhase(phase) {
        state.currentPhase = phase;
        state.phaseDone = false;

        if (state.isHost) {
            renderHostMonitor(phase);
            startPolling(POLL_ACTIVE);
        } else {
            switchScreen('screenActivity');
            if (config.onPhaseStart) {
                config.onPhaseStart(phase, {
                    participants: state.participants,
                    roomId: state.roomId,
                    participantId: state.participantId,
                    activityEl: document.getElementById('screenActivity')
                });
            }
            // 학생도 폴링: 단계 변경 감지
            startPolling(POLL_ACTIVE);
        }
    }

    // ============================================================
    //  호스트 모니터 화면
    // ============================================================
    function renderHostMonitor(phase) {
        switchScreen('screenHostMonitor');
        var phaseInfo = config.phases[phase - 1] || { name: '단계 ' + phase, hostLabel: '' };
        var totalPhases = config.phases.length;
        var isLastPhase = (phase >= totalPhases);

        var nextLabel = isLastPhase ? '결과 보기' : '다음 단계';

        var el = document.getElementById('screenHostMonitor');
        el.innerHTML =
            '<div class="host-monitor">' +
                '<div class="host-phase-label">PHASE ' + phase + ' / ' + totalPhases + '</div>' +
                '<div class="host-phase-name">' + esc(phaseInfo.hostLabel || phaseInfo.name) + '</div>' +
                '<div class="host-progress-ring" id="hostRing">' +
                    '<svg viewBox="0 0 160 160">' +
                        '<circle class="ring-bg" cx="80" cy="80" r="65"/>' +
                        '<circle class="ring-fill" id="ringFill" cx="80" cy="80" r="65" ' +
                            'stroke-dasharray="408.4" stroke-dashoffset="408.4"/>' +
                    '</svg>' +
                    '<div class="host-progress-text">' +
                        '<div class="num" id="hostProgressNum">0/?</div>' +
                        '<div class="label">완료</div>' +
                    '</div>' +
                '</div>' +
                '<div class="host-next-btn">' +
                    '<button class="collab-btn collab-btn-success" id="hostNextBtn" onclick="EoCollab._nextPhase()">' +
                        nextLabel +
                    '</button>' +
                '</div>' +
            '</div>';
    }

    function updateHostMonitor(data) {
        if (!data.phase_progress) return;
        var completed = data.phase_progress.completed;
        var total = data.phase_progress.total;

        var numEl = document.getElementById('hostProgressNum');
        if (numEl) numEl.textContent = completed + '/' + total;

        var circumference = 408.4;
        var pct = total > 0 ? completed / total : 0;
        var offset = circumference * (1 - pct);
        var fillEl = document.getElementById('ringFill');
        if (fillEl) fillEl.setAttribute('stroke-dashoffset', offset);
    }

    // ============================================================
    //  다음 단계 (방장)
    // ============================================================
    function nextPhase() {
        // 중복 클릭 방지
        var btn = document.getElementById('hostNextBtn');
        if (btn) { btn.disabled = true; btn.textContent = '처리 중...'; }

        var totalPhases = config.phases.length;
        var next = state.currentPhase + 1;

        if (next > totalPhases) {
            // 마지막 단계 이후 → 결과 표시
            // 결과 단계 진입: 활동 파일의 onPhaseStart에서 결과 렌더링
            apiPost('/collab.php?action=next_phase', {
                room_id: state.roomId,
                participant_id: state.participantId
            }, function(data) {
                stopPolling();
                state.currentPhase = data.new_phase;
                // 호스트도 결과 화면으로
                switchScreen('screenActivity');
                if (config.onPhaseStart) {
                    config.onPhaseStart(data.new_phase, {
                        participants: state.participants,
                        roomId: state.roomId,
                        participantId: state.participantId,
                        activityEl: document.getElementById('screenActivity'),
                        isResultPhase: true
                    });
                }
            });
            return;
        }

        apiPost('/collab.php?action=next_phase', {
            room_id: state.roomId,
            participant_id: state.participantId
        }, function(data) {
            state.currentPhase = data.new_phase;
            enterPhase(data.new_phase);
        });
    }

    // ============================================================
    //  단계 데이터 제출 (학생)
    // ============================================================
    function submitPhaseData(phase, phaseData, callback) {
        apiPost('/collab.php?action=submit_phase', {
            room_id: state.roomId,
            participant_id: state.participantId,
            phase: phase,
            phase_data: phaseData
        }, function() {
            state.phaseDone = true;
            showStudentWaiting();
            if (callback) callback();
        });
    }

    function showStudentWaiting() {
        switchScreen('screenStudentWaiting');
        var el = document.getElementById('screenStudentWaiting');
        el.innerHTML =
            '<div class="student-waiting">' +
                '<div class="waiting-icon">✅</div>' +
                '<div class="waiting-title">제출 완료!</div>' +
                '<div class="waiting-desc">다른 친구들이 완료할 때까지 기다려 주세요.<br>선생님이 다음 단계를 시작하면 자동으로 넘어갑니다.</div>' +
            '</div>';
        // 폴링 유지: 단계 변경 감지
    }

    // ============================================================
    //  평가 대상 배정 요청 (방장이 Phase 2 진입 시)
    // ============================================================
    function assignTargets(callback) {
        apiPost('/collab.php?action=assign_targets', {
            room_id: state.roomId,
            participant_id: state.participantId,
            max_targets: config.maxTargets
        }, function(data) {
            if (callback) callback(data);
        });
    }

    // ============================================================
    //  단계 데이터 조회
    // ============================================================
    function getPhaseData(phase, callback) {
        apiGet('/collab.php?action=phase_data&room_id=' + state.roomId + '&phase=' + phase, callback);
    }

    // ============================================================
    //  폴링
    // ============================================================
    function startPolling(interval) {
        stopPolling();
        pollTimer = setInterval(fetchStatus, interval);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function fetchStatus() {
        if (!state.roomId) return;
        apiGet('/collab.php?action=status&room_id=' + state.roomId, function(data) {
            // 대기실에서의 업데이트
            if (state.status === 'waiting' && data.status === 'waiting') {
                updateLobbyUI(data);
                return;
            }
            // 대기실 → 활동 전환 감지 (학생)
            if (state.status === 'waiting' && data.status === 'active') {
                state.status = 'active';
                updateLobbyUI(data); // 여기서 enterPhase 호출됨
                return;
            }
            // 활동 중 — 호스트 모니터 업데이트
            if (state.isHost && data.status === 'active') {
                state.participants = data.participants;  // 참여자 목록 동기화
                updateHostMonitor(data);
                return;
            }
            // 활동 중 — 학생 단계 변경 감지
            if (!state.isHost && data.status === 'active') {
                state.participants = data.participants;  // 참여자 목록 동기화
                if (data.current_phase !== state.currentPhase) {
                    stopPolling();
                    enterPhase(data.current_phase);
                }
                return;
            }
        });
    }

    // ============================================================
    //  뱃지
    // ============================================================
    function showBadge() {
        var el = document.getElementById('collabBadge');
        if (!el) return;
        var roleClass = state.isHost ? 'host' : 'student';
        var roleText = state.isHost ? '방장' : '학생';
        el.innerHTML =
            '<div>' +
                '<span class="badge-code">🔑 ' + esc(state.roomCode) + '</span>' +
                '<span class="badge-dot">·</span>' +
                '<span class="badge-school">' + esc(state.school) + '</span>' +
                '<span class="badge-dot">·</span>' +
                '<span class="badge-nick">' + esc(state.nickname) + '</span>' +
            '</div>' +
            '<span class="badge-role ' + roleClass + '">' + roleText + '</span>';
        el.classList.add('show');
    }

    // ============================================================
    //  팝업
    // ============================================================
    function showPopup(title, message, onConfirm, onCancel) {
        var el = document.getElementById('collabPopup');
        el.innerHTML =
            '<div class="collab-popup">' +
                '<h3>' + title + '</h3>' +
                '<p>' + message + '</p>' +
                '<div class="popup-btns">' +
                    '<button class="collab-btn collab-btn-secondary" id="popupCancel">취소</button>' +
                    '<button class="collab-btn collab-btn-primary" id="popupConfirm">시작</button>' +
                '</div>' +
            '</div>';
        el.classList.add('show');

        document.getElementById('popupConfirm').onclick = function() {
            el.classList.remove('show');
            if (onConfirm) onConfirm();
        };
        document.getElementById('popupCancel').onclick = function() {
            el.classList.remove('show');
            if (onCancel) onCancel();
        };
    }

    // ============================================================
    //  유틸리티
    // ============================================================
    function getContainer() {
        var c = document.querySelector('.collab-container');
        if (!c) {
            c = document.createElement('div');
            c.className = 'collab-container';
            document.body.appendChild(c);
        }
        return c;
    }

    function switchScreen(id) {
        document.querySelectorAll('.collab-screen').forEach(function(s) {
            s.classList.remove('active');
        });
        var target = document.getElementById(id);
        if (target) target.classList.add('active');
    }

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function clearErrors() {
        document.querySelectorAll('.error-msg').forEach(function(e) { e.textContent = ''; });
    }

    function showFieldErr(id, msg) {
        var el = document.getElementById(id);
        if (el) el.textContent = msg;
    }

    function apiPost(url, body, onSuccess, onError) {
        fetch(API_BASE + url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.ok) {
                if (onSuccess) onSuccess(res.data);
            } else {
                if (onError) onError(res.data.error || '오류가 발생했습니다.');
            }
        })
        .catch(function(e) {
            console.error('API error:', e);
            if (onError) onError('서버 연결에 실패했습니다.');
        });
    }

    function apiGet(url, onSuccess) {
        fetch(API_BASE + url)
        .then(function(r) { return r.json(); })
        .then(function(data) { if (onSuccess) onSuccess(data); })
        .catch(function(e) { console.error('API error:', e); });
    }

    // ============================================================
    //  공개 API
    // ============================================================
    return {
        init: init,
        state: state,
        submitPhaseData: submitPhaseData,
        assignTargets: assignTargets,
        getPhaseData: getPhaseData,
        switchScreen: switchScreen,
        showBadge: showBadge,

        // 내부 호출용 (onclick에서 사용)
        _showCreateForm: showCreateForm,
        _showJoinForm: showJoinForm,
        _backToEntry: function() { switchScreen('screenEntry'); },
        _doCreate: doCreate,
        _doJoin: doJoin,
        _startActivity: startActivity,
        _nextPhase: nextPhase
    };
})();
