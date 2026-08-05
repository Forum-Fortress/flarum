from __future__ import annotations

import logging
import smtplib
from email.message import EmailMessage
from urllib.parse import urlparse

LOGGER = logging.getLogger(__name__)


def _resolve_from_email(settings, email_settings, *, configured_from_email: str) -> str:
    candidate = (configured_from_email or "").strip()
    if candidate:
        return candidate
    username = str(getattr(email_settings, "smtp_username", "") or "").strip()
    if "@" in username and "." in username.split("@", 1)[-1]:
        return username
    base_url = str(getattr(getattr(settings, "app", None), "public_base_url", "") or "").strip()
    hostname = ""
    if base_url:
        try:
            parsed = urlparse(base_url)
            hostname = str(parsed.hostname or "").strip().lower()
        except Exception:
            hostname = ""
    if hostname and "." in hostname:
        return f"noreply@{hostname}"
    return "noreply@forumfortress.com"


def send_email(
    settings,
    *,
    to_email: str,
    subject: str,
    text_body: str,
    html_body: str | None = None,
) -> bool:
    email_settings = settings.email
    is_enabled = bool(getattr(email_settings, "enabled", True))
    smtp_host = str(getattr(email_settings, "smtp_host", "") or "").strip()
    try:
        smtp_port = int(getattr(email_settings, "smtp_port", 587))
    except (TypeError, ValueError):
        LOGGER.exception("smtp_port_invalid value=%s", getattr(email_settings, "smtp_port", None))
        return False

    if not is_enabled or not smtp_host:
        return False
    from_email = _resolve_from_email(
        settings,
        email_settings,
        configured_from_email=getattr(email_settings, "from_email", ""),
    )
    if not from_email:
        return False

    message = EmailMessage()
    from_name = (email_settings.from_name or "").strip()
    if from_name:
        message["From"] = f"{from_name} <{from_email}>"
    else:
        message["From"] = str(from_email)
    message["To"] = to_email
    message["Subject"] = subject
    message.set_content(text_body)
    if html_body:
        message.add_alternative(html_body, subtype="html")

    if email_settings.smtp_use_ssl:
        smtp = smtplib.SMTP_SSL(smtp_host, smtp_port, timeout=15)
    else:
        smtp = smtplib.SMTP(smtp_host, smtp_port, timeout=15)

    try:
        try:
            smtp.ehlo()
            if email_settings.smtp_use_tls and not email_settings.smtp_use_ssl:
                smtp.starttls()
                smtp.ehlo()
            if email_settings.smtp_username:
                smtp.login(email_settings.smtp_username, email_settings.smtp_password or "")
            smtp.send_message(message)
        except smtplib.SMTPException:
            LOGGER.exception("smtp_send_failed to=%s host=%s", to_email, smtp_host)
            return False
        finally:
            smtp.quit()
    except (OSError, RuntimeError):
        LOGGER.exception("smtp_connect_failed to=%s host=%s", to_email, smtp_host)
        return False
    except Exception:
        LOGGER.exception("smtp_send_failed_unexpected to=%s host=%s", to_email, smtp_host)
        return False

    return True
