from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand
from django.db import transaction

from board.models import Comment, Notice, Post, Product, Profile


class Command(BaseCommand):
    help = "공유 커뮤니티 시드를 멱등하게 생성합니다."

    @transaction.atomic
    def handle(self, *args, **options):
        user_model = get_user_model()
        accounts = [
            ("admin", "admin123", "관리자", "admin@blackout.lab", True, 999999),
            ("testuser", "testpass", "테스터", "test@blackout.lab", False, 1000),
            ("alice", "alice1234", "앨리스", "alice@blackout.lab", False, 1500),
        ]
        users = {}
        for username, password, name, email, staff, points in accounts:
            user, created = user_model.objects.get_or_create(
                username=username,
                defaults={"first_name": name, "email": email, "is_staff": staff},
            )
            if created:
                user.set_password(password)
                user.save(update_fields=["password"])
            Profile.objects.get_or_create(user=user, defaults={"points": points})
            users[username] = user

        Notice.objects.get_or_create(
            title="[공지] BLACKOUT 실습 카페 이용 안내",
            defaults={"content": "본 카페는 로컬 학습 환경입니다. 함께 즐겁게 토론해 주세요.",
                      "author": users["admin"]},
        )
        first, _ = Post.objects.get_or_create(
            title="가입 인사 드립니다!", author=users["testuser"],
            defaults={"content": "반갑습니다. 웹 보안 스터디를 위해 가입했습니다 😊"},
        )
        Post.objects.get_or_create(
            title="SQL Injection 학습 자료 공유", author=users["alice"],
            defaults={"content": "PortSwigger Academy의 SQL Injection 트랙을 추천합니다!"},
        )
        Comment.objects.get_or_create(
            post=first, author=users["alice"], content="환영합니다! 함께 공부해요 :)")
        Comment.objects.get_or_create(
            post=first, author=users["admin"],
            content="잘 오셨습니다! 실습 랩을 마음껏 활용해 보세요.")
        for name, description, price, stock in [
            ("실습 포인트 충전권", "포인트 1000 충전 쿠폰", 1000, 100),
            ("BLACKOUT 후드티", "한정판 보안 스터디 굿즈", 35000, 20),
            ("프리미엄 멘토링 1회", "1:1 웹해킹 멘토링", 200000, 5),
        ]:
            Product.objects.get_or_create(
                name=name, defaults={"description": description, "price": price, "stock": stock})
        self.stdout.write(self.style.SUCCESS("커뮤니티 시드 준비 완료"))
