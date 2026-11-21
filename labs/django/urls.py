from django.contrib.auth.views import LoginView, LogoutView
from django.urls import path

from board import views

urlpatterns = [
    path("", views.index),
    path("register", views.register),
    path("login", LoginView.as_view(template_name="form.html", extra_context={"title": "로그인"}), name="login"),
    path("logout", LogoutView.as_view()),
    path("post/write", views.write),
    path("post/<int:pk>", views.detail, name="detail"),
    path("post/<int:pk>/edit", views.write),
    path("post/<int:pk>/comment", views.comment),
    path("post/<int:pk>/like", views.like),
    path("profile", views.profile),
    path("shop", views.shop, name="shop"),
    path("shop/<int:pk>/buy", views.buy),
    path("admin", views.admin_panel, name="admin_panel"),
    path("admin/notice", views.create_notice),
    path("notice/<int:pk>", views.notice_detail),
    path("practice", views.practice),
    path("style.css", views.style),
]
