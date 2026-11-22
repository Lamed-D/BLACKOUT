"""독립 SSO 경로(Google/Kakao/Naver). 기존 로컬 로그인 흐름은 변경하지 않는다.

PHP/Node 엔진과 동일한 OAuth 2.0 흐름을 표준 라이브러리(urllib)로 구현한 안전한 코드.
공급자 키는 환경변수로 주입하며, 미설정 시 로컬 로그인에는 영향이 없다.
"""
import hmac
import json
import os
import secrets
import urllib.parse
import urllib.request

from django.contrib.auth import get_user_model, login
from django.contrib import messages
from django.http import HttpResponseNotFound
from django.shortcuts import redirect, render

from .models import Profile

PROVIDERS = {
    "google": {
        "prefix": "GOOGLE",
        "authorize": "https://accounts.google.com/o/oauth2/v2/auth",
        "token": "https://oauth2.googleapis.com/token",
        "profile": "https://www.googleapis.com/oauth2/v2/userinfo",
        "scope": "openid email profile",
    },
    "kakao": {
        "prefix": "KAKAO",
        "authorize": "https://kauth.kakao.com/oauth/authorize",
        "token": "https://kauth.kakao.com/oauth/token",
        "profile": "https://kapi.kakao.com/v2/user/me",
        "scope": "profile_nickname account_email",
    },
    "naver": {
        "prefix": "NAVER",
        "authorize": "https://nid.naver.com/oauth2.0/authorize",
        "token": "https://nid.naver.com/oauth2.0/token",
        "profile": "https://openapi.naver.com/v1/nid/me",
        "scope": "",
    },
}


def _config(provider):
    base = PROVIDERS.get(provider)
    if not base:
        return None
    p = base["prefix"]
    # 엔드포인트는 환경변수로 덮어쓸 수 있다(자체호스팅·로컬 테스트용). 미설정 시 실제 공급자 URL.
    return {
        "id": os.environ.get(p + "_CLIENT_ID", ""),
        "secret": os.environ.get(p + "_CLIENT_SECRET", ""),
        "authorize": os.environ.get(p + "_AUTHORIZE_URL", base["authorize"]),
        "token": os.environ.get(p + "_TOKEN_URL", base["token"]),
        "profile": os.environ.get(p + "_PROFILE_URL", base["profile"]),
        "scope": base["scope"],
    }


def _callback_url(provider):
    root = os.environ.get("OAUTH_REDIRECT_BASE", "http://127.0.0.1:8103").rstrip("/")
    return f"{root}/oauth/{provider}/callback"


def _request(url, form=None, bearer=None):
    headers = {"Accept": "application/json"}
    data = None
    if form is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
        data = urllib.parse.urlencode(form).encode()
    if bearer:
        headers["Authorization"] = "Bearer " + bearer
    req = urllib.request.Request(url, data=data, headers=headers,
                                 method="POST" if form is not None else "GET")
    with urllib.request.urlopen(req, timeout=8) as resp:
        body = resp.read(1048576)
    parsed = json.loads(body.decode("utf-8"))
    if not isinstance(parsed, dict):
        raise ValueError("공급자 응답 형식이 올바르지 않습니다.")
    return parsed


def _parse_profile(provider, data):
    """returns (remote_id, name, avatar, verified_email)."""
    if provider == "google":
        return (str(data.get("id", "")), str(data.get("name", "")),
                str(data.get("picture", "")),
                str(data.get("email", "")) if data.get("verified_email") else "")
    if provider == "kakao":
        account = data.get("kakao_account", {}) or {}
        prof = account.get("profile", {}) or {}
        ok = account.get("is_email_verified") and account.get("is_email_valid")
        return (str(data.get("id", "")), str(prof.get("nickname", "")),
                str(prof.get("profile_image_url", "")),
                str(account.get("email", "")) if ok else "")
    response = data.get("response", {}) or {}
    # Naver 응답에는 검증된 이메일 표시가 없어 기존 계정 이메일 매칭에 사용하지 않는다.
    return (str(response.get("id", "")), str(response.get("name") or response.get("nickname") or ""),
            str(response.get("profile_image", "")), "")


def oauth_login(request, provider):
    cfg = _config(provider)
    if cfg is None:
        return HttpResponseNotFound()
    if not cfg["id"] or not cfg["secret"]:
        messages.error(request, "소셜 로그인 키가 설정되지 않았습니다. 관리자에게 문의하세요.")
        return redirect("/login")
    state = secrets.token_hex(32)
    request.session["oauth_state_" + provider] = state
    params = {"client_id": cfg["id"], "redirect_uri": _callback_url(provider),
              "response_type": "code", "state": state}
    if cfg["scope"]:
        params["scope"] = cfg["scope"]
    return redirect(cfg["authorize"] + "?" + urllib.parse.urlencode(params))


def oauth_callback(request, provider):
    cfg = _config(provider)
    if cfg is None:
        return HttpResponseNotFound()
    expected = request.session.pop("oauth_state_" + provider, "")
    state = request.GET.get("state", "")
    code = request.GET.get("code", "")
    if not expected or not state or not code or not hmac.compare_digest(expected, state):
        return render(request, "message.html",
                      {"title": "SSO 오류", "message": "로그인 요청을 확인할 수 없습니다."}, status=400)
    try:
        form = {"grant_type": "authorization_code", "code": code,
                "redirect_uri": _callback_url(provider),
                "client_id": cfg["id"], "client_secret": cfg["secret"]}
        if provider == "naver":
            form["state"] = state
        token = _request(cfg["token"], form=form)
        access = token.get("access_token")
        if not access or not isinstance(access, str):
            raise ValueError("토큰이 없습니다.")
        profile_data = _request(cfg["profile"], bearer=access)
        remote_id, name, avatar, verified_email = _parse_profile(provider, profile_data)
        if not remote_id or len(remote_id) > 255:
            raise ValueError("계정 ID가 없습니다.")

        user_model = get_user_model()
        prof = Profile.objects.filter(oauth_provider=provider, oauth_id=remote_id).select_related("user").first()
        user = prof.user if prof else None
        if user is None and verified_email:
            user = user_model.objects.filter(email=verified_email).first()
        if user is None:
            username = f"{provider}_{secrets.token_hex(8)}"
            user = user_model.objects.create(
                username=username,
                first_name=name[:150] if name else username,
                email=verified_email or "",
            )
            user.set_unusable_password()
            user.save()
        profile, _ = Profile.objects.get_or_create(user=user)
        profile.oauth_provider = provider
        profile.oauth_id = remote_id
        if avatar:
            profile.avatar = avatar
        profile.save(update_fields=["oauth_provider", "oauth_id", "avatar"])
        login(request, user)
        messages.info(request, "소셜 계정으로 로그인되었습니다.")
        return redirect("/")
    except Exception:
        messages.error(request, "소셜 로그인에 실패했습니다. 다시 시도해 주세요.")
        return redirect("/login")
