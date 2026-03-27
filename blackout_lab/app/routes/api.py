from flask import Blueprint, jsonify, request, make_response, session
from lxml import etree
import pickle
import base64
import jwt as pyjwt

bp = Blueprint('api', __name__, url_prefix='/api')

def log_telemetry(tag, message):
    entries = session.get('telemetry', [])
    session['telemetry'] = entries[-19:] + [{'tag': tag, 'msg': message}]
    session.modified = True


@bp.route('/import', methods=['POST'])
def xml_import():
    # [VULNERABILITY] XXE (XML External Entity)
    xml_data = request.data
    if not xml_data:
        return jsonify({"error": "No XML data provided"}), 400
        
    try:
        # resolve_entities=True explicitly allows XXE parsing
        parser = etree.XMLParser(resolve_entities=True, no_network=False)
        root = etree.fromstring(xml_data, parser)
        return jsonify({"message": f"XML parsing successful. Root tag: {root.tag}", "content": root.text})
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@bp.route('/config', methods=['POST'])
def load_config():
    # [VULNERABILITY] Insecure Deserialization
    encoded_data = request.form.get('data') or request.json.get('data')
    if not encoded_data:
        return jsonify({"error": "No data provided"}), 400
        
    try:
        data = base64.b64decode(encoded_data)
        # Vulnerability here: unpickling arbitrary data directly leads to RCE
        obj = pickle.loads(data)
        return jsonify({"message": "Configuration loaded", "config_type": str(type(obj))})
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@bp.route('/user_data', methods=['GET'])
def user_data():
    # [VULNERABILITY] CORS Misconfiguration
    origin = request.headers.get('Origin', '*')
    resp = make_response(jsonify({"sensitive_data": "USER_API_KEY_54321", "credit": 500}))
    resp.headers['Access-Control-Allow-Origin'] = origin
    resp.headers['Access-Control-Allow-Credentials'] = 'true'
    return resp

@bp.route('/clear_telemetry', methods=['POST'])
def clear_telemetry():
    session.pop('telemetry', None)
    session.modified = True
    return jsonify({"ok": True})

@bp.route('/jwt_verify', methods=['POST'])
def jwt_verify():
    """[VULNERABILITY] JWT Forgery - uses weak hardcoded secret 'secret'.
    Users can forge an admin token by signing with 'secret' or using algorithm=none."""
    token = (request.json or {}).get('token', '')
    if not token:
        return jsonify({"error": "No token provided"}), 400
    try:
        # WEAK: secret is trivially guessable
        payload = pyjwt.decode(token, 'secret', algorithms=['HS256', 'none'])
        log_telemetry('JWT', f"Token decoded: role={payload.get('role')} user={payload.get('username')}")
        if payload.get('role') == 'admin':
            return jsonify({"status": "SUCCESS", "message": "관리자 권한 획득!", "payload": payload})
        return jsonify({"status": "OK", "message": "일반 사용자 토큰입니다.", "payload": payload})
    except Exception as e:
        return jsonify({"error": str(e)}), 401

@bp.route('/ssti_theme', methods=['POST'])
def ssti_theme():
    """[VULNERABILITY] Server-Side Template Injection via render_template_string."""
    from flask import render_template_string
    user_input = (request.json or {}).get('theme', '')
    log_telemetry('SSTI', f"render_template_string input: {user_input[:120]}")
    try:
        # VULNERABILITY: user input directly rendered as a Jinja2 template
        result = render_template_string(user_input)
        return jsonify({"rendered": result})
    except Exception as e:
        return jsonify({"error": str(e)}), 500
