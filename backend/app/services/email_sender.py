"""Send one plain email. The only module that knows which email service is used.

settings.EMAIL_PROVIDER picks the service:
  console  log the message instead of sending it (local runs, tests)
  brevo    Brevo's transactional HTTPS API (https://developers.brevo.com)

Why an HTTPS API and not SMTP: Render's free plan blocks outgoing SMTP ports,
so smtplib would just time out there. Switching service later means adding one
function here and one EMAIL_PROVIDER value; nothing else changes.
"""
import logging

import httpx

from app.config import settings

log = logging.getLogger(__name__)

BREVO_URL = "https://api.brevo.com/v3/smtp/email"


class EmailError(Exception):
    """The email could not be handed to the provider. The caller answers 503."""


def send_email(to: str, subject: str, text: str) -> None:
    provider = settings.EMAIL_PROVIDER.strip().lower()
    if provider == "console":
        # WARNING level so it shows in Render's log with the default config.
        log.warning("EMAIL (console, not sent) to=%s subject=%s\n%s", to, subject, text)
        return
    if provider == "brevo":
        _send_brevo(to, subject, text)
        return
    raise EmailError(f"Unknown EMAIL_PROVIDER {settings.EMAIL_PROVIDER!r}")


def _send_brevo(to: str, subject: str, text: str) -> None:
    if not settings.BREVO_API_KEY or not settings.EMAIL_FROM:
        raise EmailError("EMAIL_PROVIDER=brevo needs BREVO_API_KEY and EMAIL_FROM")
    try:
        res = httpx.post(
            BREVO_URL,
            headers={"api-key": settings.BREVO_API_KEY, "accept": "application/json"},
            json={
                "sender": {"email": settings.EMAIL_FROM, "name": settings.EMAIL_FROM_NAME},
                "to": [{"email": to}],
                "subject": subject,
                "textContent": text,
            },
            timeout=10,
        )
    except httpx.HTTPError as exc:
        raise EmailError(f"Brevo unreachable: {exc}") from exc
    if res.status_code >= 300:
        # Brevo's body says why (e.g. "sender not verified"); log it, never the key.
        log.error("Brevo refused the email: %s %s", res.status_code, res.text[:300])
        raise EmailError(f"Brevo answered {res.status_code}")
