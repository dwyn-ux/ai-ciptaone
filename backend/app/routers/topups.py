from typing import Any, Dict, List
from fastapi import APIRouter, Depends, File, Request, UploadFile, status
from app.config import settings
from app.core.rate_limit import limiter
from app.models.schemas import TopupCreateRequest, TopupResponse, UserResponse
from app.routers.deps import get_current_user_from_session
from app.services.topup_service import topup_service

router = APIRouter(prefix="/api/v1/topups", tags=["Top-up & Payments"])


@router.post("", response_model=TopupResponse, status_code=status.HTTP_201_CREATED)
async def create_topup(
    req: TopupCreateRequest,
    request: Request,
    current_user: UserResponse = Depends(get_current_user_from_session),
):
    # Rate limit topup creation
    limiter.check_rate_limit(f"topup_usr_{current_user.id}", max_requests=settings.RATE_LIMIT_TOPUP_PER_MINUTE)

    return await topup_service.create_topup(
        user_id=current_user.id,
        user_email=current_user.email,
        amount_minor=req.amount_minor,
        payment_method=req.payment_method,
    )


@router.get("", response_model=List[Dict[str, Any]])
async def list_topups(current_user: UserResponse = Depends(get_current_user_from_session)):
    return await topup_service.list_user_topups(user_id=current_user.id)


@router.get("/{identifier}", response_model=Dict[str, Any])
async def get_topup_status(
    identifier: str,
    current_user: UserResponse = Depends(get_current_user_from_session),
):
    return await topup_service.get_topup_by_id_or_order(identifier, user_id=current_user.id)


@router.post("/{identifier}/proof")
async def upload_proof(
    identifier: str,
    file: UploadFile = File(...),
    current_user: UserResponse = Depends(get_current_user_from_session),
):
    content_bytes = await file.read()
    content_type = file.content_type or "application/octet-stream"
    filename = file.filename or "proof.jpg"

    return await topup_service.upload_proof(
        order_id=identifier,
        user_id=current_user.id,
        filename=filename,
        content_type=content_type,
        file_bytes=content_bytes,
    )
