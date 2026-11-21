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


def practice(request):
    catalog = json.loads((Path(__file__).resolve().parent.parent / "data" / "vulns.json").read_text(encoding="utf-8"))
    vulns = [item for item in catalog["vulns"]
             if item.get("engine", "all") in ("all", "django")]
    return render(request, "practice.html", {"categories": catalog["categories"], "vulns": vulns})


def style(request):
    return FileResponse(open(Path(__file__).resolve().parent.parent / "public" / "style.css", "rb"),
                        content_type="text/css")
