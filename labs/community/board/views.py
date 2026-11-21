from django.contrib.auth.decorators import login_required
from django.contrib.auth.forms import UserCreationForm
from django.core.paginator import Paginator
from django.forms import modelform_factory
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import Comment, Post

PostForm = modelform_factory(Post, fields=["title", "content"])
CommentForm = modelform_factory(Comment, fields=["content"])


def index(request):
    query = request.GET.get("q", "").strip()
    posts = Post.objects.select_related("author").filter(title__icontains=query).order_by("-pk")
    page = Paginator(posts, 15).get_page(request.GET.get("page"))
    return render(request, "index.html", {"page": page, "query": query})


def register(request):
    form = UserCreationForm(request.POST if request.method == "POST" else None)
    if form.is_valid():
        form.save()
        return redirect("login")
    return render(request, "form.html", {"form": form, "title": "회원가입"})


@login_required
def write(request, pk=None):
    post = get_object_or_404(Post, pk=pk, author=request.user) if pk else None
    form = PostForm(request.POST if request.method == "POST" else None, instance=post)
    if form.is_valid():
        post = form.save(commit=False)
        post.author = request.user
        post.save()
        return redirect("detail", pk=post.pk)
    return render(request, "form.html", {"form": form, "title": "게시글 수정" if pk else "새 글 쓰기"})


def detail(request, pk):
    post = get_object_or_404(Post.objects.select_related("author"), pk=pk)
    return render(request, "detail.html", {"post": post, "form": CommentForm()})


@login_required
@require_POST
def comment(request, pk):
    post = get_object_or_404(Post, pk=pk)
    form = CommentForm(request.POST)
    if form.is_valid():
        Comment.objects.create(post=post, author=request.user, **form.cleaned_data)
        return redirect("detail", pk=pk)
    return render(request, "detail.html", {"post": post, "form": form}, status=400)
