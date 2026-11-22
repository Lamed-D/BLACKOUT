from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("board", "0002_product_comment_created_comment_parent_post_updated_and_more"),
    ]

    operations = [
        migrations.AddField(
            model_name="profile",
            name="avatar",
            field=models.URLField(blank=True, max_length=500),
        ),
        migrations.AddField(
            model_name="profile",
            name="oauth_provider",
            field=models.CharField(blank=True, max_length=20),
        ),
        migrations.AddField(
            model_name="profile",
            name="oauth_id",
            field=models.CharField(blank=True, max_length=255),
        ),
    ]
