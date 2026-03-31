from flask import Flask
import os

# Flask-Dance는 기본적으로 HTTPS를 요구하므로, 로컬 개발 시 이 환경변수로 HTTP 허용
os.environ.setdefault('OAUTHLIB_INSECURE_TRANSPORT', '1')

def create_app(allow_cmd_exec: bool = True):
    # Since app is moved into /app, templates/static are up one level
    app = Flask(__name__, template_folder='../templates', static_folder='../static')
    app.config['SECRET_KEY'] = 'blackout-secret-key'
    app.config['ALLOW_CMD_EXEC'] = allow_cmd_exec
    
    BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    UPLOAD_FOLDER = os.path.join(BASE_DIR, 'uploads')
    app.config['UPLOAD_FOLDER'] = UPLOAD_FOLDER
    
    if not os.path.exists(UPLOAD_FOLDER):
        os.makedirs(UPLOAD_FOLDER)
        
    sample_txt_path = os.path.join(UPLOAD_FOLDER, 'sample.txt')
    if not os.path.exists(sample_txt_path):
        with open(sample_txt_path, 'w', encoding='utf-8') as f:
            f.write("BLACKOUT Web-Security 사내 권장 가이드라인입니다. 본 취약점 랩은 학습용도로만 사용해주세요.")
            
    # Register blueprints
    from app.routes.auth import bp as auth_bp
    from app.routes.board import bp as board_bp
    from app.routes.practice import bp as practice_bp
    from app.routes.admin import bp as admin_bp
    from app.routes.api import bp as api_bp
    from app.routes.oauth import bp as oauth_bp, google_bp
    
    app.register_blueprint(auth_bp)
    app.register_blueprint(board_bp)
    app.register_blueprint(practice_bp)
    app.register_blueprint(admin_bp)
    app.register_blueprint(api_bp)
    app.register_blueprint(oauth_bp)
    app.register_blueprint(google_bp, url_prefix='/login/google', strict_slashes=False)  # Flask-Dance Google
    
    from flask import render_template
    @app.errorhandler(404)
    def not_found_error(error):
        return render_template('404.html'), 404

    @app.errorhandler(500)
    def internal_error(error):
        return render_template('500.html', error=error), 500
        
    return app
