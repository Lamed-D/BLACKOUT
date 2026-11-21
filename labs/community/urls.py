from django.contrib.auth.views import LoginView, LogoutView
from django.urls import path
from django.views.generic import TemplateView

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
    path("practice", TemplateView.as_view(template_name="practice.html")),
]
