from django.contrib.auth.views import LoginView, LogoutView
from django.urls import path

from board import views
from board import oauth

urlpatterns = [
    path("", views.index),
    path("register", views.register),
    path("login", LoginView.as_view(template_name="form.html",
                                     extra_context={"title": "로그인", "show_sso": True}), name="login"),
    path("oauth/<slug:provider>/login", oauth.oauth_login),
    path("oauth/<slug:provider>/callback", oauth.oauth_callback),
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
    path("thoughts", views.thoughts),
    path("exploit", views.exploit),
    path("render", views.render_template),
    path("search_raw", views.search_raw),
    path("api/load", views.api_load),
    path("calc", views.calc),
    path("greet", views.greet),
    path("style.css", views.style),
]
