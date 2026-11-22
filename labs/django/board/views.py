from django.contrib.auth.decorators import login_required
import json
from pathlib import Path

from django.contrib.auth.forms import UserCreationForm
from django.core.paginator import Paginator
from django.db import transaction
from django.db.models import F, Q, Count
from django.forms import modelform_factory
from django.http import FileResponse, HttpResponseForbidden
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST
from django.views.decorators.csrf import csrf_exempt
from django.contrib import messages
from django.utils import timezone

from .models import Comment, Notice, Order, Post, PostLike, Product, Profile

PostForm = modelform_factory(Post, fields=["title", "content"])
CommentForm = modelform_factory(Comment, fields=["content"])


def index(request):
    query = request.GET.get("q", "").strip()
    posts = Post.objects.select_related("author").annotate(comment_count=Count("comments"))
    if query:
        posts = posts.filter(Q(title__icontains=query) | Q(content__icontains=query))
    page = Paginator(posts.order_by("-pk"), 10).get_page(request.GET.get("page"))
    notices = Notice.objects.select_related("author").order_by("-pk")[:3]
    return render(request, "index.html", {"page": page, "query": query,
                                           "notices": notices,
                                           "page_numbers": page.paginator.get_elided_page_range(page.number, on_each_side=2)})


def register(request):
    form = UserCreationForm(request.POST if request.method == "POST" else None)
    if form.is_valid():
        user = form.save()
        Profile.objects.get_or_create(user=user)
        return redirect("login")
    return render(request, "form.html", {"form": form, "title": "회원가입"})


@login_required
def write(request, pk=None):
    post = get_object_or_404(Post, pk=pk, author=request.user) if pk else None
    form = PostForm(request.POST if request.method == "POST" else None, instance=post)
    if form.is_valid():
        post = form.save(commit=False)
        post.author = request.user
        post.updated = timezone.now()
        post.save()
        return redirect("detail", pk=post.pk)
    return render(request, "form.html", {"form": form, "title": "게시글 수정" if pk else "새 글 쓰기"})


def detail(request, pk):
    post = get_object_or_404(Post.objects.select_related("author"), pk=pk)
    Post.objects.filter(pk=pk).update(views=F("views") + 1)
    post.refresh_from_db(fields=["views"])
    return render(request, "detail.html", {"post": post, "form": CommentForm(),
                                           "comments": post.comments.select_related("author").order_by("pk"),
                                           "like_count": post.post_likes.count()})


@login_required
@require_POST
def comment(request, pk):
    post = get_object_or_404(Post, pk=pk)
    form = CommentForm(request.POST)
    if form.is_valid():
        Comment.objects.create(post=post, author=request.user, **form.cleaned_data)
        return redirect("detail", pk=pk)
    return render(request, "detail.html", {"post": post, "form": form,
                                           "comments": post.comments.select_related("author").order_by("pk"),
                                           "like_count": post.post_likes.count()}, status=400)


@login_required
@require_POST
def like(request, pk):
    post = get_object_or_404(Post, pk=pk)
    PostLike.objects.get_or_create(post=post, user=request.user)
    return redirect("detail", pk=pk)


@login_required
def profile(request):
    profile, _ = Profile.objects.get_or_create(user=request.user)
    recent = Post.objects.filter(author=request.user).order_by("-pk")[:5]
    return render(request, "profile.html", {
        "profile": profile, "recent": recent,
        "post_count": Post.objects.filter(author=request.user).count(),
        "comment_count": Comment.objects.filter(author=request.user).count(),
        "like_count": PostLike.objects.filter(user=request.user).count(),
    })


def shop(request):
    profile = None
    if request.user.is_authenticated:
        profile, _ = Profile.objects.get_or_create(user=request.user)
    return render(request, "shop.html", {"products": Product.objects.order_by("pk"), "profile": profile})


@login_required
@require_POST
def buy(request, pk):
    with transaction.atomic():
        product = get_object_or_404(Product.objects.select_for_update(), pk=pk)
        profile, _ = Profile.objects.select_for_update().get_or_create(user=request.user)
        if product.stock < 1 or profile.points < product.price:
            messages.error(request, "재고 또는 포인트가 부족합니다.")
            return redirect("shop")
        product.stock -= 1
        profile.points -= product.price
        product.save(update_fields=["stock"])
        profile.save(update_fields=["points"])
        Order.objects.create(user=request.user, product=product, quantity=1,
                             unit_price=product.price, total=product.price)
    messages.success(request, "구매가 완료되었습니다.")
    return redirect("shop")


@login_required
def admin_panel(request):
    if not request.user.is_staff:
        return HttpResponseForbidden("접근할 수 없습니다.")
    return render(request, "admin.html", {
        "members": Profile.objects.select_related("user").order_by("-pk")[:50],
        "notices": Notice.objects.order_by("-pk")[:10],
        "order_count": Order.objects.count(),
    })


@login_required
@require_POST
def create_notice(request):
    if not request.user.is_staff:
        return HttpResponseForbidden("접근할 수 없습니다.")
    title = request.POST.get("title", "").strip()
    content = request.POST.get("content", "").strip()
    if not title or len(title) > 200 or not content or len(content) > 10000:
        messages.error(request, "공지 내용을 확인해 주세요.")
        return redirect("admin_panel")
    Notice.objects.create(title=title, content=content, author=request.user)
    return redirect("admin_panel")


