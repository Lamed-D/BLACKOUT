(() => {
  const storageKey = 'blackout-theme';
  const saved = localStorage.getItem(storageKey);
  if (saved === 'dark') document.documentElement.dataset.theme = 'dark';
  document.addEventListener('DOMContentLoaded', () => {
    const nav = document.querySelector('.custom-nav .container > div');
    if (nav) {
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'btn btn-sm btn-outline-secondary theme-toggle';
      toggle.setAttribute('aria-label', '다크 모드 전환');
      toggle.textContent = document.documentElement.dataset.theme === 'dark' ? '☀️' : '🌙';
      toggle.addEventListener('click', () => {
        const dark = document.documentElement.dataset.theme !== 'dark';
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        localStorage.setItem(storageKey, dark ? 'dark' : 'light');
        toggle.textContent = dark ? '☀️' : '🌙';
      });
      nav.prepend(toggle);
    }

    const loginForm = document.querySelector('form[action="/login"]');
    if (loginForm) {
      const choices = document.createElement('div');
      choices.className = 'sso-options mt-4';
      choices.innerHTML = '<p class="small text-secondary text-center mb-2">간편 로그인</p>' +
        '<a class="sso-button sso-google" href="/oauth/google/login">Google</a>' +
        '<a class="sso-button sso-kakao" href="/oauth/kakao/login">Kakao</a>' +
        '<a class="sso-button sso-naver" href="/oauth/naver/login">Naver</a>';
      loginForm.after(choices);
    }

    const path = location.pathname;
    if (path === '/post/write' || /^\/post\/\d+\/edit$/.test(path)) {
      const textarea = document.querySelector('form textarea[name="content"]');
      if (!textarea) return;
      const sheet = document.createElement('link');
      sheet.rel = 'stylesheet';
      sheet.href = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css';
      document.head.append(sheet);
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js';
      script.onload = () => {
        const editor = document.createElement('div');
        editor.id = 'post-editor';
        editor.setAttribute('aria-label', '본문 편집기');
        textarea.after(editor);
        const quill = new Quill(editor, { theme: 'snow', modules: {
          toolbar: [['bold', 'italic', 'underline'], [{ header: [1, 2, 3, false] }],
            [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'blockquote', 'code-block']]
        } });
        quill.clipboard.dangerouslyPasteHTML(textarea.value);
        textarea.classList.add('visually-hidden');
        textarea.form.addEventListener('submit', () => { textarea.value = quill.root.innerHTML; });
      };
      document.head.append(script);
      const input = document.querySelector('form input[type="file"][name="file"]');
      if (input) {
        const drop = document.createElement('div');
        drop.className = 'attachment-drop';
        drop.textContent = '파일을 선택하거나 이곳에 끌어다 놓으세요';
        input.before(drop);
        drop.addEventListener('click', () => input.click());
        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragging'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragging'));
        drop.addEventListener('drop', e => {
          e.preventDefault(); drop.classList.remove('dragging');
          if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            drop.textContent = input.files[0].name;
          }
        });
        input.addEventListener('change', () => { drop.textContent = input.files[0]?.name || '파일을 선택하세요'; });
      }
    }
  });
})();
