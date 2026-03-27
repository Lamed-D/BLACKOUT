from flask import Blueprint, render_template, request, redirect, send_file, current_app, session
import os
import re
import urllib.request

bp = Blueprint('practice', __name__)

def log_telemetry(tag, message):
    entries = session.get('telemetry', [])
    entries = entries[-19:] + [{'tag': tag, 'msg': message}]
    session['telemetry'] = entries
    session.modified = True

@bp.route('/practice')
def practice():
    return render_template('practice.html')

@bp.route('/url', methods=['GET'])
def url_test():
    return render_template('url.html')

@bp.route('/utils/url_preview')
def url_preview():
    # [VULNERABILITY] SSRF / Open Redirect
    target = request.args.get('url', '')
    if not target:
        return "No URL provided."
    try:
        req = urllib.request.Request(target, headers={'User-Agent': 'Mozilla/5.0'})
        response = urllib.request.urlopen(req)
        content = response.read().decode('utf-8')
        return content
    except Exception as e:
        try:
            return redirect(target)
        except Exception:
            return f"Error connecting to {target}: {e}"

@bp.route('/ping', methods=['GET', 'POST'])
def ping():
    output = ""
    allow  = current_app.config.get('ALLOW_CMD_EXEC', True)

    if request.method == 'POST':
        ip = request.form.get('ip', '').strip()

        if not allow:
            # [NETWORK MODE] — ping only: sanitize to valid IP/hostname chars
            # Strip any shell metacharacters — Command Injection 체험 불가
            safe_ip = re.sub(r'[^\w.\-]', '', ip)
            cmd = f"ping -n 2 {safe_ip}" if os.name == 'nt' else f"ping -c 2 {safe_ip}"
            log_telemetry('CMD', f'[SAFE] {cmd}')
            try:
                output = os.popen(cmd).read()
                if not output:
                    output = f"[{safe_ip}] ping 결과가 없습니다. (Host unreachable)"
            except Exception:
                output = "Ping 실행 오류"
        else:
            # [VULNERABILITY] OS Command Injection — local mode only
            cmd = f"ping -c 4 {ip}" if os.name != 'nt' else f"ping -n 4 {ip}"
            log_telemetry('CMD', cmd)
            try:
                output = os.popen(cmd).read()
            except Exception:
                output = "Command Execution Error"

    return render_template('ping.html', output=output, allow=allow)

@bp.route('/download')
def download_file():
    filename = request.args.get('file', '')
    if not filename:
        return render_template('download.html')
    # [VULNERABILITY] Directory Traversal / Arbitrary File Download
    file_path = os.path.join(current_app.config['UPLOAD_FOLDER'], filename)
    try:
        return send_file(file_path, as_attachment=True)
    except FileNotFoundError:
        return f"File '{file_path}' not found."

@bp.route('/xss')
def xss_page():
    # [VULNERABILITY] Reflected XSS — input echoed without escaping
    return render_template('xss.html', input=request.args.get('input', ''))
