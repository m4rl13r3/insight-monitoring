from __future__ import annotations

import smtplib
import ssl
from email.message import EmailMessage
from urllib.parse import urlencode
from urllib.request import urlopen


def send_sms(user: str, password: str, message: str) -> bool:
    if not user or not password or not message:
        return False

    query = urlencode({"user": user, "pass": password, "msg": message})
    sms_url = f"https://smsapi.free-mobile.fr/sendmsg?{query}"

    try:
        with urlopen(sms_url, timeout=12) as response:
            return int(getattr(response, "status", 0) or 0) == 200
    except Exception:
        return False


def send_email_smtp(
    smtp_host: str,
    smtp_port: int | str,
    smtp_username: str,
    smtp_password: str,
    smtp_encryption: str,
    from_name: str,
    to_email: str,
    to_name: str,
    subject: str,
    message: str,
) -> bool:
    host = str(smtp_host or "").strip()
    username = str(smtp_username or "").strip()
    password = str(smtp_password or "").strip()
    recipient = str(to_email or "").strip()
    if not host or not username or not password or not recipient:
        return False

    try:
        port = int(str(smtp_port or "").strip() or "465")
    except Exception:
        port = 465

    text = " ".join(str(message or "").replace("\r", " ").replace("\n", " ").split()).strip()
    if not text:
        return False

    safe_subject = str(subject or "").strip() or "Updates monitoring"
    display_name = str(to_name or "").strip() or "Team member"
    sender_name = str(from_name or "").strip() or "Insight"
    encryption = str(smtp_encryption or "ssl").strip().lower()

    email_message = EmailMessage()
    email_message["From"] = f"{sender_name} <{username}>"
    email_message["To"] = f"{display_name} <{recipient}>"
    email_message["Subject"] = safe_subject
    email_message.set_content(text)
    html = (
        "<html><body>"
        f"<p>Hello {display_name},</p>"
        f"<p>{text}</p>"
        "<p>Regards,<br>The Insight Team</p>"
        "</body></html>"
    )
    email_message.add_alternative(html, subtype="html")

    try:
        if encryption == "ssl":
            with smtplib.SMTP_SSL(host, port, timeout=15, context=ssl.create_default_context()) as smtp:
                smtp.login(username, password)
                smtp.send_message(email_message)
            return True

        with smtplib.SMTP(host, port, timeout=15) as smtp:
            smtp.ehlo()
            if encryption in {"tls", "starttls"}:
                smtp.starttls(context=ssl.create_default_context())
                smtp.ehlo()
            smtp.login(username, password)
            smtp.send_message(email_message)
        return True
    except Exception:
        return False


def send_incident_alert(
    user: str,
    password: str,
    site_url: str,
    state: str,
    http_code: int | None = None,
    pm_text: str = "",
    timeout: bool = False,
) -> bool:
    safe_pm_text = " ".join(str(pm_text or "").replace("\r", " ").replace("\n", " ").split())
    lower_pm = safe_pm_text.lower()
    pm_invalid = (
        safe_pm_text == ""
        or "content-type:" in lower_pm
        or "http error:" in lower_pm
        or "curl error:" in lower_pm
        or "debug:" in lower_pm
    )

    if state == "open":
        message = f"Incident opened. {site_url} did not respond after several attempts."
    elif state == "close":
        if timeout or pm_invalid:
            message = (
                f"Incident resolved because {site_url} appears to be available again. "
                "The incident report is unavailable; contact the Insight Team."
            )
        else:
            message = (
                f"Incident resolved because {site_url} appears to be available again. "
                f"Incident summary: {safe_pm_text[:280]}"
            )
    else:
        return False

    return send_sms(user, password, message)