def notice_detail(request, pk):
    notice = get_object_or_404(Notice.objects.select_related("author"), pk=pk)
    Notice.objects.filter(pk=pk).update(views=F("views") + 1)
    return render(request, "notice.html", {"notice": notice})


DATA_DIR = Path(__file__).resolve().parent.parent / "data"


def practice(request):
    catalog = json.loads((DATA_DIR / "vulns.json").read_text(encoding="utf-8"))
    vulns = [item for item in catalog["vulns"]
             if item.get("engine", "all") in ("all", "django")]
    exfiles = json.loads((DATA_DIR / "exploits.json").read_text(encoding="utf-8")).get("files", [])
    for v in vulns:
        v["files"] = [f for f in exfiles if v["id"] in f.get("items", [])]
    return render(request, "practice.html", {"categories": catalog["categories"], "vulns": vulns, "exfiles": exfiles})


def thoughts(request):
    approaches = json.loads((DATA_DIR / "approaches.json").read_text(encoding="utf-8"))
    engine = "django"
    groups = []
    for g in approaches["groups"]:
        entries = [e for e in g["entries"] if engine in e.get("engines", [])]
        if entries:
            groups.append({"title": g["title"], "entries": entries})
    return render(request, "thoughts.html",
                  {"approaches": {"intro": approaches["intro"], "groups": groups}, "engine": engine})


def exploit(request):
    import base64
    from django.http import HttpResponse, HttpResponseNotFound
    name = Path(request.GET.get("name", "")).name
    files = json.loads((DATA_DIR / "exploits.json").read_text(encoding="utf-8")).get("files", [])
    meta = next((f for f in files if f.get("name") == name), None)
    if not meta:
        return HttpResponseNotFound("예제 파일을 찾을 수 없습니다.")
    store = meta.get("store", name)
    target = DATA_DIR / "exploits" / Path(store).name
    if not target.is_file():
        return HttpResponseNotFound("예제 파일을 찾을 수 없습니다.")
    raw = target.read_text(encoding="utf-8")
    data = base64.b64decode(raw) if meta.get("encoded") else raw.encode()
    resp = HttpResponse(data, content_type="text/plain; charset=utf-8")
    if not request.GET.get("raw"):
        resp["Content-Disposition"] = f'attachment; filename="{name}"'
    return resp


# ============================================================
# Django 전용 취약점 — 의도적 결함(로컬 전용). 안전한 커뮤니티 CRUD 와 분리.
# ============================================================
def render_template(request):
    # [VULN] SSTI — 사용자 입력을 Django Template 로 렌더 + settings 를 컨텍스트에 노출
    from django.template import Template, Context
    from django.conf import settings as dj_settings
    from django.http import HttpResponse
    tpl = request.GET.get("tpl", "")
    if not tpl:
        return HttpResponse("usage: /render?tpl={{ settings.SECRET_KEY }}", content_type="text/plain; charset=utf-8")
    try:
        out = Template(tpl).render(Context({"settings": dj_settings, "request": request, "user": request.user}))
    except Exception as exc:  # noqa: BLE001
        out = f"[template error] {exc}"
    return HttpResponse(out, content_type="text/plain; charset=utf-8")


def search_raw(request):
    # [VULN] SQL Injection — .raw() 에 문자열을 직접 포매팅
    from django.contrib.auth import get_user_model
    from django.http import HttpResponse
    q = request.GET.get("q", "")
    # [VULN] 문자열 직접 연결(= 비교). psycopg 가 리터럴 %% 를 플레이스홀더로 보므로 LIKE 대신 = 사용.
    sql = "SELECT id, username, email FROM auth_user WHERE username = '" + q + "'"
    try:
        rows = list(get_user_model().objects.raw(sql))
        body = "id\tusername\temail(또는 UNION 주입 컬럼)\n" + "\n".join(
            f"{u.id}\t{u.username}\t{u.email}" for u in rows)
    except Exception as exc:  # noqa: BLE001  — [VULN] verbose 오류(쿼리 노출)
        body = f"[sql error] {exc}\nquery: {sql}"
    return HttpResponse(body or "(no results)", content_type="text/plain; charset=utf-8")


@csrf_exempt
def api_load(request):
    # [VULN] 안전하지 않은 역직렬화 — 신뢰 불가 base64(pickle) 복원 → RCE
    import base64
    import pickle
    from django.http import HttpResponse
    data = request.POST.get("data", "") or request.body.decode("utf-8", "ignore")
    if not data:
        return HttpResponse("no data", status=400)
    try:
        obj = pickle.loads(base64.b64decode(data))
        return HttpResponse(f"loaded: {obj!r}", content_type="text/plain; charset=utf-8")
    except Exception as exc:  # noqa: BLE001
        return HttpResponse(f"[deser] {exc}", content_type="text/plain; charset=utf-8")


def style(request):
    return FileResponse(open(Path(__file__).resolve().parent.parent / "public" / "style.css", "rb"),
                        content_type="text/css")
