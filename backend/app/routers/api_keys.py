from typing import List
from fastapi import APIRouter, Depends, status
from app.models.schemas import ApiKeyCreatedResponse, ApiKeyCreateRequest, ApiKeyResponse, UserResponse
from app.routers.deps import get_current_user_from_session
from app.services.api_key_service import api_key_service

router = APIRouter(prefix="/api/v1/api-keys", tags=["API Keys"])


@router.post("", response_model=ApiKeyCreatedResponse, status_code=status.HTTP_201_CREATED)
async def create_api_key(
    req: ApiKeyCreateRequest,
    current_user: UserResponse = Depends(get_current_user_from_session),
):
    return await api_key_service.create_api_key(user_id=current_user.id, name=req.name)


@router.get("", response_model=List[ApiKeyResponse])
async def list_api_keys(current_user: UserResponse = Depends(get_current_user_from_session)):
    return await api_key_service.list_user_api_keys(user_id=current_user.id)


@router.delete("/{key_id}")
async def revoke_api_key(
    key_id: str,
    current_user: UserResponse = Depends(get_current_user_from_session),
):
    await api_key_service.revoke_api_key(user_id=current_user.id, key_id=key_id)
    return {"status": "success", "message": "API key berhasil dicabut."}
