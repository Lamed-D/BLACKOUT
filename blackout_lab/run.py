from app import create_app

# ─── 실행 호스트 설정 ───────────────────────────────────────────────────
# '127.0.0.1' → 로컬 전용 (Command Injection 실습 활성화)
# '0.0.0.0'   → 네트워크 공개 (Command Injection 자동 비활성화 — ping만 허용)
HOST = '127.0.0.1'
PORT = 5000
# ────────────────────────────────────────────────────────────────────────

app = create_app(allow_cmd_exec=(HOST == '127.0.0.1'))

if __name__ == '__main__':
    app.run(host=HOST, port=PORT, debug=True)
