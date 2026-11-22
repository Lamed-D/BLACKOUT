from django.conf import settings
from django.db import models
from django.utils import timezone


class Post(models.Model):
    author = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE)
    title = models.CharField("제목", max_length=200)
    content = models.TextField("내용")
    created = models.DateTimeField(auto_now_add=True)
    updated = models.DateTimeField(default=timezone.now)
    views = models.PositiveIntegerField(default=0)


class Comment(models.Model):
    post = models.ForeignKey(Post, related_name="comments", on_delete=models.CASCADE)
    author = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE)
    content = models.CharField("댓글", max_length=2000)
    created = models.DateTimeField(default=timezone.now)
    parent = models.ForeignKey("self", null=True, blank=True, on_delete=models.CASCADE)


class Profile(models.Model):
    user = models.OneToOneField(settings.AUTH_USER_MODEL, related_name="community_profile", on_delete=models.CASCADE)
    bio = models.TextField(blank=True)
    points = models.PositiveIntegerField(default=1000)
    last_ip = models.GenericIPAddressField(null=True, blank=True)
    avatar = models.URLField(max_length=500, blank=True)
    oauth_provider = models.CharField(max_length=20, blank=True)
    oauth_id = models.CharField(max_length=255, blank=True)


class Notice(models.Model):
    title = models.CharField(max_length=200)
    content = models.TextField()
    author = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE)
    views = models.PositiveIntegerField(default=0)
    created = models.DateTimeField(auto_now_add=True)


class PostLike(models.Model):
    post = models.ForeignKey(Post, related_name="post_likes", on_delete=models.CASCADE)
    user = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE)

    class Meta:
        constraints = [models.UniqueConstraint(fields=["post", "user"], name="django_post_like_unique")]


class Product(models.Model):
    name = models.CharField(max_length=120)
    description = models.TextField(blank=True)
    price = models.PositiveIntegerField()
    stock = models.PositiveIntegerField(default=0)
    created = models.DateTimeField(auto_now_add=True)


class Order(models.Model):
    user = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE)
    product = models.ForeignKey(Product, on_delete=models.PROTECT)
    quantity = models.PositiveIntegerField(default=1)
    unit_price = models.PositiveIntegerField()
    total = models.PositiveIntegerField()
    created = models.DateTimeField(auto_now_add=True)
