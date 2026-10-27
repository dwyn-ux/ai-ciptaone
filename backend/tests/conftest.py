import os
import shutil
import tempfile
import pytest
from app.config import settings
from app.core.database import db

TEST_DB_PATH = os.path.join(tempfile.gettempdir(), "test_decomu_d1.sqlite3")


@pytest.fixture(scope="session", autouse=True)
def setup_test_database():
    """Configures the app to use an isolated SQLite test database with schema initialized."""
    if os.path.exists(TEST_DB_PATH):
        try:
            os.remove(TEST_DB_PATH)
        except Exception:
            pass

    settings.DB_MODE = "sqlite"
    settings.SQLITE_PATH = TEST_DB_PATH
    db.mode = "sqlite"
    db.sqlite_path = TEST_DB_PATH
    db.close()

    # Apply all migrations
    migrations_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "../../migrations"))
    db.init_schema(migrations_dir)

    yield

    db.close()
    if os.path.exists(TEST_DB_PATH):
        try:
            os.remove(TEST_DB_PATH)
        except Exception:
            pass
