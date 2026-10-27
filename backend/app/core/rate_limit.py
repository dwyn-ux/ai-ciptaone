import time
import threading
from typing import Dict, Tuple
from app.core.errors import AppError, ErrorCode


class InMemoryRateLimiter:
    """
    Sliding window in-memory rate limiter.
    Provides sub-millisecond rate checks without overloading the database.
    """

    def __init__(self):
        self._lock = threading.Lock()
        # key -> (count, window_start_timestamp)
        self._buckets: Dict[str, Tuple[int, float]] = {}

    def check_rate_limit(self, key: str, max_requests: int, window_seconds: int = 60) -> bool:
        """
        Returns True if request is allowed, raises AppError(RATE_LIMIT_EXCEEDED) otherwise.
        """
        now = time.time()
        with self._lock:
            if key in self._buckets:
                count, window_start = self._buckets[key]
                if now - window_start < window_seconds:
                    if count >= max_requests:
                        retry_after = int(window_seconds - (now - window_start)) + 1
                        raise AppError(
                            code=ErrorCode.RATE_LIMIT_EXCEEDED,
                            message=f"Batas pemakaian terlampaui. Coba lagi dalam {retry_after} detik.",
                            status_code=429,
                            details={"retry_after_seconds": retry_after}
                        )
                    self._buckets[key] = (count + 1, window_start)
                    return True
                else:
                    self._buckets[key] = (1, now)
                    return True
            else:
                self._buckets[key] = (1, now)
                return True

    def cleanup(self):
        """Cleans up expired buckets to prevent memory leaks."""
        now = time.time()
        with self._lock:
            expired = [k for k, (_, w_start) in self._buckets.items() if now - w_start > 3600]
            for k in expired:
                del self._buckets[k]


limiter = InMemoryRateLimiter()
