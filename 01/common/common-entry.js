/* ============================================================
   EoBooks Digital Contents — Common Entry & Result Module
   공통 로직: 초기화면 생성, 입력 검증, API 호출, 뱃지, 결과 탭
   수정 시 전 활동에 일괄 반영됩니다.

   사용법 (각 활동 파일에서):
   ─────────────────────────────────────
   EoEntry.init({
       activityId: 'unit1_act1',
       title: '🎭 직업 카드 어드벤처',
       subtitle: '나의 가치관과 적성을 발견해보세요!',
       onStart: function() {
           // 활동 시작 시 실행할 코드 (화면 전환, 초기화 등)
       }
   });
   ─────────────────────────────────────
   ============================================================ */

const EoEntry = (function () {

    // ==================== 공통 설정 ====================
    const API_BASE = '/d_contents/01/api';

    // ==================== 공유 상태 ====================
    // 활동별 JS에서 EoEntry.state로 접근 가능
    const state = {
        sessionCode: '',
        school: '',
        nickname: '',
        participantId: null,
        groupId: null,
        mode: 'solo',       // 'solo' 또는 'group'
        currentScreen: 'entry'
    };

    let _config = {};   // init()에서 전달받는 활동별 설정

    // ==================== 초기화 ====================
    function init(config) {
        _config = config;

        // URL에서 context 자동 감지 (?context=middle 또는 ?context=high)
        var params = new URLSearchParams(window.location.search);
        state.context = params.get('context') || '';   // 'middle', 'high', 또는 ''

        _buildEntryScreen();
        _bindEntryEvents();
    }

    // ==================== 초기 화면 HTML 생성 ====================
    function _buildEntryScreen() {
        const el = document.getElementById('entryScreen');
        if (!el) return;

        el.innerHTML = `
            <div class="entry-header">
                <div class="entry-title">${_config.title || ''}</div>
                <div class="entry-subtitle">${_config.subtitle || ''}</div>
            </div>

            <div class="entry-instructions">
                <strong>\uD83D\uDCD6 사용 방법</strong>
                <ol>
                    <li>학교와 별명을 입력하세요.</li>
                    <li>함께 할 친구들이 있으면 함께하기 코드를 입력하세요.</li>
                    <li>함께하기 코드는 최대 12자까지 입력할 수 있습니다.</li>
                    <li>"활동 시작"을 눌러 시작하세요.</li>
                </ol>
            </div>

            <div class="entry-form">
                <div class="form-group">
                    <label class="form-label">함께하기 코드 <span style="color: #aaa; font-weight: normal;">(선택)</span></label>
                    <input type="text" id="sessionCode" placeholder="예: 1반 (함께할 친구들이 입력할 코드)" maxlength="12">
                    <div class="error-message" id="codeError"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">학교 <span class="required">*</span></label>
                    <input type="text" id="school" placeholder="예: ${state.context === 'high' ? '이오고등학교' : '이오중학교'}" minlength="2" maxlength="20">
                    <div class="error-message" id="schoolError"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">별명 <span class="required">*</span></label>
                    <input type="text" id="nickname" placeholder="예: 김진로" maxlength="12">
                    <div class="error-message" id="nicknameError"></div>
                </div>

                <div style="text-align: center;"><button class="btn btn-primary" id="startBtn" disabled style="margin-top: 16px;">활동 시작</button></div>
            </div>
        `;
    }

    // ==================== 이벤트 바인딩 ====================
    function _bindEntryEvents() {
        const codeInput = document.getElementById('sessionCode');
        const schoolInput = document.getElementById('school');
        const nicknameInput = document.getElementById('nickname');
        const startBtn = document.getElementById('startBtn');

        if (!codeInput || !schoolInput || !nicknameInput || !startBtn) return;

        function validateForm() {
            const hasSchool = schoolInput.value.trim().length >= 1;
            const hasNickname = nicknameInput.value.trim().length >= 1;
            startBtn.disabled = !(hasSchool && hasNickname);
        }

        codeInput.addEventListener('input', validateForm);
        schoolInput.addEventListener('input', validateForm);
        nicknameInput.addEventListener('input', validateForm);
        startBtn.addEventListener('click', function () { _handleStart(); });
    }

    // ==================== 활동 시작 처리 ====================
    async function _handleStart() {
        const code = document.getElementById('sessionCode').value.trim();
        const school = document.getElementById('school').value.trim();
        const nickname = document.getElementById('nickname').value.trim();
        const startBtn = document.getElementById('startBtn');

        // --- 입력 검증 ---
        if (!school) {
            showError('schoolError', '학교를 입력하세요.');
            return;
        }
        if (school.length > 20) {
            showError('schoolError', '학교는 최대 20자까지 입력할 수 있습니다.');
            return;
        }
        if (!nickname) {
            showError('nicknameError', '별명을 입력하세요.');
            return;
        }
        if (nickname.length > 12) {
            showError('nicknameError', '별명은 최대 12자까지 입력할 수 있습니다.');
            return;
        }
        if (code) {
            if (code.length > 12) {
                showError('codeError', '함께하기 코드는 최대 12자까지 입력할 수 있습니다.');
                return;
            }
        }

        // --- 상태 저장 ---
        state.nickname = nickname;
        state.school = school;
        state.sessionCode = code;

        startBtn.disabled = true;
        startBtn.textContent = '시작 중...';

        try {
            if (code) {
                // 그룹 참여
                const response = await fetch(`${API_BASE}/group.php?action=join`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        group_code: code,
                        school: school,
                        activity_id: _config.activityId,
                        nickname: nickname
                    })
                });

                const data = await response.json();

                if (!response.ok) {
                    const errorTarget = response.status === 409 ? 'nicknameError' : 'codeError';
                    showError(errorTarget, data.error || '그룹 참여에 실패했습니다.');
                    startBtn.disabled = false;
                    startBtn.textContent = '활동 시작';
                    return;
                }

                state.participantId = data.participant_id;
                state.groupId = data.group_id;
                state.mode = 'group';
            } else {
                // 혼자 하기
                const response = await fetch(`${API_BASE}/solo.php?action=start`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        activity_id: _config.activityId,
                        school: school,
                        nickname: nickname
                    })
                });

                const data = await response.json();

                if (!response.ok) {
                    showError('nicknameError', data.error || '세션 생성에 실패했습니다.');
                    startBtn.disabled = false;
                    startBtn.textContent = '활동 시작';
                    return;
                }

                state.participantId = data.participant_id;
                state.groupId = null;
                state.mode = 'solo';
            }

            // 뱃지 표시 (내용 채우기 + display:flex + body padding)
            showBadge();

            // 활동별 시작 콜백 호출
            if (typeof _config.onStart === 'function') {
                _config.onStart();
            }

        } catch (error) {
            console.error('Start error:', error);
            showError('nicknameError', '서버에 연결할 수 없습니다. 인터넷 연결을 확인하고 다시 시도해 주세요.');
            startBtn.disabled = false;
            startBtn.textContent = '활동 시작';
        }
    }

    // ==================== 에러 메시지 표시 ====================
    function showError(elementId, message) {
        const errorEl = document.getElementById(elementId);
        if (!errorEl) return;
        errorEl.textContent = message;
        errorEl.classList.add('show');
        setTimeout(function () {
            errorEl.classList.remove('show');
            errorEl.textContent = '';
        }, 4000);
    }

    // ==================== 화면 전환 ====================
    function showScreen(screenName) {
        document.querySelectorAll('.screen').forEach(function (s) {
            s.classList.remove('active');
        });
        var el = document.getElementById(screenName + 'Screen');
        if (el) el.classList.add('active');
        state.currentScreen = screenName;
    }

    // ==================== 뱃지 일괄 업데이트 ====================
    // ID + 클래스 양쪽 모두 지원 (활동 HTML이 ID를 사용하므로)
    function updateAllBadges() {
        var codeDisplay = state.sessionCode
            ? '함께하기 코드: ' + state.sessionCode
            : '혼자 하기';
        var schoolDisplay = '학교: ' + state.school;
        var nicknameDisplay = '별명: ' + state.nickname;

        // 클래스 기반 (기존 호환)
        document.querySelectorAll('.badge-code').forEach(function (el) {
            el.textContent = codeDisplay;
        });
        document.querySelectorAll('.badge-school').forEach(function (el) {
            el.textContent = schoolDisplay;
        });
        document.querySelectorAll('.badge-nickname').forEach(function (el) {
            el.textContent = nicknameDisplay;
        });

        // ID 기반 (#user-badge 구조)
        var bc = document.getElementById('badge-code');
        var bs = document.getElementById('badge-school');
        var bn = document.getElementById('badge-nick');
        if (bc) bc.textContent = codeDisplay;
        if (bs) bs.textContent = schoolDisplay;
        if (bn) bn.textContent = nicknameDisplay;
    }

    // ==================== 뱃지 표시/숨김 ====================
    // 활동 시작 시 #user-badge를 보여주고 body padding 추가
    function showBadge() {
        var badge = document.getElementById('user-badge');
        if (!badge) return;
        updateAllBadges();
        badge.style.display = 'flex';
        document.body.style.paddingTop = '32px';
    }

    function hideBadge() {
        var badge = document.getElementById('user-badge');
        if (!badge) return;
        badge.style.display = 'none';
        document.body.style.paddingTop = '0';
    }

    // ==================== 결과 탭 전환 ====================
    function setupResultTabs() {
        document.querySelectorAll('.result-tab').forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                var tabName = e.target.getAttribute('data-tab');
                document.querySelectorAll('.result-tab').forEach(function (t) {
                    t.classList.remove('active');
                });
                document.querySelectorAll('.result-content').forEach(function (c) {
                    c.classList.remove('active');
                });
                e.target.classList.add('active');
                var targetEl = document.getElementById(tabName);
                if (targetEl) targetEl.classList.add('active');
            });
        });

        // 혼자 하기일 때 "우리 반 통계" 탭 숨김
        if (state.mode !== 'group') {
            var groupTab = document.getElementById('groupStatsTab');
            if (groupTab) groupTab.style.display = 'none';
        }
    }

    // ==================== 새로고침 버튼 쿨다운 (10초) ====================
    function refreshCooldown(btnId) {
        var btn = document.getElementById(btnId);
        if (!btn) return;

        btn.disabled = true;
        var cooldown = 10;
        var interval = setInterval(function () {
            cooldown--;
            btn.textContent = '새로고침 (' + cooldown + '초)';
            if (cooldown <= 0) {
                clearInterval(interval);
                btn.disabled = false;
                btn.textContent = '새로고침';
            }
        }, 1000);
    }

    // ==================== Public API ====================
    return {
        init: init,
        state: state,
        API_BASE: API_BASE,
        showError: showError,
        showScreen: showScreen,
        showBadge: showBadge,
        hideBadge: hideBadge,
        updateAllBadges: updateAllBadges,
        setupResultTabs: setupResultTabs,
        refreshCooldown: refreshCooldown
    };

})();
