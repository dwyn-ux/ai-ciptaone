from fastapi import APIRouter, status
from fastapi.responses import JSONResponse
from app.core.database import db

router = APIRouter(prefix="/health", tags=["Health Checks"])


@router.get("/live")
async def liveness_check():
    """Liveness probe: verifies process is alive and accepting connections."""
    return {"status": "ok", "service": "ai-ciptaone-router"}


@router.get("/ready")
async def readiness_check():
    """Readiness probe: verifies database connectivity and core subsystems."""
    try:
        row = await db.fetch_one("SELECT 1 as is_ready")
        if row and row.get("is_ready") == 1:
            return {"status": "ready", "database": "connected"}
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"status": "not_ready", "database_error": str(e)},
        )
    return JSONResponse(
        status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
        content={"status": "not_ready", "error": "Database check failed"},
    )
