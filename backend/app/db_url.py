"""Turn DATABASE_URL into what SQLAlchemy + PyMySQL need.

Why this exists: hosted MySQL (Aiven) hands out a URL like

    mysql://avnadmin:PASS@host:port/defaultdb?ssl-mode=REQUIRED

which does not work as-is:
  * "mysql://" makes SQLAlchemy look for the MySQLdb driver; we ship PyMySQL.
  * "ssl-mode" is a MySQL-client option PyMySQL does not understand, and
    Aiven refuses connections that are not encrypted.

So the URL can be pasted from the provider unchanged, and this module:
  1. switches "mysql://" to "mysql+pymysql://",
  2. removes ssl-mode from the URL,
  3. turns TLS on when ssl-mode asked for it or DB_SSL_CA is set.

With DB_SSL_CA (path to the provider's CA certificate) the server's
certificate is verified. Without it the connection is still encrypted but the
server is not verified - acceptable for a demo, not for real student data.
"""
from sqlalchemy.engine import URL, make_url

_SSL_ON = {"required", "verify_ca", "verify_identity", "preferred"}


def prepare_db_url(raw_url: str, ssl_ca: str | None = None) -> tuple[URL, dict]:
    """Return (url, connect_args) ready for create_engine()."""
    url = make_url(raw_url)
    if url.get_backend_name() != "mysql":
        return url, {}

    if url.drivername == "mysql":
        url = url.set(drivername="mysql+pymysql")

    query = dict(url.query)
    mode = None
    for key in ("ssl-mode", "ssl_mode", "sslmode"):
        if key in query:
            mode = str(query.pop(key)).lower().replace("-", "_")
    url = url.set(query=query)

    if ssl_ca:
        return url, {"ssl": {"ca": ssl_ca}}
    if mode in _SSL_ON:
        # Non-empty dict so PyMySQL turns TLS on; no CA means "encrypt, don't verify".
        return url, {"ssl": {"verify_mode": False, "check_hostname": False}}
    return url, {}
