"""DATABASE_URL clean-up for hosted MySQL (app/db_url.py)."""
from app.db_url import prepare_db_url

AIVEN = "mysql://avnadmin:p%40ss@mysql-x.aivencloud.com:10955/defaultdb?ssl-mode=REQUIRED"


def test_aiven_url_gets_pymysql_driver_and_tls():
    url, args = prepare_db_url(AIVEN)
    assert url.drivername == "mysql+pymysql"
    assert "ssl-mode" not in url.query
    assert url.password == "p@ss"
    assert args == {"ssl": {"verify_mode": False, "check_hostname": False}}


def test_ca_file_turns_on_verification():
    _, args = prepare_db_url(AIVEN, ssl_ca="/etc/secrets/ca.pem")
    assert args == {"ssl": {"ca": "/etc/secrets/ca.pem"}}


def test_local_mysql_without_ssl_mode_is_unchanged():
    url, args = prepare_db_url("mysql+pymysql://u:p@localhost:3306/timetable")
    assert url.drivername == "mysql+pymysql"
    assert args == {}


def test_sqlite_passes_through():
    url, args = prepare_db_url("sqlite://")
    assert url.get_backend_name() == "sqlite"
    assert args == {}
